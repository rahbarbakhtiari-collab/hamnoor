# Manahel Rewards 0.2.0

- DB schema 1.1.0: adds `reward_rules.limits_json` and `reward_events.decision_json` only.
- Adds Reward Policy / Limit Contract v1.
- Adds account-serialized limit evaluation for unique entity, daily award count, daily points, and cooldown.
- Adds policy timezone setting for local calendar-day limits while preserving UTC database timestamps.
- Adds explainable Event decisions in wp-admin.
- Seeds disabled Quran-part (+10), Personal-Quran (+50), and Dua (+3) policy templates.
- Keeps Dhikr as a hard pre-Rule exclusion with zero points.
- Existing v0.1.x rules remain behavior-compatible with `limits_json = NULL`.
- No new table, queue, WooCommerce, LearnDash, referral, badge, level, or remote API.
