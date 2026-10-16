# Manahel Rewards 0.6.2 — Economic-Value Identity Gate & Atomic Balance Rebuild

- Adds a centralized fail-closed identity gate for all automatic value from Hamnoor `participation.completed` and Manahel Referral `referral.qualified`.
- Enabled earning rules mint points only when the recipient maps to a currently verified Hamnoor mobile identity.
- Low-assurance participation remains unchanged; unverified rewardable events are durably recorded as `ignored / identity_unverified` and are not retroactively awarded after later verification.
- Referral inviter and referred rewards are independently gated, so an unverified inviter cannot receive economic value even when the referred user is legitimate.
- `rebuild_balance()` is now an InnoDB transaction that locks the account row before reading Ledger, matching the award/manual-adjustment lock order and removing the stale-SUM overwrite race.
- No database migration; existing rules and Ledger data are unchanged.
