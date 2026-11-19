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

use BroCode\Poppy\Exception\InvalidReportException;

/**
 * Parses a feedback report body (spec 9.3, schemas/feedback-report.schema.json).
 *
 * The body must be a JSON object of at most 16 KB in valid UTF-8, with `task` (1–200 characters) and
 * `description` (1–2000 characters) as strings; lengths count characters after trimming. Other fields are
 * ignored. The size cap applies before decoding, so a large body is never parsed; 16 KB leaves room for a
 * full-length report sent entirely as \uXXXX escapes (about 13 KB).
 */
class ReportParser
{
    public const MAX_BODY_BYTES = 16384;
    public const MAX_TASK_CHARS = 200;
    public const MAX_DESCRIPTION_CHARS = 2000;

    /**
     * Parse and validate the body.
     *
     * @param string $body Raw request body
     * @return Report
     * @throws InvalidReportException
     */
    public function parse(string $body): Report
    {
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new InvalidReportException('Report body exceeds the size limit.');
        }
        if (!mb_check_encoding($body, 'UTF-8')) {
            throw new InvalidReportException('Report body is not valid UTF-8.');
        }
        try {
            $data = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidReportException('Report body is not valid JSON.');
        }
        if (!$data instanceof \stdClass) {
            throw new InvalidReportException('Report body is not a JSON object.');
        }
        $fields = get_object_vars($data);

        return new Report(
            $this->text($fields, 'task', self::MAX_TASK_CHARS),
            $this->text($fields, 'description', self::MAX_DESCRIPTION_CHARS)
        );
    }

    /**
     * A required string field, trimmed, with 1 to $max characters.
     *
     * @param array<string,mixed> $fields
     * @param string $name
     * @param int $max
     * @return string
     * @throws InvalidReportException
     */
    private function text(array $fields, string $name, int $max): string
    {
        $value = $fields[$name] ?? null;
        if (!is_string($value)) {
            throw new InvalidReportException(sprintf('Report field "%s" is missing or not a string.', $name));
        }
        $value = trim($value);
        $length = mb_strlen($value, 'UTF-8');
        if ($length < 1 || $length > $max) {
            throw new InvalidReportException(sprintf('Report field "%s" has %d characters.', $name, $length));
        }

        return $value;
    }
}
