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

namespace BroCode\Poppy\Model\Feedback;

use BroCode\Poppy\Model\ResourceModel\Feedback as FeedbackResource;
use BroCode\Poppy\Model\Session\VerifiedSession;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Stores Personal Agent feedback reports (spec 9.3) and counts them per agent.
 *
 * A report is attributed to the agent (client_id) and the store view. The User ID, Agent ID and Session ID
 * are left out: the agent is what a merchant needs, and the rest would be personal data without a purpose.
 */
class FeedbackRepository
{
    /**
     * @param FeedbackResource $resource
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly FeedbackResource $resource,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Store a report.
     *
     * @param Report $report
     * @param VerifiedSession $session The Session the report came with
     * @param int $storeId Store view the report was sent to
     * @return void
     */
    public function save(Report $report, VerifiedSession $session, int $storeId): void
    {
        $this->resource->insertReport([
            'store_id' => $storeId,
            'client_id' => $session->clientId,
            'client_hash' => $this->clientHash($session->clientId),
            'task' => $report->task,
            'description' => $report->description,
            'created_at' => $this->dateTime->gmtDate(),
        ]);
    }

    /**
     * Number of one agent's reports created at or after the given time, and the oldest one's created_at.
     *
     * @param string $clientId The agent's client_id; looked up by its hash
     * @param string $since UTC datetime
     * @return array{count: int, oldest: string|null}
     */
    public function clientWindow(string $clientId, string $since): array
    {
        return $this->resource->windowByClient($this->clientHash($clientId), $since);
    }

    /**
     * Number of one store view's reports created at or after the given time, and the oldest one's created_at.
     *
     * @param int $storeId
     * @param string $since UTC datetime
     * @return array{count: int, oldest: string|null}
     */
    public function storeWindow(int $storeId, string $since): array
    {
        return $this->resource->windowByStore($storeId, $since);
    }

    /**
     * The hash reports of one agent are stored and counted by: sha256 of the client_id.
     *
     * @param string $clientId
     * @return string
     */
    private function clientHash(string $clientId): string
    {
        return hash('sha256', $clientId);
    }
}
