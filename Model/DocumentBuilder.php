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

namespace BroCode\Poppy\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds the Personal Agent Protocol discovery document (poppy.json) for the current store.
 *
 * Website-only shape (spec 1.0, section 3.1): protocol_version, organization and web, no auth.
 * The UCP extension entry is added only when a UCP module serves the profile at /.well-known/ucp.
 */
class DocumentBuilder
{
    public const PROTOCOL_VERSION = '1.0';

    private const XML_PATH_ENABLED = 'brocode_poppy/general/enabled';
    private const XML_PATH_ORGANIZATION_NAME = 'brocode_poppy/general/organization_name';
    private const XML_PATH_LOGO_URI = 'brocode_poppy/general/logo_uri';
    private const XML_PATH_EXTENSION_UCP = 'brocode_poppy/extensions/ucp';
    private const XML_PATH_STORE_INFORMATION_NAME = 'general/store_information/name';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param ModuleManager $moduleManager
     * @param CompanyIdentity $companyIdentity
     * @param array<string,string> $ucpProfileProviders module name => config flag that switches its profile on
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly ModuleManager $moduleManager,
        private readonly CompanyIdentity $companyIdentity,
        private readonly array $ucpProfileProviders = []
    ) {
    }

    /**
     * Build the document for the current store.
     *
     * @return array<string, mixed>|null null when switched off or when the store has no HTTPS base URL
     */
    public function build(): ?array
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)) {
            return null;
        }

        $domain = $this->companyIdentity->domain();
        if ($domain === null) {
            return null;
        }

        /** @var Store $store */
        $store = $this->storeManager->getStore();

        $organization = ['name' => $this->organizationName($store), 'domain' => $domain];
        $logo = trim((string) $this->scopeConfig->getValue(self::XML_PATH_LOGO_URI, ScopeInterface::SCOPE_STORE));
        if (str_starts_with($logo, 'https://')) {
            $organization['logo_uri'] = $logo;
        }

        $document = [
            'protocol_version' => self::PROTOCOL_VERSION,
            'organization' => $organization,
            // Empty object: the website is open to Personal Agents, without a browser-session endpoint.
            'web' => new \stdClass(),
        ];

        if ($this->scopeConfig->isSetFlag(self::XML_PATH_EXTENSION_UCP, ScopeInterface::SCOPE_STORE)
            && $this->isUcpProfileServed()
        ) {
            $document['extensions'] = ['ucp' => [['version' => '1']]];
        }

        return $document;
    }

    /**
     * Whether a known UCP module is enabled and switched on for this store.
     *
     * Spec 3.1: list an extension only when it works, so the admin toggle alone is not enough.
     *
     * @return bool
     */
    private function isUcpProfileServed(): bool
    {
        foreach ($this->ucpProfileProviders as $module => $flagPath) {
            if ($this->moduleManager->isEnabled($module)
                && $this->scopeConfig->isSetFlag($flagPath, ScopeInterface::SCOPE_STORE)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Configured name, then the Store Information name, then the store's frontend name.
     *
     * @param Store $store
     * @return string
     */
    private function organizationName(Store $store): string
    {
        foreach ([self::XML_PATH_ORGANIZATION_NAME, self::XML_PATH_STORE_INFORMATION_NAME] as $path) {
            $name = trim((string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE));
            if ($name !== '') {
                return $name;
            }
        }

        return (string) $store->getFrontendName();
    }
}
