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

use BroCode\Poppy\Exception\ClientMetadataException;
use BroCode\Poppy\Exception\JoseException;
use BroCode\Poppy\Exception\SessionException;
use BroCode\Poppy\Model\Agent\ClientMetadata;
use BroCode\Poppy\Model\Agent\ClientMetadataResolver;
use BroCode\Poppy\Model\CompanyIdentity;
use BroCode\Poppy\Model\Jose\JwsVerifier;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\ScopeInterface;

/**
 * Verifies the Poppy-Session header (spec 5.2, 5.3) and Poppy-Version (3.4) of one request, for a
 * Company without an OAuth issuer.
 *
 * Order (kit patterns/session-header.md): agent policy and client metadata, delegation, Session JWT
 * signature and kid, typ and claims, ath, then Poppy-Version. The Session lookup (step 6, ended
 * Sessions) belongs to the repository. Nothing is cached here; metadata caching is the resolver's.
 * Exception messages name the failed check only, never token contents.
 */
class SessionVerifier
{
    public const HEADER_SESSION = 'Poppy-Session';
    public const HEADER_VERSION = 'Poppy-Version';
    public const HEADER_EXTENSIONS = 'Poppy-Extensions';
    public const XML_PATH_BLOCKED = 'brocode_poppy/session/blocked_client_ids';
    public const XML_PATH_ALLOWED = 'brocode_poppy/session/allowed_client_ids';

    /**
     * Acceptance window for the Session JWT's iat: about a minute plus clock skew (kit).
     */
    public const MAX_AGE = 90;
    public const MAX_SKEW = 30;

    /**
     * Longest delegation lifetime, exp - iat (spec 5.2 Delegated keys).
     */
    public const MAX_DELEGATION_LIFETIME = 86400;

    private const SESSION_TYP = 'poppy-session+jwt';
    private const DELEGATION_TYP = 'poppy-delegation+jwt';
    private const DELEGATION_ALGS = ['ES256', 'PS384'];
    private const SESSION_ALGS = ['ES256'];
    private const SID_PATTERN = '/^[A-Za-z0-9_-]{22,256}$/';
    private const VERSION_PATTERN = '/^1\.(0|[1-9][0-9]{0,8})$/';

    /**
     * JWK members that hold private or symmetric key material (RFC 7518 6.2.2, 6.3.2, 6.4.1).
     */
    private const PRIVATE_MEMBERS = ['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth', 'k'];

    /**
     * @param ClientMetadataResolver $metadataResolver
     * @param JwsVerifier $jwsVerifier
     * @param CompanyIdentity $companyIdentity
     * @param ScopeConfigInterface $scopeConfig
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly ClientMetadataResolver $metadataResolver,
        private readonly JwsVerifier $jwsVerifier,
        private readonly CompanyIdentity $companyIdentity,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Verify the request's Session header and protocol version.
     *
     * @param HttpRequest $request
     * @return VerifiedSession
     * @throws SessionException
     */
    public function verify(HttpRequest $request): VerifiedSession
    {
        $compact = $request->getHeader(self::HEADER_SESSION);
        if (!is_string($compact) || $compact === '') {
            throw $this->invalid('Poppy-Session header missing.');
        }
        $now = (int)$this->dateTime->gmtTimestamp();

        try {
            // UNVERIFIED: only to pick the agent and the delegation; both are verified below.
            $header = $this->jwsVerifier->readHeader($compact);
            $clientId = $this->unverifiedIssuer($compact);
            $this->assertAgentAllowed($clientId);
            $metadata = $this->metadataResolver->resolve($clientId);
            $delegatedKey = $this->verifyDelegation($header['poppy_dlg'] ?? null, $metadata, $now);

            [$header, $claims] = $this->jwsVerifier->verify($compact, $delegatedKey, self::SESSION_ALGS);
            if (($header['kid'] ?? null) !== $this->jwsVerifier->thumbprint($delegatedKey)) {
                throw $this->invalid('Session JWT kid is not the delegated key thumbprint.');
            }
        } catch (JoseException $e) {
            throw $this->invalid('Session JWT or delegation refused.', $e);
        } catch (ClientMetadataException $e) {
            throw $this->invalid('Client metadata refused.', $e);
        }

        if (($header['typ'] ?? null) !== self::SESSION_TYP) {
            throw $this->invalid('Session JWT typ is not poppy-session+jwt.');
        }
        $session = $this->checkClaims($claims, $clientId, $request, $now);

        $version = $request->getHeader(self::HEADER_VERSION);
        if (!is_string($version) || !preg_match(self::VERSION_PATTERN, $version)) {
            throw new SessionException(SessionException::VERSION_UNSUPPORTED, 'Poppy-Version missing or not served.');
        }

        $extensions = $request->getHeader(self::HEADER_EXTENSIONS);

        return new VerifiedSession(
            $session['clientId'],
            $session['sid'],
            $session['userId'],
            $session['agentId'],
            is_string($extensions) ? $this->parseExtensions($extensions) : []
        );
    }

