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

namespace BroCode\Poppy\Model\Jose;

use BroCode\Poppy\Exception\JoseException;
use Magento\Framework\Jwt\Jws\JwsSignatureJwks;
use Magento\Framework\Jwt\JwkFactory;
use Magento\Framework\Jwt\JwtManagerInterface;

/**
 * Verifies compact JWS tokens against one caller-chosen JWK through Magento's JWT adapter.
 *
 * The adapter verifies whatever `alg` the header names when the JWK has none, so the algorithm is
 * checked here against the caller's list, the key must fit it, and `alg` is always pinned on the
 * key handed to the adapter. Only public key members ever reach the adapter.
 */
class JwsVerifier
{
    /**
     * Public members each key type needs, in RFC 7638 order (lexicographic).
     */
    private const PUBLIC_MEMBERS = [
        'EC' => ['crv', 'kty', 'x', 'y'],
        'RSA' => ['e', 'kty', 'n'],
    ];

    /**
     * What each supported algorithm needs from the key.
     */
    private const ALG_KEYS = [
        'ES256' => ['kty' => 'EC', 'crv' => 'P-256', 'coordinateBytes' => 32],
        'PS384' => ['kty' => 'RSA', 'minBits' => 2048],
    ];

    private const COMPACT_PATTERN = '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*\.[A-Za-z0-9_-]+$/';

    /**
     * @param JwtManagerInterface $jwtManager
     * @param JwkFactory $jwkFactory
     */
    public function __construct(
        private readonly JwtManagerInterface $jwtManager,
        private readonly JwkFactory $jwkFactory
    ) {
    }

    /**
     * Verify the token's signature with the JWK and return its header and claims.
     *
     * @param string $compact Compact JWS
     * @param array<string,mixed> $jwk Verification key; private members are ignored
     * @param string[] $allowedAlgs Header `alg` values to accept, compared exactly (ES256, PS384)
     * @return array{0:array<string,mixed>,1:array<string,mixed>} Protected header and claims
     * @throws JoseException
     */
    public function verify(string $compact, array $jwk, array $allowedAlgs): array
    {
        $header = $this->readHeader($compact);
        $alg = $header['alg'] ?? null;
        if (!is_string($alg) || !in_array($alg, $allowedAlgs, true) || !isset(self::ALG_KEYS[$alg])) {
            throw new JoseException('Signature algorithm not accepted.');
        }
        // No extension is understood, so crit must be rejected (RFC 7515 4.1.11); b64 needs crit.
        if (array_key_exists('crit', $header) || array_key_exists('b64', $header)) {
            throw new JoseException('Critical header parameters are not supported.');
        }

        $publicJwk = $this->publicKeyFor($jwk, $alg);
        try {
            $this->jwtManager->read($compact, [new JwsSignatureJwks($this->jwkFactory->createFromData($publicJwk))]);
        } catch (\Throwable $e) {
            throw new JoseException('Signature verification failed.');
        }

        // The signature covers exactly these bytes, so they are decoded here rather than trusting
        // the adapter's claim parsing (which accepts non-object payloads and honours `cty`).
        $claims = $this->decodeObjectSegment(explode('.', $compact)[1]);
        if ($claims === null) {
            throw new JoseException('Claims are not a JSON object.');
        }

        return [$header, $claims];
    }

    /**
     * Decode the protected header WITHOUT verifying the signature.
     *
     * Use it only to choose the key to verify with (`kid`, `poppy_dlg`, `typ`); never trust its
     * values before verify() has succeeded.
     *
     * @param string $compact Compact JWS
     * @return array<string,mixed>
     * @throws JoseException
     */
    public function readHeader(string $compact): array
    {
        if (!preg_match(self::COMPACT_PATTERN, $compact)) {
            throw new JoseException('Not a compact JWS.');
        }
        $header = $this->decodeObjectSegment(explode('.', $compact)[0]);
        if ($header === null) {
            throw new JoseException('JWS header is not a JSON object.');
        }

        return $header;
    }

