<?php
/**
 * Copyright (C) 2026 Benjamin Rosenberger <bensch.rosenberger@gmail.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * @copyright 2026 Benjamin Rosenberger
 * @author bensch.rosenberger@gmail.com
 * @license MIT
 * @link https://brocode.at
 */


declare(strict_types=1);

namespace BroCode\Poppy\Model\Agent;

use BroCode\Poppy\Exception\ClientMetadataException;
use BroCode\Poppy\Exception\FetchException;
use BroCode\Poppy\Exception\RateLimitException;
use BroCode\Poppy\Model\Http\FetchResult;
use BroCode\Poppy\Model\Http\SafeFetcher;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Fetches and validates a Personal Agent's client metadata and key set (spec 4.1), with caching.
 *
 * A resolved agent is cached for the shorter max-age of the two documents. A failure is cached for
 * 60 s for the client_id and for its whole host, so random paths cannot make every request fetch
 * again. Every fetch on a cache miss spends from a per-store budget per minute; past it the caller
 * gets a RateLimitException (HTTP 429). A refresh (unknown delegation kid) refetches at most once a
 * minute per client_id.
 */
class ClientMetadataResolver
{
    public const CACHE_PREFIX = 'brocode_poppy_cimd_';
    public const HOST_CACHE_PREFIX = 'brocode_poppy_cimd_host_';
    public const REFRESH_CACHE_PREFIX = 'brocode_poppy_cimd_refresh_';
    public const BUDGET_CACHE_PREFIX = 'brocode_poppy_fetch_budget_';
    public const XML_PATH_MAX_FETCHES = 'brocode_poppy/session/max_fetches_per_minute';
    public const DEFAULT_MAX_FETCHES = 30;
    public const NEGATIVE_TTL = 60;
    public const AUTH_METHOD = 'private_key_jwt';

    /**
     * JWK members that hold private or symmetric key material (RFC 7518 6.2.2, 6.3.2, 6.4.1).
     */
    private const PRIVATE_MEMBERS = ['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth', 'k'];

    /**
     * @param SafeFetcher $fetcher
     * @param CacheInterface $cache
     * @param SerializerInterface $serializer
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly SafeFetcher $fetcher,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Resolve the client metadata at the client_id URL.
     *
     * @param string $clientId
     * @param bool $refresh Fetch again despite a cached result, at most once a minute (key rotation)
     * @return ClientMetadata
     * @throws ClientMetadataException
     * @throws RateLimitException when the fetch budget of this store is spent for this minute
     */
    public function resolve(string $clientId, bool $refresh = false): ClientMetadata
    {
        if (!$this->isClientIdUrl($clientId)) {
            throw new ClientMetadataException('client_id is not a valid https client ID URL.');
        }

        $cacheKey = self::CACHE_PREFIX . hash('sha256', $clientId);
        $cached = $this->fromCache($cacheKey, $clientId);
        $refreshKey = self::REFRESH_CACHE_PREFIX . hash('sha256', $clientId);
        if ($cached !== null && (!$refresh || $this->cache->load($refreshKey))) {
            return $cached;
        }
        $hostKey = self::HOST_CACHE_PREFIX . hash('sha256', $this->host($clientId));
        if ($this->cache->load($hostKey)) {
            throw new ClientMetadataException('Client metadata host failed recently; not retried yet.');
        }
        if ($refresh) {
            $this->cache->save('1', $refreshKey, [], self::NEGATIVE_TTL);
        }

        try {
            $metadata = $this->fetch($clientId);
        } catch (ClientMetadataException $e) {
            $negative = (string)$this->serializer->serialize(['ok' => false]);
            $this->cache->save($negative, $cacheKey, [], self::NEGATIVE_TTL);
            $this->cache->save($negative, $hostKey, [], self::NEGATIVE_TTL);
            throw $e;
        }

        $this->cache->save(
            (string)$this->serializer->serialize([
                'ok' => true,
                'client_id' => $metadata->clientId,
                'keys' => $metadata->keysByKid,
                'max_age' => $metadata->maxAge,
            ]),
            $cacheKey,
            [],
            $metadata->maxAge
        );

        return $metadata;
    }

