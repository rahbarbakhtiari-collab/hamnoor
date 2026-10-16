# Manahel Referral 0.2.1 — Verified Identity Qualification Gate

- Referral attribution remains low-friction and unchanged.
- `referral.qualified` is now fail-closed until the referred Hamnoor identity has a verified mobile.
- Verification is delegated to Hamnoor (`Hamnoor_Mobile_Access::is_verified()`), so OTP and authoritative trusted messenger channels use the same central trust policy.
- An unverified completion leaves the attribution pending; a later verified completion inside the 30-day window may qualify it.
- Qualified event context records `identity_verified=1` and `identity_gate=hamnoor_mobile_verified_v1` for durable auditability.
- No database migration.
