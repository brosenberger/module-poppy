# Plan: Session-header verification (Poppy 1.0, signed out, no issuer)

Status: agreed, 2026-11-19. Scope: verify the `Poppy-Session` header (spec 4.1, 5.2, 5.3, 3.4)
for a Company without an OAuth issuer, keep Session records, and expose one GET resource
that uses them so the kit validator can exercise every `session.*` check.

## Problem (one sentence)

`BroCode_Poppy` publishes `poppy.json` but cannot yet recognise a Personal Agent and its
signed-out Session, which every later feature (tasks/feedback, browser handoff, conversations)
requires.

## Facts this plan rests on

- Checks in order: kit `patterns/session-header.md` (client metadata → delegation → Session JWT →
  claims → `ath` → Session lookup → `Poppy-Version`). Errors: 400 `invalid_session`, 400
  `session_ended`, 400 `version_unsupported` (all `{"error": "<code>"}`).
- Without an issuer, `aud` is `https://{organization.domain}`: lowercase host, no trailing slash.
- Algorithms: delegation `ES256` **and** `PS384` (both MUST be accepted), Session JWT `ES256`.
  Delegation lifetime ≤ 24 h; `kid` of the Session JWT = RFC 7638 thumbprint of `cnf.jwk`.
- The validator tests Session checks only against a conversation endpoint or a `--probe` GET URL
  (`validate/checks/resource.ts`). There is neither today, so this plan adds the smallest
  spec-defined GET resource: the **tasks endpoint** (spec 9; `tasks.schema.json` has no required
  fields).
- Magento ships the JOSE stack: `Magento\Framework\Jwt\JwtManagerInterface::read()` verifies a JWS
  against `Jws\JwsSignatureJwks` + `Jwk`, and the adapter registers `ES256` and `PS384`
  (`module-jwt-framework-adapter`). It does **not** check claims; we do.
- angeo UCP endpoints use no Session claims, so they may ignore the header (kit `reference/ucp.md` §4).

## Decisions

| Topic | Decision | Why |
|---|---|---|
| Where to verify | Pattern B: one `SessionVerifier` service, called by each Poppy controller | No gateway in front of Magento; one resource today |
| JOSE | Magento `JwtManagerInterface` in production code; `web-token/jwt-framework` (already in vendor) only to **sign** in tests | Reuse what ships; no new dependency |
| Outbound fetches | `Magento\Framework\HTTP\Client\Curl`: https only, no redirects, 3 s timeout, 64 KB cap, host resolved once and pinned with `CURLOPT_RESOLVE` to a public IP | Spec guides "fetching metadata safely"; blocks SSRF and DNS rebinding |
| Private addresses | Allowed only via `app/etc/env.php` → `brocode_poppy/allow_private_addresses` (deployment config, not admin) | Needed for the sandbox validator run; must never be switchable from the admin |
| Metadata cache | Magento cache, key `sha256(url)`, TTL = response `max-age` clamped to 60–3600 s; failures cached 60 s | Spec: agents and Companies cache by HTTP headers |
| Delegation cache | Key `sha256(whole delegation)`, until its `exp` | Kit: never key by `jti` |
| Replay cache | None in v1 | Kit allows it; the only resource is an idempotent GET. Revisit when a state-changing endpoint arrives |
| Acceptance window | `iat` in `[now − 60 s − 30 s skew, now + 30 s]` | Kit: about a minute plus small skew |
| `htu` | Built from the store's secure base URL + request path, query and fragment dropped | Kit: never from the `Host` header behind proxies |
| Agent policy | Open by default; admin `blocked_client_ids` and `allowed_client_ids` (empty = all), newline lists; refused → `invalid_session` | Spec 4.1: each Company decides |
| Access tokens | None issued. Any `Authorization: Bearer …` on a Poppy resource → 401, `WWW-Authenticate: Bearer error="invalid_token"`; a Session JWT carrying `ath` → `invalid_session`; `access_token` in the query is ignored | No issuer; validator `token.invented`, `token.in-url`, `session.ath-without-token` |
| Session store | New table `brocode_poppy_session`, unique `session_hash = sha256(client_id \n sid)` (no `sub`, so a known `sid` with another User ID is detectable and refused); cron deletes rows idle > 30 days, ended rows kept 30 days so they keep answering `session_ended` | Kit pattern A; spec 5.5 "remember ended sids" |
| Tasks endpoint | `GET /poppy/v1/tasks` → `{"excluded_tasks": []}`, `Cache-Control: private, no-store`; listed in `poppy.json` as `tasks.endpoint` only while `brocode_poppy/session/enabled` = 1 | Smallest spec resource; makes the verifier testable end to end. Content comes with the feedback feature |
| Logging | Never log the header or JWTs; log only `client_id` + failure code at debug | Spec 10.2 |

