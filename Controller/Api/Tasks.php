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

namespace BroCode\Poppy\Controller\Api;

use BroCode\Poppy\Exception\GateException;
use BroCode\Poppy\Model\Session\RequestGate;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * GET {tasks.endpoint} (spec 9): the tasks this Company doesn't offer through the protocol, per Session.
 *
 * The Session check (switches, Bearer refusal, verification, record, error mapping) is RequestGate.
 * Lists the feedback endpoint (9.3) while feedback is switched on.
 */
class Tasks implements HttpGetActionInterface
{
    private const FEEDBACK_PATH = 'poppy/v1/feedback';

    /**
     * @param HttpRequest $request
     * @param JsonFactory $jsonFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param RequestGate $gate
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly HttpRequest $request,
        private readonly JsonFactory $jsonFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly RequestGate $gate,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Verify the Session, keep its record, and return the tasks document.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create()->setHeader('Cache-Control', 'private, no-store', true);

        try {
            $this->gate->open($this->request);
        } catch (GateException $e) {
            foreach ($e->getHeaders() as $name => $value) {
                $result->setHeader($name, $value, true);
            }

            return $result->setHttpResponseCode($e->getStatusCode())->setData($e->getBody());
        }

        $document = ['excluded_tasks' => []];
        if ($this->scopeConfig->isSetFlag(Feedback::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)) {
            /** @var Store $store */
            $store = $this->storeManager->getStore();
            // Link base URL, so a store code or base path stays in the URL the router and htu see.
            $document['feedback'] = [
                'endpoint' => $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, true) . self::FEEDBACK_PATH,
            ];
        }

        return $result->setData($document);
    }
}
