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

namespace BroCode\Poppy\Model\Session;

/**
 * Parser for one RFC 9651 (Structured Field Values) List, as used by Poppy-Extensions.
 *
 * Bare items come back as [type, value] with type string, token, integer, decimal, bytes, boolean,
 * date or displaystring; inner lists as ['innerlist', list of [item, params]]. Any syntax error
 * fails the whole field (RFC 9651 4.2), and parse() returns null.
 */
class StructuredFieldList
{
    /**
     * @var int Read position in the input
     */
    private int $pos = 0;

    /**
     * @param string $input The field value
     */
    public function __construct(
        private readonly string $input
    ) {
    }

    /**
     * Parse the List.
     *
     * @return list<array{0:array{0:string,1:mixed},1:array<string,array{0:string,1:mixed}>}>|null
     */
    public function parse(): ?array
    {
        $this->pos = 0;
        try {
            $this->skip(' ');
            $members = [];
            while (!$this->atEnd()) {
                $item = $this->peek() === '(' ? $this->innerList() : $this->bareItem();
                $members[] = [$item, $this->parameters()];
                $this->skip(" \t");
                if ($this->atEnd()) {
                    break;
                }
                $this->expect(',');
                $this->skip(" \t");
                if ($this->atEnd()) {
                    return null; // trailing comma
                }
            }
        } catch (\UnexpectedValueException $e) {
            return null;
        }

        return $members;
    }

    /**
     * An inner list: ( item params SP item params ... ).
     *
     * @return array{0:string,1:mixed}
     */
    private function innerList(): array
    {
        $this->expect('(');
        $items = [];
        while (true) {
            $this->skip(' ');
            if ($this->peek() === ')') {
                $this->pos++;

                return ['innerlist', $items];
            }
            $items[] = [$this->bareItem(), $this->parameters()];
            $next = $this->peek();
            if ($next !== ' ' && $next !== ')') {
                throw new \UnexpectedValueException('Bad inner list.');
            }
        }
    }

