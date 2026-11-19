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

namespace BroCode\Poppy\Model\Http;

use BroCode\Poppy\Exception\FetchException;
use Magento\Framework\App\DeploymentConfig;
use Laminas\Uri\Http as HttpUri;
use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * Fetches a JSON object from an agent-controlled https URL without exposing internal addresses.
 *
 * https only, no redirects, 3 s timeout, 64 KB cap. The host is resolved once, every address
 * must be public, and the connection is pinned to the first one so a second DNS answer
 * (rebinding) cannot point curl elsewhere.
 */
class SafeFetcher
{
    public const CONFIG_ALLOW_PRIVATE = 'brocode_poppy/allow_private_addresses';
    public const MAX_BYTES = 65536;
    public const TIMEOUT_SECONDS = 3;
    public const DEFAULT_MAX_AGE = 300;
    public const MIN_MAX_AGE = 60;
    public const MAX_MAX_AGE = 3600;
    public const USER_AGENT = 'BroCode-Poppy/0.1 (+https://brocode.at)';

    /**
     * @param CurlFactory $curlFactory
     * @param HostResolver $hostResolver
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly HostResolver $hostResolver,
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /**
     * Fetch the URL and decode its body as a JSON object.
     *
     * @param string $url
     * @return FetchResult
     * @throws FetchException
     */
    public function fetchJson(string $url): FetchResult
    {
        try {
            $uri = new HttpUri($url);
        } catch (\Exception $e) {
            throw new FetchException('Malformed URL.', 0, $e);
        }
        $host = strtolower(trim((string)$uri->getHost(), '[]'));
        if (strtolower((string)$uri->getScheme()) !== 'https' || $host === '') {
            throw new FetchException('Only absolute https URLs may be fetched.');
        }
        $port = (int)($uri->getPort() ?: 443);

        $address = $this->pinnableAddress($host);
        $pinned = str_contains($address, ':') ? '[' . $address . ']' : $address;

        $curl = $this->curlFactory->create();
        $curl->setOptions([
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $pinned],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_MAXFILESIZE => self::MAX_BYTES,
        ]);
        $curl->addHeader('Accept', 'application/json');
        $curl->addHeader('User-Agent', self::USER_AGENT);

        try {
            $curl->get($url);
        } catch (\Exception $e) {
            throw new FetchException('Fetch failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $curl->getStatus();
        if ($status !== 200) {
            throw new FetchException(
                $status >= 300 && $status < 400
                    ? 'Redirects are not followed (status ' . $status . ').'
                    : 'Unexpected status ' . $status . '.'
            );
        }

        $body = (string)$curl->getBody();
        if (strlen($body) > self::MAX_BYTES) {
            throw new FetchException('Response body exceeds ' . self::MAX_BYTES . ' bytes.');
        }

        return new FetchResult($this->decodeObject($body), $this->maxAge($curl->getHeaders()));
    }

    /**
     * Resolve the host and return the address to pin, refusing any non-public answer.
     *
     * @param string $host
     * @return string
     * @throws FetchException
     */
    private function pinnableAddress(string $host): string
    {
        $addresses = $this->hostResolver->resolve($host);
        if ($addresses === []) {
            throw new FetchException('Host does not resolve.');
        }

        if (!$this->deploymentConfig->get(self::CONFIG_ALLOW_PRIVATE)) {
            foreach ($addresses as $address) {
                // Magento2 standard checks 8.1 compatibility; this module requires PHP >= 8.2 (composer.json).
                // phpcs:ignore PHPCompatibility.Constants.NewConstants.filter_flag_global_rangeFound
                if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false
                    || $this->isNat64($address)
                ) {
                    throw new FetchException('Host resolves to a non-public address.');
                }
            }
        }

        return $addresses[0];
    }

    /**
     * Whether the address is in the NAT64 well-known prefix 64:ff9b::/96 (RFC 6052).
     *
     * FILTER_FLAG_GLOBAL_RANGE accepts it, but a NAT64 gateway maps it onto any IPv4 address,
     * private ones included.
     *
     * @param string $address
     * @return bool
     */
    private function isNat64(string $address): bool
    {
        $packed = inet_pton($address);

        return $packed !== false && strlen($packed) === 16
            && substr($packed, 0, 12) === "\x00\x64\xff\x9b" . str_repeat("\x00", 8);
    }

    /**
     * Decode the body, which must be a JSON object.
     *
     * @param string $body
     * @return array<string,mixed>
     * @throws FetchException
     */
    private function decodeObject(string $body): array
    {
        try {
            $decoded = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new FetchException('Response is not valid JSON.', 0, $e);
        }
        if (!$decoded instanceof \stdClass) {
            throw new FetchException('Response is not a JSON object.');
        }

        /** @var array<string,mixed> $object */
        $object = json_decode($body, true, 64, JSON_THROW_ON_ERROR);

        return $object;
    }

    /**
     * Cache lifetime from Cache-Control, clamped to 60..3600 s; 300 s when absent.
     *
     * @param array<string,mixed> $headers
     * @return int
     */
    private function maxAge(array $headers): int
    {
        $cacheControl = null;
        foreach ($headers as $name => $value) {
            if (strtolower((string)$name) === 'cache-control' && is_string($value)) {
                $cacheControl = $value;
            }
        }
        if ($cacheControl === null) {
            return self::DEFAULT_MAX_AGE;
        }
        if (preg_match('/(?:^|[\s,])no-(?:store|cache)(?:$|[\s,=])/i', $cacheControl)) {
            return self::MIN_MAX_AGE;
        }
        if (!preg_match('/(?:^|[\s,])max-age\s*=\s*"?(\d+)/i', $cacheControl, $match)) {
            return self::DEFAULT_MAX_AGE;
        }

        return max(self::MIN_MAX_AGE, min(self::MAX_MAX_AGE, (int)$match[1]));
    }
}
