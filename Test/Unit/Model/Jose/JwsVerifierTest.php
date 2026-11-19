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

namespace BroCode\Poppy\Test\Unit\Model\Jose;

use BroCode\Poppy\Exception\JoseException;
use BroCode\Poppy\Model\Jose\JwsVerifier;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\Algorithm\PS384;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Magento\Framework\Jwt\JwkFactory as MagentoJwkFactory;
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
 * Real cryptography: tokens are signed with web-token and verified through Magento's real JWT adapter.
 */
class JwsVerifierTest extends TestCase
{
    private const ALGS = ['ES256', 'PS384'];
    private const CLAIMS = '{"iss":"https://agent.example/agent.json"}';

    /**
     * @var JWK|null Shared P-256 key (key generation is slow enough to share)
     */
    private static ?JWK $ecKey = null;

    /**
     * @var JWK|null Shared RSA-3072 key
     */
    private static ?JWK $rsaKey = null;

    /**
     * Subject wired to the real Magento JWS stack; JWE and unsecured managers are never reached.
     *
     * @return JwsVerifier
     */
    private function verifier(): JwsVerifier
    {
        $algorithms = new JwsAlgorithmManagerFactory();
        $serializers = new JwsSerializerPoolFactory();
        $jwsManager = new JwsManager(
            new JwsBuilderFactory($algorithms),
            $serializers,
            new JwsLoaderFactory($serializers, $algorithms),
            new JwsFactory()
        );
        $jwtManager = new JwtManager(
            $jwsManager,
            $this->createMock(JweManager::class),
            $this->createMock(UnsecuredJwtManager::class)
        );

        return new JwsVerifier($jwtManager, new MagentoJwkFactory());
    }

    /**
     * @return JWK
     */
    private static function ecKey(): JWK
    {
        return self::$ecKey ??= JWKFactory::createECKey('P-256');
    }

    /**
     * @return JWK
     */
    private static function rsaKey(): JWK
    {
        return self::$rsaKey ??= JWKFactory::createRSAKey(3072);
    }

    /**
     * Sign a compact JWS with web-token.
     *
     * @param JWK $key
     * @param array<string,mixed> $header
     * @param string $payload
     * @return string
     */
    private function sign(JWK $key, array $header, string $payload = self::CLAIMS): string
    {
        $builder = new JWSBuilder(new AlgorithmManager([new ES256(), new PS384(), new HS256()]));
        $jws = $builder->create()->withPayload($payload)->addSignature($key, $header)->build();

        return (new CompactSerializer())->serialize($jws, 0);
    }

    /**
     * Public members of a web-token key as a plain array.
     *
     * @param JWK $key
     * @return array<string,mixed>
     */
    private function publicJwk(JWK $key): array
    {
        return $key->toPublic()->all();
    }

    public function testThumbprintMatchesRfc7638Example(): void
    {
        $jwk = [
            'kty' => 'RSA',
            'n' => '0vx7agoebGcQSuuPiLJXZptN9nndrQmbXEps2aiAFbWhM78LhWx4cbbfAAtVT86zwu1RK7aPFFxuhDR1L6tSoc_BJECPeb'
                . 'WKRXjBZCiFV4n3oknjhMstn64tZ_2W-5JsGY4Hc5n9yBXArwl93lqt7_RN5w6Cf0h4QyQ5v-65YGjQR0_FDW2QvzqY368QQ'
                . 'MicAtaSqzs8KJZgnYb9c7d0zgdAZHzu6qMQvRL5hajrn1n91CbOpbISD08qNLyrdkt-bFTWhAI4vMQFh6WeZu0fM4lFd2Nc'
                . 'Rwr3XPksINHaQ-G_xBniIqbw0Ls1jF44-csFCur-kEgU8awapJzKnqDKgw',
            'e' => 'AQAB',
            'alg' => 'RS256',
            'kid' => '2011-04-29',
        ];

        $this->assertSame('NzbLsXh8uDCcd-6MNwXF4W_7noWXFZAfHkxZsRGC9Xs', $this->verifier()->thumbprint($jwk));
    }

    public function testThumbprintOfEcKeyUsesOnlyRequiredMembers(): void
    {
        // Hand-checked: base64url(sha256('{"crv":"P-256","kty":"EC","x":"AAAA","y":"BBBB"}')).
        $jwk = ['y' => 'BBBB', 'x' => 'AAAA', 'kty' => 'EC', 'crv' => 'P-256', 'kid' => 'ignored', 'd' => 'ignored'];

        $this->assertSame('lwGl3agdHIksq2_XBDEqf0uaLL38Nw_OLqL2Qq_D58I', $this->verifier()->thumbprint($jwk));
    }

    public function testVerifiesEs256(): void
    {
        $token = $this->sign(
            self::ecKey(),
            ['alg' => 'ES256', 'typ' => 'poppy-session+jwt', 'kid' => 'k1', 'poppy_dlg' => 'abc.def.ghi'],
            '{"iss":"https://agent.example/agent.json","iat":1790899200}'
        );

        [$header, $claims] = $this->verifier()->verify($token, $this->publicJwk(self::ecKey()), self::ALGS);

        $this->assertSame('ES256', $header['alg']);
        $this->assertSame('abc.def.ghi', $header['poppy_dlg']);
        $this->assertSame(['iss' => 'https://agent.example/agent.json', 'iat' => 1790899200], $claims);
    }

