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
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Creates and continues Poppy Sessions (spec 5.3) and refuses ended or conflicting ones.
 */
class SessionRepository
{
    private const MAX_CLIENT_ID = 2048;
    private const MAX_USER_ID = 255;
    private const MAX_AGENT_ID = 255;

    /**
     * @param SessionResource $resource
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly SessionResource $resource,
        private readonly DateTime $dateTime
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
        $now = $this->dateTime->gmtDate();

        $this->resource->insertIfAbsent([
            'session_hash' => $hash,
            'client_id' => $session->clientId,
            'sid' => $session->sid,
            'user_id' => $session->userId,
            'agent_id' => $session->agentId,
            'created_at' => $now,
            'last_seen_at' => $now,
        ]);

        $row = $this->resource->loadByHash($hash);
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

        $this->resource->markSeen((int)$row['entity_id'], $now);
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
