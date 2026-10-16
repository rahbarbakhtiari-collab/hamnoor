# Manahel Rewards — Core Foundation v0.1.0

## Scope

This release implements only the reward core:

- Reward accounts with rebuildable balance cache
- Canonical event receipts
- Structured exact-match rule engine
- Append-only ledger
- Idempotency at event and event+rule layers
- Hamnoor `participation.completed` adapter
- Admin views for rules, events, ledger, accounts and manual adjustments
- Hard reward-policy exclusion for Dhikr

It deliberately does **not** implement WooCommerce, LearnDash, Referral, Badge, Level, Streak, prize fulfillment, remote REST connectors, queues, outbox, public reward APIs or cross-domain identity mapping.

## Tables

1. `wp_manahel_reward_accounts`
2. `wp_manahel_reward_events`
3. `wp_manahel_reward_rules`
4. `wp_manahel_reward_ledger`

The actual prefix follows the current WordPress `$wpdb->prefix`.

## Source of truth

The ledger is the source of truth. `accounts.balance` is a rebuildable projection/cache and can be recalculated from `SUM(ledger.delta)`.

Ledger rows are append-only. Corrections are new ledger entries, not edits to historical entries.

## Hamnoor contract

The plugin subscribes through:

`hamnoor_core_api()->on('participation.completed', ...)`

Hamnoor remains authoritative for completion. Manahel Rewards never changes Hamnoor completion state and does not call Hamnoor during reward persistence except read-only identity resolution.

## Identity

Hamnoor `subject_user_id` is a Hamnoor-domain identity, not assumed to equal the WordPress user ID. The adapter resolves the already-linked WordPress user via Hamnoor's identity record or the `_hamnoor_user_id` compatibility meta. Rewards never creates or relinks that identity.

Cross-site identity mapping is intentionally deferred until Academy/Store integration becomes real.

## Idempotency

Layer 1:

`UNIQUE(events.dedupe_key)` where the key is SHA-256 of:

`source|event_name|entity_type|entity_id`

Layer 2:

`UNIQUE(ledger.event_id, ledger.rule_id)`

This ensures duplicate event delivery and rule re-processing do not mint duplicate points.

## Dhikr policy

Dhikr completion is still captured as a domain event for audit, but it is intercepted before rule evaluation:

`status=ignored`, `error_code=policy_excluded:dhikr`, no ledger entry, no balance change.

This is a product policy in v0.1.0 and cannot be bypassed by adding a rule.

## Transaction model

The durable event receipt is inserted first so failed processing remains visible/retryable. Reward mutation then runs transactionally on InnoDB:

- resolve/create account
- lock account row
- evaluate rules
- append ledger entries
- update balance cache
- mark event processed
- commit

On failure, reward mutations roll back and the receipt is marked `failed` outside the rollback so an admin can retry it.

## Rule model

v0.1 rules use exact-match structured conditions only. There is no `eval`, callable, arbitrary SQL, regex engine or scripting language.

Allowed paths:

- `entity_type`
- `campaign_id`
- `context.content_type`
- `context.participation_strategy`
- `context.kind`
- `context.quantity`

Automatic reward rule points must be positive integers. Negative values are reserved for auditable manual adjustment/reversal/redemption-style ledger entries.

## Failure boundary

Hamnoor commits completion before emitting its domain event. The Hamnoor Core API isolates Reward listener failures, so a Rewards failure cannot invalidate an already-completed Hamnoor participation.
