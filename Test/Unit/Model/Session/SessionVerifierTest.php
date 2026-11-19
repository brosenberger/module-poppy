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

namespace BroCode\Poppy\Test\Unit\Model\Session;

use BroCode\Poppy\Exception\ClientMetadataException;
use BroCode\Poppy\Exception\SessionException;
use BroCode\Poppy\Model\Agent\ClientMetadata;
use BroCode\Poppy\Model\Agent\ClientMetadataResolver;
use BroCode\Poppy\Model\CompanyIdentity;
use BroCode\Poppy\Model\Jose\JwsVerifier;
use BroCode\Poppy\Model\Session\SessionVerifier;
use BroCode\Poppy\Model\Session\VerifiedSession;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\PS384;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Jwt\JwkFactory as MagentoJwkFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\JwtFrameworkAdapter\Model\JweManager;
use Magento\JwtFrameworkAdapter\Model\JwsAlgorithmManagerFactory;
use Magento\JwtFrameworkAdapter\Model\JwsBuilderFactory;
use Magento\JwtFrameworkAdapter\Model\JwsFactory;
use Magento\JwtFrameworkAdapter\Model\JwsLoaderFactory;
use Magento\JwtFrameworkAdapter\Model\JwsManager;
use Magento\JwtFrameworkAdapter\Model\JwsSerializerPoolFactory;
use Magento\JwtFrameworkAdapter\Model\JwtManager;
use Magento\JwtFrameworkAdapter\Model\UnsecuredJwtManager;
use PHPUnit\Framework\TestCase;

