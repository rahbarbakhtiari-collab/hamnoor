# Manahel Referral 0.3.0 — Participation First

- Removes OTP/mobile-verification as a requirement for referral qualification.
- A new-user referral qualifies after first-touch attribution plus a real qualifying Quran/Dua completion.
- Adds campaign-share attribution: personalized Hamnoor share URLs carry `mref` + `mrc`; an existing user who completes the shared campaign can create one `referral.campaign_participant` fact for that inviter/campaign/user.
- New-user global referral has precedence over the lower-value campaign-attraction event on the same first completion (no double-pay).
- Click/share alone never qualifies. Self-referral remains blocked.
- No database schema change.
