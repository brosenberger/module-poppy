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

use BroCode\Poppy\Exception\InvalidReportException;
use BroCode\Poppy\Model\Feedback\ReportParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReportParserTest extends TestCase
{
    public function testValidReportIsParsedAndTrimmed(): void
    {
        $report = (new ReportParser())->parse(
            '{"task":"  Return an order ","description":"No returns endpoint.\n","extra":true}'
        );

        $this->assertSame('Return an order', $report->task);
        $this->assertSame('No returns endpoint.', $report->description);
    }

    public function testLimitsCountCharactersNotBytes(): void
    {
        // 200 and 2000 three-byte characters: 600 / 6000 bytes, within the 8 KB cap.
        $task = str_repeat('€', 200);
        $description = str_repeat('€', 2000);

        $report = (new ReportParser())->parse(json_encode(
            ['task' => $task, 'description' => $description],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));

        $this->assertSame($task, $report->task);
        $this->assertSame($description, $report->description);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function invalidBodies(): array
    {
        return [
            'task is a number' => ['{"task":42,"description":"x"}'],
            'task only' => ['{"task":42}'],
            'description missing' => ['{"task":"Return an order"}'],
            'task missing' => ['{"description":"x"}'],
            'task 201 characters' => ['{"task":"' . str_repeat('a', 201) . '","description":"x"}'],
            'description 2001 characters' => ['{"task":"t","description":"' . str_repeat('a', 2001) . '"}'],
            'task blank after trim' => ['{"task":"   ","description":"x"}'],
            'description empty' => ['{"task":"t","description":""}'],
            'description is null' => ['{"task":"t","description":null}'],
            'JSON array' => ['[{"task":"t","description":"x"}]'],
            'empty JSON array' => ['[]'],
            'JSON string' => ['"task"'],
            'not JSON' => ['task=t&description=x'],
            'empty body' => [''],
            'invalid UTF-8' => ["{\"task\":\"t\xC3\x28\",\"description\":\"x\"}"],
            'over 16 KB' => [self::paddedBody(16385)],
        ];
    }

    #[DataProvider('invalidBodies')]
    public function testInvalidBodyIsRefused(string $body): void
    {
        $this->expectException(InvalidReportException::class);

        (new ReportParser())->parse($body);
    }

    /**
     * A valid report padded with an ignored field to exactly $bytes bytes.
     *
     * @param int $bytes
     * @return string
     */
    private static function paddedBody(int $bytes): string
    {
        $prefix = '{"task":"t","description":"x","pad":"';
        $suffix = '"}';

        return $prefix . str_repeat('a', $bytes - strlen($prefix) - strlen($suffix)) . $suffix;
    }

    public function testFullLengthReportSentAsUnicodeEscapesIsAccepted(): void
    {
        // 200 + 2000 characters as \u20ac escapes: 6 bytes each, about 13.2 KB on the wire.
        $body = '{"task":"' . str_repeat('\u20ac', 200) . '","description":"'
            . str_repeat('\u20ac', 2000) . '"}';
        $this->assertGreaterThan(8192, strlen($body));

        $report = (new ReportParser())->parse($body);

        $this->assertSame(str_repeat('€', 200), $report->task);
        $this->assertSame(str_repeat('€', 2000), $report->description);
    }

    public function testBodyOfExactly16KbIsAccepted(): void
    {
        $body = self::paddedBody(16384);
        $this->assertSame(16384, strlen($body));

        $this->assertSame('t', (new ReportParser())->parse($body)->task);
    }
}
