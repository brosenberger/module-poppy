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
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class CompanyIdentityTest extends TestCase
{
    /**
     * Subject whose current store has the given secure base URL.
     *
     * @param string $secureBaseUrl
     * @return CompanyIdentity
     */
    private function identity(string $secureBaseUrl): CompanyIdentity
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturnMap([
            [UrlInterface::URL_TYPE_WEB, true, $secureBaseUrl],
        ]);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new CompanyIdentity($storeManager);
    }

    public function testIdentifierHasNoTrailingSlashAndLowercaseHost(): void
    {
        $identity = $this->identity('https://Shop.Example.com/');

        self::assertSame('shop.example.com', $identity->domain());
        self::assertSame('https://shop.example.com', $identity->identifier());
    }

    public function testPortKeptWhenNot443(): void
    {
        self::assertSame('https://shop.test:8443', $this->identity('https://shop.test:8443/')->identifier());
        self::assertSame('https://shop.test', $this->identity('https://shop.test:443/')->identifier());
    }

    public function testNullWithoutHttps(): void
    {
        $identity = $this->identity('http://shop.example.com/');

        self::assertNull($identity->domain());
        self::assertNull($identity->identifier());
    }
}
