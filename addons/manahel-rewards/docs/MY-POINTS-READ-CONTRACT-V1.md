# Manahel Rewards — My Points Read Contract v1

## Scope

Read-only authenticated-user projection for Phase 3. It does not create Reward Accounts and does not mutate Events, Rules, Ledger or balances.

## Public integration

- `manahel_rewards_get_user_summary($wp_user_id = 0)` — read-only PHP API.
- `manahel_rewards_get_current_user_balance()` — current authenticated user balance.
- `[manahel_rewards_my_points]` — complete current-user UX.
- `[manahel_rewards_balance]` — balance only.

Frontend shortcodes do not accept arbitrary user/account identifiers.

## Summary shape

- `balance`: cached account projection.
- `today_earned`: positive `earn` Ledger delta on the current policy-local day.
- `policy`: canonical Quran share / Personal Quran / Dua rule status.
- `recent_ledger`: append-only financial history projected to human labels.
- `recent_no_reward`: recent zero-award Events already linked to the same Reward Account.

## Source of truth

- Financial history: Ledger.
- Fast current balance: `reward_accounts.balance`.
- Policy windows: existing Limit Contract v1 + `occurred_at` + Reward Policy Timezone.
- No derived frontend number may update financial state.

## Privacy

The frontend always resolves `get_current_user_id()` and never trusts a user/account query parameter. Personalized rendering sets `DONOTCACHEPAGE`.

## Performance target

The user summary is intentionally bounded: one account lookup, one canonical-rules lookup, grouped daily/latest rule aggregates, one today-earn aggregate, bounded recent Ledger query and bounded recent zero-reward query. No per-history-item or per-rule query loop is allowed.
