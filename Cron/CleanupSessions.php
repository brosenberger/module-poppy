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

namespace BroCode\Poppy\Cron;

use BroCode\Poppy\Model\ResourceModel\Session as SessionResource;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Deletes Sessions idle or ended for more than 30 days, and Sessions seen only once after 24 hours.
 *
 * Rows go in batches of 1000, at most 1000 batches per run, so one run never holds a long lock.
 */
class CleanupSessions
{
    private const RETENTION_SECONDS = 2592000;
    private const SEEN_ONCE_SECONDS = 86400;
    private const BATCH_SIZE = 1000;
    private const MAX_BATCHES = 1000;

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
     * Run the cleanup.
     *
     * @return void
     */
    public function execute(): void
    {
        $now = (int)$this->dateTime->gmtTimestamp();
        $cutoff = $this->dateTime->gmtDate('Y-m-d H:i:s', $now - self::RETENTION_SECONDS);
        $seenOnceCutoff = $this->dateTime->gmtDate('Y-m-d H:i:s', $now - self::SEEN_ONCE_SECONDS);
        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $deleted = $this->resource->deleteExpired($cutoff, $cutoff, $seenOnceCutoff, self::BATCH_SIZE);
            if ($deleted < self::BATCH_SIZE) {
                break;
            }
        }
    }
}
