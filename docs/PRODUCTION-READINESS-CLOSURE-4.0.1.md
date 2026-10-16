# Hamnoor 4.0.1 — Production Readiness Closure

This patch is based byte-for-byte on the user-supplied `hamnoor(6).zip` baseline before the modifications below.

## Scope

1. **Contribution schedule enforcement**
   - New Dua commitments, direct Dua completions, and numeric Dhikr contributions are rejected before `starts_at` and after `ends_at` inside the existing campaign transaction/row lock.
   - Cron expiry is no longer part of the correctness boundary.
   - Idempotent retries return their already-persisted result and do not create new work after the window closes.
   - Existing pending Dua commitments use the same completion-grace semantics as Quran shares: `completion_grace_days = NULL` means no additional deadline, `0` means `ends_at`, positive values add site-local calendar days.
   - Pending Dua read state remains available while an `expired` campaign is still inside its valid completion window.

2. **Quran AJAX mobile card closure**
   - Claim/complete AJAX continues to return the canonical `single` card context.
   - Mobile part-grid/card alignment opts out of CSS Grid stretch, eliminating the observed large empty vertical band on AJAX-replaced share cards.

## Versioning

- Plugin: `4.0.1`
- Architecture: `3.5.0` unchanged
- Core API: `2.0.0` unchanged
- DB schema: `4.5.0` unchanged
- No migration.

## Deliberately not changed

- `Tested up to: 7.0` remains unchanged until a real WordPress 7.1 runtime smoke passes.
- Legacy migration batch thresholds are unchanged; they remain a deployment preflight only for installations whose legacy row counts exceed the existing bounds.

## Cumulative verified extension contracts preserved

This release candidate also preserves the previously Work-verified generic Hamnoor extension contracts from the cumulative Phase 6 Hamnoor build:

- dashboard summary extension (`hamnoor_dashboard_summary_extension`)
- completion feedback extension (`hamnoor_completion_extension_feedback` / browser completion feedback bridge)
- privacy-minimal `identity.created` fact after successful WP user bridging

These remain generic Core extension points; Hamnoor has no direct dependency on Rewards or Referral.

## Production packaging

The standalone Phase 6 source-test script is intentionally not shipped in the production candidate. Its contract is re-run externally during build verification; runtime plugin code is unchanged by removing that non-production test file.
