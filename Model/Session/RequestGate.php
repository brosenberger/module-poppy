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

namespace BroCode\Poppy\Model\Session;

use BroCode\Poppy\Exception\GateException;
use BroCode\Poppy\Exception\RateLimitException;
use BroCode\Poppy\Exception\SessionException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * The entry check of every Session resource (spec 5.3): switches, Bearer refusal, Session verification and record.
 *
 * Resources are served only while both poppy.json and Sessions are switched on. This Company issues no access
 * tokens, so any Bearer token is refused as invalid. Any unexpected error is answered as invalid_session, never
 * as a 500 with an error report. Every refusal is a GateException carrying the response to send.
 */
class RequestGate
{
    private const XML_PATH_ENABLED = 'brocode_poppy/general/enabled';
    private const XML_PATH_SESSION_ENABLED = 'brocode_poppy/session/enabled';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param SessionVerifier $sessionVerifier
     * @param SessionRepository $sessionRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SessionVerifier $sessionVerifier,
        private readonly SessionRepository $sessionRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Verify the request's Session and keep its record.
     *
     * @param HttpRequest $request
     * @return VerifiedSession
     * @throws GateException with the response for every refusal
     */
    public function open(HttpRequest $request): VerifiedSession
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)
            || !$this->scopeConfig->isSetFlag(self::XML_PATH_SESSION_ENABLED, ScopeInterface::SCOPE_STORE)
        ) {
            throw new GateException(404, ['error' => 'not_found']);
        }

        if (preg_match('/^\s*Bearer\s/i', (string) $request->getHeader('Authorization'))) {
            throw new GateException(
                401,
                ['error' => 'invalid_token'],
                ['WWW-Authenticate' => 'Bearer error="invalid_token"']
            );
        }

        try {
            $session = $this->sessionVerifier->verify($request);
            try {
                $this->sessionRepository->touch($session);
            } catch (SessionException $e) {
                $this->logger->debug('Poppy Session refused.', [
                    'client_id' => $session->clientId,
                    'error' => $e->getErrorCode(),
                ]);
                throw $e;
            }
        } catch (SessionException $e) {
            throw new GateException(400, ['error' => $e->getErrorCode()]);
        } catch (RateLimitException $e) {
            // Spec 5.6: 429 with Retry-After, no custom body.
            throw new GateException(429, [], ['Retry-After' => (string)$e->getRetryAfter()]);
        } catch (\Throwable $e) {
            // Last resort: the class only, never the message, which may echo request data.
            $this->logger->warning('Poppy Session verification failed unexpectedly.', [
                'exception' => get_class($e),
            ]);

            throw new GateException(400, ['error' => SessionException::INVALID_SESSION]);
        }

        return $session;
    }
}
