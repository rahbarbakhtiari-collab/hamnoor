# Manahel Rewards — Referral Adapter v1

## Ownership boundary

Manahel Referral owns invite codes, first-touch attribution, qualification and the durable `referral.qualified` fact. Manahel Rewards owns only point policy, idempotency, limits, Ledger and balances.

Rewards never reads referral cookies and never decides whether a referral is legitimate.

## Consumed event

Source: `manahel_referral`  
Event: `referral.qualified`  
Schema: `1`

One qualified referral is normalized into two recipient-specific Reward facts:

- `entity_type=referral_inviter` → inviter account.
- `entity_type=referral_referred` → newly referred account.

Both use the same stable Referral entity id, but the entity type is part of the Rewards dedupe key, so the two legitimate awards remain distinct and each is idempotent.

## Default templates

Templates are seeded **disabled** on version upgrade:

- `MANAHEL_REFERRAL_QUALIFIED_INVITER`: +20, unique per referral, max 5/day, max 100 points/day, max 20/month, max 400 points/month.
- `MANAHEL_REFERRAL_QUALIFIED_REFERRED`: +10, unique per referral.

No upgrade automatically turns point distribution on.

## Durable reconciliation

Manahel Referral persists qualified events before publishing them. Rewards keeps a local event-store cursor and runs a bounded hourly reconciliation. Live delivery is immediate; reconciliation is the recovery path when Rewards was disabled or unavailable at qualification time.

The cursor advances only after both inviter and referred facts have reached a terminal `processed`/`ignored`/deduplicated state. Failed Reward receipts are retried through the existing Reward Event Processor.

## Limit Contract v2

Referral introduces backward-compatible monthly calendar caps:

- `max_awards_per_month`
- `max_points_per_month`

Monthly windows use the existing Reward Policy Timezone. Existing v1 rules retain their original behavior and require no database migration.
