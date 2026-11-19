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

use BroCode\Poppy\Exception\SessionException;
use BroCode\Poppy\Model\Session\SessionRepository;
use BroCode\Poppy\Model\Session\SessionVerifier;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\ScopeInterface;

/**
 * GET {tasks.endpoint} (spec 9): the tasks this Company doesn't offer through the protocol, per Session.
 *
 * The first Poppy resource that uses Session identity, so it verifies the Session header (5.3) and keeps
 * the Session record. This Company issues no access tokens, so any Bearer token is refused as invalid.
 */
class Tasks implements HttpGetActionInterface
{
    private const XML_PATH_SESSION_ENABLED = 'brocode_poppy/session/enabled';

    /**
     * @param HttpRequest $request
     * @param JsonFactory $jsonFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param SessionVerifier $sessionVerifier
     * @param SessionRepository $sessionRepository
     */
    public function __construct(
        private readonly HttpRequest $request,
        private readonly JsonFactory $jsonFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SessionVerifier $sessionVerifier,
        private readonly SessionRepository $sessionRepository
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

        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_SESSION_ENABLED, ScopeInterface::SCOPE_STORE)) {
            return $result->setHttpResponseCode(404)->setData(['error' => 'not_found']);
        }

        if (preg_match('/^\s*Bearer\s/i', (string) $this->request->getHeader('Authorization'))) {
            return $result->setHttpResponseCode(401)
                ->setHeader('WWW-Authenticate', 'Bearer error="invalid_token"', true)
                ->setData(['error' => 'invalid_token']);
        }

        try {
            $this->sessionRepository->touch($this->sessionVerifier->verify($this->request));
        } catch (SessionException $e) {
            return $result->setHttpResponseCode(400)->setData(['error' => $e->getErrorCode()]);
        }

        return $result->setData(['excluded_tasks' => []]);
    }
}
