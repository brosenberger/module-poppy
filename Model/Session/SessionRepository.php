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

use BroCode\Poppy\Exception\SessionException;
use BroCode\Poppy\Model\ResourceModel\Session as SessionResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Creates and continues Poppy Sessions (spec 5.3) and refuses ended or conflicting ones.
 *
 * New Sessions per client_id are capped per hour (brocode_poppy/session/max_new_sessions_per_hour),
 * so one agent cannot fill the table; beyond the cap a new Session is refused as invalid_session.
 */
class SessionRepository
{
    public const XML_PATH_MAX_NEW_SESSIONS = 'brocode_poppy/session/max_new_sessions_per_hour';
    public const DEFAULT_MAX_NEW_SESSIONS = 1000;
    private const MAX_CLIENT_ID = 2048;
    private const MAX_USER_ID = 255;
    private const MAX_AGENT_ID = 255;

    /**
     * @param SessionResource $resource
     * @param DateTime $dateTime
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly SessionResource $resource,
        private readonly DateTime $dateTime,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Create the Session if it is new, otherwise continue it.
     *
     * @param VerifiedSession $session
     * @return void
     * @throws SessionException session_ended or invalid_session
     */
    public function touch(VerifiedSession $session): void
    {
        $this->assertFitsColumns($session);
        $hash = hash('sha256', $session->clientId . "\n" . $session->sid);
        $clientHash = hash('sha256', $session->clientId);
        $now = $this->dateTime->gmtDate();

        $row = $this->resource->loadByHash($hash);
        $inserted = false;
        if ($row === null) {
            $this->assertUnderNewSessionCap($clientHash);
            $inserted = $this->resource->insertIfAbsent([
                'session_hash' => $hash,
                'client_hash' => $clientHash,
                'client_id' => $session->clientId,
                'sid' => $session->sid,
                'user_id' => $session->userId,
                'agent_id' => $session->agentId,
                'created_at' => $now,
                'last_seen_at' => $now,
                'seen_count' => 1,
            ]) === 1;
            $row = $this->resource->loadByHash($hash);
        }
        if ($row === null) {
            throw new SessionException(SessionException::INVALID_SESSION, 'Session could not be stored.');
        }
        if ($row['ended_at'] !== null) {
            throw new SessionException(SessionException::SESSION_ENDED, 'Session has ended.');
        }
        if ($row['user_id'] !== $session->userId || $row['agent_id'] !== $session->agentId) {
            throw new SessionException(
                SessionException::INVALID_SESSION,
                'Session is bound to a different User ID or Agent ID.'
            );
        }

        // A row this request inserted is already seen once; a concurrent insert counts as a visit.
        if (!$inserted) {
            $this->resource->markSeen((int)$row['entity_id'], $now);
        }
    }

    /**
     * Refuse a new Session once the client has started the configured number in the last hour.
     *
     * @param string $clientHash
     * @return void
     * @throws SessionException
     */
    private function assertUnderNewSessionCap(string $clientHash): void
    {
        $max = (int)$this->scopeConfig->getValue(self::XML_PATH_MAX_NEW_SESSIONS);
        if ($max <= 0) {
            $max = self::DEFAULT_MAX_NEW_SESSIONS;
        }
        $since = $this->dateTime->gmtDate('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() - 3600);
        if ($this->resource->countCreatedSince($clientHash, $since) >= $max) {
            throw new SessionException(
                SessionException::INVALID_SESSION,
                'Personal Agent started too many Sessions in the last hour.'
            );
        }
    }

    /**
     * Refuse values longer than their columns; the spec sets no length for client_id, sub or agent_id.
     *
     * @param VerifiedSession $session
     * @return void
     * @throws SessionException
     */
    private function assertFitsColumns(VerifiedSession $session): void
    {
        if (strlen($session->clientId) > self::MAX_CLIENT_ID
            || strlen($session->userId) > self::MAX_USER_ID
            || ($session->agentId !== null && strlen($session->agentId) > self::MAX_AGENT_ID)
        ) {
            throw new SessionException(SessionException::INVALID_SESSION, 'Session identifiers exceed storage limits.');
        }
    }
}
