# BroCode_Poppy

Personal Agent Protocol (Poppy) 1.0 for Magento 2. Publishes `/.well-known/poppy.json` per
store, links an existing UCP profile, and verifies the signed `Poppy-Session` header that
personal agents (Meta's Muse and others) send, on a `GET /poppy/v1/tasks` endpoint. Agents
can report tasks they couldn't do in the shop (`POST /poppy/v1/feedback`); the reports show up
in an admin grid.

It covers the signed-out part of Poppy: no OAuth issuer, no sign-in, no access tokens. It
implements no UCP itself. Pair it with a module that serves `/.well-known/ucp` (tested with
`angeo/module-ucp` + `angeo/module-ucp-catalog`).

Write-up: [Personal Agent Protocol for Magento 2: What to Ship](https://brocode.at/blog/magento-personal-agent-protocol/)

## Install

```bash
ddev composer require brocode/module-poppy
ddev exec php bin/magento module:enable BroCode_Poppy
ddev exec php bin/magento setup:upgrade
```

Requires PHP 8.2+ and Magento 2.4 with `Magento_JwtFrameworkAdapter` and `Magento_Reports` (both
ship with core; the feedback grid sits under *Reports*).
`setup:upgrade` creates the tables `brocode_poppy_session` and `brocode_poppy_feedback`.

## What it does

### Discovery: `/.well-known/poppy.json`

Served by its own frontend router, built from store config on every request:

```json
{
  "protocol_version": "1.0",
  "organization": { "name": "Main Website Store", "domain": "shop.example.com" },
  "web": {},
  "tasks": { "endpoint": "https://shop.example.com/poppy/v1/tasks" },
  "extensions": { "ucp": [{ "version": "1" }] }
}
```

- `organization.domain` comes from the store's secure base URL (lowercase, port only when not 443).
  Without an https base URL nothing is published (404). Each website/host gets its own file.
- `organization.name` falls back to the Store Information name, then the store's frontend name.
- `logo_uri` is published only when it is https.
- `tasks` is listed only while Personal Agent Sessions are accepted.
- `extensions.ucp` is listed only when the admin switch is on **and** a known UCP module is enabled
  and switched on for the store. Known modules are a `di.xml` argument of
  `BroCode\Poppy\Model\DocumentBuilder` (`ucpProfileProviders`: module name → its on/off config path);
  the default entry is `Angeo_Ucp` → `angeo_ucp/general/enabled`.
- Response: `application/json`, `Cache-Control: max-age=300, public`. Flush the cache after a config change.

### Sessions: `GET /poppy/v1/tasks`

Verifies `Poppy-Session` and `Poppy-Version` as the spec requires, then answers
`{"excluded_tasks": []}` (`Cache-Control: private, no-store`). In order:

1. Checks on the unverified token first (type, audience, method, URL, issue time, `sid`,
   `Poppy-Version`), so a malformed request causes no outbound traffic.
2. Admin blocklist and optional allowlist of agent `client_id`s.
3. Fetches the agent's client metadata and JWKS: https only, no redirects, 3 s timeout, 64 KB cap,
   resolved IP pinned for the connection; private and local addresses refused. Metadata is cached
   per its `max-age` (60–3600 s); cache-miss fetches are capped per store per minute (429 above it).
4. Verifies the delegation (ES256 or PS384) and the Session JWT (ES256) through Magento's own
   `JwtManagerInterface`; claims are checked by the module.
5. Records the Session (`brocode_poppy_session`); ended Sessions keep answering `session_ended`.

Errors are `{"error": "invalid_session" | "session_ended" | "version_unsupported"}` with 400.
A request with `Authorization: Bearer …` gets 401 `WWW-Authenticate: Bearer error="invalid_token"`:
the module never issues tokens. Headers and JWTs are never logged; failures log only the
`client_id` and the failure code at debug level.

A daily cron (`03:17`) deletes Sessions idle for 30 days, Sessions seen only once after 24 h, and
ended Sessions after 30 days.

### Feedback: `POST /poppy/v1/feedback`

Spec 9.3: a Personal Agent reports a User's task it couldn't do through the protocol. Listed in the
tasks response as `"feedback": {"endpoint": "…/poppy/v1/feedback"}` only while feedback is on.

```json
{ "task": "Pay with a gift card", "description": "The checkout offers no field for a gift card code." }
```

- Same Session checks as the tasks endpoint; a report without a valid `Poppy-Session` gets 400
  `invalid_session`, so every stored report has a verified agent.
- `task` 1–200 and `description` 1–2000 characters, both required; body at most 16 KB. Anything else
  gets 400 `{"error": "invalid_request"}`. Accepted reports get 202 with no body.
- At most 50 reports per agent and 500 per store view per hour (both configurable); above either, 429
  with `Retry-After` set to when the oldest report in that window turns an hour old. Counting and
  storing are not atomic, so parallel requests can pass a cap by a few reports.
- Stored in `brocode_poppy_feedback` with store, agent `client_id`, task, description and date. The
  User ID and Session ID are not stored. The report text is never logged.
- Admin: *Reports → Poppy → Agent Feedback* (ACL `BroCode_Poppy::feedback`), with mass delete. The
  text is shown escaped: it is untrusted input written by an agent.
- A daily cron (`03:27`) deletes reports older than 90 days (configurable).

## Configure

Stores → Configuration → General → Personal Agent Protocol (Poppy):

| Path | Default | Effect |
|---|---|---|
| `brocode_poppy/general/enabled` | 0 | Serve `poppy.json` (and the tasks endpoint); 404 otherwise |
| `brocode_poppy/general/organization_name` | empty | Organization name override |
| `brocode_poppy/general/logo_uri` | empty | Logo URL, published only when https |
| `brocode_poppy/session/enabled` | 0 | Accept Personal Agent Sessions on `/poppy/v1/tasks` |
| `brocode_poppy/session/blocked_client_ids` | empty | One `client_id` per line, always refused |
| `brocode_poppy/session/allowed_client_ids` | empty | One per line; empty = every verified agent |
| `brocode_poppy/session/max_fetches_per_minute` | 30 | Cap on agent metadata fetches per store |
| `brocode_poppy/session/max_new_sessions_per_hour` | 1000 | Cap on new Sessions per agent |
| `brocode_poppy/feedback/enabled` | 0 | Accept feedback reports (needs Sessions on) |
| `brocode_poppy/feedback/max_reports_per_hour` | 50 | Reports per agent per hour |
| `brocode_poppy/feedback/max_reports_total_per_hour` | 500 | Reports per store view per hour, all agents |
| `brocode_poppy/feedback/retention_days` | 90 | Days a report is kept |
| `brocode_poppy/extensions/ucp` | 0 | Advertise the UCP profile (see above) |

Local testing only: agents hosted on private addresses (DDEV, the kit validator's test agent)
need a deployment setting in `app/etc/env.php`. It cannot be set from the admin and must never
be set on production:

```php
'brocode_poppy' => ['allow_private_addresses' => true],
```

## Verify

```bash
curl -s https://<host>/.well-known/poppy.json | jq .
curl -s https://<host>/poppy/v1/tasks   # {"error":"invalid_session"}
```

Then the validator from the [company kit](https://personalagentprotocol.org/kit/company):

```bash
node <kit>/validate/index.ts check https://<host> \
  --probe https://<host>/poppy/v1/tasks --probe-session
```

Result for 1.0.0 on Magento 2.4.8-p5 / PHP 8.4: exit 0, Foundation 38 passed / 0 failed, Interfaces 2 / 0,
all 24 `session.*` checks pass. Sign-in checks are skipped (no issuer by design). The feedback
endpoint added in 1.1.0 is covered by unit tests; it has not had a validator run yet.

## Limits

- No sign-in, OAuth issuer, access tokens, conversations or browser handoff.
- Storefront pages do not verify `Poppy-Session`; they stay fully cached.
- No replay cache: a feedback request replayed inside the Session JWT's acceptance window (about
  90 s) stores a duplicate report; the hourly cap bounds it.
- Sessions are shared across store views on the same domain.

## License

MIT, see [LICENSE](LICENSE).
