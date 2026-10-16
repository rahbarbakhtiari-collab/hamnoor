# Hamnoor 4.0.3 — Production Readiness RC3

## Scope

This release candidate is a bounded closure for the WordPress 7.1 localization timing Notice observed during RC2 certification.

## Changes

1. `Hamnoor_I18n::init()` is registered on WordPress `init` priority 0 rather than being called directly during `plugins_loaded`.
2. The custom 15-minute cron schedule does not invoke `__()` when WordPress requests schedules before `after_setup_theme`/`init`; the display label remains translatable once localization is safe.

## Intentionally unchanged

- Architecture: `3.5.0`
- Core API: `2.0.0`
- DB version: `4.5.0`
- Database schema and migrations
- Campaign, claim, reading, contribution, reminder business rules
- REST/AJAX contracts
- Notifications provider architecture

## Required certification

Run this exact artifact on WordPress 7.1 + PHP 8.3 and confirm that a fresh activation/normal request produces no Hamnoor `_load_textdomain_just_in_time` Notice, then continue the remaining RC2 blocked concurrency, security, browser, and product suites.