## Steps

### 1. Spike: Magento JWS verification with a raw EC/RSA JWK
- Throwaway script (`ddev exec php`, not committed) signs ES256 and PS384 compact JWS with
  `web-token`, verifies them with `JwtManagerInterface::read($token, [new JwsSignatureJwks($jwk)])`,
  with an extra header parameter (`poppy_dlg`) and `typ` set.
- Done: both verify; a one-byte signature change throws. If the adapter rejects either, switch
  `JwsVerifier` to `web-token` directly and record it here.
- **Result (2026-11-19, 2.4.8-p5, web-token 3.4.10): passed.** Via `JwtManagerInterface::read()` with
  `JwsSignatureJwks(JwkFactory::createFromData($publicJwk))`:
  - verify: ES256 with `typ` + `kid` + custom `poppy_dlg` header; PS384 with an RSA-3072 key;
    `readHeaders()` exposes `poppy_dlg` before verification (needed to pick the delegated key).
  - reject (`MalformedTokenException`): tampered signature, another EC key, ES256 token against a
    JWK pinned to PS384, `alg: none`, HS256 keyed with the public JWK.
  - **Consequence for step 4:** a JWK **without** `alg` also verifies, so `JwsVerifier` must check
    the header `alg` against the allowed list itself and always pin `alg` on the JWK it builds.
    Pinning was shown to work (the ES256-vs-PS384 case is rejected).

### 2. `Model/CompanyIdentity` (extracted from `DocumentBuilder`)
- `public function domain(): ?string` and `public function identifier(): ?string`
  (`'https://' . domain`). `DocumentBuilder` now uses it. Two call sites (document, verifier).
- Tests: existing `DocumentBuilderTest` stays green; `CompanyIdentityTest::testIdentifierHasNoTrailingSlashAndLowercaseHost`
  (`https://Shop.Example.com/` → `https://shop.example.com`), `testPortKeptWhenNot443`
  (`https://shop.test:8443/` → `https://shop.test:8443`), `testNullWithoutHttps`.

### 3. Safe fetching: `Model/Http/HostResolver`, `Model/Http/SafeFetcher`
- `HostResolver::resolve(string $host): string[]` (wraps `dns_get_record` A/AAAA; mockable).
- `SafeFetcher::fetchJson(string $url): FetchResult` (`array $body`, `int $maxAge`); throws
  `FetchException`.
- Tests (`SafeFetcherTest`, resolver mocked, Curl mocked):
  `testRejectsHttp`, `testRejectsLoopback` (`127.0.0.1`, `::1`), `testRejectsPrivateAndLinkLocal`
  (`10.0.0.1`, `192.168.1.1`, `169.254.169.254`, `fd00::1`), `testAllowsPrivateWhenDeploymentConfigSaysSo`,
  `testDoesNotFollowRedirects` (302 → `FetchException`), `testRejectsBodyOver64Kb`,
  `testMaxAgeClampedTo60And3600` (`max-age=5` → 60, `max-age=86400` → 3600, none → 300).

### 4. `Model/Agent/ClientMetadataResolver`, `Model/Jose/JwsVerifier`
- `ClientMetadataResolver::resolve(string $clientId): ClientMetadata` (`clientId`, `array<string,array> $keysByKid`).
  Rules: `client_id` is https and equals the document's `client_id`; `jwks_uri` on the same host as
  `client_id`; `token_endpoint_auth_method` = `private_key_jwt`; cached as above.
- `JwsVerifier::verify(string $compact, array $jwk, array $algs): array{0: array, 1: array}` (header, claims)
  and `JwsVerifier::thumbprint(array $jwk): string` (RFC 7638).
- Tests: `JwsVerifierTest::testThumbprintMatchesRfc7638Example` (the RFC 7638 §3.1 RSA key →
  `NzbLsXh8uDCcd-6MNwXF4W_7noWXFZAfHkxZsRGC9Xs`), `testVerifiesEs256`, `testVerifiesPs384`,
  `testRejectsTamperedSignature`, `testRejectsAlgNotInList` (HS256 header → rejected).
  `ClientMetadataResolverTest::testRejectsClientIdMismatch`, `testRejectsJwksOnOtherHost`,
  `testRejectsWrongAuthMethod`, `testCachesWithinMaxAge` (fetcher called once for two resolves).

### 5. `Model/Session/SessionVerifier` (+ `VerifiedSession`, `Exception/SessionException`)
- `SessionVerifier::verify(\Magento\Framework\App\Request\Http $request): VerifiedSession`;
  `SessionException::getErrorCode(): string` ∈ {`invalid_session`, `session_ended`, `version_unsupported`}.
