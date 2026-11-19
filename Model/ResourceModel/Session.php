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
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Persistence of Poppy Sessions: small SQL operations, no domain logic.
 */
class Session extends AbstractDb
{
    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init('brocode_poppy_session', 'entity_id');
    }

    /**
     * Load a Session row by its hash.
     *
     * @param string $hash
     * @return array<string,mixed>|null
     */
    public function loadByHash(string $hash): ?array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->getMainTable())
            ->where('session_hash = ?', $hash);
        $row = $connection->fetchRow($select);

        return $row === false ? null : $row;
    }

    /**
     * Insert a row unless one with the same session_hash exists (atomic, changes nothing on conflict).
     *
     * @param array<string,mixed> $row
     * @return int 1 when the row was inserted, 0 when one existed
     */
    public function insertIfAbsent(array $row): int
    {
        // ON DUPLICATE KEY UPDATE session_hash = VALUES(session_hash): a no-op on conflict, never overwrites a row
        return $this->connection()->insertOnDuplicate($this->getMainTable(), $row, ['session_hash']) === 1 ? 1 : 0;
    }

    /**
     * Set last_seen_at of a Session and count the visit.
     *
     * @param int $entityId
     * @param string $now UTC datetime
     * @return void
     */
    public function markSeen(int $entityId, string $now): void
    {
        $this->connection()->update(
            $this->getMainTable(),
            ['last_seen_at' => $now, 'seen_count' => new Expression('seen_count + 1')],
            ['entity_id = ?' => $entityId]
        );
    }

    /**
     * Delete up to $limit idle, long-ended or abandoned Sessions.
     *
     * Each condition is selected on its own index, then the IDs are deleted in one statement.
     *
     * @param string $idleBefore UTC datetime: delete open Sessions last seen before this
     * @param string $endedBefore UTC datetime: delete ended Sessions ended before this
     * @param string $seenOnceBefore UTC datetime: delete open Sessions seen once and created before this
     * @param int $limit Most rows to delete in this call
     * @return int Number of deleted rows
     */
    public function deleteExpired(string $idleBefore, string $endedBefore, string $seenOnceBefore, int $limit): int
    {
        $connection = $this->connection();
        $table = $this->getMainTable();
        $conditions = [
            ['ended_at < ?' => $endedBefore],
            ['ended_at IS NULL' => null, 'last_seen_at < ?' => $idleBefore],
            ['ended_at IS NULL' => null, 'seen_count = ?' => 1, 'created_at < ?' => $seenOnceBefore],
        ];
        $ids = [];
        foreach ($conditions as $where) {
            $remaining = $limit - count($ids);
            if ($remaining <= 0) {
                break;
            }
            $select = $connection->select()->from($table, ['entity_id'])->limit($remaining);
            foreach ($where as $condition => $value) {
                if ($value === null) {
                    $select->where($condition);
                } else {
                    $select->where($condition, $value);
                }
            }
            if ($ids !== []) {
                $select->where('entity_id NOT IN (?)', $ids);
            }
            foreach ($connection->fetchCol($select) as $id) {
                $ids[] = (int)$id;
            }
        }

        return $ids === [] ? 0 : $connection->delete($table, ['entity_id IN (?)' => $ids]);
    }

    /**
     * Number of Sessions of one client created at or after the given time (index client_hash, created_at).
     *
     * @param string $clientHash sha256 of the client_id
     * @param string $since UTC datetime
     * @return int
     */
    public function countCreatedSince(string $clientHash, string $since): int
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->getMainTable(), ['COUNT(*)'])
            ->where('client_hash = ?', $clientHash)
            ->where('created_at >= ?', $since);

        return (int)$connection->fetchOne($select);
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
            throw new \LogicException('No database connection for brocode_poppy_session.');
        }

        return $connection;
    }
}
