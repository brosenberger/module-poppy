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
use BroCode\Poppy\Exception\InvalidReportException;
use BroCode\Poppy\Model\Feedback\FeedbackRepository;
use BroCode\Poppy\Model\Feedback\ReportParser;
use BroCode\Poppy\Model\Session\RequestGate;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * POST {feedback.endpoint} (spec 9.3): a Personal Agent reports a task it could not do in this shop.
 *
 * Served only while feedback is switched on (brocode_poppy/feedback/enabled) and the Session gate lets the
 * request through, so every report is attributed to a verified agent. Reports are capped per hour per agent and
 * per store view; Retry-After is the time until the oldest report in the full window leaves it.
 * Accepted reports get 202 with no body. The report text is untrusted data: it is stored, never logged.
 * The Session header (htm/htu signed JWT) is the request's proof, so there is no form key to validate.
 */
class Feedback implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public const XML_PATH_ENABLED = 'brocode_poppy/feedback/enabled';
    public const XML_PATH_MAX_REPORTS = 'brocode_poppy/feedback/max_reports_per_hour';
    public const XML_PATH_MAX_REPORTS_TOTAL = 'brocode_poppy/feedback/max_reports_total_per_hour';
    private const DEFAULT_MAX_REPORTS = 50;
    private const DEFAULT_MAX_REPORTS_TOTAL = 500;
    private const WINDOW_SECONDS = 3600;

    /**
     * @param HttpRequest $request
     * @param RawFactory $rawFactory
     * @param JsonFactory $jsonFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param RequestGate $gate
     * @param ReportParser $parser
     * @param FeedbackRepository $repository
     * @param StoreManagerInterface $storeManager
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly HttpRequest $request,
        private readonly RawFactory $rawFactory,
        private readonly JsonFactory $jsonFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly RequestGate $gate,
        private readonly ReportParser $parser,
        private readonly FeedbackRepository $repository,
        private readonly StoreManagerInterface $storeManager,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Verify the Session, validate the report and store it.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)) {
            return $this->json(404, ['error' => 'not_found']);
        }

        try {
            $session = $this->gate->open($this->request);
        } catch (GateException $e) {
            return $this->json($e->getStatusCode(), $e->getBody(), $e->getHeaders());
        }

        try {
            $report = $this->parser->parse((string)$this->request->getContent());
        } catch (InvalidReportException $e) {
            $this->logger->debug('Poppy feedback refused.', [
                'client_id' => $session->clientId,
                'error' => 'invalid_request',
            ]);

            return $this->json(400, ['error' => 'invalid_request']);
        }

        try {
            $storeId = (int)$this->storeManager->getStore()->getId();
            $retryAfter = $this->retryAfter($session->clientId, $storeId);
            if ($retryAfter !== null) {
                $this->logger->debug('Poppy feedback refused.', [
                    'client_id' => $session->clientId,
                    'error' => 'rate_limited',
                ]);

                // Spec 5.6: 429 with Retry-After, no custom body.
                return $this->json(429, [], ['Retry-After' => (string)$retryAfter]);
            }
            $this->repository->save($report, $session, $storeId);
        } catch (\Throwable $e) {
            // The class only: a database message may quote the report text.
            $this->logger->warning('Poppy feedback could not be stored.', ['exception' => get_class($e)]);

            return $this->json(500, []);
        }

        $this->logger->debug('Poppy feedback accepted.', ['client_id' => $session->clientId]);

        return $this->rawFactory->create()
            ->setHttpResponseCode(202)
            ->setHeader('Cache-Control', 'no-store', true)
            ->setContents('');
    }

    /**
     * Seconds until a report is accepted again, or null while the agent and the store are below their caps.
     *
     * The count and the insert are not atomic: parallel requests can pass a cap by a few reports.
     *
     * @param string $clientId
     * @param int $storeId
     * @return int|null
     */
    private function retryAfter(string $clientId, int $storeId): ?int
    {
        $now = (int)$this->dateTime->gmtTimestamp();
        $since = $this->dateTime->gmtDate('Y-m-d H:i:s', $now - self::WINDOW_SECONDS);

        $window = $this->repository->clientWindow($clientId, $since);
        if ($window['count'] >= $this->cap(self::XML_PATH_MAX_REPORTS, self::DEFAULT_MAX_REPORTS)) {
            return $this->secondsUntilFree($window['oldest'], $now);
        }
        $window = $this->repository->storeWindow($storeId, $since);
        if ($window['count'] >= $this->cap(self::XML_PATH_MAX_REPORTS_TOTAL, self::DEFAULT_MAX_REPORTS_TOTAL)) {
            return $this->secondsUntilFree($window['oldest'], $now);
        }

        return null;
    }

    /**
     * A configured cap, or the default when unset or not positive.
     *
     * @param string $path
     * @param int $default
     * @return int
     */
    private function cap(string $path, int $default): int
    {
        $max = (int)$this->scopeConfig->getValue($path);

        return $max > 0 ? $max : $default;
    }

    /**
     * Seconds until the oldest report in the window is an hour old, at least 1.
     *
     * @param string|null $oldest UTC datetime of the oldest report in the window
     * @param int $now Unix time
     * @return int
     */
    private function secondsUntilFree(?string $oldest, int $now): int
    {
        if ($oldest === null) {
            return self::WINDOW_SECONDS;
        }
        $oldestTs = strtotime($oldest . ' UTC');
        if ($oldestTs === false) {
            return self::WINDOW_SECONDS;
        }

        return max(1, $oldestTs + self::WINDOW_SECONDS - $now);
    }

    /**
     * A JSON response that is never cached.
     *
     * @param int $status
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     * @return Json
     */
    private function json(int $status, array $body, array $headers = []): Json
    {
        $result = $this->jsonFactory->create()->setHeader('Cache-Control', 'no-store', true);
        foreach ($headers as $name => $value) {
            $result->setHeader($name, $value, true);
        }

        return $result->setHttpResponseCode($status)->setData($body);
    }

    /**
     * No form key: the signed Session header is the proof of this request.
     *
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * No form key: the signed Session header is the proof of this request.
     *
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
