# Plan: feedback endpoint (spec 9.3)

## Problem (one sentence)

Personal Agents can't tell the store which tasks they failed at, so a merchant has no record
of what agents can't do in the shop; spec 9.3 defines `POST {feedback.endpoint}` for that.

## Facts this plan rests on

- Spec 9.3 (kit `reference/spec.md`): body `{"task": string 1–200 chars, "description": string
  1–2000 chars}` (`schemas/feedback-report.schema.json`, both required). Response **202, no body**,
  or 200 with an alternative (not built). Malformed or too long → 400 `{"error":"invalid_request"}`.
  `invalid_session` / `session_ended` as for every Session endpoint. Rate limits follow 5.6 (429 +
  `Retry-After`).
- The Company SHOULD verify the Session header and MUST before attributing a report. Report text is
  data, never instructions ("including when a model reads it"). Agents SHOULD NOT put personal
  data in it, but may.
- `feedback.endpoint` is listed in the **tasks response**, not in `poppy.json` (changelog 0.4).
- Validator `checks/feedback.ts`: `feedback.no-session` (no header → 400 invalid_session; 202 counts
  as a fail), `feedback.accepted` (signed-out Session → 202 no body), `feedback.invalid`
  (`{"task":42}` and a 201-char task → 400 invalid_request), `feedback.alternative-references`
  (skipped without a 200). A feedback endpoint also enables `session.invalid-everywhere`.
- Magento validates a form key on every frontend POST unless the action implements
  `CsrfAwareActionInterface`.

## Decisions

| Topic | Decision | Why |
|---|---|---|
| Route | `POST /poppy/v1/feedback` in `Controller/Router`, `Controller\Api\Feedback` (`HttpPostActionInterface` + `CsrfAwareActionInterface`: no exception, `validateForCsrf` → true) | Same router as tasks; the Session JWT (`htm`/`htu`) is the request's proof, not a form key |
| Attribution | Session **required** (no unattributed reports) | Validator `feedback.no-session`; anonymous text is a spam channel |
| Shared gate | Extract the switch/Bearer/verify/touch/error mapping from `Tasks` into `Model/Session/RequestGate::open(HttpRequest): VerifiedSession` throwing a `GateResponse`-mapped exception; `Tasks` and `Feedback` both call it | Security-critical; two copies drift. Exception to the "extract at 3" rule, stated here |
| Switch | `brocode_poppy/feedback/enabled` (default 0), only effective with sessions on; off → 404 and no `feedback` in the tasks response | Opt-in like every other part |
| Validation | Body ≤ 16 KB before decoding; JSON object; `task`/`description` strings, trimmed length by `mb_strlen` within limits; unknown fields ignored; invalid UTF-8 refused | 9.3 limits are characters; cap stops large bodies before `json_decode`. 16 KB, not 8: 2000 characters sent as `\uXXXX` escapes are ≈ 12 KB |
| Storage | Table `brocode_poppy_feedback`: `entity_id`, `store_id`, `client_id` (agent), `client_hash`, `task`, `description`, `created_at`. **No `sub`/`sid`** | The agent is the attribution that matters to a merchant; leave the User ID out (data minimisation) |
| Abuse cap | Per `client_hash` per hour, config `brocode_poppy/feedback/max_reports_per_hour` (default 50) → 429 `Retry-After: 3600` | Spec 5.6; one indexed count |
| Retention | Daily cron deletes reports older than `brocode_poppy/feedback/retention_days` (default 90) | Reports may hold personal data despite the SHOULD NOT |
| Admin | Read-only grid *Reports → Poppy → Agent Feedback* (`ui_component` listing: date, store, agent, task, description), ACL `BroCode_Poppy::feedback`, mass delete | The value of the feature is the merchant seeing the list. Text columns render escaped (knockout `text`) |
| Response | `Raw` result, 202, empty body, `Cache-Control: no-store` | Spec |
| Logging | client_id + outcome code at debug; never the report text | Spec 10.2, report is untrusted |

## Steps

1. `RequestGate` extraction — `Tasks` behaviour unchanged. Tests: existing `TasksTest` stays green
   unchanged; new `RequestGateTest` pins 404/401/400/429/500→400 mapping.
2. `Model/Feedback/ReportParser::parse(string $body): Report` (+ `InvalidReportException`). Tests,
   red first: valid → Report; `{"task":42}`, missing description, 201-char task, 2001-char
   description, 200/2000 multibyte chars accepted, non-object JSON, invalid UTF-8, > 8 KB → invalid.
3. Storage: `etc/db_schema.xml` + whitelist, `Model/ResourceModel/Feedback`, `Model/Feedback/FeedbackRepository::save(Report, VerifiedSession, int $storeId)`, `countSince(client_hash, since)`; cron `CleanupFeedback`. Tests: repository count/insert via mocked connection, cron deletes by cutoff.
4. `Controller\Api\Feedback` + router + `DocumentBuilder`-independent tasks response:
   `Tasks` adds `"feedback": {"endpoint": "<secure base>poppy/v1/feedback"}` when enabled.
   Tests: `TasksTest::testListsFeedbackEndpointOnlyWhenEnabled`, `FeedbackTest` (202 empty body,
   invalid_request, 429, 404 when off, CSRF opt-out).
5. Admin: `etc/adminhtml/{routes,menu}.xml`, `acl.xml`, `system.xml` group *Agent Feedback*,
   controller `Adminhtml/Feedback/Index` + `MassDelete`, layout, `ui_component/brocode_poppy_feedback_listing.xml`, collection + grid data provider.
6. Sandbox: copy, `setup:upgrade`, `cache:flush`, curl 404/400/202, admin grid shows the report
   escaped (a `<script>` task renders as text).
7. Validator: `--probe … --probe-session` → exit 0; `feedback.no-session`, `feedback.accepted`,
   `feedback.invalid` and `session.invalid-everywhere` pass.
8. Quality gate: unit tests, PHPCS Magento2 0/0, PHPStan L8, `setup:di:compile`. README section,
   version 1.1.0.

## Out of scope

- 200 with an alternative (needs excluded-task/alternative content; next).
- `excluded_tasks` content and `skill_index`.
- Notifications (mail) on new reports.

## Execution

- 1–5 RequestGate, parser, storage, controller, admin — subagent `general-purpose` · opus, foreground — done: unit tests red then green, phpcs 0/0, PHPStan L8 in the sandbox.
- 6–7 Sandbox + validator — inline — done: curl results and validator exit 0 with the four checks above.
- 8 Quality gate + README — inline — done: commands' passing output.
- Review — subagent `general-purpose` · opus · xhigh — diff `1.0.0..HEAD`.
