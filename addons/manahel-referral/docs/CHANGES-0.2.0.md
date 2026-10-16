# Manahel Referral 0.2.0 — Referral UX

- Adds `[manahel_referral_my_referrals]` for current-user Referral UX.
- Adds read-only inviter summary: successful, pending, expired and recent referrals.
- Adds Web Share API with clipboard fallback; Share/Copy never award points.
- Adds optional dashboard card through the existing generic `hamnoor_dashboard_summary_extension` hook; Hamnoor Core is unchanged.
- Adds mixed WordPress/Hamnoor-session identity handling via `mref_context=hamnoor` without carrying user/account IDs.
- Adds admin selection for the published “My Referrals” page.
- Adds optional financial projection contract `manahel_referral_financial_summary`; Referral never queries Rewards storage.
- Keeps Referral DB schema at 1.0.0; no table or column migration.
