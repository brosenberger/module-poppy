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
use BroCode\Poppy\Model\Agent\ClientMetadataResolver;
use BroCode\Poppy\Model\Http\FetchResult;
use BroCode\Poppy\Model\Http\SafeFetcher;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
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

        return new ClientMetadataResolver($fetcher, $cache, new Json());
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
}
