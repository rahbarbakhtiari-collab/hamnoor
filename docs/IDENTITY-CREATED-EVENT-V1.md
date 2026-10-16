# Hamnoor `identity.created` Event Contract v1

## Purpose

`identity.created` is a privacy-minimal domain fact emitted only after a **new** Hamnoor user has been persisted and the existing Hamnoor → WordPress user bridge has completed successfully.

It is intended for optional local add-ons that need a reliable new-identity boundary (for example referral attribution). Hamnoor does not make referral, reward, academy, store, or marketing decisions.

## Event name

`identity.created`

## Schema version

`1`

## Payload

```json
{
  "event_name": "identity.created",
  "schema_version": 1,
  "occurred_at": "2026-08-25T07:00:00Z",
  "subject_user_id": 123,
  "subject_public_id": "HM-00000123",
  "context": {
    "identity_type": "hamnoor_user"
  }
}
```

## Guarantees

- Emitted only for the request that actually creates the new Hamnoor identity.
- Existing-user lookup/login does not emit it.
- Emitted after the WordPress bridge succeeds.
- Listener failure cannot invalidate the already-created identity.
- No phone, email, IP address, session token, tracking token, user-agent, or profile data is emitted.
- No referral code is interpreted by Hamnoor.

## Non-goals

This event does not mean a referral is valid, a user is verified for rewards, or an award should be granted. Those decisions belong to independent add-ons.
