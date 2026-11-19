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
 * Persistence of Poppy Sessions: four small SQL operations, no domain logic.
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
     * @return void
     */
    public function insertIfAbsent(array $row): void
    {
        // ON DUPLICATE KEY UPDATE session_hash = VALUES(session_hash): a no-op on conflict, never overwrites a row
        $this->connection()->insertOnDuplicate($this->getMainTable(), $row, ['session_hash']);
    }

    /**
     * Set last_seen_at of a Session.
     *
     * @param int $entityId
     * @param string $now UTC datetime
     * @return void
     */
    public function markSeen(int $entityId, string $now): void
    {
        $this->connection()->update(
            $this->getMainTable(),
            ['last_seen_at' => $now],
            ['entity_id = ?' => $entityId]
        );
    }

    /**
     * Delete idle and long-ended Sessions.
     *
     * @param string $idleBefore UTC datetime: delete open Sessions last seen before this
     * @param string $endedBefore UTC datetime: delete ended Sessions ended before this
     * @return int Number of deleted rows
     */
    public function deleteExpired(string $idleBefore, string $endedBefore): int
    {
        $connection = $this->connection();
        $condition = $connection->quoteInto('(ended_at IS NULL AND last_seen_at < ?)', $idleBefore)
            . ' OR ' . $connection->quoteInto('(ended_at < ?)', $endedBefore);

        return $connection->delete($this->getMainTable(), $condition);
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
