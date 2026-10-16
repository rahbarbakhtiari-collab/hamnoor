# Hamnoor Core Release Architecture Audit

Audit baseline: Hamnoor 4.0.0 / Architecture 3.5.0 / DB 4.5.0 / Core API 2.0.0

## 1. Objective and non-goals

The goal of this audit is to finish Hamnoor Core as a coherent product core and create only the extension point that is justified by current business facts.

Not built in this phase:

- points / wallet / reward rules
- badges / levels / streaks
- referral engine
- LearnDash integration
- WooCommerce integration
- webhooks solely for future use
- event store / outbox / message broker / queue
- global cross-system identity table
- new future-only REST mutation endpoints

The intended future boundary is:

`Hamnoor Core -> participation.completed -> Connector / Integration Layer -> Reward / Academy / Store`

Core remains authoritative for participation state and does not make reward decisions.

---

## 2. Architecture map

### Core/domain vocabulary

- `Hamnoor_Campaign_Domain`: canonical content types, participation strategies, access modes, lifecycle/review vocabulary.
- `Hamnoor_Campaign_State_Machine`: canonical lifecycle/review transition rules for new domain paths.
- `Hamnoor_Command_Contracts`: input contract boundary used by newer adapters.
- `Hamnoor_Access_Policy`: assurance/capability decisions for sensitive product actions.

### Persistence/domain services

- `Hamnoor_DB`: legacy-but-authoritative storage implementation for segmented Quran, users, participants and operational tables.
- `Hamnoor_Campaign_Repository`: canonical Campaign read/update boundary for v2 consumers.
- `Hamnoor_Campaign_Service`: canonical Campaign creation/update/lifecycle service for newer flows.
- `Hamnoor_Part_Service` / `Hamnoor_Part_Repository`: compatibility service/repository facade; completion converges on `Hamnoor_DB::complete_part()`.
- `Hamnoor_Contribution_Service`: transactional Dua/Dhikr participation ledger.
- `Hamnoor_Personal_Quran_Progress_Service`: owner-only linear Quran progress/completion.

### Extension kernel

- `Hamnoor_Event_Bus`: in-process event facade.
- `Hamnoor_Core_API`: public add-on facade; `on()` is the preferred subscription boundary.
- `Hamnoor_Legacy_Event_Bridge`: one-way compatibility bridge from established hooks to older 4.x event names.
- `Hamnoor_Domain_Events`: new privacy-minimal canonical domain-event contract emitter.

### Adapters

- Frontend templates/JavaScript/AJAX handlers
- REST v1 compatibility API
- REST v2 stable-ID read API
- Admin adapters

Adapters should validate/authorize/shape requests and call authoritative operations; they should not invent separate completion rules.

---

## 3. Real domain model

### Campaign

Persistence: `khatms`

Stable identities:

- internal integer `id` — stable inside this installation
- `campaign_public_id` (`CMP-*`) — stable external/add-on identity
- `token` — public URL/access identifier, not the cross-system identity contract

Content types:

- `quran`
- `dua`
- `dhikr`

Participation strategies:

- `segmented_claim` — public/group Quran shares
- `linear_quran_progress` — one owner-only personal Quran completion
- `repeatable_completion` — Dua
- `numeric_contribution` — Dhikr

Lifecycle:

`draft -> scheduled/active/... -> paused/completed/expired/cancelled -> archived`

The exact allowed transitions are defined by the state-machine class for canonical paths. Legacy status compatibility remains in `Hamnoor_DB::change_status()`.

Relationships:

- Campaign -> Parts/Participants for segmented Quran
- Campaign -> Personal Quran Progress for owner-only linear Quran
- Campaign -> Contributions for Dua/Dhikr
- Campaign -> owner Hamnoor User

### Hamnoor User

Persistence: `users`

Stable identities:

- internal integer `id`
- privacy-safe `public_id` (`HM-*`)
- `wp_user_id` is a bridge to WordPress identity where linked

Phone is authentication/contact data and is **not** treated as a durable cross-system identity.

No global identity mapping was added. A future cross-site mapping belongs outside Core.

### Participant

Persistence: `participants`

Purpose: segmented Quran campaign participation identity/claim grouping.

Stable identities:

- internal integer `id`
- `public_id` (`PAR-*`)

May link to a Hamnoor user; guest participation is supported.

### Quran Part

Persistence: `parts`

Stable identities:

- integer `id`
- `public_id` (`PRT-*`)

States:

- `available`
- `claimed`
- `done`

Real transitions:

- available -> claimed
- available -> done when campaign uses `auto_done_on_claim`
- claimed -> done
- claimed -> available on release

A done share cannot be released.

### Personal Quran Progress

Persistence: `personal_quran_progress`

Identity: one row per personal-Quran campaign; the campaign public ID is the stable participation identity for external events.

Reading states:

- `not_started`
- `reading`
- `ready`
- `completed`

Position is monotonic. Completion requires the canonical end of Quran and explicit completion.

### Contribution

Persistence: `contributions`

Stable identities:

- integer `id`
- `public_id` (`CTR-*`)

Dua states/facts:

- `dua_commitment`, `pending`, quantity `0` — selected for later, not complete
- `completion`, `valid`, quantity `1` — one completed recitation

Dhikr:

- `numeric`, `valid`, positive quantity — one accepted numeric participation record

Admin correction:

- `adjustment` — ledger correction; not a participant completion event

---

## 4. Authoritative business operations

### Segmented Quran completion

Authoritative implementation: `Hamnoor_DB::complete_part()`.

REST, frontend AJAX, reading UI and service/repository facades converge on this implementation. The class name is legacy, but the business rule itself is centralized; moving callers merely to make the architecture look cleaner would add risk without business value.

This audit hardened the transition with an atomic conditional write:

`WHERE id = ? AND status = 'claimed'`

A simultaneous loser reloads the row and returns idempotent success if the winner already wrote `done`; it does not emit a duplicate canonical completion event.

### Personal Quran completion

Authoritative implementation: `Hamnoor_Personal_Quran_Progress_Service::complete()`.

It locks the campaign/progress records, requires owner access, requires end-of-Quran progress, commits both progress and Campaign completion, and treats already-completed retries idempotently.

### Dua completion

Authoritative implementations within one service:

- pending -> completed: `Hamnoor_Contribution_Service::complete_pending_dua()`
- direct completed recitation: `Hamnoor_Contribution_Service::record()` with repeatable strategy / `completion`

Both are transactionally persisted and idempotent according to their existing contracts.

### Dhikr accepted participation

Authoritative implementation: `Hamnoor_Contribution_Service::record()` with numeric strategy / `numeric`.

It serializes on the Campaign row, checks strategy/lifecycle/owner policy/idempotency/target and content maximum, clips to the remaining target, then persists one valid ledger row.

### Campaign creation

Newer content-aware paths use `Hamnoor_Campaign_Service::create()` after command validation. Some legacy Quran/REST-v1 paths still reach older creation services/DB adapters.

This is real architectural duplication, but no evidence currently shows divergent completion/reward semantics and changing all creation routes is not required for the future completion extension point. It remains a bounded compatibility debt rather than a release-blocking refactor in this phase.

---

## 5. Completion definition

| Strategy | Completed when | Authorized subject | Reversible? | Timestamp/state |
|---|---|---|---|---|
| Segmented Quran | claimed share successfully transitions to `done`, or winning claim is configured auto-done | tracking-token holder, linked participant, Campaign manager/allowed verifier | no normal done->claimed transition | `done_at`, status `done` |
| Personal Quran | owner has reached Quran end and explicit completion commits | Campaign owner only | no normal completed->reading transition | `completed_at_utc`, reading status `completed`, Campaign completed |
| Dua pending | caller's pending commitment transactionally becomes valid `completion` quantity 1 | contribution owner | no normal reversal; admin adjustment is separate accounting | `occurred_at_utc` updated on completion |
| Dua direct | a new valid repeatable `completion` row is committed | authenticated/created Hamnoor user allowed by Campaign policy | immutable fact; adjustment is separate | `occurred_at_utc` |
| Dhikr | a new valid numeric row with accepted positive quantity is committed | authenticated/created Hamnoor user allowed by Campaign policy | immutable fact; adjustment is separate | `occurred_at_utc` |

The canonical extension fact is now `participation.completed`, emitted only after these persisted transitions.

---

## 6. Stable extension point added

One new domain event was added:

`participation.completed`

Why exactly one:

- it is a current real business fact across all participation strategies;
- future Reward/Academy/Store connectors need a precise completion fact;
- adding speculative campaign/referral/reward events now would create hook explosion and premature contracts.

Contract details are in `docs/PARTICIPATION-COMPLETED-EVENT-V1.md`.

Legacy `khatm.*`, `part.*`, participant and notification bridge events remain unchanged for compatibility and are not promoted as the canonical future completion contract.

---

## 7. Changes made by this audit