    /**
     * RFC 7638 JWK thumbprint (SHA-256, base64url without padding) of an EC or RSA key.
     *
     * @param array<string,mixed> $jwk
     * @return string
     * @throws JoseException
     */
    public function thumbprint(array $jwk): string
    {
        $members = [];
        foreach (self::PUBLIC_MEMBERS[$jwk['kty'] ?? ''] ?? [] as $name) {
            if (!isset($jwk[$name]) || !is_string($jwk[$name])) {
                throw new JoseException('JWK lacks a required member.');
            }
            $members[$name] = $jwk[$name];
        }
        if ($members === []) {
            throw new JoseException('Unsupported key type.');
        }

        return $this->base64UrlEncode(
            hash('sha256', json_encode($members, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true)
        );
    }

    /**
     * Copy the key's public members, check they fit the algorithm, and pin `alg`.
     *
     * @param array<string,mixed> $jwk
     * @param string $alg
     * @return array<string,string>
     * @throws JoseException
     */
    private function publicKeyFor(array $jwk, string $alg): array
    {
        $rule = self::ALG_KEYS[$alg];
        if (($jwk['kty'] ?? null) !== $rule['kty']) {
            throw new JoseException('Key type does not fit the algorithm.');
        }
        if (array_key_exists('alg', $jwk) && $jwk['alg'] !== $alg) {
            throw new JoseException('Key is declared for another algorithm.');
        }
        if (array_key_exists('use', $jwk) && $jwk['use'] !== 'sig') {
            throw new JoseException('Key is not a signing key.');
        }

        $public = [];
        foreach (self::PUBLIC_MEMBERS[$rule['kty']] as $name) {
            if (!isset($jwk[$name]) || !is_string($jwk[$name]) || $jwk[$name] === '') {
                throw new JoseException('Key lacks a required member.');
            }
            $public[$name] = $jwk[$name];
        }

        if ($rule['kty'] === 'EC') {
            if ($public['crv'] !== $rule['crv']
                || strlen($this->base64UrlDecode($public['x'])) !== $rule['coordinateBytes']
                || strlen($this->base64UrlDecode($public['y'])) !== $rule['coordinateBytes']
            ) {
                throw new JoseException('Key curve does not fit the algorithm.');
            }
        } elseif ($this->bitLength($this->base64UrlDecode($public['n'])) < $rule['minBits']) {
            throw new JoseException('RSA key is too short.');
        }

        $public['alg'] = $alg;

        return $public;
    }

    /**
     * Bit length of a big-endian unsigned integer.
     *
     * @param string $bytes
     * @return int
     */
    private function bitLength(string $bytes): int
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            return 0;
        }

        return (strlen($bytes) - 1) * 8 + strlen(decbin(ord($bytes[0])));
    }

    /**
     * Decode a base64url segment holding a JSON object; null when it is anything else.
     *
     * @param string $segment
     * @return array<string,mixed>|null
     */
    private function decodeObjectSegment(string $segment): ?array
    {
        try {
            $json = $this->base64UrlDecode($segment);
            $decoded = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException | JoseException $e) {
            return null;
        }
        if (!$decoded instanceof \stdClass) {
            return null;
        }

        /** @var array<string,mixed> $object */
        $object = json_decode($json, true, 64, JSON_THROW_ON_ERROR);

        return $object;
    }

    /**
     * Strict base64url decoding (no padding, URL alphabet only).
     *
     * @param string $value
     * @return string
     * @throws JoseException
     */
    private function base64UrlDecode(string $value): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- decoding JOSE data, not obfuscation
        $decoded = preg_match('/^[A-Za-z0-9_-]*$/', $value) ? base64_decode(strtr($value, '-_', '+/'), true) : false;
        if ($decoded === false) {
            throw new JoseException('Invalid base64url.');
        }

        return $decoded;
    }

    /**
     * Base64url encoding without padding.
     *
     * @param string $value
     * @return string
     */
    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
