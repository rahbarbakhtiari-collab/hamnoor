# Manahel Rewards 0.3.0 — My Points UX

Phase 3 adds a read-only, mobile-first user experience without changing the financial schema or write path.

## Added

- `Manahel_Rewards_User_Summary_Service`: read-only user projection for balance, today earnings, canonical policy status and recent Ledger history.
- `[manahel_rewards_my_points]`: complete personal points dashboard.
- `[manahel_rewards_balance]`: compact current-user balance output.
- Mobile-first frontend stylesheet with no external dependency.
- Human-readable policy state for Quran share, Personal Quran and Dua.
- Optional recent no-reward explanations for events already linked to the user's Reward Account.
- Read API functions `manahel_rewards_get_user_summary()` and `manahel_rewards_get_current_user_balance()`.

## Security / privacy

- Shortcodes always resolve the current authenticated WordPress user; no arbitrary account/user attribute is accepted.
- Viewing the page never creates a Reward Account.
- Personalized shortcode rendering sets `DONOTCACHEPAGE` to reduce accidental full-page cache leakage.

## Deliberately not added

- No DB migration; schema remains 1.1.0.
- No WooCommerce, LearnDash, Referral, Badge, Level, Leaderboard or prize shop.
- No public REST endpoint and no frontend mutation/AJAX endpoint.
