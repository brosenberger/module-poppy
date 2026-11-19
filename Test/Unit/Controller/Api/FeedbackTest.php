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

namespace BroCode\Poppy\Test\Unit\Controller\Api;

use BroCode\Poppy\Controller\Api\Feedback;
use BroCode\Poppy\Exception\GateException;
use BroCode\Poppy\Model\Feedback\FeedbackRepository;
use BroCode\Poppy\Model\Feedback\Report;
use BroCode\Poppy\Model\Feedback\ReportParser;
use BroCode\Poppy\Model\Session\RequestGate;
use BroCode\Poppy\Model\Session\VerifiedSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FeedbackTest extends TestCase
{
    private const CLIENT_ID = 'https://agent.example/client';
    private const NOW_TS = 1795082400; // 2026-11-19 10:00:00 UTC
    private const VALID = '{"task":"Return an order","description":"No returns endpoint."}';

    /** @var int|null Status set on the result */
    private ?int $status = null;

    /** @var array<string,string> Headers set on the result */
    private array $headers = [];

    /** @var mixed JSON data set on the result */
    private mixed $data = null;

    /** @var string|null Raw contents set on the result */
    private ?string $contents = null;

    /** @var bool Whether the gate was opened */
    private bool $opened = false;

    /** @var FeedbackRepository&MockObject */
    private $repository;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /**
     * Controller with feedback on unless overridden.
     *
     * @param string $body
     * @param GateException|null $refusal What the gate throws, null to let the request through
     * @param array<string,mixed> $config
     * @return Feedback
     */
    private function controller(string $body, ?GateException $refusal = null, array $config = []): Feedback
    {
        $config += ['brocode_poppy/feedback/enabled' => true];

        $json = $this->createMock(Json::class);
        $this->record($json);
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->data = $data;

            return $json;
        });
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $raw = $this->createMock(Raw::class);
        $this->record($raw);
        $raw->method('setContents')->willReturnCallback(function (string $contents) use ($raw) {
            $this->contents = $contents;

            return $raw;
        });
        $rawFactory = $this->createMock(RawFactory::class);
        $rawFactory->method('create')->willReturn($raw);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path) => (bool)($config[$path] ?? false)
        );
        $scopeConfig->method('getValue')->willReturnCallback(static fn (string $path) => $config[$path] ?? null);

        $request = $this->createMock(HttpRequest::class);
        $request->method('getContent')->willReturn($body);

        $gate = $this->createMock(RequestGate::class);
        $gate->method('open')->willReturnCallback(function () use ($refusal) {
            $this->opened = true;
            if ($refusal !== null) {
                throw $refusal;
            }

            return new VerifiedSession(self::CLIENT_ID, str_repeat('s', 22), 'usr_1', null, []);
        });

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn('3');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW_TS);
        $dateTime->method('gmtDate')->willReturnCallback(
            static fn (?string $format = null, $input = null) => gmdate(
                $format ?? 'Y-m-d H:i:s',
                (int)($input ?? self::NOW_TS)
            )
        );

        return new Feedback(
            $request,
            $rawFactory,
            $jsonFactory,
            $scopeConfig,
            $gate,
            new ReportParser(),
            $this->repository,
            $storeManager,
            $dateTime,
            $this->logger
        );
    }

    /**
     * Record status and headers set on a result mock.
     *
     * @param (Json&MockObject)|(Raw&MockObject) $result
     * @return void
     */
    private function record(MockObject $result): void
    {
        $result->method('setHeader')->willReturnCallback(function (string $name, string $value) use ($result) {
            $this->headers[$name] = $value;

            return $result;
        });
        $result->method('setHttpResponseCode')->willReturnCallback(function (int $code) use ($result) {
            $this->status = $code;

            return $result;
        });
    }

    protected function setUp(): void
    {
        $this->repository = $this->createMock(FeedbackRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testValidReportIs202WithEmptyBody(): void
    {
        $this->windows(0, null, 0, null);
        $this->repository->expects($this->once())->method('save')->with(
            $this->callback(static fn (Report $r) => $r->task === 'Return an order'
                && $r->description === 'No returns endpoint.'),
            $this->callback(static fn (VerifiedSession $s) => $s->clientId === self::CLIENT_ID),
            3
        );

        $this->controller(self::VALID)->execute();

        $this->assertSame(202, $this->status);
        $this->assertSame('', $this->contents);
        $this->assertNull($this->data);
        $this->assertSame('no-store', $this->headers['Cache-Control'] ?? null);
    }

    public function testInvalidBodyIs400InvalidRequest(): void
    {
        // Validator feedback.invalid: {"task":42}.
        $this->repository->expects($this->never())->method('save');

        $this->controller('{"task":42}')->execute();

        $this->assertSame(400, $this->status);
        $this->assertSame(['error' => 'invalid_request'], $this->data);
    }

    public function testGateRefusalIsSentAsIsAndTheBodyIsNotRead(): void
    {
        // Validator feedback.no-session: no Session header is 400 invalid_session, never 202.
        $this->repository->expects($this->never())->method('save');

        $this->controller(self::VALID, new GateException(400, ['error' => 'invalid_session']))->execute();

        $this->assertSame(400, $this->status);
        $this->assertSame(['error' => 'invalid_session'], $this->data);
    }

    public function testGateRateLimitKeepsItsRetryAfter(): void
    {
        $this->controller(self::VALID, new GateException(429, [], ['Retry-After' => '17']))->execute();

        $this->assertSame(429, $this->status);
        $this->assertSame('17', $this->headers['Retry-After'] ?? null);
    }

    /**
     * Repository windows: the agent's and the store's reports in the last hour.
     *
     * @param int $clientCount
     * @param string|null $clientOldest
     * @param int $storeCount
     * @param string|null $storeOldest
     * @return void
     */
    private function windows(int $clientCount, ?string $clientOldest, int $storeCount, ?string $storeOldest): void
    {
        $this->repository->method('clientWindow')
            ->with(self::CLIENT_ID, '2026-11-19 09:00:00')
            ->willReturn(['count' => $clientCount, 'oldest' => $clientOldest]);
        $this->repository->method('storeWindow')
            ->with(3, '2026-11-19 09:00:00')
            ->willReturn(['count' => $storeCount, 'oldest' => $storeOldest]);
    }

    public function testAgentCapIs429UntilItsOldestReportLeavesTheWindow(): void
    {
        // Now 10:00:00; the agent's oldest report in the window is from 09:20:00 -> free again at 10:20:00.
        $this->windows(2, '2026-11-19 09:20:00', 0, null);
        $this->repository->expects($this->never())->method('save');

        $this->controller(self::VALID, null, ['brocode_poppy/feedback/max_reports_per_hour' => '2'])->execute();

        $this->assertSame(429, $this->status);
        $this->assertSame('1200', $this->headers['Retry-After'] ?? null);
    }

    public function testRetryAfterIsAtLeastOneSecond(): void
    {
        $this->windows(2, '2026-11-19 09:00:00', 0, null);

        $this->controller(self::VALID, null, ['brocode_poppy/feedback/max_reports_per_hour' => '2'])->execute();

        $this->assertSame(429, $this->status);
        $this->assertSame('1', $this->headers['Retry-After'] ?? null);
    }

    public function testStoreCapIs429WithRetryAfterFromTheStoresOldestReport(): void
    {
        $this->windows(0, null, 2, '2026-11-19 09:59:00');
        $this->repository->expects($this->never())->method('save');

        $this->controller(self::VALID, null, [
            'brocode_poppy/feedback/max_reports_per_hour' => '100',
            'brocode_poppy/feedback/max_reports_total_per_hour' => '2',
        ])->execute();

        $this->assertSame(429, $this->status);
        $this->assertSame('3540', $this->headers['Retry-After'] ?? null);
    }

    public function testStoreCapDefaultsTo500WhenUnset(): void
    {
        $this->windows(0, null, 500, '2026-11-19 09:30:00');
        $this->repository->expects($this->never())->method('save');

        $this->controller(self::VALID)->execute();

        $this->assertSame(429, $this->status);
    }

    public function testAgentCapAppliesWhileTheStoreIsFarBelowItsCap(): void
    {
        $this->windows(50, '2026-11-19 09:30:00', 50, '2026-11-19 09:30:00');
        $this->repository->expects($this->never())->method('save');

        $this->controller(self::VALID)->execute();

        $this->assertSame(429, $this->status);
    }

    public function testReportBelowBothCapsIsAccepted(): void
    {
        $this->windows(1, '2026-11-19 09:30:00', 499, '2026-11-19 09:01:00');
        $this->repository->expects($this->once())->method('save');

        $this->controller(self::VALID, null, ['brocode_poppy/feedback/max_reports_per_hour' => '2'])->execute();

        $this->assertSame(202, $this->status);
    }

    public function testNotFoundWhileFeedbackIsDisabled(): void
    {
        $this->controller(self::VALID, null, ['brocode_poppy/feedback/enabled' => false])->execute();

        $this->assertSame(404, $this->status);
        $this->assertSame(['error' => 'not_found'], $this->data);
        $this->assertFalse($this->opened);
    }

    public function testStorageFailureIs500AndLogsNoReportText(): void
    {
        $this->windows(0, null, 0, null);
        $this->repository->method('save')->willThrowException(
            new \RuntimeException('SQLSTATE: INSERT ... VALUES ("Return an order", "No returns endpoint.")')
        );
        $this->logger->expects($this->once())->method('warning')->with(
            $this->anything(),
            ['exception' => \RuntimeException::class]
        );

        $this->controller(self::VALID)->execute();

        $this->assertSame(500, $this->status);
        $this->assertSame([], $this->data);
    }

    public function testOptsOutOfFormKeyCsrfValidation(): void
    {
        $controller = $this->controller(self::VALID);
        $request = $this->createMock(HttpRequest::class);

        $this->assertTrue($controller->validateForCsrf($request));
        $this->assertNull($controller->createCsrfValidationException($request));
    }
}
