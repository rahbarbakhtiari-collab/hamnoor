# Manahel Rewards 0.8.5 — Auto Fulfillment for Preloaded Codes

- Added transactional one-time code inventory (`manahel_reward_codes`).
- `fulfillment_mode=code` now means automatic fulfillment from preloaded inventory.
- Redemption, points debit, stock reservation, and code assignment occur in one InnoDB transaction.
- If no code is available, redemption fails before any points are debited.
- Auto-fulfilled code rewards never enter manager-pending state.
- The issued code is exposed in the user's redemption history and included in the normal Hamnoor fulfillment notification.
- Replay with the same idempotency key returns the same redemption/code and never double-debits.
- Refunding an already-issued code returns points but never recycles the code to inventory.
- Manual and physical rewards keep the existing pending/admin-fulfillment workflow.
