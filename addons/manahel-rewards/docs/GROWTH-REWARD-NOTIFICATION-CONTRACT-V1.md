# Growth Reward Notification Contract v1

## Goal
Tell a user when a high-value viral-growth reward was actually committed, without spamming them for routine personal activity.

## External-by-default rewards
- Successful new-user activation reward for the inviter.
- Successful existing-participant activation through a shared campaign.

## Internal-only / immediate UX rewards
- Quran part completion.
- Personal Quran completion.
- Dua completion.
- Referred-user welcome reward.

## Delivery model
Rewards does not talk directly to Eitaa/Bale/Rubika/Web Push. It creates one semantic Hamnoor notification after Ledger commit. Hamnoor Notifications then applies its existing consent, channel priority, retry and fallback policy.

## Financial isolation
The `manahel_rewards/earning_committed` action is emitted only after COMMIT. Side-effect exceptions are isolated and cannot alter Ledger or balance.