    /**
     * Parameters: *( ";" *SP key [ "=" bare-item ] ).
     *
     * @return array<string,array{0:string,1:mixed}>
     */
    private function parameters(): array
    {
        $params = [];
        while ($this->peek() === ';') {
            $this->pos++;
            $this->skip(' ');
            $key = $this->key();
            $value = ['boolean', true];
            if ($this->peek() === '=') {
                $this->pos++;
                $value = $this->bareItem();
            }
            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * A parameter key: ( lcalpha / "*" ) *( lcalpha / DIGIT / "_" / "-" / "." / "*" ).
     *
     * @return string
     */
    private function key(): string
    {
        if (!preg_match('/\G[a-z*][a-z0-9_.*-]*/', $this->input, $m, 0, $this->pos)) {
            throw new \UnexpectedValueException('Bad key.');
        }
        $this->pos += strlen($m[0]);

        return $m[0];
    }

    /**
     * One bare item.
     *
     * @return array{0:string,1:mixed}
     */
    private function bareItem(): array
    {
        $c = $this->peek();
        if ($c === '"') {
            return ['string', $this->quoted()];
        }
        if ($c === '%') {
            $this->pos++;

            return ['displaystring', $this->displayString()];
        }
        if ($c === '@') {
            $this->pos++;
            $number = $this->number();
            if ($number[0] !== 'integer') {
                throw new \UnexpectedValueException('Bad date.');
            }

            return ['date', $number[1]];
        }
        if ($c === '-' || ctype_digit($c)) {
            return $this->number();
        }
        if ($c === ':') {
            return ['bytes', $this->bytes()];
        }
        if ($c === '?') {
            return ['boolean', $this->boolean()];
        }

        return ['token', $this->token()];
    }

    /**
     * An sf-string: printable ASCII, with \" and \\ as the only escapes.
     *
     * @return string
     */
    private function quoted(): string
    {
        $this->expect('"');
        $out = '';
        while (!$this->atEnd()) {
            $c = $this->input[$this->pos++];
            if ($c === '\\') {
                $next = $this->input[$this->pos++] ?? '';
                if ($next !== '"' && $next !== '\\') {
                    throw new \UnexpectedValueException('Bad escape.');
                }
                $out .= $next;
            } elseif ($c === '"') {
                return $out;
            } elseif (ord($c) < 0x20 || ord($c) > 0x7e) {
                throw new \UnexpectedValueException('Bad character.');
            } else {
                $out .= $c;
            }
        }
        throw new \UnexpectedValueException('Unterminated string.');
    }

    /**
     * An sf-displaystring after "%": percent-encoded UTF-8 in quotes.
     *
     * @return string
     */
    private function displayString(): string
    {
        if (!preg_match('/\G"((?:[\x20-\x21\x23\x24\x26-\x7e]|%[0-9a-f]{2})*)"/', $this->input, $m, 0, $this->pos)) {
            throw new \UnexpectedValueException('Bad display string.');
        }
        $this->pos += strlen($m[0]);
        $decoded = (string)preg_replace_callback(
            '/%([0-9a-f]{2})/',
            static fn (array $hex): string => (string)hex2bin($hex[1]),
            $m[1]
        );
        if (!mb_check_encoding($decoded, 'UTF-8')) {
            throw new \UnexpectedValueException('Bad display string.');
        }

        return $decoded;
    }

    /**
     * An sf-integer (up to 15 digits) or sf-decimal (12.3 digits).
     *
     * @return array{0:string,1:int|float}
     */
    private function number(): array
    {
        if (!preg_match('/\G-?[0-9]+(\.[0-9]+)?/', $this->input, $m, 0, $this->pos)) {
            throw new \UnexpectedValueException('Bad number.');
        }
        $this->pos += strlen($m[0]);
        $digits = ltrim($m[0], '-');
        if (isset($m[1])) {
            [$int, $frac] = explode('.', $digits);
            if (strlen($int) > 12 || strlen($frac) > 3) {
                throw new \UnexpectedValueException('Bad decimal.');
            }

            return ['decimal', (float)$m[0]];
        }
        if (strlen($digits) > 15) {
            throw new \UnexpectedValueException('Integer too long.');
        }

        return ['integer', (int)$m[0]];
    }

    /**
     * An sf-binary: base64 between colons.
     *
     * @return string
     */
    private function bytes(): string
    {
        if (!preg_match('/\G:([A-Za-z0-9+\/=]*):/', $this->input, $m, 0, $this->pos)) {
            throw new \UnexpectedValueException('Bad byte sequence.');
        }
        $this->pos += strlen($m[0]);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- RFC 9651 byte sequence, not obfuscation
        $decoded = base64_decode($m[1], true);
        if ($decoded === false) {
            throw new \UnexpectedValueException('Bad byte sequence.');
        }

        return $decoded;
    }

    /**
     * An sf-boolean: ?0 or ?1.
     *
     * @return bool
     */
    private function boolean(): bool
    {
        $value = substr($this->input, $this->pos, 2);
        if ($value !== '?0' && $value !== '?1') {
            throw new \UnexpectedValueException('Bad boolean.');
        }
        $this->pos += 2;

        return $value === '?1';
    }

    /**
     * An sf-token: ( ALPHA / "*" ) *( tchar / ":" / "/" ).
     *
     * @return string
     */
    private function token(): string
    {
        if (!preg_match('/\G[A-Za-z*][!#$%&\'*+.^_`|~0-9A-Za-z:\/-]*/', $this->input, $m, 0, $this->pos)) {
            throw new \UnexpectedValueException('Bad token.');
        }
        $this->pos += strlen($m[0]);

        return $m[0];
    }

    /**
     * Consume one expected character.
     *
     * @param string $char
     * @return void
     */
    private function expect(string $char): void
    {
        if ($this->peek() !== $char) {
            throw new \UnexpectedValueException('Unexpected character.');
        }
        $this->pos++;
    }

    /**
     * Skip any of the characters.
     *
     * @param string $chars
     * @return void
     */
    private function skip(string $chars): void
    {
        $this->pos += strspn($this->input, $chars, $this->pos);
    }

    /**
     * The current character, or '' at the end.
     *
     * @return string
     */
    private function peek(): string
    {
        return $this->input[$this->pos] ?? '';
    }

    /**
     * Whether the input is consumed.
     *
     * @return bool
     */
    private function atEnd(): bool
    {
        return $this->pos >= strlen($this->input);
    }
}
