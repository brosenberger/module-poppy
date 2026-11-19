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

namespace BroCode\Poppy\Test\Unit\Model;

use BroCode\Poppy\Model\CompanyIdentity;
use BroCode\Poppy\Model\DocumentBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class DocumentBuilderTest extends TestCase
{
    /**
     * Build the subject with stubbed store config, base URL and enabled modules.
     *
     * @param array<string, string|null> $config
     * @param string $baseUrl
     * @param string $frontendName
     * @param string[] $enabledModules
     * @return DocumentBuilder
     */
    private function builder(
        array $config,
        string $baseUrl = 'https://Shop.Example.com/',
        string $frontendName = 'Main Store',
        array $enabledModules = []
    ): DocumentBuilder {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path, string $scope = ScopeInterface::SCOPE_STORE): ?string => $config[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path): bool => (bool) ($config[$path] ?? false)
        );

        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturnMap([
            [UrlInterface::URL_TYPE_WEB, true, $baseUrl],
            [UrlInterface::URL_TYPE_LINK, true, rtrim($baseUrl, '/') . '/de/'],
        ]);
        $store->method('getFrontendName')->willReturn($frontendName);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('isEnabled')->willReturnCallback(
            static fn (string $module): bool => in_array($module, $enabledModules, true)
        );

        return new DocumentBuilder(
            $scopeConfig,
            $storeManager,
            $moduleManager,
            new CompanyIdentity($storeManager),
            ['Angeo_Ucp' => 'angeo_ucp/general/enabled']
        );
    }

    public function testDisabledReturnsNull(): void
    {
        self::assertNull($this->builder(['brocode_poppy/general/enabled' => '0'])->build());
    }

    public function testWebsiteOnlyDocumentHasNoAuthAndLowercaseDomain(): void
    {
        $doc = $this->builder(['brocode_poppy/general/enabled' => '1'])->build();

        // `web` is an empty JSON object: the website is browsable, with no browser-session endpoint.
        self::assertEquals([
            'protocol_version' => '1.0',
            'organization' => ['name' => 'Main Store', 'domain' => 'shop.example.com'],
            'web' => new \stdClass(),
        ], $doc);
        self::assertArrayNotHasKey('auth', $doc);
        self::assertArrayNotHasKey('extensions', $doc);
    }

    public function testNonDefaultPortIsPartOfTheDomain(): void
    {
        $doc = $this->builder(['brocode_poppy/general/enabled' => '1'], 'https://shop.test:8443/')->build();

        self::assertSame('shop.test:8443', $doc['organization']['domain']);
    }

    public function testOrganizationNameFallsBackToStoreInformationThenFrontendName(): void
    {
        $fromOverride = $this->builder([
            'brocode_poppy/general/enabled' => '1',
            'brocode_poppy/general/organization_name' => 'ACME GmbH',
            'general/store_information/name' => 'ACME Store',
        ])->build();
        $fromStoreInfo = $this->builder([
            'brocode_poppy/general/enabled' => '1',
            'general/store_information/name' => 'ACME Store',
        ])->build();

        self::assertSame('ACME GmbH', $fromOverride['organization']['name']);
        self::assertSame('ACME Store', $fromStoreInfo['organization']['name']);
    }

    public function testLogoOnlyWhenHttps(): void
    {
        $https = $this->builder([
            'brocode_poppy/general/enabled' => '1',
            'brocode_poppy/general/logo_uri' => 'https://cdn.example.com/logo.svg',
        ])->build();
        $http = $this->builder([
            'brocode_poppy/general/enabled' => '1',
            'brocode_poppy/general/logo_uri' => 'http://cdn.example.com/logo.svg',
        ])->build();

        self::assertSame('https://cdn.example.com/logo.svg', $https['organization']['logo_uri']);
        self::assertArrayNotHasKey('logo_uri', $http['organization']);
    }

    public function testUcpExtensionListedWhenToggleOnAndProfileModuleServesIt(): void
    {
        $doc = $this->builder([
            'brocode_poppy/general/enabled' => '1',
            'brocode_poppy/extensions/ucp' => '1',
            'angeo_ucp/general/enabled' => '1',
        ], enabledModules: ['Angeo_Ucp'])->build();

        self::assertSame(['ucp' => [['version' => '1']]], $doc['extensions']);
    }

    public function testUcpExtensionNotListedWhenProfileModuleIsSwitchedOff(): void
    {
        $doc = $this->builder([
            'brocode_poppy/general/enabled' => '1',
            'brocode_poppy/extensions/ucp' => '1',
            'angeo_ucp/general/enabled' => '0',
        ], enabledModules: ['Angeo_Ucp'])->build();

        self::assertArrayNotHasKey('extensions', $doc);
    }

    public function testUcpExtensionNotListedWithoutAnyProfileModule(): void
    {
        $doc = $this->builder([
            'brocode_poppy/general/enabled' => '1',
            'brocode_poppy/extensions/ucp' => '1',
            'angeo_ucp/general/enabled' => '1',
        ])->build();

        self::assertArrayNotHasKey('extensions', $doc);
    }

    public function testUcpExtensionNotListedWhenToggleOff(): void
    {
        $doc = $this->builder([
            'brocode_poppy/general/enabled' => '1',
            'angeo_ucp/general/enabled' => '1',
        ], enabledModules: ['Angeo_Ucp'])->build();

        self::assertArrayNotHasKey('extensions', $doc);
    }

    public function testTasksEndpointListedOnlyWhenSessionsEnabled(): void
    {
        $on = $this->builder([
            'brocode_poppy/general/enabled' => '1',
            'brocode_poppy/session/enabled' => '1',
        ], 'https://shop.example.com/')->build();
        $off = $this->builder(['brocode_poppy/general/enabled' => '1'], 'https://shop.example.com/')->build();

        // Link base URL (store code in URL here), so the path matches what the router and htu see.
        self::assertSame(['endpoint' => 'https://shop.example.com/de/poppy/v1/tasks'], $on['tasks']);
        self::assertArrayNotHasKey('tasks', $off);
    }

    public function testHttpBaseUrlIsNotPublished(): void
    {
        // poppy.json must be served over HTTPS (spec section 3): no secure base URL, no valid domain.
        $doc = $this->builder(['brocode_poppy/general/enabled' => '1'], 'http://shop.example.com/')->build();

        self::assertNull($doc);
    }
}
