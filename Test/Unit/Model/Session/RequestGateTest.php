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

namespace BroCode\Poppy\Test\Unit\Model\Session;

use BroCode\Poppy\Exception\GateException;
use BroCode\Poppy\Exception\RateLimitException;
use BroCode\Poppy\Exception\SessionException;
use BroCode\Poppy\Model\Session\RequestGate;
use BroCode\Poppy\Model\Session\SessionRepository;
use BroCode\Poppy\Model\Session\SessionVerifier;
use BroCode\Poppy\Model\Session\VerifiedSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RequestGateTest extends TestCase
{
    /**
     * @var bool Whether the verifier was called
     */
    private bool $verified = false;

    /**
     * @var bool Whether the repository was asked to keep the Session
     */
    private bool $touched = false;

    /**
     * Gate with both config flags on unless overridden; verifier and repository behave as given.
     *
     * @param \Throwable|null $verifierError
     * @param \Throwable|null $repositoryError
     * @param array<string,bool> $flags
     * @param string|false $authorization
     * @return RequestGate
     */
    private function gate(
        ?\Throwable $verifierError = null,
        ?\Throwable $repositoryError = null,
        array $flags = [],
        string|false $authorization = false
    ): RequestGate {
        $flags += ['brocode_poppy/general/enabled' => true, 'brocode_poppy/session/enabled' => true];
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(static fn (string $path) => $flags[$path] ?? false);

        $verifier = $this->createMock(SessionVerifier::class);
        $verifier->method('verify')->willReturnCallback(function () use ($verifierError) {
            $this->verified = true;
            if ($verifierError !== null) {
                throw $verifierError;
            }

            return new VerifiedSession('https://agent.example/agent.json', str_repeat('s', 22), 'usr_1', null, []);
        });
        $repository = $this->createMock(SessionRepository::class);
        $repository->method('touch')->willReturnCallback(function () use ($repositoryError) {
            $this->touched = true;
            if ($repositoryError !== null) {
                throw $repositoryError;
            }
        });

        return new RequestGate($scopeConfig, $verifier, $repository, $this->createMock(LoggerInterface::class));
    }

    /**
     * Request whose Authorization header is the given value.
     *
     * @param string|false $authorization
     * @return HttpRequest
     */
    private function request(string|false $authorization = false): HttpRequest
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getHeader')->willReturnCallback(
            static fn (string $name) => strcasecmp($name, 'Authorization') === 0 ? $authorization : false
        );

        return $request;
    }

    /**
     * Open the gate and return the refusal it throws.
     *
     * @param RequestGate $gate
     * @param HttpRequest|null $request
     * @return GateException
     */
    private function refusal(RequestGate $gate, ?HttpRequest $request = null): GateException
    {
        try {
            $gate->open($request ?? $this->request());
        } catch (GateException $e) {
            return $e;
        }
        $this->fail('The gate let the request through.');
    }

    public function testValidSessionIsReturnedAndKept(): void
    {
        $session = $this->gate()->open($this->request());

        $this->assertSame('https://agent.example/agent.json', $session->clientId);
        $this->assertTrue($this->touched);
    }

    public function testNotFoundWhileDiscoveryIsDisabled(): void
    {
        $e = $this->refusal($this->gate(null, null, ['brocode_poppy/general/enabled' => false]));

        $this->assertSame(404, $e->getStatusCode());
        $this->assertSame(['error' => 'not_found'], $e->getBody());
        $this->assertFalse($this->verified);
    }

    public function testNotFoundWhileSessionsAreDisabled(): void
    {
        $e = $this->refusal($this->gate(null, null, ['brocode_poppy/session/enabled' => false]));

        $this->assertSame(404, $e->getStatusCode());
        $this->assertFalse($this->verified);
    }

    public function testBearerTokenIs401InvalidToken(): void
    {
        $e = $this->refusal($this->gate(), $this->request('Bearer abc'));

        $this->assertSame(401, $e->getStatusCode());
        $this->assertSame(['error' => 'invalid_token'], $e->getBody());
        $this->assertSame(['WWW-Authenticate' => 'Bearer error="invalid_token"'], $e->getHeaders());
        $this->assertFalse($this->verified);
    }

    public function testVerifierSessionErrorIs400WithItsCode(): void
    {
        $e = $this->refusal($this->gate(new SessionException(SessionException::VERSION_UNSUPPORTED)));

        $this->assertSame(400, $e->getStatusCode());
        $this->assertSame(['error' => 'version_unsupported'], $e->getBody());
        $this->assertFalse($this->touched);
    }

    public function testRepositorySessionErrorIs400WithItsCode(): void
    {
        $e = $this->refusal($this->gate(null, new SessionException(SessionException::SESSION_ENDED)));

        $this->assertSame(400, $e->getStatusCode());
        $this->assertSame(['error' => 'session_ended'], $e->getBody());
    }

    public function testRateLimitIs429WithRetryAfterAndNoCustomBody(): void
    {
        $e = $this->refusal($this->gate(new RateLimitException(17)));

        $this->assertSame(429, $e->getStatusCode());
        $this->assertSame([], $e->getBody());
        $this->assertSame(['Retry-After' => '17'], $e->getHeaders());
    }

    public function testUnexpectedErrorIsInvalidSessionNot500(): void
    {
        $e = $this->refusal($this->gate(new \ValueError('curl_setopt(): must not contain any null bytes')));

        $this->assertSame(400, $e->getStatusCode());
        $this->assertSame(['error' => 'invalid_session'], $e->getBody());
    }
}
