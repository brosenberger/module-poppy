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

use Laminas\Uri\Http as HttpUri;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The Company's identity for the current store, from its secure base URL.
 *
 * Without an OAuth issuer the Company identifier is https://{organization.domain}: lowercase host,
 * port only when it is not 443, no trailing slash (spec 1.0, sections 3.1 and 5.3). It is the
 * organization.domain in poppy.json and the expected `aud` of every Session JWT.
 */
class CompanyIdentity
{
    /**
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Host of the secure base URL, lowercase, with the port when it is not 443.
     *
     * @return string|null null when the store has no https base URL
     */
    public function domain(): ?string
    {
        /** @var Store $store */
        $store = $this->storeManager->getStore();
        $uri = new HttpUri((string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB, true));
        $host = (string) $uri->getHost();
        if ($uri->getScheme() !== 'https' || $host === '') {
            return null;
        }

        $domain = strtolower($host);
        $port = (int) $uri->getPort();
        if ($port !== 0 && $port !== 443) {
            $domain .= ':' . $port;
        }

        return $domain;
    }

    /**
     * The Company identifier: https://{organization.domain}.
     *
     * @return string|null null when the store has no https base URL
     */
    public function identifier(): ?string
    {
        $domain = $this->domain();

        return $domain === null ? null : 'https://' . $domain;
    }
}