1. Added `Hamnoor_Domain_Events` as one internal canonical emitter.
2. Added schema-versioned, privacy-minimal `participation.completed` payload v1.
3. Emitted the event after successful completion for:
   - segmented Quran normal completion
   - Quran auto-done-on-claim
   - personal Quran completion
   - pending Dua completion
   - direct Dua completion
   - Dhikr numeric participation
4. Explicitly excluded pending Dua selection and admin adjustments.
5. Hardened concurrent Quran completion so two simultaneous completion requests cannot both publish completion facts.
6. Fixed a real contribution frontend correctness defect where `$numeric` was read before assignment when selecting the success notification/message path.
7. Added this architecture audit and the event contract documentation.
8. Added a separate test-only probe plugin outside Hamnoor Core to prove an independent plugin can subscribe through `hamnoor_core_api()`.

---

## 8. Changes deliberately NOT made

- No new DB table or column.
- No migration or DB version bump.
- No points/wallet/reward data.
- No LearnDash or WooCommerce dependency.
- No HTTP call from completion logic to another system.
- No event store/outbox/queue/retry subsystem.
- No global identity mapping.
- No speculative REST mutation API.
- No broad rewrite of `Hamnoor_DB` into repositories merely for naming/style.
- No campaign-creation rewrite solely to eliminate legacy adapters.
- No replacement of current numeric IDs with UUIDs.
- No mass event-hook expansion.

---

## 9. Security audit summary

Static review of the primary mutation paths found no obvious P0 SQL injection, CSRF, IDOR or privilege-escalation defect in the audited completion paths.

Existing controls observed include:

- nonce checks in form/AJAX adapters;
- ownership/capability/access-policy checks on sensitive actions;
- prepared SQL for caller-controlled query values;
- tracking-token verification for scoped Quran completion;
- owner-only enforcement for personal Quran;
- Campaign policy/lifecycle enforcement for contribution writes;
- transactional locking on concurrency-sensitive Campaign/contribution/personal-Quran paths.

The new canonical event does not contain phone, email, IP, tracking tokens, metadata JSON or database object snapshots.

### Compatibility privacy debt — P2

The older `Hamnoor_Legacy_Event_Bridge` can pass broad legacy `$data` or object payloads on some non-canonical compatibility events. Changing these shapes now could break legacy listeners. New connectors must use the documented canonical event rather than `on_any()` over legacy traffic. A future deliberate deprecation/sanitization pass can narrow those legacy payloads if/when compatibility policy permits.

This static review is not a penetration-test certification.

---

## 10. Performance audit summary

### Real issue observed — P2

Campaign listing is bounded but still contains an N+1 progress-query pattern: the list retrieves Campaign rows, then progress is resolved per card/Campaign. Dua/Dhikr canonical progress can add per-Campaign aggregate work as well.

Why not refactored here:

- listing is currently bounded (not an unbounded table walk);
- no production load evidence was provided;
- batching all participation strategies correctly is a cross-cutting read-model change, unrelated to the minimal completion extension point;
- introducing a new cache/index/batch abstraction solely on hypothetical future scale would violate the requested minimalism.

Before high-volume production traffic, profile real list latency/query count and then introduce one measured batch read model if needed.

No unbounded user-activity history query was introduced by this work; contribution history already caps `per_page` and other core reads have explicit limits in the reviewed paths.

---

## 11. Time and timezone audit

Newer contribution/personal-Quran persistence uses UTC fields (`*_utc` / `gmdate`). Legacy Campaign/Part operational timestamps mostly use WordPress site-local MySQL time.

The canonical event contract avoids propagating this ambiguity: `occurred_at` and EventBus `emitted_at` for `participation.completed` are UTC ISO-8601 (`...Z`).

### Remaining compatibility debt — P2

Do not migrate legacy timestamps merely for architectural neatness. If a future API requires strict temporal comparison across all entities, define one conversion boundary and migrate only with a real consumer/test case.

---

## 12. Gap classification

### P0 — must be fixed before release

**Fixed in this audit**

- Contribution frontend used `$numeric` before assignment in the post-write notification branch. Depending on PHP error handling this could raise a notice and choose the wrong Dua-vs-Dhikr notification semantics. Assignment now occurs before use.
- Segmented Quran completion could race: two requests could both read `claimed`, both update the same row and both publish completion side effects. The authoritative write is now conditional on `status='claimed'`; the concurrent loser observes `done` and returns idempotently without emitting the new canonical event.

**Known outside this architecture audit**

