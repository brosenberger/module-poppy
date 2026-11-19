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

namespace BroCode\Poppy\Controller\WellKnown;

use BroCode\Poppy\Model\DocumentBuilder;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Serves poppy.json. The document is the same for every visitor, so it is publicly cacheable.
 */
class Poppy implements HttpGetActionInterface
{
    private const MAX_AGE = 300;

    /**
     * @param JsonFactory $jsonFactory
     * @param DocumentBuilder $documentBuilder
     */
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly DocumentBuilder $documentBuilder
    ) {
    }

    /**
     * Return the document, or 404 when nothing is published for this store.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $document = $this->documentBuilder->build();

        if ($document === null) {
            return $result->setHttpResponseCode(404)
                ->setHeader('Cache-Control', 'no-store', true)
                ->setData(['error' => 'not_found']);
        }

        return $result->setHeader('Cache-Control', 'public, max-age=' . self::MAX_AGE, true)
            ->setData($document);
    }
}