- `VerifiedSession`: readonly `clientId`, `sid`, `userId`, `?agentId`, `string[] $extensions`.
- Tests (`SessionVerifierTest`, real ES256/PS384 keys generated in `setUp`, resolver and repository
  mocked, clock injected). One test per validator id, expected code in brackets:
  missing header [invalid_session] · `typ` not `poppy-session+jwt` [invalid_session] · signed by a key
  the delegation doesn't name [invalid_session] · `kid` ≠ thumbprint [invalid_session] · signed
  directly with a `jwks_uri` key [invalid_session] · delegation signature bad [invalid_session] ·
  delegation expired [invalid_session] · delegation lifetime 24 h + 1 s [invalid_session] ·
  delegation `typ` wrong [invalid_session] · delegation `iss` ≠ JWT `iss` [invalid_session] ·
  delegation `kid` not in JWKS [invalid_session] · no `sub` [invalid_session] · `aud` with trailing
  slash [invalid_session] · `htm` POST on a GET [invalid_session] · `htu` other path
  [invalid_session] · `htu` with query on the request → accepted · `iat` 3600 s old
  [invalid_session] · `ath` without token [invalid_session] · `Poppy-Version` missing / `2.0`
  [version_unsupported] · blocked `client_id` [invalid_session] · allowlist set and client absent
  [invalid_session] · repository says ended [session_ended] · valid ES256 delegation →
  `VerifiedSession` with the JWT's `sid`/`sub` · valid PS384 delegation → accepted ·
  `Poppy-Extensions: "ucp";v="1"` → `extensions === ['ucp' => '1']`.

### 6. Session records: `etc/db_schema.xml`, `Model/ResourceModel/Session`, `Model/Session/SessionRepository`, `Cron/CleanupSessions`
- Table `brocode_poppy_session`: `entity_id`, `session_hash` (varchar 64, unique, `sha256(client_id \n sid)`), `client_id`
  (varchar 2048), `sid` (varchar 256 — the session JWT schema allows `^[A-Za-z0-9_-]{22,256}$`), `user_id` (varchar 255), `agent_id` (nullable), `created_at`,
  `last_seen_at`, `ended_at` (nullable).
- `SessionRepository::touch(VerifiedSession $s): void` — insert if absent, else update `last_seen_at`;
  throws `session_ended` when `ended_at` is set; throws `invalid_session` when the stored `agent_id`
  differs. Insert uses `insertOnDuplicate` (atomic).
- `CleanupSessions::execute(): void`, daily.
- Tests: `SessionRepositoryTest` with a mocked connection (insert path, ended path [session_ended], agent mismatch [invalid_session]);
  the DB round trip is covered by the validator's `session.continue` / `session.new-session`.

### 7. Tasks endpoint and wiring
- `Controller/Router`: add `/poppy/v1/tasks` → `Controller\Api\Tasks`.
- `Controller/Api/Tasks::execute(): Json`: bearer check → `SessionVerifier::verify()` →
  `SessionRepository::touch()` → `{"excluded_tasks": []}`; errors as above.
- `DocumentBuilder`: `tasks.endpoint` = secure base URL + `poppy/v1/tasks` when
  `brocode_poppy/session/enabled`.
- Admin: group "Personal Agents" with `session/enabled`, `allowed_client_ids`, `blocked_client_ids`.
- Tests: `DocumentBuilderTest::testTasksEndpointListedOnlyWhenSessionsEnabled`; controller covered by
  the validator.

### 8. End-to-end with the kit validator (sandbox)
- `app/etc/env.php`: `'brocode_poppy' => ['allow_private_addresses' => true]` (sandbox only).
- `node <kit>/validate/index.ts keygen --base-url https://magento2-sandbox.ddev.site/poppy-test`
  in the validator workdir; copy `agent.json` + `jwks.json` to sandbox `pub/poppy-test/`.
- Run: `NODE_EXTRA_CA_CERTS="$(mkcert -CAROOT)/rootCA.pem" node <kit>/validate/index.ts check
  https://magento2-sandbox.ddev.site --probe https://magento2-sandbox.ddev.site/poppy/v1/tasks
  --probe-session --allow-private-addresses`
- Done: exit 0; every `session.*`, `token.invented`, `token.in-url` and `tasks.response` check
  passes; only `metadata.*`, `assertion.*` and sign-in checks remain skipped (no issuer).

### 9. Quality gate
- `ddev exec vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/BroCode/Poppy/Test/Unit` → all green.
- `ddev exec vendor/bin/phpcs --standard=Magento2 --extensions=php,xml app/code/BroCode/Poppy` → 0 / 0.
- `ddev exec vendor/bin/phpstan analyse -l 8 app/code/BroCode/Poppy` → no errors.
- `curl -sk -o /dev/null -w '%{http_code}' https://magento2-sandbox.ddev.site/poppy/v1/tasks` → 400.

