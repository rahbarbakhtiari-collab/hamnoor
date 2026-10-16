# Dua / Dhikr Contribution Journey

This stage connects the existing Foundation 3.5 contribution-ledger domain to the real public Hamnoor web journey.

## Frozen architecture

- Hamnoor Core `4.0.0`
- Foundation `3.5.0`
- DB `4.5.0`
- no schema or migration change
- no Parts or Claims for Dua/Dhikr campaigns
- no REST v2 public mutation

## Canonical mutation path

Public web UI -> `Hamnoor_Command_Contracts::contribution()` -> `Hamnoor_Contribution_Service::record()`.

The frontend adapter does not write SQL and does not reimplement target, idempotency, max-contribution, remaining-target, or lifecycle completion rules.

## Identity

Ordinary participation remains low assurance. The public adapter reuses the established claim identity boundary:

`resolve_claim_identity()` -> `ensure_and_login_for_claim()`.

A guest may enter name and mobile, receive a normal Hamnoor L0 session, and participate without OTP or a WordPress authentication session. Sensitive account/campaign actions remain outside this journey.

## Dua

- strategy: repeatable
- one successful submission = one complete recitation
- canonical ledger always records quantity `1`
- campaign completes when the contribution sum reaches the configured target

## Dhikr

- strategy: numeric
- user submits a positive quantity
- `max_contribution` is enforced by the canonical ledger
- final contribution is capped again by the remaining campaign target
- campaign completes automatically when the target is reached

## Browser closure evidence

Disposable WordPress + MySQL + Chrome must prove:

1. guest Dua contribution creates only a low-assurance Hamnoor session
2. two sequential Dua submissions store `1 + 1` and complete target `2`
3. idempotency key rotates between distinct successful submissions
4. Dhikr request `4` with max contribution `3` stores exactly `3`
5. next Dhikr request `9` with only `2` remaining stores exactly `2`
6. Dhikr completes at exactly `5/5`
7. both campaigns contain zero Part rows
8. no WordPress logged-in cookie is created by ordinary participation