    /**
     * Fetch and validate both documents.
     *
     * @param string $clientId
     * @return ClientMetadata
     * @throws ClientMetadataException
     * @throws RateLimitException
     */
    private function fetch(string $clientId): ClientMetadata
    {
        try {
            $document = $this->budgetedFetch($clientId);
        } catch (FetchException $e) {
            throw new ClientMetadataException('Client metadata could not be fetched: ' . $e->getMessage(), 0, $e);
        }
        $body = $document->body;

        if (($body['client_id'] ?? null) !== $clientId) {
            throw new ClientMetadataException('Document client_id does not equal the URL it was fetched from.');
        }
        if (($body['token_endpoint_auth_method'] ?? null) !== self::AUTH_METHOD) {
            throw new ClientMetadataException('token_endpoint_auth_method must be private_key_jwt.');
        }
        $jwksUri = $body['jwks_uri'] ?? null;
        if (!is_string($jwksUri) || !$this->isHttpsUrl($jwksUri)
            || $this->host($jwksUri) !== $this->host($clientId)
        ) {
            throw new ClientMetadataException('jwks_uri must be an https URL on the same host as client_id.');
        }

        try {
            $jwks = $this->budgetedFetch($jwksUri);
        } catch (FetchException $e) {
            throw new ClientMetadataException('Key set could not be fetched: ' . $e->getMessage(), 0, $e);
        }

        $keys = $this->indexKeys($jwks->body['keys'] ?? null);
        if ($keys === []) {
            throw new ClientMetadataException('Key set has no keys with a kid.');
        }

        return new ClientMetadata($clientId, $keys, min($document->maxAge, $jwks->maxAge));
    }

    /**
     * Fetch one document, spending one unit of this store's fetch budget for the current minute.
     *
     * The counter lives in the Magento cache: approximate under concurrency, which is enough to keep
     * unsigned requests from turning the store into a fetch amplifier.
     *
     * @param string $url
     * @return FetchResult
     * @throws FetchException
     * @throws RateLimitException
     */
    private function budgetedFetch(string $url): FetchResult
    {
        $now = (int)$this->dateTime->gmtTimestamp();
        $key = self::BUDGET_CACHE_PREFIX . (int)$this->storeManager->getStore()->getId() . '_' . intdiv($now, 60);
        $max = (int)$this->scopeConfig->getValue(self::XML_PATH_MAX_FETCHES, ScopeInterface::SCOPE_STORE);
        if ($max <= 0) {
            $max = self::DEFAULT_MAX_FETCHES;
        }
        $used = (int)$this->cache->load($key);
        if ($used >= $max) {
            throw new RateLimitException(60 - $now % 60, 'Metadata fetch budget spent for this minute.');
        }
        $this->cache->save((string)($used + 1), $key, [], 120);

        return $this->fetcher->fetchJson($url);
    }

    /**
     * Index the key set by kid: keys without a string kid are skipped, private members dropped.
     *
     * The first key wins when two share a kid.
     *
     * @param mixed $keys
     * @return array<string,array<string,mixed>>
     * @throws ClientMetadataException
     */
    private function indexKeys(mixed $keys): array
    {
        if (!is_array($keys) || !array_is_list($keys)) {
            throw new ClientMetadataException('Key set has no keys array.');
        }

        $indexed = [];
        foreach ($keys as $key) {
            if (!is_array($key) || !isset($key['kid']) || !is_string($key['kid']) || $key['kid'] === ''
                || isset($indexed[$key['kid']])
            ) {
                continue;
            }
            $indexed[$key['kid']] = array_diff_key($key, array_flip(self::PRIVATE_MEMBERS));
        }

        return $indexed;
    }

    /**
     * Cached result for the key: metadata, null on a miss, or the cached failure rethrown.
     *
     * @param string $cacheKey
     * @param string $clientId
     * @return ClientMetadata|null
     * @throws ClientMetadataException
     */
    private function fromCache(string $cacheKey, string $clientId): ?ClientMetadata
    {
        $raw = $this->cache->load($cacheKey);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $entry = $this->serializer->unserialize($raw);
        } catch (\InvalidArgumentException $e) {
            return null;
        }
        if (!is_array($entry)) {
            return null;
        }
        if (($entry['ok'] ?? null) === false) {
            throw new ClientMetadataException('Client metadata failed recently; not retried yet.');
        }
        if (($entry['client_id'] ?? null) !== $clientId || !is_array($entry['keys'] ?? null)
            || !is_int($entry['max_age'] ?? null)
        ) {
            return null;
        }

        return new ClientMetadata($clientId, $entry['keys'], $entry['max_age']);
    }

    /**
     * Whether the URL is a client ID URL as the CIMD draft defines it.
     *
     * It needs https, a host and a path, and has no fragment, user info or dot segments.
     *
     * @param string $url
     * @return bool
     */
    private function isClientIdUrl(string $url): bool
    {
        if (!$this->isHttpsUrl($url)) {
            return false;
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- plain URL parsing, no I/O
        $path = (string)parse_url($url, PHP_URL_PATH);
        if ($path === '' || $path[0] !== '/') {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * An absolute https URL with a host, without user info or fragment.
     *
     * @param string $url
     * @return bool
     */
    private function isHttpsUrl(string $url): bool
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- plain URL parsing, no I/O
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && !str_contains($url, '#')
            && !preg_match('/\s/', $url);
    }

    /**
     * Lowercase host of a URL.
     *
     * @param string $url
     * @return string
     */
    private function host(string $url): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- plain URL parsing, no I/O
        return strtolower((string)parse_url($url, PHP_URL_HOST));
    }
}
