# Manahel Rewards 0.8.2 — Unified Hamnoor Growth Hub

- Adds a unified Hamnoor dashboard destination for points, rewards, referrals, and history.
- Canonical in-app routes use the Hamnoor base URL:
  - `/?tab=growth&section=points`
  - `/?tab=growth&section=rewards`
  - `/?tab=growth&section=invite`
  - `/?tab=growth&section=history`
- Adds a four-tab hub: «امتیازهای من | جوایز | دعوت دوستان | تاریخچه».
- Moves Referral navigation into the same hub without changing Manahel Referral storage or qualification logic.
- Adds a single «رشد و جوایز» dashboard group and logical mobile More-menu shortcuts.
- Merges Rewards + Referral teaser cards into one compact dashboard card when both plugins are active.
- Legacy standalone Rewards/Referral pages remain backward compatible.
- Financial engine, Ledger, redemption service, referral attribution, and DB schema are unchanged.