## Out of scope

- Verifying the header on website pages and on the angeo UCP endpoints (they use no Session claims).
- Replay cache, operator signatures (Web Bot Auth), DPoP, anything with an issuer.
- Tasks content (excluded tasks, skill index, feedback): next feature.

## Resolved questions (2026-11-19)

1. **Website pages:** deferred. Only Poppy endpoints verify the header; pages stay fully cached. Documented as a known gap.
2. **Agent policy:** open by default, with an admin blocklist and an optional allowlist.
3. **Retention:** 30 days idle; ended sessions kept 30 days so they keep answering `session_ended`.

## Execution

- 1. Spike — inline — **done 2026-11-19**: ES256 + PS384 verify through `JwtManagerInterface`, all five attack variants rejected.
- 2. CompanyIdentity — inline — **done 2026-11-19**: 3 new tests (red first) + 10 existing green, phpcs 0/0, PHPStan L8 clean, DI compile OK, poppy.json unchanged, validator Foundation 13/0.
- 3. Safe fetching — subagent `general-purpose` · opus, foreground — **done 2026-11-19**: 14 SafeFetcher tests (red first), 27 module tests green, phpcs 0/0, PHPStan L8 clean, DI compile OK. Added on review: NAT64 `64:ff9b::/96` refused, `User-Agent: BroCode-Poppy/0.1 (+https://brocode.at)`. `allow_private_addresses` (env.php) skips the whole public-address check, loopback included.
- 4. Metadata resolver + JWS — subagent `general-purpose` · opus, foreground — **done 2026-11-19**: 30 tests (red first: 30/30 'not implemented'), 57 module tests green, phpcs 0/0, PHPStan L8 clean, DI compile OK. Magento `JwtManagerInterface` used (real adapter built in the unit test, signing via web-token). "Same domain" (4.1) read as exact lowercase host. Also refused: `crit`/`b64` headers, JWK `alg` ≠ header, `use` ≠ `sig`, P-256 coordinates ≠ 32 bytes. `module.xml` now sequences `Magento_JwtFrameworkAdapter`.
- 5. SessionVerifier — subagent `general-purpose` · opus, foreground — **done 2026-11-19**: 38 tests (red: 38/38 'not implemented'), 95 module tests green, mutations (delegation lifetime, `htu`) caught, phpcs 0/0, PHPStan L8 clean, DI compile OK. Verifier is storage-free: `session_ended` and agent-id mismatch move to step 6. Accepts `Poppy-Version` 1.x (spec 3.1/3.4); `sid` per schema `{22,256}`; block/allow list checked before any metadata fetch; delegation cache not built (optional).
- 6. Session records + cron — subagent `general-purpose` · sonnet, foreground — **done 2026-11-19**: 10 tests (red first), table created by `setup:upgrade`, real DB round trip (continue, other user_id → invalid_session, ended → session_ended, cron delete) with 0 rows left. Key changed to `sha256(client_id \n sid)`. Added on review: values longer than their columns (client_id 2048, user_id/agent_id 255; spec sets no limit) → `invalid_session` before any write. 107 module tests green, phpcs 0/0, PHPStan L8 clean, DI compile OK.
- 7. Tasks endpoint + wiring — inline — **done 2026-11-19**: test red first (missing `tasks` key), 108 tests green, phpcs 0/0, PHPStan L8 clean, DI compile OK. Live: sessions off → 404; on → `tasks.endpoint` in poppy.json (link base URL + `poppy/v1/tasks`, so store code/base path match router and `htu`); no or garbage header → 400 `invalid_session` (`no-store, private`); Bearer → 401 `invalid_token`; not cached by FPC. Admin group "Personal Agents": enabled, blocked/allowed client_ids.
- 8. Validator end-to-end — inline — **done 2026-11-19**: exit 0; Foundation 38 passed / 0 failed, Interfaces 2 / 0. All 24 `session.*` checks, `token.in-url`, `tasks.response`, `tasks.alternative-references` pass. Skipped by the validator's design: `session.invalid-everywhere` (needs a conversation, feedback or browser endpoint) and `token.invented` (issuerless Company, no account access); the plan's "token.invented passes" was wrong for an issuerless Company. DB: 2 Sessions for the test agent (start+continue share a sid, new-session adds one). Sandbox-only: test agent in `pub/poppy-test/`, `brocode_poppy/allow_private_addresses` in env.php.
- 9. Quality gate + review — reviewer subagent `general-purpose` · opus · xhigh (security-relevant verification code, reviewing another agent's output) — done: no blocking findings; phpunit/phpcs/phpstan as in step 9.

Steps run in sequence: they share one repo and one sandbox, so no worktrees.
