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

namespace BroCode\Poppy\Test\Unit\Model\Feedback;

use BroCode\Poppy\Model\Feedback\FeedbackRepository;
use BroCode\Poppy\Model\Feedback\Report;
use BroCode\Poppy\Model\ResourceModel\Feedback as FeedbackResource;
use BroCode\Poppy\Model\Session\VerifiedSession;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;

class FeedbackRepositoryTest extends TestCase
{
    private const CLIENT_ID = 'https://agent.example/client';
    /** sha256("https://agent.example/client"), computed once with sha256sum */
    private const CLIENT_HASH = '02c6fbda6488140e2fa2635444f8add4ad45d01ef1840f956f16ab9b4ab586bf';

    public function testSaveStoresAgentStoreAndTextButNoUserOrSessionId(): void
    {
        $resource = $this->createMock(FeedbackResource::class);
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-11-19 10:00:00');
        $resource->expects($this->once())->method('insertReport')->with([
            'store_id' => 3,
            'client_id' => self::CLIENT_ID,
            'client_hash' => self::CLIENT_HASH,
            'task' => 'Return an order',
            'description' => 'No returns endpoint.',
            'created_at' => '2026-11-19 10:00:00',
        ]);

        (new FeedbackRepository($resource, $dateTime))->save(
            new Report('Return an order', 'No returns endpoint.'),
            new VerifiedSession(self::CLIENT_ID, 's1234567890123456789012', 'usr_1', 'agent_7', []),
            3
        );
    }

    public function testClientWindowIsLookedUpByTheHashOfTheClientId(): void
    {
        $window = ['count' => 7, 'oldest' => '2026-11-19 09:05:00'];
        $resource = $this->createMock(FeedbackResource::class);
        $resource->expects($this->once())->method('windowByClient')
            ->with(self::CLIENT_HASH, '2026-11-19 09:00:00')
            ->willReturn($window);

        $result = (new FeedbackRepository($resource, $this->createMock(DateTime::class)))
            ->clientWindow(self::CLIENT_ID, '2026-11-19 09:00:00');

        $this->assertSame($window, $result);
    }

    public function testStoreWindowIsLookedUpByStoreId(): void
    {
        $window = ['count' => 3, 'oldest' => '2026-11-19 09:30:00'];
        $resource = $this->createMock(FeedbackResource::class);
        $resource->expects($this->once())->method('windowByStore')
            ->with(4, '2026-11-19 09:00:00')
            ->willReturn($window);

        $result = (new FeedbackRepository($resource, $this->createMock(DateTime::class)))
            ->storeWindow(4, '2026-11-19 09:00:00');

        $this->assertSame($window, $result);
    }
}
