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

use BroCode\Poppy\Controller\Api\Tasks;
use BroCode\Poppy\Exception\RateLimitException;
use BroCode\Poppy\Exception\SessionException;
use BroCode\Poppy\Model\Session\SessionRepository;
use BroCode\Poppy\Model\Session\SessionVerifier;
use BroCode\Poppy\Model\Session\VerifiedSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TasksTest extends TestCase
{
    /**
     * @var int|null Status set on the result
     */
    private ?int $status = null;

    /**
     * @var array<string,string> Headers set on the result
     */
    private array $headers = [];

    /**
     * @var mixed Data set on the result
     */
    private mixed $data = null;

    /**
     * @var bool Whether the verifier was called
     */
    private bool $verified = false;

    /**
     * Controller with both config flags on unless overridden; verifier and repository behave as given.
     *
     * @param \Throwable|null $verifierError
     * @param \Throwable|null $repositoryError
     * @param array<string,bool> $flags
     * @return Tasks
     */
    private function controller(
        ?\Throwable $verifierError = null,
        ?\Throwable $repositoryError = null,
        array $flags = []
    ): Tasks {
        $flags += ['brocode_poppy/general/enabled' => true, 'brocode_poppy/session/enabled' => true];

        $json = $this->createMock(Json::class);
        $json->method('setHeader')->willReturnCallback(function (string $name, string $value) use ($json) {
            $this->headers[$name] = $value;

            return $json;
        });
        $json->method('setHttpResponseCode')->willReturnCallback(function (int $code) use ($json) {
            $this->status = $code;

            return $json;
        });
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->data = $data;

            return $json;
        });
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(static fn (string $path) => $flags[$path] ?? false);

        $request = $this->createMock(HttpRequest::class);
        $request->method('getHeader')->willReturn(false);

        $verifier = $this->createMock(SessionVerifier::class);
        $verifier->method('verify')->willReturnCallback(function () use ($verifierError) {
            $this->verified = true;
            if ($verifierError !== null) {
                throw $verifierError;
            }

            return new VerifiedSession('https://agent.example/agent.json', str_repeat('s', 22), 'usr_1', null, []);
        });
        $repository = $this->createMock(SessionRepository::class);
        if ($repositoryError !== null) {
            $repository->method('touch')->willThrowException($repositoryError);
        }

        return new Tasks(
            $request,
            $jsonFactory,
            $scopeConfig,
            $verifier,
            $repository,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testValidSessionGetsTheTasksDocument(): void
    {
        $this->controller()->execute();

        $this->assertNull($this->status);
        $this->assertSame(['excluded_tasks' => []], $this->data);
        $this->assertSame('private, no-store', $this->headers['Cache-Control'] ?? null);
    }

    public function testNotFoundWhileDiscoveryIsDisabled(): void
    {
        // F5: Sessions on, poppy.json off.
        $this->controller(null, null, ['brocode_poppy/general/enabled' => false])->execute();

        $this->assertSame(404, $this->status);
        $this->assertFalse($this->verified);
    }

    public function testNotFoundWhileSessionsAreDisabled(): void
    {
        $this->controller(null, null, ['brocode_poppy/session/enabled' => false])->execute();

        $this->assertSame(404, $this->status);
        $this->assertFalse($this->verified);
    }

    public function testSessionErrorIs400WithItsCode(): void
    {
        $this->controller(null, new SessionException(SessionException::SESSION_ENDED))->execute();

        $this->assertSame(400, $this->status);
        $this->assertSame(['error' => 'session_ended'], $this->data);
    }

    public function testUnexpectedErrorIsInvalidSessionNot500(): void
    {
        // F2: a ValueError from curl or an \Exception from DNS must not become a 500 and a var/report.
        $this->controller(new \ValueError('curl_setopt(): must not contain any null bytes'))->execute();

        $this->assertSame(400, $this->status);
        $this->assertSame(['error' => 'invalid_session'], $this->data);
    }

    public function testFetchCapIs429WithRetryAfter(): void
    {
        // F3 / spec 5.6: HTTP 429 with Retry-After.
        $this->controller(new RateLimitException(17))->execute();

        $this->assertSame(429, $this->status);
        $this->assertSame('17', $this->headers['Retry-After'] ?? null);
    }
}
