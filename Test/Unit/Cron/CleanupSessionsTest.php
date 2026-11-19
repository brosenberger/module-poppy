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

use BroCode\Poppy\Cron\CleanupSessions;
use BroCode\Poppy\Model\ResourceModel\Session as SessionResource;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;

class CleanupSessionsTest extends TestCase
{
    public function testDeletesInBatchesUntilABatchIsNotFull(): void
    {
        // F4: idle 30 days, ended 30 days, seen only once 24 hours; deleted 1000 rows at a time.
        $resource = $this->createMock(SessionResource::class);
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(1790900000); // 2026-10-02 00:13:20 UTC
        $dateTime->method('gmtDate')->willReturnCallback(
            static fn (string $format, int $timestamp) => gmdate($format, $timestamp)
        );
        $resource->expects($this->exactly(3))->method('deleteExpired')
            ->with('2026-09-02 00:13:20', '2026-09-02 00:13:20', '2026-10-01 00:13:20', 1000)
            ->willReturnOnConsecutiveCalls(1000, 1000, 3);

        (new CleanupSessions($resource, $dateTime))->execute();
    }

    public function testStopsAfterTheBatchLimitPerRun(): void
    {
        $resource = $this->createMock(SessionResource::class);
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(1790900000);
        $dateTime->method('gmtDate')->willReturn('2026-09-02 00:13:20');
        $resource->expects($this->exactly(1000))->method('deleteExpired')->willReturn(1000);

        (new CleanupSessions($resource, $dateTime))->execute();
    }
}
