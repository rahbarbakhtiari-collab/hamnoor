# Hamnoor `participation.completed` Domain Event — Contract v1

## Purpose

`participation.completed` is the single stable completion extension point for code outside Hamnoor Core.

It announces a **persisted business fact**: one participation has actually completed according to the authoritative rules of its participation strategy.

It is intentionally **not** a reward event, analytics click event, webhook, queue message, or event-store record.

External code should subscribe through the public Core API:

```php
add_action('plugins_loaded', static function () {
    if (!function_exists('hamnoor_core_api')) { return; }

    hamnoor_core_api()->on('participation.completed', static function ($payload, $context) {
        // Connector logic here. Keep the connector idempotent.
    }, 10, 'my-connector');
}, 20);
```

Using `hamnoor_core_api()->on()` is preferred over binding directly to `hamnoor/event/participation.completed`, because the Core API/EventBus wrapper isolates listener exceptions from Hamnoor runtime state.

## Emission rule

The event is emitted only after the authoritative participation state has been successfully persisted.

Current sources:

| Participation | Authoritative completion | Event entity |
|---|---|---|
| Segmented Quran share | `parts.status: claimed -> done` | `quran_part` |
| Quran `auto_done_on_claim` | winning claim transaction commits as `done` | `quran_part` |
| Personal Quran | progress reaches end and explicit completion commits | `personal_quran` |
| Dua selected for later | pending contribution transitions to valid `completion` | `contribution` |
| Direct Dua completion | new valid `completion` ledger row | `contribution` |
| Dhikr | new valid `numeric` ledger row | `contribution` |

The following do **not** emit this event:

- merely selecting a Dua for later (`dua_commitment`, pending)
- reading-position/autosave updates
- admin contribution `adjustment`
- idempotent retries that observe an already-completed state/row
- UI clicks that do not persist a completed participation

## Payload schema v1

```json
{
  "event_name": "participation.completed",
  "schema_version": 1,
  "occurred_at": "2026-08-24T12:00:00Z",
  "actor_user_id": 123,
  "subject_user_id": 123,
  "actor_public_id": "HM-00000123",
  "subject_public_id": "HM-00000123",
  "entity_type": "contribution",
  "entity_id": "CTR-...",
  "campaign_id": "CMP-...",
  "context": {
    "content_type": "dua",
    "participation_strategy": "repeatable_completion",
    "kind": "completion",
    "quantity": 1
  }
}
```

### Required semantics

- `event_name`: always `participation.completed`.
- `schema_version`: integer `1` for this contract.
- `occurred_at`: UTC ISO-8601 timestamp ending in `Z`.
- `actor_user_id`: internal Hamnoor user who caused the transition when available; may be `0` for a privileged/system path without a Hamnoor actor.
- `subject_user_id`: Hamnoor user who owns the completed participation when available; may be `0` for an unlinked/guest participation.
- `actor_public_id` / `subject_public_id`: privacy-safe Hamnoor public user IDs when available; otherwise empty strings.
- `entity_type`: one of `quran_part`, `personal_quran`, `contribution` in v1.
- `entity_id`: stable public entity ID when healthy data has one. For personal Quran, the campaign itself is the participation entity. Numeric fallback is installation-local only for a damaged/pre-backfill record.
- `campaign_id`: stable campaign public ID (`CMP-*`) in healthy data.
- `context`: minimal domain facts needed to interpret completion.

## Privacy contract

The canonical event intentionally excludes:

- phone/mobile number
- email
- IP address
- tracking/access token
- idempotency key
- `metadata_json`
- full Campaign/Part/Contribution/User database objects

Legacy 3.x-to-4.x bridge events remain for compatibility and are **not** the recommended external completion contract.

## Delivery and failure model

This v1 contract is an in-process WordPress event, not durable messaging.

- Core state is committed first.
- Listener failure cannot roll back the completed participation.
- Listeners registered through `hamnoor_core_api()->on()` are exception-isolated.
- Canonical emission itself is wrapped so even a throwing direct WordPress listener cannot make the already-persisted completion fail to the caller.
- There is no retry queue, outbox, dead-letter store, or cross-domain HTTP delivery in Core.

If a future external integration requires durable cross-domain delivery, that reliability layer belongs in a Connector/Integration plugin when the requirement is real.

## Idempotency note

Core prevents duplicate emission on the current authoritative completion paths, including a conditional `claimed -> done` write for concurrent Quran-completion requests.

A future Reward/Store/Academy connector must still implement its own idempotency. Event delivery semantics are not a substitute for a reward ledger's duplicate protection.
