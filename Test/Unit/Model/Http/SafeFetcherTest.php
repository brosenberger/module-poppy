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

namespace BroCode\Poppy\Test\Unit\Model\Http;

use BroCode\Poppy\Exception\FetchException;
use BroCode\Poppy\Model\Http\HostResolver;
use BroCode\Poppy\Model\Http\SafeFetcher;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SafeFetcherTest extends TestCase
{
    /**
     * @var array<int,mixed> Curl options the subject set on the client
     */
    private array $options = [];

    /**
     * @var string|null URL passed to Curl::get(), null when no request was made
     */
    private ?string $requestedUrl = null;

    /**
     * @var array<string,string> Request headers the subject added
     */
    private array $sentHeaders = [];

    /**
     * Build the subject with a mocked resolver, Curl client and deployment config.
     *
     * @param string[] $addresses What the host resolves to
     * @param bool $allowPrivate Value of brocode_poppy/allow_private_addresses
     * @param int $status Response status
     * @param string $body Response body
     * @param array<string,string> $headers Response headers
     * @return SafeFetcher
     */
    private function fetcher(
        array $addresses,
        bool $allowPrivate = false,
        int $status = 200,
        string $body = '{"client_id":"https://agent.example/"}',
        array $headers = []
    ): SafeFetcher {
        $this->options = [];
        $this->requestedUrl = null;
        $this->sentHeaders = [];

        /** @var Curl&MockObject $curl */
        $curl = $this->createMock(Curl::class);
        $curl->method('setOption')->willReturnCallback(function ($name, $value): void {
            $this->options[$name] = $value;
        });
        $curl->method('setOptions')->willReturnCallback(function (array $options): void {
            $this->options = $options + $this->options;
        });
        $curl->method('addHeader')->willReturnCallback(function (string $name, string $value): void {
            $this->sentHeaders[$name] = $value;
        });
        $curl->method('get')->willReturnCallback(function (string $url): void {
            $this->requestedUrl = $url;
        });
        $curl->method('getStatus')->willReturn($status);
        $curl->method('getBody')->willReturn($body);
        $curl->method('getHeaders')->willReturn($headers);

        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($curl);

        $resolver = $this->createMock(HostResolver::class);
        $resolver->method('resolve')->willReturn($addresses);

        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnMap([
            ['brocode_poppy/allow_private_addresses', null, $allowPrivate ? '1' : null],
        ]);

        return new SafeFetcher($curlFactory, $resolver, $deploymentConfig);
    }

    /**
     * Assert the fetch is refused before any request goes out.
     *
     * @param SafeFetcher $fetcher
     * @param string $url
     * @return void
     */
    private function assertRefused(SafeFetcher $fetcher, string $url): void
    {
        try {
            $fetcher->fetchJson($url);
            $this->fail('Expected FetchException for ' . $url);
        } catch (FetchException $e) {
            $this->assertNull($this->requestedUrl, 'No request may be sent for ' . $url);
        }
    }

    public function testRejectsHttp(): void
    {
        $this->assertRefused($this->fetcher(['93.184.215.14']), 'http://agent.example/metadata.json');
    }

    public function testRejectsLoopback(): void
    {
        $this->assertRefused($this->fetcher(['127.0.0.1']), 'https://agent.example/metadata.json');
        $this->assertRefused($this->fetcher(['::1']), 'https://agent.example/metadata.json');
    }

    public function testRejectsPrivateAndLinkLocal(): void
    {
        foreach (['10.0.0.1', '192.168.1.1', '169.254.169.254', 'fd00::1'] as $address) {
            $this->assertRefused($this->fetcher([$address]), 'https://agent.example/metadata.json');
        }
    }

    public function testRejectsNat64Prefix(): void
    {
        // 64:ff9b::/96 passes FILTER_FLAG_GLOBAL_RANGE but maps onto IPv4 (here 127.0.0.1) through a NAT64 gateway.
        $this->assertRefused($this->fetcher(['64:ff9b::7f00:1']), 'https://agent.example/metadata.json');
        $this->assertRefused($this->fetcher(['64:ff9b::a00:1']), 'https://agent.example/metadata.json');
    }

    public function testSendsUserAgentAndAccept(): void
    {
        $this->fetcher(['93.184.215.14'])->fetchJson('https://agent.example/metadata.json');

        $this->assertSame('BroCode-Poppy/0.1 (+https://brocode.at)', $this->sentHeaders['User-Agent'] ?? null);
        $this->assertSame('application/json', $this->sentHeaders['Accept'] ?? null);
    }

    public function testRejectsWhenAnyResolvedAddressIsPrivate(): void
    {
        $this->assertRefused(
            $this->fetcher(['93.184.215.14', '10.0.0.1']),
            'https://agent.example/metadata.json'
        );
    }

    public function testRejectsUnresolvableHost(): void
    {
        $this->assertRefused($this->fetcher([]), 'https://agent.example/metadata.json');
    }

    public function testAllowsPrivateWhenDeploymentConfigSaysSo(): void
    {
        $result = $this->fetcher(['10.0.0.1'], true)->fetchJson('https://agent.example/metadata.json');

        $this->assertSame(['client_id' => 'https://agent.example/'], $result->body);
        $this->assertSame('https://agent.example/metadata.json', $this->requestedUrl);
        $this->assertSame(['agent.example:443:10.0.0.1'], $this->options[CURLOPT_RESOLVE]);
    }

    public function testPinsResolvedAddress(): void
    {
        $this->fetcher(['93.184.215.14', '93.184.215.15'])->fetchJson('https://agent.example/metadata.json');
        $this->assertSame(['agent.example:443:93.184.215.14'], $this->options[CURLOPT_RESOLVE]);

        $this->fetcher(['2606:2800:21f:cb07:6820:80da:af6b:8b2c'])
            ->fetchJson('https://agent.example:8443/metadata.json');
        $this->assertSame(
            ['agent.example:8443:[2606:2800:21f:cb07:6820:80da:af6b:8b2c]'],
            $this->options[CURLOPT_RESOLVE]
        );
    }

    public function testDoesNotFollowRedirects(): void
    {
        $fetcher = $this->fetcher(
            ['93.184.215.14'],
            false,
            302,
            '',
            ['Location' => 'https://169.254.169.254/latest/meta-data/']
        );

        try {
            $fetcher->fetchJson('https://agent.example/metadata.json');
            $this->fail('Expected FetchException for a 302');
        } catch (FetchException $e) {
            $this->assertFalse($this->options[CURLOPT_FOLLOWLOCATION]);
            $this->assertSame(CURLPROTO_HTTPS, $this->options[CURLOPT_PROTOCOLS]);
            $this->assertSame(CURLPROTO_HTTPS, $this->options[CURLOPT_REDIR_PROTOCOLS]);
        }
    }

    public function testRejectsBodyOver64Kb(): void
    {
        $body = '{"pad":"' . str_repeat('a', 65530) . '"}';
        $this->assertSame(65540, strlen($body));

        $fetcher = $this->fetcher(['93.184.215.14'], false, 200, $body);

        try {
            $fetcher->fetchJson('https://agent.example/metadata.json');
            $this->fail('Expected FetchException for a body over 64 KB');
        } catch (FetchException $e) {
            $this->assertSame(65536, $this->options[CURLOPT_MAXFILESIZE]);
        }
    }

    public function testRejectsNonObjectJson(): void
    {
        foreach (['[1,2]', '"text"', 'not json', ''] as $body) {
            $fetcher = $this->fetcher(['93.184.215.14'], false, 200, $body);
            try {
                $fetcher->fetchJson('https://agent.example/metadata.json');
                $this->fail('Expected FetchException for body ' . $body);
            } catch (FetchException $e) {
                $this->assertSame('https://agent.example/metadata.json', $this->requestedUrl);
            }
        }
    }

    public function testAcceptsEmptyObject(): void
    {
        $result = $this->fetcher(['93.184.215.14'], false, 200, '{}')
            ->fetchJson('https://agent.example/metadata.json');

        $this->assertSame([], $result->body);
    }

    public function testMaxAgeClampedTo60And3600(): void
    {
        $url = 'https://agent.example/metadata.json';
        $public = ['93.184.215.14'];

        $this->assertSame(
            60,
            $this->fetcher($public, false, 200, '{}', ['Cache-Control' => 'max-age=5'])->fetchJson($url)->maxAge
        );
        $this->assertSame(
            3600,
            $this->fetcher($public, false, 200, '{}', ['cache-control' => 'public, max-age=86400'])
                ->fetchJson($url)->maxAge
        );
        $this->assertSame(300, $this->fetcher($public)->fetchJson($url)->maxAge);
        $this->assertSame(
            300,
            $this->fetcher($public, false, 200, '{}', ['Cache-Control' => 'public'])->fetchJson($url)->maxAge
        );
        $this->assertSame(
            900,
            $this->fetcher($public, false, 200, '{}', ['CACHE-CONTROL' => 'Max-Age=900'])->fetchJson($url)->maxAge
        );
        $this->assertSame(
            60,
            $this->fetcher($public, false, 200, '{}', ['Cache-Control' => 'no-store, max-age=900'])
                ->fetchJson($url)->maxAge
        );
    }
}
