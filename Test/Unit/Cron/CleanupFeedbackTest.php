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

namespace BroCode\Poppy\Test\Unit\Cron;

use BroCode\Poppy\Cron\CleanupFeedback;
use BroCode\Poppy\Model\ResourceModel\Feedback as FeedbackResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CleanupFeedbackTest extends TestCase
{
    private const NOW_TS = 1795082400; // 2026-11-19 10:00:00 UTC

    /**
     * Cron with the given retention_days config value.
     *
     * @param FeedbackResource $resource
     * @param string|null $retentionDays
     * @return CleanupFeedback
     */
    private function cron(FeedbackResource $resource, ?string $retentionDays): CleanupFeedback
    {
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW_TS);
        $dateTime->method('gmtDate')->willReturnCallback(
            static fn (string $format, int $timestamp) => gmdate($format, $timestamp)
        );
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $path === 'brocode_poppy/feedback/retention_days' ? $retentionDays : null
        );

        return new CleanupFeedback($resource, $dateTime, $scopeConfig);
    }

    /**
     * @return array<string,array{0:string|null,1:string}>
     */
    public static function retention(): array
    {
        return [
            'configured 30 days' => ['30', '2026-10-20 10:00:00'],
            'default 90 days when unset' => [null, '2026-08-21 10:00:00'],
            'default 90 days when zero' => ['0', '2026-08-21 10:00:00'],
        ];
    }

    #[DataProvider('retention')]
    public function testDeletesReportsOlderThanTheRetentionInBatches(?string $days, string $cutoff): void
    {
        $resource = $this->createMock(FeedbackResource::class);
        $resource->expects($this->exactly(2))->method('deleteCreatedBefore')
            ->with($cutoff, 1000)
            ->willReturnOnConsecutiveCalls(1000, 12);

        $this->cron($resource, $days)->execute();
    }

    public function testStopsAfterTheBatchLimitPerRun(): void
    {
        $resource = $this->createMock(FeedbackResource::class);
        $resource->expects($this->exactly(1000))->method('deleteCreatedBefore')->willReturn(1000);

        $this->cron($resource, '90')->execute();
    }
}
