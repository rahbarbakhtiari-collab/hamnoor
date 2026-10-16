# Manahel Referral 0.1.0

- Independent referral plugin with three InnoDB tables: codes, attributions, immutable qualification events.
- First-touch `mref` capture without persistent click tracking.
- Attribution only on Hamnoor `identity.created` for a new identity.
- Hard self-referral and one-inviter-per-referred-user protections.
- 30-day qualification window.
- Qualification only from verified Quran/Dua completion facts; Dhikr excluded.
- Transactional one-time qualification under concurrency.
- Durable `referral.qualified` event log and local WordPress action.
- Minimal `[manahel_referral_invite]` shortcode.
- Read-only admin audit pages.
