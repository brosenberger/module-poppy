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

namespace BroCode\Poppy\Test\Unit\Model\Agent;

use BroCode\Poppy\Exception\ClientMetadataException;
use BroCode\Poppy\Exception\FetchException;
use BroCode\Poppy\Exception\RateLimitException;
use BroCode\Poppy\Model\Agent\ClientMetadataResolver;
use BroCode\Poppy\Model\Http\FetchResult;
use BroCode\Poppy\Model\Http\SafeFetcher;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ClientMetadataResolverTest extends TestCase
{
    private const CLIENT_ID = 'https://agent.example/agent.json';
    private const JWKS_URI = 'https://agent.example/jwks.json';
    private const EC_KEY = ['kty' => 'EC', 'crv' => 'P-256', 'x' => 'xxxx', 'y' => 'yyyy', 'kid' => 'k1'];

    /**
     * @var array<string,FetchResult|FetchException> Response per URL
     */
    private array $responses = [];

    /**
     * @var string[] URLs fetched, in order
     */
    private array $fetched = [];

    /**
     * @var array<string,string> In-memory cache contents
     */
    private array $cacheData = [];

    /**
     * @var array<string,int|null> Lifetime passed per saved cache key
     */
    private array $cacheLifetimes = [];

    /**
     * @var int brocode_poppy/session/max_fetches_per_minute
     */
    private int $maxFetches = 30;

    /**
     * @var int Current store ID
     */
    private int $storeId = 1;

    /**
     * @var int Clock, UTC timestamp
     */
    private int $now = 1790899990; // 10 s into a minute

    /**
     * Subject with a fake fetcher (answers from $responses) and an in-memory cache.
     *
     * @return ClientMetadataResolver
     */
    private function resolver(): ClientMetadataResolver
    {
        /** @var SafeFetcher&MockObject $fetcher */
        $fetcher = $this->createMock(SafeFetcher::class);
        $fetcher->method('fetchJson')->willReturnCallback(function (string $url): FetchResult {
            $this->fetched[] = $url;
            $response = $this->responses[$url] ?? new FetchException('Unexpected status 404.');
            if ($response instanceof FetchException) {
                throw $response;
            }

            return $response;
        });

        /** @var CacheInterface&MockObject $cache */
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $key) => $this->cacheData[$key] ?? false);
        $cache->method('save')->willReturnCallback(
            function (string $data, string $key, array $tags = [], $lifetime = null): bool {
                $this->cacheData[$key] = $data;
                $this->cacheLifetimes[$key] = $lifetime;

                return true;
            }
        );

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            fn (string $path) => $path === 'brocode_poppy/session/max_fetches_per_minute'
                ? (string)$this->maxFetches
                : null
        );
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(function () {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getId')->willReturn($this->storeId);

            return $store;
        });
        $clock = $this->createMock(DateTime::class);
        $clock->method('gmtTimestamp')->willReturnCallback(fn () => $this->now);

        return new ClientMetadataResolver($fetcher, $cache, new Json(), $scopeConfig, $storeManager, $clock);
    }

    /**
     * Register another valid agent on its own host.
     *
     * @param string $host
     * @return string Its client_id
     */
    private function otherAgent(string $host): string
    {
        $clientId = 'https://' . $host . '/agent.json';
        $this->responses[$clientId] = new FetchResult([
            'client_id' => $clientId,
            'jwks_uri' => 'https://' . $host . '/jwks.json',
            'token_endpoint_auth_method' => 'private_key_jwt',
        ], 300);
        $this->responses['https://' . $host . '/jwks.json'] = new FetchResult(['keys' => [self::EC_KEY]], 300);

        return $clientId;
    }

    /**
     * Register a valid agent: metadata (max-age 300) and key set (max-age 120), with overrides.
     *
     * @param array<string,mixed> $metadataOverrides
     * @param array<int,mixed>|null $keys
     * @return void
     */
    private function agent(array $metadataOverrides = [], ?array $keys = null): void
    {
        $metadata = array_merge([
            'client_id' => self::CLIENT_ID,
            'client_name' => 'Example Agent',
            'jwks_uri' => self::JWKS_URI,
            'redirect_uris' => ['https://agent.example/callback'],
            'token_endpoint_auth_method' => 'private_key_jwt',
        ], $metadataOverrides);
        $this->responses[self::CLIENT_ID] = new FetchResult($metadata, 300);
        $this->responses[self::JWKS_URI] = new FetchResult(['keys' => $keys ?? [self::EC_KEY]], 120);
    }

    /**
     * @return string
     */
    private function cacheKey(): string
    {
        return 'brocode_poppy_cimd_' . hash('sha256', self::CLIENT_ID);
    }

    public function testResolvesValidAgent(): void
    {
        $this->agent();

        $metadata = $this->resolver()->resolve(self::CLIENT_ID);

        $this->assertSame(self::CLIENT_ID, $metadata->clientId);
        $this->assertSame(['k1' => self::EC_KEY], $metadata->keysByKid);
        $this->assertSame(120, $metadata->maxAge);
    }

    public function testRejectsClientIdMismatch(): void
    {
        $this->agent(['client_id' => 'https://agent.example/other.json']);

        $this->expectException(ClientMetadataException::class);
        $this->resolver()->resolve(self::CLIENT_ID);
    }

    public function testRejectsClientIdThatDiffersOnlyByTrailingSlash(): void
    {
        $this->agent(['client_id' => self::CLIENT_ID . '/']);

        $this->expectException(ClientMetadataException::class);
        $this->resolver()->resolve(self::CLIENT_ID);
    }

    public function testRejectsJwksOnOtherHost(): void
    {
        $this->agent(['jwks_uri' => 'https://keys.evil.example/jwks.json']);
        $this->responses['https://keys.evil.example/jwks.json'] = new FetchResult(['keys' => [self::EC_KEY]], 300);

        $this->expectException(ClientMetadataException::class);
        try {
            $this->resolver()->resolve(self::CLIENT_ID);
        } finally {
            $this->assertSame([self::CLIENT_ID], $this->fetched);
        }
    }

    public function testRejectsHttpJwksUri(): void
    {
        $this->agent(['jwks_uri' => 'http://agent.example/jwks.json']);

        $this->expectException(ClientMetadataException::class);
        $this->resolver()->resolve(self::CLIENT_ID);
    }

    public function testRejectsWrongAuthMethod(): void
    {
        $this->agent(['token_endpoint_auth_method' => 'client_secret_basic']);

        $this->expectException(ClientMetadataException::class);
        $this->resolver()->resolve(self::CLIENT_ID);
    }

    public function testRejectsHttpClientId(): void
    {
        $this->agent();

        $this->expectException(ClientMetadataException::class);
        try {
            $this->resolver()->resolve('http://agent.example/agent.json');
        } finally {
            $this->assertSame([], $this->fetched);
        }
    }

    public function testRejectsClientIdWithFragment(): void
    {
        $this->expectException(ClientMetadataException::class);
        try {
            $this->resolver()->resolve(self::CLIENT_ID . '#x');
        } finally {
            $this->assertSame([], $this->fetched);
        }
    }

    public function testFetchFailureBecomesClientMetadataException(): void
    {
        $this->agent();
        $this->responses[self::JWKS_URI] = new FetchException('Host resolves to a non-public address.');

        $this->expectException(ClientMetadataException::class);
        $this->resolver()->resolve(self::CLIENT_ID);
    }

    public function testRejectsKeySetWithoutUsableKeys(): void
    {
        $this->agent([], [['kty' => 'EC', 'crv' => 'P-256', 'x' => 'xxxx', 'y' => 'yyyy']]);

        $this->expectException(ClientMetadataException::class);
        $this->resolver()->resolve(self::CLIENT_ID);
    }

    public function testCachesWithinMaxAge(): void
    {
        $this->agent();
        $resolver = $this->resolver();

        $first = $resolver->resolve(self::CLIENT_ID);
        $second = $resolver->resolve(self::CLIENT_ID);

        $this->assertSame([self::CLIENT_ID, self::JWKS_URI], $this->fetched);
        $this->assertSame(120, $this->cacheLifetimes[$this->cacheKey()]);
        $this->assertSame($first->keysByKid, $second->keysByKid);
        $this->assertSame(self::CLIENT_ID, $second->clientId);
    }

    public function testFailureIsNegativelyCached(): void
    {
        $this->agent(['token_endpoint_auth_method' => 'client_secret_basic']);
        $resolver = $this->resolver();

        $failures = 0;
        for ($i = 0; $i < 2; $i++) {
            try {
                $resolver->resolve(self::CLIENT_ID);
            } catch (ClientMetadataException $e) {
                $failures++;
            }
        }

        $this->assertSame(2, $failures);
        $this->assertSame([self::CLIENT_ID], $this->fetched);
        $this->assertSame(60, $this->cacheLifetimes[$this->cacheKey()]);
    }

    public function testKeysIndexedByKidWithoutPrivateMembers(): void
    {
        $this->agent([], [
            self::EC_KEY + ['d' => 'secret'],
            ['kty' => 'RSA', 'n' => 'nnnn', 'e' => 'AQAB', 'kid' => 'r1', 'alg' => 'PS384', 'use' => 'sig',
                'd' => 's', 'p' => 's', 'q' => 's', 'dp' => 's', 'dq' => 's', 'qi' => 's'],
            ['kty' => 'oct', 'k' => 'secret', 'kid' => 'o1'],
            ['kty' => 'EC', 'crv' => 'P-256', 'x' => 'no', 'y' => 'kid'],
            'not-a-key',
        ]);

        $metadata = $this->resolver()->resolve(self::CLIENT_ID);

        $this->assertSame([
            'k1' => self::EC_KEY,
            'r1' => ['kty' => 'RSA', 'n' => 'nnnn', 'e' => 'AQAB', 'kid' => 'r1', 'alg' => 'PS384', 'use' => 'sig'],
            'o1' => ['kty' => 'oct', 'kid' => 'o1'],
        ], $metadata->keysByKid);
    }

    public function testFailureIsNegativelyCachedPerHost(): void
    {
        // F3: a per-URL negative cache is defeated by random paths on the same host.
        $resolver = $this->resolver();
        try {
            $resolver->resolve('https://agent.example/random-1.json');
            $this->fail('Expected ClientMetadataException for a 404');
        } catch (ClientMetadataException $e) {
            $this->assertSame(['https://agent.example/random-1.json'], $this->fetched);
        }

        $this->agent();
        try {
            $resolver->resolve(self::CLIENT_ID);
            $this->fail('Expected the host to be negatively cached');
        } catch (ClientMetadataException $e) {
            $this->assertSame(['https://agent.example/random-1.json'], $this->fetched);
        }
        $this->assertSame(60, $this->cacheLifetimes['brocode_poppy_cimd_host_' . hash('sha256', 'agent.example')]);
    }

    public function testFetchesOverTheMinuteCapAreRateLimited(): void
    {
        $this->maxFetches = 3;
        $this->agent();
        $resolver = $this->resolver();
        $resolver->resolve(self::CLIENT_ID);
        $other = $this->otherAgent('other.example');

        try {
            $resolver->resolve($other);
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            // The clock is 10 s into the minute, so the window ends in 50 s.
            $this->assertSame(50, $e->getRetryAfter());
        }
        $this->assertSame([self::CLIENT_ID, self::JWKS_URI, $other], $this->fetched);
    }

    public function testCacheHitsUseNoFetchBudget(): void
    {
        $this->maxFetches = 2;
        $this->agent();
        $resolver = $this->resolver();

        $resolver->resolve(self::CLIENT_ID);
        $resolver->resolve(self::CLIENT_ID);
        $resolver->resolve(self::CLIENT_ID);

        $this->assertCount(2, $this->fetched);
    }

    public function testFetchBudgetIsPerStoreAndPerMinute(): void
    {
        $this->maxFetches = 2;
        $this->agent();
        $resolver = $this->resolver();
        $resolver->resolve(self::CLIENT_ID);

        $this->storeId = 2;
        $resolver->resolve($this->otherAgent('two.example'));

        $this->storeId = 1;
        $this->now += 60;
        $resolver->resolve($this->otherAgent('three.example'));

        $this->assertCount(6, $this->fetched);
    }

    public function testRefreshRefetchesOnceAMinute(): void
    {
        // F10: an unknown delegation kid may mean the agent rotated its keys.
        $this->agent();
        $resolver = $this->resolver();
        $resolver->resolve(self::CLIENT_ID);

        $rotated = ['kty' => 'EC', 'crv' => 'P-256', 'x' => 'x2', 'y' => 'y2', 'kid' => 'k2'];
        $this->responses[self::JWKS_URI] = new FetchResult(['keys' => [$rotated]], 300);

        $this->assertSame(['k2' => $rotated], $resolver->resolve(self::CLIENT_ID, true)->keysByKid);
        $this->assertSame(['k2' => $rotated], $resolver->resolve(self::CLIENT_ID, true)->keysByKid);
        $this->assertSame([self::CLIENT_ID, self::JWKS_URI, self::CLIENT_ID, self::JWKS_URI], $this->fetched);
    }

    public function testRefreshIsSubjectToTheFetchCap(): void
    {
        $this->maxFetches = 3;
        $this->agent();
        $resolver = $this->resolver();
        $resolver->resolve(self::CLIENT_ID);

        $this->expectException(RateLimitException::class);
        $resolver->resolve(self::CLIENT_ID, true);
    }
}
