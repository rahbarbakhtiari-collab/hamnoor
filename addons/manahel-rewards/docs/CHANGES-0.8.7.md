# Manahel Rewards 0.8.8

- Hardens the redemption notification bridge so it resolves the authoritative Hamnoor identity from `hamnoor_users.wp_user_id` when `_hamnoor_user_id` user meta is missing or stale.
- Repairs the mirrored `_hamnoor_user_id` meta when a valid authoritative link is found.
- Records the latest redemption-notification bridge failure in a non-autoload option instead of silently dropping it.
- Makes the post-redemption success copy truthful: reward delivery is guaranteed independently; external messenger delivery is attempted only when a connected channel is eligible.
- No database schema migration. Rewards schema remains 1.3.0.
