# Manahel Rewards — Reward Policy & Limit Contract v1

Plugin target: 0.2.0  
DB schema target: 1.1.0

## Scope

This contract adds reward eligibility limits and explainable decisions to the verified 0.1.1 Core Foundation without adding a new table, queue, store integration, LearnDash integration, referral system, badges, levels, or a remote API.

## Schema delta

`reward_rules.limits_json` and `reward_events.decision_json` are the only schema additions. Existing rules migrate with `limits_json = NULL`, which means no additional v1 policy limit. Existing events keep `decision_json = NULL`.

## Rule limits v1

```json
{
  "version": 1,
  "unique_per_entity": true,
  "max_awards_per_day": 6,
  "max_points_per_day": 60,
  "cooldown_seconds": 0
}
```

All limits are scoped to `reward_account + rule`.

- `unique_per_entity`: one successful Ledger earning for the same entity under the same rule.
- `max_awards_per_day`: maximum successful Ledger earn rows for the rule/account in the policy-local calendar day.
- `max_points_per_day`: maximum sum of positive Ledger earn deltas for the rule/account in the policy-local calendar day. Partial awards are not issued.
- `cooldown_seconds`: minimum temporal distance between successful awards under the same rule/account.

The reward account row is locked before limit evaluation and remains locked until Ledger insert, balance update, Event decision update, and COMMIT. This serializes competing awards for the same account.

## Policy timezone

Database timestamps remain UTC. Daily limits use the calendar day in `manahel_rewards_policy_timezone`, initialized from the WordPress site timezone. The stored value must be a valid `DateTimeZone` identifier/offset.

Daily constraints count all successful awards in the same policy-local day, regardless of delivery order. Cooldown compares the current Event time with the nearest successful award before or after it, so delayed/out-of-order delivery cannot violate the cooldown invariant.

## Hard exclusion

Hamnoor `participation.completed` events with `context.content_type = dhikr` are audited but are stopped before Rule matching:

`policy_excluded:dhikr` → zero Ledger entries → zero balance change.

This remains true even if an administrator creates a matching Dhikr rule outside the normal UI.

## Default policy templates

Upgrade seeds these rules only when their codes do not already exist. They are always seeded **disabled** so upgrading cannot unexpectedly start awarding points.

| Code | Entity | Points | Limits |
|---|---|---:|---|
| `HAMNOOR_QURAN_PART_COMPLETED` | `quran_part` | 10 | unique/entity; 6 awards/day; 60 points/day |
| `HAMNOOR_PERSONAL_QURAN_COMPLETED` | `personal_quran` | 50 | unique/entity; 1 award/day; 50 points/day; 604800s cooldown |
| `HAMNOOR_DUA_COMPLETED` | `contribution` | 3 | unique/entity; 3 awards/day; 9 points/day |

## Decision snapshot v1

Every newly evaluated Event stores a non-financial explanation in `decision_json`. Ledger remains the source of truth for money-like point history.

Representative decision codes:

- `awarded`
- `no_matching_rule`
- `policy_excluded:dhikr`
- `limit.unique_entity`
- `limit.cooldown`
- `limit.daily_awards`
- `limit.daily_points`
- `idempotent_existing_award`
- `account_resolution_failed`
- `processing_failed`

An Event may be `processed` with zero points when a matching rule is limited. If at least one matching rule awards points, the top-level result is `awarded`; per-rule decisions still preserve any limited rules.

## Non-retroactivity

Changing a rule or its limits does not rewrite historical Ledger rows. Old Event decisions are not recalculated automatically. Existing v0.1.x rules with `limits_json = NULL` retain their previous unlimited behavior until explicitly replaced/disabled/configured.

## Reward truth

- Ledger is the source of truth.
- Balance is a rebuildable cache.
- Event decisions are explainability/audit metadata, not financial truth.
