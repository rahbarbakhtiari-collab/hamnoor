# Manahel Rewards 0.7.1 — Growth Reward Notification Bridge

- Adds an optional post-commit bridge from high-value growth rewards into Hamnoor's existing notification store/dispatcher.
- External notification defaults are intentionally limited to:
  - `MANAHEL_REFERRAL_QUALIFIED_INVITER`
  - `MANAHEL_REFERRAL_CAMPAIGN_PARTICIPANT`
- Personal completion rewards (Quran share, personal Quran, Dua) and the small referred-user welcome reward do not generate external messages.
- Reuses Hamnoor Notifications routing, consent, fallback and retry behavior; no second transport system is introduced.
- Notification creation happens only after the Rewards financial transaction commits.
- Event-id based deduplication prevents a bridge replay from creating a second in-app notification.
- No DB schema migration. Rewards DB remains `1.1.0`.
