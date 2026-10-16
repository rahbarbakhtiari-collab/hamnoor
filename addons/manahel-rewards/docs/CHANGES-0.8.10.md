# Manahel Rewards 0.8.10 — Critical UI Accessibility Hardening

- Raised user-facing microcopy in Rewards/Growth surfaces from 9–11px to a readable 12px baseline.
- Raised primary interactive targets under 44px to at least 44px, including catalog filters, growth CTAs, dashboard CTA, toast close and redemption dialog close.
- Replaced low-contrast #98a2b3 microcopy with the established --mr-muted token.
- Added keyboard focus trap to the redemption dialog.
- Restores focus to the trigger after cancel/backdrop/Escape close.
- Added focus-visible treatment for key Rewards controls.
- No database, Ledger, Rule Engine, redemption eligibility, Referral or Hamnoor Core logic changed.
- DB schema remains 1.3.0.
