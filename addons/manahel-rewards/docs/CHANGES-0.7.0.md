# Manahel Rewards 0.7.0 — Growth First / Earn First

- Earning no longer requires OTP/mobile verification. A resolved local user can earn points immediately.
- Keeps a future `redemption_requires_verification()` policy seam so verification can be required when value leaves the system, not while users participate.
- Growth-first default weights (only untouched old defaults auto-migrate):
  - New user successfully attracted: inviter +40.
  - Existing user activated through a personalized campaign share: inviter +10.
  - Referred-user welcome: +5.
  - Quran share completion: +2.
  - Dua completion: +1.
  - Personal Quran completion: +5.
- Click/share alone remains non-rewardable; Referral publishes only real participation outcomes.
- Adds durable `referral.campaign_participant` adapter + reconciliation.
- Existing administrator-customized rules are never overwritten.
- Rules remain disabled unless the administrator enables them.
- No DB schema migration.
