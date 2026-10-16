# Manahel Rewards 0.4.0 — Hamnoor Dashboard Integration

- Keeps DB schema at `1.1.0`; no migration, table or column changes.
- Adds an optional listener for Hamnoor's generic `hamnoor_dashboard_summary_extension` hook.
- Renders a compact read-only card with current balance, today's earned points and optional My Points link.
- Adds `get_compact_for_wp_user()` so the embedded card does not execute full history/policy queries.
- Adds a configurable published WordPress page for the `[manahel_rewards_my_points]` destination.
- Adds viewer resolution through the authenticated Hamnoor custom session when no WordPress auth cookie exists; the server-resolved Hamnoor user must already have a valid `wp_user_id` bridge.
- Keeps generic WordPress-user behavior unchanged when a normal WordPress login exists.
- Dashboard/My Points reads never create Reward Accounts or Ledger rows.
- Adds cache protection for personalized output.
- No WooCommerce, LearnDash, Referral, Badge, Level, Leaderboard or redemption features are introduced.
