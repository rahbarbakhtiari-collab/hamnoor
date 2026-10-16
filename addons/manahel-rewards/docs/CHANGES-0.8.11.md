# Manahel Rewards 0.8.11 — Shared UI Token Bridge (Phase 2A)

- Added an opt-in bridge for **Hamnoor Shared UI Token Contract v1**.
- The bridge maps Rewards `--mr-*` semantic variables to canonical `--hamnoor-*` variables only when the host explicitly opts in with `data-hamnoor-ui-contract="v1"`.
- Existing Rewards visual defaults remain active by default, so installing 0.8.11 over 0.8.10 does **not intentionally alter the current UI**.
- Added local semantic tokens for danger, touch target, control height, and radius families as migration groundwork.
- Shared-token fallbacks intentionally preserve the current 0.8.10 Rewards values in this phase; canonical visual harmonization is deferred until Hamnoor Core exposes the approved v1 tokens consistently.
- No DB, Ledger, Rule Engine, earning, Referral, redemption eligibility, fulfillment, notification, or identity logic changed.
- DB schema remains **1.3.0**.
