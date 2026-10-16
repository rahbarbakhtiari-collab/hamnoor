# Manahel Rewards 0.6.0

- Adds an optional Manahel Referral adapter for durable `referral.qualified` facts.
- Seeds disabled inviter (+20) and referred-user (+10) Reward templates.
- Adds Reward Limit Contract v2 monthly award/point caps without a DB migration.
- Adds bounded referral reconciliation for qualifications emitted while Rewards was unavailable.
- Extends Retry to referral-source Reward receipts.
- Adds human Ledger labels for referral earnings.
- Existing Hamnoor Quran/Dua/Dhikr policy and Phase 1–5 contracts remain unchanged.
- DB schema remains 1.1.0.
