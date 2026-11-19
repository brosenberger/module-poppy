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

use BroCode\Poppy\Exception\SessionException;
use BroCode\Poppy\Model\ResourceModel\Session as SessionResource;
use BroCode\Poppy\Model\Session\SessionRepository;
use BroCode\Poppy\Model\Session\VerifiedSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SessionRepositoryTest extends TestCase
{
    private const CLIENT_ID = 'https://agent.example/client';
    private const SID = 's1234567890123456789012';
    /** sha256("https://agent.example/client\ns1234567890123456789012"), computed once with sha256sum */
    private const HASH = '49b7b4cff12e4fb1f5075de1bacfe9aba2df467a0e7888edd8d964e0b14978a4';
    private const NOW = '2026-11-19 10:00:00';
    private const HOUR_AGO = '2026-11-19 09:00:00';
    private const NOW_TS = 1795082400; // 2026-11-19 10:00:00 UTC

    /** @var SessionResource&MockObject */
    private $resource;

    /** @var SessionRepository */
    private $repository;

    /** @var string|null brocode_poppy/session/max_new_sessions_per_hour */
    private ?string $maxNewSessions = null;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(SessionResource::class);
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW_TS);
        $dateTime->method('gmtDate')->willReturnCallback(
            static fn (?string $format = null, $input = null) => $input === null
                ? self::NOW
                : gmdate($format ?? 'Y-m-d H:i:s', (int)$input)
        );
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            fn (string $path) => $path === 'brocode_poppy/session/max_new_sessions_per_hour'
                ? $this->maxNewSessions
                : null
        );
        $this->repository = new SessionRepository($this->resource, $dateTime, $scopeConfig);
    }

    private function session(string $userId = 'usr_1', ?string $agentId = 'agt_1'): VerifiedSession
    {
        return new VerifiedSession(self::CLIENT_ID, self::SID, $userId, $agentId, []);
    }

    /**
     * @param string|null $endedAt
     * @return array<string,mixed>
     */
    private function row(?string $endedAt = null, string $userId = 'usr_1', ?string $agentId = 'agt_1'): array
    {
        return [
            'entity_id' => '42',
            'session_hash' => self::HASH,
            'user_id' => $userId,
            'agent_id' => $agentId,
            'ended_at' => $endedAt,
        ];
    }

    public function testNewSessionIsInsertedAsSeenOnce(): void
    {
        // F4 changed this flow: look up first, count against the hourly cap, insert with seen_count 1.
        $this->resource->method('loadByHash')->willReturnOnConsecutiveCalls(null, $this->row());
        $this->resource->method('countCreatedSince')->willReturn(0);
        $this->resource->expects($this->once())->method('insertIfAbsent')->with([
            'session_hash' => self::HASH,
            'client_hash' => hash('sha256', self::CLIENT_ID),
            'client_id' => self::CLIENT_ID,
            'sid' => self::SID,
            'user_id' => 'usr_1',
            'agent_id' => 'agt_1',
            'created_at' => self::NOW,
            'last_seen_at' => self::NOW,
            'seen_count' => 1,
        ])->willReturn(1);
        $this->resource->expects($this->never())->method('markSeen');

        $this->repository->touch($this->session());
    }

    public function testNewSessionOverTheHourlyCapIsInvalidSession(): void
    {
        $this->resource->method('loadByHash')->willReturn(null);
        $this->resource->expects($this->once())->method('countCreatedSince')
            ->with(hash('sha256', self::CLIENT_ID), self::HOUR_AGO)
            ->willReturn(1000);
        $this->resource->expects($this->never())->method('insertIfAbsent');

        $this->assertRefused(SessionException::INVALID_SESSION, $this->session());
    }

    public function testHourlyCapIsConfigurable(): void
    {
        $this->maxNewSessions = '5';
        $this->resource->method('loadByHash')->willReturnOnConsecutiveCalls(null, $this->row(), null);
        $this->resource->method('countCreatedSince')->willReturnOnConsecutiveCalls(4, 5);
        $this->resource->expects($this->once())->method('insertIfAbsent')->willReturn(1);

        $this->repository->touch($this->session());
        $this->assertRefused(SessionException::INVALID_SESSION, $this->session());
    }

    public function testKnownSessionIsNotCountedAgainstTheCap(): void
    {
        $this->resource->method('loadByHash')->willReturn($this->row());
        $this->resource->expects($this->never())->method('countCreatedSince');
        $this->resource->expects($this->never())->method('insertIfAbsent');

        $this->repository->touch($this->session());
    }

    public function testSessionInsertedByAConcurrentRequestIsMarkedSeen(): void
    {
        $this->resource->method('loadByHash')->willReturnOnConsecutiveCalls(null, $this->row());
        $this->resource->method('countCreatedSince')->willReturn(0);
        $this->resource->method('insertIfAbsent')->willReturn(0);
        $this->resource->expects($this->once())->method('markSeen')->with(42, self::NOW);

        $this->repository->touch($this->session());
    }

    public function testKnownSessionIsMarkedSeen(): void
    {
        $this->resource->method('loadByHash')->with(self::HASH)->willReturn($this->row());
        $this->resource->expects($this->once())->method('markSeen')->with(42, self::NOW);

        $this->repository->touch($this->session());
    }

    public function testNullAgentIdMatchesNullAgentId(): void
    {
        $this->resource->method('loadByHash')->willReturn($this->row(null, 'usr_1', null));
        $this->resource->expects($this->once())->method('markSeen');

        $this->repository->touch($this->session('usr_1', null));
    }

    public function testEndedSessionThrowsSessionEnded(): void
    {
        $this->resource->method('loadByHash')->willReturn($this->row('2026-11-18 09:00:00'));
        $this->resource->expects($this->never())->method('markSeen');

        $this->assertRefused(SessionException::SESSION_ENDED, $this->session());
    }

    public function testDifferentUserIdThrowsInvalidSession(): void
    {
        $this->resource->method('loadByHash')->willReturn($this->row());
        $this->resource->expects($this->never())->method('markSeen');

        $this->assertRefused(SessionException::INVALID_SESSION, $this->session('usr_2'));
    }

    public function testDifferentAgentIdThrowsInvalidSession(): void
    {
        $this->resource->method('loadByHash')->willReturn($this->row());
        $this->resource->expects($this->never())->method('markSeen');

        $this->assertRefused(SessionException::INVALID_SESSION, $this->session('usr_1', 'agt_2'));
    }

    public function testMissingAgentIdAgainstStoredAgentIdThrowsInvalidSession(): void
    {
        $this->resource->method('loadByHash')->willReturn($this->row());

        $this->assertRefused(SessionException::INVALID_SESSION, $this->session('usr_1', null));
    }

    public function testOverlongValuesAreRefusedBeforeAnyWrite(): void
    {
        // The spec sets no length for sub, agent_id or client_id; the columns do (255, 255, 2048).
        $this->resource->expects($this->never())->method('insertIfAbsent');
        $this->resource->expects($this->never())->method('markSeen');

        $this->assertRefused(SessionException::INVALID_SESSION, $this->session(str_repeat('u', 256)));
        $this->assertRefused(SessionException::INVALID_SESSION, $this->session('usr_1', str_repeat('a', 256)));
        $this->assertRefused(
            SessionException::INVALID_SESSION,
            new VerifiedSession('https://agent.example/' . str_repeat('c', 2027), self::SID, 'usr_1', null, [])
        );
    }

    public function testValuesAtColumnLimitAreAccepted(): void
    {
        $userId = str_repeat('u', 255);
        $this->resource->method('loadByHash')->willReturn($this->row(null, $userId, null));
        $this->resource->expects($this->once())->method('markSeen');

        $this->repository->touch($this->session($userId, null));
    }

    public function testHashIsSha256OfClientIdAndSid(): void
    {
        $this->resource->expects($this->once())->method('loadByHash')->with(self::HASH)
            ->willReturn($this->row());

        $this->repository->touch($this->session());
    }

    public function testHashDoesNotIncludeUserId(): void
    {
        $hashes = [];
        $this->resource->method('loadByHash')->willReturnCallback(
            function (string $hash) use (&$hashes): array {
                $hashes[] = $hash;
                return $this->row();
            }
        );

        $this->repository->touch($this->session('usr_1'));
        try {
            $this->repository->touch($this->session('usr_2'));
        } catch (SessionException $e) {
            $this->assertSame(SessionException::INVALID_SESSION, $e->getErrorCode());
        }

        $this->assertSame([self::HASH, self::HASH], $hashes);
    }

    private function assertRefused(string $code, VerifiedSession $session): void
    {
        try {
            $this->repository->touch($session);
            $this->fail('Expected SessionException ' . $code);
        } catch (SessionException $e) {
            $this->assertSame($code, $e->getErrorCode());
        }
    }
}
