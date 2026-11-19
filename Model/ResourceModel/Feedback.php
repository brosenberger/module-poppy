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

namespace BroCode\Poppy\Model\ResourceModel;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Persistence of Personal Agent feedback reports: small SQL operations, no domain logic.
 */
class Feedback extends AbstractDb
{
    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init('brocode_poppy_feedback', 'entity_id');
    }

    /**
     * Insert one report row.
     *
     * @param array<string,mixed> $row
     * @return void
     */
    public function insertReport(array $row): void
    {
        $this->connection()->insert($this->getMainTable(), $row);
    }

    /**
     * Reports of one client created at or after the given time (index client_hash, created_at).
     *
     * @param string $clientHash sha256 of the client_id
     * @param string $since UTC datetime
     * @return array{count: int, oldest: string|null} Number of reports and the oldest created_at
     */
    public function windowByClient(string $clientHash, string $since): array
    {
        return $this->window('client_hash = ?', $clientHash, $since);
    }

    /**
     * Reports of one store view created at or after the given time (index store_id, created_at).
     *
     * @param int $storeId
     * @param string $since UTC datetime
     * @return array{count: int, oldest: string|null} Number of reports and the oldest created_at
     */
    public function windowByStore(int $storeId, string $since): array
    {
        return $this->window('store_id = ?', $storeId, $since);
    }

    /**
     * Count and oldest created_at of the reports matching one condition since the given time.
     *
     * @param string $condition
     * @param int|string $value
     * @param string $since
     * @return array{count: int, oldest: string|null}
     */
    private function window(string $condition, int|string $value, string $since): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->getMainTable(), ['count' => 'COUNT(*)', 'oldest' => 'MIN(created_at)'])
            ->where($condition, $value)
            ->where('created_at >= ?', $since);
        $row = $connection->fetchRow($select);
        $oldest = is_array($row) ? ($row['oldest'] ?? null) : null;

        return [
            'count' => is_array($row) ? (int)($row['count'] ?? 0) : 0,
            'oldest' => $oldest === null ? null : (string)$oldest,
        ];
    }

    /**
     * Delete up to $limit reports created before the given time (index created_at).
     *
     * @param string $before UTC datetime
     * @param int $limit Most rows to delete in this call
     * @return int Number of deleted rows
     */
    public function deleteCreatedBefore(string $before, int $limit): int
    {
        $connection = $this->connection();
        $table = $this->getMainTable();
        $select = $connection->select()
            ->from($table, ['entity_id'])
            ->where('created_at < ?', $before)
            ->limit($limit);
        $ids = array_map('intval', $connection->fetchCol($select));

        return $ids === [] ? 0 : $connection->delete($table, ['entity_id IN (?)' => $ids]);
    }

    /**
     * Delete the reports with the given IDs (admin mass action).
     *
     * @param int[] $ids
     * @return int Number of deleted rows
     */
    public function deleteByIds(array $ids): int
    {
        return $ids === [] ? 0 : $this->connection()->delete($this->getMainTable(), ['entity_id IN (?)' => $ids]);
    }

    /**
     * The connection of this resource.
     *
     * @return AdapterInterface
     * @throws \LogicException when no connection is available
     */
    private function connection(): AdapterInterface
    {
        $connection = $this->getConnection();
        if ($connection === false) {
            throw new \LogicException('No database connection for brocode_poppy_feedback.');
        }

        return $connection;
    }
}
