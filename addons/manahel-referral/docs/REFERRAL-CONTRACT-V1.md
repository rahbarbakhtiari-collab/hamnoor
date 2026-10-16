# Manahel Referral Contract v1

## Scope

Version 1 implements only `Invite → Attribute → Qualify`. It does not award points itself and does not store clicks, IP addresses, device fingerprints, phone numbers or email addresses.

## First-touch capture

- Public query argument: `mref`.
- Server-only cookie: `manahel_referral_first_touch`.
- Cookie is `HttpOnly`, `SameSite=Lax`, secure on HTTPS and expires after 30 days.
- A valid existing first-touch cookie is not replaced by a later invite link.
- A click/open alone creates no attribution and no reward.

## Attribution

Attribution is persisted only after Hamnoor emits `identity.created` for a genuinely new identity whose WordPress bridge is available in the same request.

Hard rules:

- one referred WordPress user → at most one attribution;
- self-referral is rejected;
- existing Hamnoor users are not retroactively attributed by clicking a link;
- once attribution exists, a later code cannot replace the inviter;
- no reward decision is made at attribution time.

## Qualification

A persisted attribution is eligible for 30 days. The first server-confirmed Hamnoor `participation.completed` event qualifies it when it is:

- Quran `quran_part` completion;
- Quran `personal_quran` completion; or
- Dua `contribution` completion.

Dhikr/numeric participation does not qualify a referral in v1.

Qualification uses a transaction and a row lock. Concurrent qualifying events can transition one attribution from `attributed` to `qualified` only once.

## Domain event

After the qualification transaction commits, the plugin persists and emits:

`referral.qualified` schema version `1`.

Payload:

```json
{
  "event_name": "referral.qualified",
  "schema_version": 1,
  "occurred_at": "2026-08-25T07:10:00Z",
  "entity_type": "referral",
  "entity_id": "MRF-...",
  "inviter_wp_user_id": 10,
  "referred_wp_user_id": 22,
  "context": {
    "qualification_source": "hamnoor",
    "qualification_event": "participation.completed",
    "qualification_entity_type": "quran_part",
    "qualification_entity_id": "PRT-...",
    "content_type": "quran"
  }
}
```

The event is local to WordPress. It contains no contact data, IP, cookies or Hamnoor session tokens.

## Durable replay

Qualified events are written to `manahel_referral_events` before publication. Consumers may call:

`manahel_referral_get_qualified_events_after($after_id, $limit)`

This prevents a downstream plugin being disabled at the instant of qualification from permanently losing the fact.

## Non-goals

No multi-level referral, affiliate commission, click analytics, campaign attribution analytics, device/IP anti-fraud, leaderboard, prizes, WooCommerce or LearnDash integration is included in v1.