    /**
     * The Session JWT's iss before verification, so its metadata can be fetched.
     *
     * @param string $compact
     * @return string
     * @throws SessionException
     */
    private function unverifiedIssuer(string $compact): string
    {
        $payload = explode('.', $compact)[1] ?? '';
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- decoding JOSE data, not obfuscation
        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        try {
            $claims = is_string($json) ? json_decode($json, true, 64, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException $e) {
            $claims = null;
        }
        $iss = is_array($claims) ? ($claims['iss'] ?? null) : null;
        if (!is_string($iss) || $iss === '') {
            throw $this->invalid('Session JWT has no iss.');
        }

        return $iss;
    }

    /**
     * Apply the Company's blocklist and allowlist (an empty allowlist admits every agent).
     *
     * @param string $clientId
     * @return void
     * @throws SessionException
     */
    private function assertAgentAllowed(string $clientId): void
    {
        if (in_array($clientId, $this->configList(self::XML_PATH_BLOCKED), true)) {
            throw $this->invalid('Personal Agent is blocked.');
        }
        $allowed = $this->configList(self::XML_PATH_ALLOWED);
        if ($allowed !== [] && !in_array($clientId, $allowed, true)) {
            throw $this->invalid('Personal Agent is not on the allowlist.');
        }
    }

    /**
     * A newline-separated config list, trimmed, without empty lines.
     *
     * @param string $path
     * @return string[]
     */
    private function configList(string $path): array
    {
        $raw = (string)$this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE);

        return array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: [])));
    }

    /**
     * Verify the delegation (spec 5.2 Delegated keys, steps 1 and 2) and return its cnf.jwk.
     *
     * @param mixed $delegation The poppy_dlg header value
     * @param ClientMetadata $metadata
     * @param int $now
     * @return array<string,mixed>
     * @throws SessionException
     * @throws JoseException
     */
    private function verifyDelegation(mixed $delegation, ClientMetadata $metadata, int $now): array
    {
        if (!is_string($delegation) || $delegation === '') {
            throw $this->invalid('Session JWT carries no delegation.');
        }
        $kid = $this->jwsVerifier->readHeader($delegation)['kid'] ?? null;
        if (!is_string($kid) || !isset($metadata->keysByKid[$kid])) {
            throw $this->invalid('Delegation kid names no key in jwks_uri.');
        }

        [$header, $claims] = $this->jwsVerifier->verify(
            $delegation,
            $metadata->keysByKid[$kid],
            self::DELEGATION_ALGS
        );

        if (($header['typ'] ?? null) !== self::DELEGATION_TYP) {
            throw $this->invalid('Delegation typ is not poppy-delegation+jwt.');
        }
        if (($claims['iss'] ?? null) !== $metadata->clientId) {
            throw $this->invalid('Delegation iss is not the Session JWT iss.');
        }
        $iat = $claims['iat'] ?? null;
        $exp = $claims['exp'] ?? null;
        if (!is_int($iat) || !is_int($exp) || !is_string($claims['jti'] ?? null) || $claims['jti'] === '') {
            throw $this->invalid('Delegation lacks iat, exp or jti.');
        }
        if ($exp <= $now) {
            throw $this->invalid('Delegation has expired.');
        }
        // exp is bounded only relative to iat, so a future iat would stretch the 24 h limit.
        if ($iat > $now + self::MAX_SKEW) {
            throw $this->invalid('Delegation issued in the future.');
        }
        if ($exp - $iat > self::MAX_DELEGATION_LIFETIME) {
            throw $this->invalid('Delegation lasts longer than 24 hours.');
        }

        $jwk = $claims['cnf']['jwk'] ?? null;
        if (!is_array($jwk) || array_intersect_key($jwk, array_flip(self::PRIVATE_MEMBERS)) !== []) {
            throw $this->invalid('Delegation cnf.jwk missing or holds private members.');
        }

        return $jwk;
    }

    /**
     * Check the verified Session JWT claims (spec 5.2, 5.3 steps 2 and 3).
     *
     * @param array<string,mixed> $claims
     * @param string $clientId
     * @param HttpRequest $request
     * @param int $now
     * @return array{clientId:string,sid:string,userId:string,agentId:?string}
     * @throws SessionException
     */
    private function checkClaims(array $claims, string $clientId, HttpRequest $request, int $now): array
    {
        if (($claims['iss'] ?? null) !== $clientId) {
            throw $this->invalid('Session JWT iss changed.');
        }
        $identifier = $this->companyIdentity->identifier();
        if ($identifier === null || ($claims['aud'] ?? null) !== $identifier) {
            throw $this->invalid('Session JWT aud is not this Company.');
        }
        $sid = $claims['sid'] ?? null;
        if (!is_string($sid) || !preg_match(self::SID_PATTERN, $sid)) {
            throw $this->invalid('Session JWT sid missing or malformed.');
        }
        $sub = $claims['sub'] ?? null;
        if (!is_string($sub) || $sub === '') {
            throw $this->invalid('Session JWT has no sub.');
        }
        $agentId = $claims['agent_id'] ?? null;
        if ($agentId !== null && (!is_string($agentId) || $agentId === '')) {
            throw $this->invalid('Session JWT agent_id malformed.');
        }
        if (!is_string($claims['jti'] ?? null) || $claims['jti'] === '') {
            throw $this->invalid('Session JWT has no jti.');
        }
        if (($claims['htm'] ?? null) !== $request->getMethod()) {
            throw $this->invalid('Session JWT htm does not match the request method.');
        }
        $htu = $claims['htu'] ?? null;
        if (!is_string($htu) || $this->normalizeUrl($htu) !== $this->expectedHtu($identifier, $request)) {
            throw $this->invalid('Session JWT htu does not match the request URL.');
        }
        $iat = $claims['iat'] ?? null;
        if (!is_int($iat) || $iat < $now - self::MAX_AGE || $iat > $now + self::MAX_SKEW) {
            throw $this->invalid('Session JWT iat outside the acceptance window.');
        }
        // This Company issues no access tokens, so a request never carries one (5.3 step 3).
        if (array_key_exists('ath', $claims)) {
            throw $this->invalid('Session JWT has ath without an access token.');
        }

        return ['clientId' => $clientId, 'sid' => $sid, 'userId' => $sub, 'agentId' => $agentId];
    }

    /**
     * The request URL as htu must name it: Company identifier plus request path.
     *
     * No query or fragment (RFC 9449 4.3), and never built from the Host header.
     *
     * @param string $identifier
     * @param HttpRequest $request
     * @return string
     */
    private function expectedHtu(string $identifier, HttpRequest $request): string
    {
        $uri = (string)$request->getRequestUri();
        $path = substr($uri, 0, strcspn($uri, '?#'));

        return $identifier . ($path === '' ? '/' : $path);
    }

    /**
     * Syntax-normalize an https URL for the htu comparison; '' for anything else.
     *
     * Lowercase scheme and host, default port dropped, query and fragment dropped (RFC 9449 4.3).
     *
     * @param string $url
     * @return string
     */
    private function normalizeUrl(string $url): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- plain URL parsing, no I/O
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
        ) {
            return '';
        }
        $port = $parts['port'] ?? 443;
        $path = $parts['path'] ?? '';

        return 'https://' . strtolower($parts['host']) . ($port === 443 ? '' : ':' . $port)
            . ($path === '' ? '/' : $path);
    }

    /**
     * Parse Poppy-Extensions, an RFC 9651 List of Strings with a String `v` parameter (spec 3.4).
     *
     * A header that fails to parse declares no extensions; members that are not Strings with a
     * String `v` are ignored. The first member of a name wins.
     *
     * @param string $value
     * @return array<string,string>
     */
    private function parseExtensions(string $value): array
    {
        $members = (new StructuredFieldList($value))->parse();
        if ($members === null) {
            return [];
        }
        $extensions = [];
        foreach ($members as [$item, $params]) {
            $version = $params['v'] ?? null;
            if ($item[0] !== 'string' || !is_array($version) || $version[0] !== 'string'
                || isset($extensions[$item[1]])
            ) {
                continue;
            }
            $extensions[$item[1]] = $version[1];
        }

        return $extensions;
    }

    /**
     * An invalid_session refusal.
     *
     * @param string $message Names the failed check, never token contents
     * @param \Throwable|null $previous
     * @return SessionException
     */
    private function invalid(string $message, ?\Throwable $previous = null): SessionException
    {
        return new SessionException(SessionException::INVALID_SESSION, $message, $previous);
    }
}