/**
 * Real cryptography: Session JWTs and delegations are signed with web-token and verified through
 * the real JwsVerifier and Magento JWT adapter. Only the agent's metadata, config and clock are mocked.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SessionVerifierTest extends TestCase
{
    private const CLIENT_ID = 'https://agent.example/agent.json';
    private const COMPANY = 'https://shop.example.com';
    private const PATH = '/poppy/v1/tasks';
    private const NOW = 1790900000;
    private const SID = 'Yx2b7Qd_Kp8mR3vT1nWc4A';
    private const SUB = 'usr_Q7c1vK';
    private const AGENT_ID = 'agt_Ln4q';
    private const EC_KID = 'agent-ec-1';
    private const RSA_KID = 'agent-rsa-1';
    private const BLOCKED_PATH = 'brocode_poppy/session/blocked_client_ids';
    private const ALLOWED_PATH = 'brocode_poppy/session/allowed_client_ids';

    /**
     * @var array<string,JWK> Keys shared by all tests (RSA generation is slow)
     */
    private static array $keys = [];

    /**
     * @var string[] client_ids the resolver mock was asked for
     */
    private array $resolved = [];

    /**
     * Shared key by name: agent EC and RSA (in jwks_uri), delegated EC, stranger EC.
     *
     * @param string $name
     * @return JWK
     */
    private static function key(string $name): JWK
    {
        return self::$keys[$name] ??= match ($name) {
            'rsa' => JWKFactory::createRSAKey(2048),
            default => JWKFactory::createECKey('P-256'),
        };
    }

    /**
     * Public members of a key, as the agent's JWKS would publish them.
     *
     * @param JWK $key
     * @param string|null $kid
     * @return array<string,mixed>
     */
    private static function publicJwk(JWK $key, ?string $kid = null): array
    {
        $jwk = $key->toPublic()->all();

        return $kid === null ? $jwk : $jwk + ['kid' => $kid];
    }

    /**
     * Sign a compact JWS with web-token.
     *
     * @param JWK $key
     * @param array<string,mixed> $header
     * @param array<string,mixed> $claims
     * @return string
     */
    private function sign(JWK $key, array $header, array $claims): string
    {
        $builder = new JWSBuilder(new AlgorithmManager([new ES256(), new PS384()]));
        $jws = $builder->create()
            ->withPayload(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            ->addSignature($key, $header)
            ->build();

        return (new CompactSerializer())->serialize($jws, 0);
    }

    /**
     * Apply overrides; a null override removes the member.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $name => $value) {
            if ($value === null) {
                unset($base[$name]);
            } else {
                $base[$name] = $value;
            }
        }

        return $base;
    }

    /**
     * A delegation for the delegated key, signed by the agent's EC key unless another root is given.
     *
     * @param array<string,mixed> $header
     * @param array<string,mixed> $claims
     * @param JWK|null $root
     * @return string
     */
    private function delegation(array $header = [], array $claims = [], ?JWK $root = null): string
    {
        $root ??= self::key('ec');
        $delegated = self::key('delegated')->toPublic()->all();

        return $this->sign(
            $root,
            $this->merge(['alg' => 'ES256', 'typ' => 'poppy-delegation+jwt', 'kid' => self::EC_KID], $header),
            $this->merge([
                'iss' => self::CLIENT_ID,
                'cnf' => ['jwk' => [
                    'kty' => $delegated['kty'],
                    'crv' => $delegated['crv'],
                    'x' => $delegated['x'],
                    'y' => $delegated['y'],
                ]],
                'iat' => self::NOW - 1800,
                'exp' => self::NOW + 5400,
                'jti' => 'Dl1x7yQbX1m3Jk0aT9cWvA',
            ], $claims)
        );
    }

    /**
     * A Session JWT for GET {COMPANY}{PATH}, signed by the delegated key unless another key is given.
     *
     * @param array<string,mixed> $header
     * @param array<string,mixed> $claims
     * @param JWK|null $key
     * @return string
     */
    private function sessionJwt(array $header = [], array $claims = [], ?JWK $key = null): string
    {
        $delegated = self::key('delegated');

        return $this->sign(
            $key ?? $delegated,
            $this->merge([
                'typ' => 'poppy-session+jwt',
                'alg' => 'ES256',
                'kid' => $delegated->thumbprint('sha256'),
                'poppy_dlg' => $this->delegation(),
            ], $header),
            $this->merge([
                'iss' => self::CLIENT_ID,
                'aud' => self::COMPANY,
                'sid' => self::SID,
                'sub' => self::SUB,
                'agent_id' => self::AGENT_ID,
                'htm' => 'GET',
                'htu' => self::COMPANY . self::PATH,
                'iat' => self::NOW,
                'jti' => 'e1j3V_bKic8-LAEBq7Rw2g',
            ], $claims)
        );
    }

    /**
     * A request mock with the given headers (null = header absent).
     *
     * @param string|null $session
     * @param string|null $version
     * @param string $method
     * @param string $uri
     * @param string|null $extensions
     * @return HttpRequest
     */
    private function request(
        ?string $session,
        ?string $version = '1.0',
        string $method = 'GET',
        string $uri = self::PATH,
        ?string $extensions = null
    ): HttpRequest {
        $headers = ['Poppy-Session' => $session, 'Poppy-Version' => $version, 'Poppy-Extensions' => $extensions];
        $request = $this->createMock(HttpRequest::class);
        $request->method('getHeader')->willReturnCallback(
            static fn (string $name, $default = false) => $headers[$name] ?? $default
        );
        $request->method('getMethod')->willReturn($method);
        $request->method('getRequestUri')->willReturn($uri);

        return $request;
    }

    /**
     * Subject with the real JOSE stack; resolver, Company identity, config and clock mocked.
     *
     * @param array<string,string> $config
     * @param bool $resolverFails
     * @return SessionVerifier
     */
    private function verifier(array $config = [], bool $resolverFails = false): SessionVerifier
    {
        $algorithms = new JwsAlgorithmManagerFactory();
        $serializers = new JwsSerializerPoolFactory();
        $jwtManager = new JwtManager(
            new JwsManager(
                new JwsBuilderFactory($algorithms),
                $serializers,
                new JwsLoaderFactory($serializers, $algorithms),
                new JwsFactory()
            ),
            $this->createMock(JweManager::class),
            $this->createMock(UnsecuredJwtManager::class)
        );

        $resolver = $this->createMock(ClientMetadataResolver::class);
        $resolver->method('resolve')->willReturnCallback(function (string $clientId) use ($resolverFails) {
            $this->resolved[] = $clientId;
            if ($resolverFails) {
                throw new ClientMetadataException('jwks_uri unreachable');
            }

            return new ClientMetadata($clientId, [
                self::EC_KID => self::publicJwk(self::key('ec'), self::EC_KID),
                self::RSA_KID => self::publicJwk(self::key('rsa'), self::RSA_KID),
            ], 300);
        });

        $identity = $this->createMock(CompanyIdentity::class);
        $identity->method('identifier')->willReturn(self::COMPANY);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $config[$path] ?? null
        );

        $clock = $this->createMock(DateTime::class);
        $clock->method('gmtTimestamp')->willReturn(self::NOW);

        return new SessionVerifier(
            $resolver,
            new \BroCode\Poppy\Model\Jose\JwsVerifier($jwtManager, new MagentoJwkFactory()),
            $identity,
            $scopeConfig,
            $clock
        );
    }

    /**
     * Assert the request is refused with the error code.
     *
     * @param string $code
     * @param HttpRequest $request
     * @param SessionVerifier|null $verifier
     * @return void
     */
    private function assertRefused(string $code, HttpRequest $request, ?SessionVerifier $verifier = null): void
    {
        try {
            ($verifier ?? $this->verifier())->verify($request);
        } catch (SessionException $e) {
            $this->assertSame($code, $e->getErrorCode());

            return;
        }
        $this->fail('Expected SessionException ' . $code . ', the request was accepted.');
    }

    public function testMissingHeaderIsInvalidSession(): void
    {
        $this->assertRefused('invalid_session', $this->request(null));
    }

    public function testTypNotSessionJwtIsInvalidSession(): void
    {
        $this->assertRefused('invalid_session', $this->request($this->sessionJwt(['typ' => 'JWT'])));
    }

    public function testSignedByKeyTheDelegationDoesNotNameIsInvalidSession(): void
    {
        $this->assertRefused('invalid_session', $this->request($this->sessionJwt([], [], self::key('stranger'))));
    }

    public function testKidNotThumbprintOfDelegatedKeyIsInvalidSession(): void
    {
        $kid = self::key('stranger')->thumbprint('sha256');

        $this->assertRefused('invalid_session', $this->request($this->sessionJwt(['kid' => $kid])));
    }

    public function testSignedDirectlyWithJwksKeyIsInvalidSession(): void
    {
        $jwt = $this->sessionJwt(
            ['poppy_dlg' => null, 'kid' => self::key('ec')->thumbprint('sha256')],
            [],
            self::key('ec')
        );

        $this->assertRefused('invalid_session', $this->request($jwt));
    }

    public function testDelegationSignedByAnotherKeyIsInvalidSession(): void
    {
        $delegation = $this->delegation([], [], self::key('stranger'));

        $this->assertRefused('invalid_session', $this->request($this->sessionJwt(['poppy_dlg' => $delegation])));
    }

    public function testExpiredDelegationIsInvalidSession(): void
    {
        $delegation = $this->delegation([], ['iat' => self::NOW - 7200, 'exp' => self::NOW - 3600]);

        $this->assertRefused('invalid_session', $this->request($this->sessionJwt(['poppy_dlg' => $delegation])));
    }

    public function testDelegationLifetimeOver24HoursIsInvalidSession(): void
    {
        $delegation = $this->delegation([], ['iat' => self::NOW - 60, 'exp' => self::NOW - 60 + 86401]);

        $this->assertRefused('invalid_session', $this->request($this->sessionJwt(['poppy_dlg' => $delegation])));
    }

    public function testDelegationLifetimeOfExactly24HoursIsAccepted(): void
    {
        $delegation = $this->delegation([], ['iat' => self::NOW - 60, 'exp' => self::NOW - 60 + 86400]);

        $session = $this->verifier()->verify($this->request($this->sessionJwt(['poppy_dlg' => $delegation])));

        $this->assertSame(self::SID, $session->sid);
    }

    public function testDelegationIssuedInTheFutureIsInvalidSession(): void
    {
        // Otherwise a far-future iat would stretch the 24 h limit: exp is only bounded relative to iat.
        $delegation = $this->delegation([], ['iat' => self::NOW + 86400 * 365, 'exp' => self::NOW + 86400 * 366]);

        $this->assertRefused('invalid_session', $this->request($this->sessionJwt(['poppy_dlg' => $delegation])));
    }

    public function testDelegationTypWrongIsInvalidSession(): void
    {
        $delegation = $this->delegation(['typ' => 'JWT']);

        $this->assertRefused('invalid_session', $this->request($this->sessionJwt(['poppy_dlg' => $delegation])));
    }

    public function testDelegationIssNotJwtIssIsInvalidSession(): void
    {
        $delegation = $this->delegation([], ['iss' => 'https://other-agent.test/agent.json']);

        $this->assertRefused('invalid_session', $this->request($this->sessionJwt(['poppy_dlg' => $delegation])));
    }

    public function testDelegationKidNotInJwksIsInvalidSession(): void
    {
        $delegation = $this->delegation(['kid' => 'validator-unknown']);

        $this->assertRefused('invalid_session', $this->request($this->sessionJwt(['poppy_dlg' => $delegation])));
    }

    public function testDelegatedKeyWithPrivateMembersIsInvalidSession(): void
    {
        $delegated = self::key('delegated')->all();
        $delegation = $this->delegation([], ['cnf' => ['jwk' => [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => $delegated['x'],
            'y' => $delegated['y'],
            'd' => $delegated['d'],
        ]]]);

        $this->assertRefused('invalid_session', $this->request($this->sessionJwt(['poppy_dlg' => $delegation])));
    }

    public function testMissingSubIsInvalidSession(): void
    {
        $this->assertRefused('invalid_session', $this->request($this->sessionJwt([], ['sub' => null])));
    }

    public function testAudWithTrailingSlashIsInvalidSession(): void
    {
        $this->assertRefused('invalid_session', $this->request($this->sessionJwt([], ['aud' => self::COMPANY . '/'])));
    }

    public function testHtmPostOnGetIsInvalidSession(): void
    {
        $this->assertRefused('invalid_session', $this->request($this->sessionJwt([], ['htm' => 'POST'])));
    }

    public function testHtuOtherPathIsInvalidSession(): void
    {
        $jwt = $this->sessionJwt([], ['htu' => self::COMPANY . '/poppy-validator/other-path']);

        $this->assertRefused('invalid_session', $this->request($jwt));
    }

    public function testHtuOtherHostIsInvalidSession(): void
    {
        $jwt = $this->sessionJwt([], ['htu' => 'https://evil.example.com' . self::PATH]);

        $this->assertRefused('invalid_session', $this->request($jwt));
    }

    public function testQueryStringOnRequestIsIgnoredForHtu(): void
    {
        $request = $this->request($this->sessionJwt(), '1.0', 'GET', self::PATH . '?access_token=abc&x=1');

        $session = $this->verifier()->verify($request);

        $this->assertSame(self::SID, $session->sid);
    }

    public function testIatAnHourOldIsInvalidSession(): void
    {
        $this->assertRefused('invalid_session', $this->request($this->sessionJwt([], ['iat' => self::NOW - 3600])));
    }

    public function testIatOneMinuteInTheFutureIsInvalidSession(): void
    {
        $this->assertRefused('invalid_session', $this->request($this->sessionJwt([], ['iat' => self::NOW + 60])));
    }

    public function testIatAtWindowEdgesIsAccepted(): void
    {
        $verifier = $this->verifier();

        $old = $verifier->verify($this->request($this->sessionJwt([], ['iat' => self::NOW - 90])));
        $ahead = $verifier->verify($this->request($this->sessionJwt([], ['iat' => self::NOW + 30])));

        $this->assertSame(self::SID, $old->sid);
        $this->assertSame(self::SID, $ahead->sid);
    }

    public function testAthWithoutAccessTokenIsInvalidSession(): void
    {
        $ath = 'fUHyO2r2Z3DZ53EsNrWBb0xWXoaNy59IiKCAqksmQEo';

        $this->assertRefused('invalid_session', $this->request($this->sessionJwt([], ['ath' => $ath])));
    }

    public function testPoppyVersionMissingIsVersionUnsupported(): void
    {
        $this->assertRefused('version_unsupported', $this->request($this->sessionJwt(), null));
    }

    public function testPoppyVersion20IsVersionUnsupported(): void
    {
        $this->assertRefused('version_unsupported', $this->request($this->sessionJwt(), '2.0'));
    }

    public function testPoppyVersion00IsVersionUnsupported(): void
    {
        $this->assertRefused('version_unsupported', $this->request($this->sessionJwt(), '0.0'));
    }

    public function testCompatibleMinorVersionIsAccepted(): void
    {
        // Spec 3.4: from 1.0, the same major version is compatible; the Company answers as 1.0.
        $session = $this->verifier()->verify($this->request($this->sessionJwt(), '1.1'));

        $this->assertSame(self::SID, $session->sid);
    }

    public function testBlockedClientIdIsInvalidSession(): void
    {
        $verifier = $this->verifier([self::BLOCKED_PATH => "https://spam.example/agent.json\n" . self::CLIENT_ID]);

        $this->assertRefused('invalid_session', $this->request($this->sessionJwt()), $verifier);
        $this->assertSame([], $this->resolved, 'A blocked client is refused before its metadata is fetched.');
    }

    public function testAllowlistWithoutClientIsInvalidSession(): void
    {
        $verifier = $this->verifier([self::ALLOWED_PATH => 'https://friend.example/agent.json']);

        $this->assertRefused('invalid_session', $this->request($this->sessionJwt()), $verifier);
    }

    public function testAllowlistWithClientIsAccepted(): void
    {
        $verifier = $this->verifier([self::ALLOWED_PATH => "https://friend.example/agent.json\r\n " . self::CLIENT_ID]);

        $session = $verifier->verify($this->request($this->sessionJwt()));

        $this->assertSame(self::CLIENT_ID, $session->clientId);
    }

    public function testResolverFailureIsInvalidSession(): void
    {
        $this->assertRefused('invalid_session', $this->request($this->sessionJwt()), $this->verifier([], true));
    }

    public function testValidEs256DelegationReturnsVerifiedSession(): void
    {
        $session = $this->verifier()->verify($this->request($this->sessionJwt()));

        $this->assertInstanceOf(VerifiedSession::class, $session);
        $this->assertSame(self::CLIENT_ID, $session->clientId);
        $this->assertSame(self::SID, $session->sid);
        $this->assertSame(self::SUB, $session->userId);
        $this->assertSame(self::AGENT_ID, $session->agentId);
        $this->assertSame([], $session->extensions);
        $this->assertSame([self::CLIENT_ID], $this->resolved);
    }

    public function testAgentIdIsOptional(): void
    {
        $session = $this->verifier()->verify($this->request($this->sessionJwt([], ['agent_id' => null])));

        $this->assertNull($session->agentId);
    }

    public function testValidPs384DelegationIsAccepted(): void
    {
        $delegation = $this->delegation(['alg' => 'PS384', 'kid' => self::RSA_KID], [], self::key('rsa'));

        $session = $this->verifier()->verify($this->request($this->sessionJwt(['poppy_dlg' => $delegation])));

        $this->assertSame(self::SUB, $session->userId);
    }

    public function testPoppyExtensionsAreParsed(): void
    {
        $request = $this->request($this->sessionJwt(), '1.0', 'GET', self::PATH, '"ucp";v="1"');

        $this->assertSame(['ucp' => '1'], $this->verifier()->verify($request)->extensions);
    }

    public function testPoppyExtensionsListWithSeveralMembers(): void
    {
        $request = $this->request(
            $this->sessionJwt(),
            '1.0',
            'GET',
            self::PATH,
            '"operations";v="3", "links";v="1";x=?1, token;v="2", "nov", "num";v=2'
        );

        // Members that are not Strings with a String v are ignored.
        $this->assertSame(['operations' => '3', 'links' => '1'], $this->verifier()->verify($request)->extensions);
    }

    public function testUnparsablePoppyExtensionsDeclareNone(): void
    {
        $request = $this->request($this->sessionJwt(), '1.0', 'GET', self::PATH, '"ucp";v="1", "links;v="1"');

        $this->assertSame([], $this->verifier()->verify($request)->extensions);
    }
}
