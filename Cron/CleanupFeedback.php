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

use BroCode\Poppy\Model\ResourceModel\Feedback as FeedbackResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Deletes feedback reports older than brocode_poppy/feedback/retention_days (default 90).
 *
 * Reports may hold personal data although agents should not send any. Rows go in batches of 1000,
 * at most 1000 batches per run, so one run never holds a long lock.
 */
class CleanupFeedback
{
    public const XML_PATH_RETENTION_DAYS = 'brocode_poppy/feedback/retention_days';
    private const DEFAULT_RETENTION_DAYS = 90;
    private const BATCH_SIZE = 1000;
    private const MAX_BATCHES = 1000;

    /**
     * @param FeedbackResource $resource
     * @param DateTime $dateTime
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly FeedbackResource $resource,
        private readonly DateTime $dateTime,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Run the cleanup.
     *
     * @return void
     */
    public function execute(): void
    {
        $days = (int)$this->scopeConfig->getValue(self::XML_PATH_RETENTION_DAYS);
        if ($days <= 0) {
            $days = self::DEFAULT_RETENTION_DAYS;
        }
        $cutoff = $this->dateTime->gmtDate('Y-m-d H:i:s', (int)$this->dateTime->gmtTimestamp() - $days * 86400);
        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            if ($this->resource->deleteCreatedBefore($cutoff, self::BATCH_SIZE) < self::BATCH_SIZE) {
                break;
            }
        }
    }
}
