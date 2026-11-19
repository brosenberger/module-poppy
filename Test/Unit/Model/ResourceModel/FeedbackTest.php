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

namespace BroCode\Poppy\Test\Unit\Model\ResourceModel;

use BroCode\Poppy\Model\ResourceModel\Feedback;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Model\ResourceModel\Db\Context;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FeedbackTest extends TestCase
{
    /** @var AdapterInterface&MockObject */
    private $connection;

    /** @var Select&MockObject */
    private $select;

    /** @var array<int,array{0:string,1:mixed}> where() calls on the select */
    private array $wheres = [];

    /** @var int|null limit() set on the select */
    private ?int $limit = null;

    /** @var Feedback */
    private $resource;

    protected function setUp(): void
    {
        $this->select = $this->createMock(Select::class);
        $this->select->method('from')->willReturnSelf();
        $this->select->method('where')->willReturnCallback(function (string $condition, $value = null) {
            $this->wheres[] = [$condition, $value];

            return $this->select;
        });
        $this->select->method('limit')->willReturnCallback(function (int $count) {
            $this->limit = $count;

            return $this->select;
        });
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($this->select);
        $resources = $this->createMock(ResourceConnection::class);
        $resources->method('getConnection')->willReturn($this->connection);
        $resources->method('getTableName')->willReturnArgument(0);
        $context = $this->createMock(Context::class);
        $context->method('getResources')->willReturn($resources);

        $this->resource = new Feedback($context);
    }

    public function testInsertReportWritesOneRow(): void
    {
        $row = ['store_id' => 1, 'task' => 't'];
        $this->connection->expects($this->once())->method('insert')->with('brocode_poppy_feedback', $row);

        $this->resource->insertReport($row);
    }

    public function testWindowByClientCountsAndFindsTheOldestReportOfTheClient(): void
    {
        $this->connection->method('fetchRow')->willReturn(['count' => '4', 'oldest' => '2026-11-19 09:12:00']);

        $this->assertSame(
            ['count' => 4, 'oldest' => '2026-11-19 09:12:00'],
            $this->resource->windowByClient('hash', '2026-11-19 09:00:00')
        );
        $this->assertSame([['client_hash = ?', 'hash'], ['created_at >= ?', '2026-11-19 09:00:00']], $this->wheres);
    }

    public function testWindowByStoreCountsTheReportsOfTheStore(): void
    {
        $this->connection->method('fetchRow')->willReturn(['count' => '0', 'oldest' => null]);

        $this->assertSame(
            ['count' => 0, 'oldest' => null],
            $this->resource->windowByStore(3, '2026-11-19 09:00:00')
        );
        $this->assertSame([['store_id = ?', 3], ['created_at >= ?', '2026-11-19 09:00:00']], $this->wheres);
    }

    public function testDeleteCreatedBeforeDeletesTheSelectedIds(): void
    {
        $this->connection->method('fetchCol')->willReturn(['5', '9']);
        $this->connection->expects($this->once())->method('delete')
            ->with('brocode_poppy_feedback', ['entity_id IN (?)' => [5, 9]])
            ->willReturn(2);

        $this->assertSame(2, $this->resource->deleteCreatedBefore('2026-08-21 10:00:00', 1000));
        $this->assertSame([['created_at < ?', '2026-08-21 10:00:00']], $this->wheres);
        $this->assertSame(1000, $this->limit);
    }

    public function testDeleteCreatedBeforeIssuesNoDeleteWithoutRows(): void
    {
        $this->connection->method('fetchCol')->willReturn([]);
        $this->connection->expects($this->never())->method('delete');

        $this->assertSame(0, $this->resource->deleteCreatedBefore('2026-08-21 10:00:00', 1000));
    }

    public function testDeleteByIdsDeletesTheGivenRows(): void
    {
        $this->connection->expects($this->once())->method('delete')
            ->with('brocode_poppy_feedback', ['entity_id IN (?)' => [3, 4]])
            ->willReturn(2);

        $this->assertSame(2, $this->resource->deleteByIds([3, 4]));
    }

    public function testDeleteByIdsWithoutIdsDeletesNothing(): void
    {
        $this->connection->expects($this->never())->method('delete');

        $this->assertSame(0, $this->resource->deleteByIds([]));
    }
}
