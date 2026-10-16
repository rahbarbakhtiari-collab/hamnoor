# Manahel Referral — UX Read Contract v1

## Scope
Read-only inviter projection for Phase 7. It does not change attribution, qualification, Referral events, Reward Ledger or balances.

## Public UI
- `[manahel_referral_invite]`: invite-link surface.
- `[manahel_referral_my_referrals]`: complete current-user Referral UX.
- `manahel_referral_get_user_summary()` and `manahel_referral_get_compact_user_summary()`.
- `manahel_referral_get_my_referrals_url()`.

## Identity
Normal WordPress pages prefer the authenticated WordPress user. A link from the authenticated Hamnoor dashboard may add `mref_context=hamnoor`; the server then resolves the valid Hamnoor session and its existing WordPress bridge. No user/account identifier is placed in the URL.

## Status projection
- `qualified` → successful.
- `attributed` with `qualifies_until >= now` → pending.
- stored `expired`, or an attributed row whose deadline already passed → expired in the read model.

Read-time expiry classification does not mutate domain state.

## Privacy
No referred-user name, mobile, email, WordPress ID or Hamnoor ID is exposed in user-facing history. Recent items contain only status and timestamps required for the inviter’s own UX.

## Optional financial projection
Referral applies `manahel_referral_financial_summary`. A downstream rewards system may return actual awarded inviter points. Referral itself never reads reward tables and never calculates points by multiplying successful invites.

## Sharing
Web Share API is preferred; clipboard is fallback. Neither copying nor sharing is a rewardable action.