    public function testVerifiesPs384(): void
    {
        $token = $this->sign(self::rsaKey(), ['alg' => 'PS384', 'typ' => 'poppy-delegation+jwt', 'kid' => 'r1']);

        [$header, $claims] = $this->verifier()->verify($token, $this->publicJwk(self::rsaKey()), self::ALGS);

        $this->assertSame('poppy-delegation+jwt', $header['typ']);
        $this->assertSame(['iss' => 'https://agent.example/agent.json'], $claims);
    }

    public function testRejectsTamperedSignature(): void
    {
        $token = $this->sign(self::ecKey(), ['alg' => 'ES256']);
        [$head, $body, $signature] = explode('.', $token);
        $middle = intdiv(strlen($signature), 2);
        $signature[$middle] = $signature[$middle] === 'A' ? 'B' : 'A';

        $this->expectException(JoseException::class);
        $this->verifier()->verify($head . '.' . $body . '.' . $signature, $this->publicJwk(self::ecKey()), self::ALGS);
    }

    public function testRejectsTamperedPayload(): void
    {
        $token = $this->sign(self::ecKey(), ['alg' => 'ES256']);
        [$head, , $signature] = explode('.', $token);
        $forged = rtrim(strtr(base64_encode('{"iss":"https://evil.example/agent.json"}'), '+/', '-_'), '=');

        $this->expectException(JoseException::class);
        $this->verifier()->verify(
            $head . '.' . $forged . '.' . $signature,
            $this->publicJwk(self::ecKey()),
            self::ALGS
        );
    }

    public function testRejectsAlgNotInList(): void
    {
        $secret = JWKFactory::createFromSecret(str_repeat('s', 32));
        $token = $this->sign($secret, ['alg' => 'HS256']);

        $this->expectException(JoseException::class);
        $this->verifier()->verify($token, $this->publicJwk(self::ecKey()), self::ALGS);
    }

    public function testRejectsJwkWithoutAlgWhenHeaderAlgNotAllowed(): void
    {
        // The spike showed Magento verifies whatever alg the header names when the JWK has none.
        $token = $this->sign(self::ecKey(), ['alg' => 'ES256']);
        $jwk = $this->publicJwk(self::ecKey());
        unset($jwk['alg']);

        $this->expectException(JoseException::class);
        $this->verifier()->verify($token, $jwk, ['PS384']);
    }

    public function testRejectsEs256WithRsaKey(): void
    {
        $token = $this->sign(self::ecKey(), ['alg' => 'ES256']);

        $this->expectException(JoseException::class);
        $this->verifier()->verify($token, $this->publicJwk(self::rsaKey()), self::ALGS);
    }

    public function testRejectsEcKeyOnOtherCurve(): void
    {
        $p384 = JWKFactory::createECKey('P-384');
        $token = $this->sign(self::ecKey(), ['alg' => 'ES256']);

        $this->expectException(JoseException::class);
        $this->verifier()->verify($token, $this->publicJwk($p384), self::ALGS);
    }

    public function testRejectsRsaKeyUnder2048Bits(): void
    {
        $small = JWKFactory::createRSAKey(1024);
        $token = $this->sign($small, ['alg' => 'PS384']);

        $this->expectException(JoseException::class);
        $this->verifier()->verify($token, $this->publicJwk($small), self::ALGS);
    }

    public function testRejectsKeyThatDeclaresAnotherAlg(): void
    {
        $token = $this->sign(self::ecKey(), ['alg' => 'ES256']);
        $jwk = $this->publicJwk(self::ecKey()) + ['alg' => 'ES384'];

        $this->expectException(JoseException::class);
        $this->verifier()->verify($token, $jwk, self::ALGS);
    }

    public function testRejectsCritHeader(): void
    {
        $token = $this->sign(self::ecKey(), ['alg' => 'ES256', 'crit' => ['exp'], 'exp' => 1]);

        $this->expectException(JoseException::class);
        $this->verifier()->verify($token, $this->publicJwk(self::ecKey()), self::ALGS);
    }

    public function testRejectsClaimsThatAreNotAnObject(): void
    {
        $token = $this->sign(self::ecKey(), ['alg' => 'ES256'], '[1,2]');

        $this->expectException(JoseException::class);
        $this->verifier()->verify($token, $this->publicJwk(self::ecKey()), self::ALGS);
    }

    public function testPrivateMembersAreIgnored(): void
    {
        $token = $this->sign(self::ecKey(), ['alg' => 'ES256']);
        // A garbage private scalar: had it reached the key, the library could not build the key from it.
        $jwk = $this->publicJwk(self::ecKey()) + ['d' => 'AAAA', 'k' => 'AAAA'];

        [, $claims] = $this->verifier()->verify($token, $jwk, self::ALGS);

        $this->assertSame(['iss' => 'https://agent.example/agent.json'], $claims);
    }

    public function testReadHeaderReturnsUnverifiedParameters(): void
    {
        $token = $this->sign(self::ecKey(), ['alg' => 'ES256', 'kid' => 'k1', 'poppy_dlg' => 'x.y.z']);
        [$head, $body] = explode('.', $token);

        // Signature removed: readHeader must not care.
        $header = $this->verifier()->readHeader($head . '.' . $body . '.AAAA');

        $this->assertSame(['alg' => 'ES256', 'kid' => 'k1', 'poppy_dlg' => 'x.y.z'], $header);
    }

    public function testReadHeaderRejectsNonCompactInput(): void
    {
        $this->expectException(JoseException::class);
        $this->verifier()->readHeader('{"payload":"e30","signatures":[]}');
    }
}