- The current frontend baseline has a separately observed live AJAX card-rendering defect after Quran claim/completion that normalizes only after refresh. This audit does not claim that UI defect is closed. It must remain on the release blocker list until a real WordPress/browser run passes.

### P1 — should be done before release

**Fixed in this audit**

- No single completion extension contract covered Quran, personal Quran, Dua and Dhikr. `participation.completed` v1 now provides that contract after successful persistence, with stable IDs/minimal payload and listener-failure containment.

**Verification still required in a real WordPress test environment**

- Install the separate probe plugin, complete each participation type, verify one log event per real state transition, retry completion, disable the probe, and confirm Hamnoor remains unchanged.

### P2 — safe after release / when evidence justifies it

- Batch Campaign progress reads if profiling confirms list N+1 matters at real scale.
- Deliberately deprecate/sanitize broad payloads of old legacy EventBus bridge events.
- Converge all legacy Campaign creation adapters on `Hamnoor_Campaign_Service` only if tests reveal divergent business rules or maintenance cost.
- Rationalize legacy site-local vs UTC timestamp storage when a real cross-system time consumer requires it.
- Consider a canonical `campaign.completed` contract only when an external consumer genuinely needs Campaign-completion facts independently of participation completion.

### P3 — future ideas; do not build now

- Reward engine / points / wallet / redemption
- LearnDash connector
- WooCommerce/store connector
- referral system
- badges/levels/streaks
- global user identity mapping
- webhooks / durable event outbox / queue / retry / dead-letter handling
- speculative external APIs for future services

---

## 13. Independent extension acceptance test

A separate plugin artifact, `hamnoor-participation-event-probe.zip`, subscribes only through:

`hamnoor_core_api()->on('participation.completed', ...)`

It does not modify Hamnoor Core and does not query internal tables.

Expected acceptance procedure in a real WordPress environment:

1. Install/activate Hamnoor.
2. Install/activate the probe plugin.
3. Complete a segmented Quran share -> exactly one event log.
4. Retry completion -> no second completion event.
5. Complete personal Quran -> exactly one event log.
6. Complete a pending Dua -> exactly one event log.
7. Record a direct Dua completion -> exactly one event log.
8. Record Dhikr -> exactly one event with actual accepted quantity.
9. Perform admin contribution adjustment -> no `participation.completed` event.
10. Disable the probe plugin.
11. Repeat Hamnoor completion operations -> Hamnoor works normally; no dependency on probe.

---

## 14. Final architecture questions

1. **Does Completion have a central, reliable definition?**  
   Yes, per actual participation strategy; the extension contract unifies the post-persistence fact without forcing all strategies into one storage model.

2. **Do all Completion paths pass through shared authoritative logic?**  
   Yes for the completion rules that matter: segmented Quran converges on `Hamnoor_DB::complete_part`; personal Quran on its progress service; Dua/Dhikr on the contribution service. Adapters do not need one giant universal function because the strategies have genuinely different state models.

3. **Can an independent plugin receive Completion without changing Core?**  
   Yes. Use `hamnoor_core_api()->on('participation.completed', ...)`. A separate probe plugin has been built for real-environment verification.

4. **Does Core depend on Reward?**  
   No.

5. **Does Core depend on LearnDash?**  
   No.

6. **Does Core depend on WooCommerce?**  
   No.

7. **Are primary IDs stable?**  
   Yes. Internal numeric IDs are stable inside the installation and Campaign/Part/Participant/Contribution/User public IDs already exist for public/add-on contracts. No UUID retrofit is needed.

8. **Are Event payloads stable and minimal?**  
   The new canonical completion contract is schema-versioned v1 and deliberately minimal/PII-free. Legacy compatibility events are not promoted as this contract.

9. **Is the Event published after successful state change?**  
   Yes for every canonical completion source added here. Transactional paths emit after COMMIT; segmented normal completion emits only after the atomic successful state update.

10. **Was an unnecessary API or Event Store built?**  
    No.

11. **Were schema changes limited to real need?**  
    No schema change was needed or made.

12. **Do all current capabilities work without regression?**  
    Static/source/contract checks for this architecture change pass, but full release readiness still requires the existing real WordPress/browser suites and closure of the known AJAX card-rendering defect. Therefore this audit does not claim a final end-to-end release PASS by itself.

---

## 15. Final principle

Hamnoor Core now exposes the one current fact future integrations actually need: a valid, persisted participation completion.

Future systems should attach outside Core. If durable delivery, cross-site identity mapping or reward accounting later becomes a real requirement, it can be implemented in a connector/integration layer without moving the completion rules out of Hamnoor or rewriting its participation state models.
