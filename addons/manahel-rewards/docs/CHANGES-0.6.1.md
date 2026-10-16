# Manahel Rewards 0.6.1 — Referral UX Read Integration

- Adds a read-only listener for `manahel_referral_financial_summary`.
- Returns actual inviter Referral earnings from Rewards Ledger rather than inferred points.
- Counts only `source=manahel_referral`, `event=referral.qualified`, `entity_type=referral_inviter`, positive `earn` entries.
- Can map recent Referral public IDs to actual awarded points for user history.
- Does not query Referral tables and performs no financial writes.
- DB schema remains 1.1.0 with no migration.
