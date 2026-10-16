# Hamnoor 4.0.5 — Internal notification allowlist hardening

## Problem
`Hamnoor_DB::claim_part()` and `Hamnoor_DB::complete_part()` already emitted internal notifications with types `part_claimed` and `part_done`. However `Hamnoor_DB::add_notification()` rejects any type absent from `important_notification_types()`. Those two types were missing from the allowlist, so both notification writes were silently discarded.

## Fix
Add `part_claimed` and `part_done` to `important_notification_types()`.

## External delivery policy
No change. `Hamnoor_Notification_Dispatcher::should_dispatch()` in Notifications Suite does not include these two high-frequency types in the default external delivery allowlist, so they remain dashboard/internal notifications unless an integrator explicitly extends the external filter.

## Versioning
- Plugin: 4.0.5
- Architecture: unchanged
- Core API: unchanged
- DB schema: 4.5.0 unchanged
- Migration: none
