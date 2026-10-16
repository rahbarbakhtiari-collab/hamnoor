# Hamnoor 4.0.2 — Production Readiness RC2

## Scope

This RC2 is a bounded release-hardening patch over the audited 4.0.1 candidate. It does not redesign the campaign engine and does not change the database schema.

## Release metadata

- Plugin version: `4.0.2`
- Architecture version: `3.5.0`
- Core API version: `2.0.0`
- DB version: `4.5.0`
- WordPress release target: `7.1`
- PHP release-test target: `7.4` through `8.3`

## Bounded fixes

1. Reading progress persistence is now monotonic inside one atomic SQL `UPDATE` using `GREATEST(...)`; concurrent stale requests cannot overwrite a newer persisted position with an older one.
2. Mutable reading state for claimed parts now reuses `Hamnoor_DB::khatm_accepts_completion()` so cancelled, archived, not-yet-started, or grace-expired campaigns cannot continue accepting progress changes. Completed shares remain reviewable.
3. `Hamnoor_Contribution_Service::complete_pending_dua()` now requires exactly one successful `pending -> valid` database transition before commit or domain events.
4. Pending-Dua reminder scheduling now checks the schedule-row update before commit and releases the occurrence if internal notification creation fails, matching the retry-safe behavior of Personal Quran reminders.

## Required runtime certification before production

This artifact is an RC, not a production certification. Run activation/deactivation, WordPress 7.1, PHP 7.4–8.3, migration, concurrency, REST/AJAX security, cron, dependency, and desktop/mobile browser tests in the disposable Local/Work environment before Go-Live.
