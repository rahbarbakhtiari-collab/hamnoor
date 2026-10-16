# Manahel Rewards 0.6.3 — Safe Rule Editing UI

## Product
- Existing reward rules can now be edited from **امتیاز مناهل → قوانین امتیاز**.
- Editable policy fields: title, points, enabled state, unique-per-entity, daily/monthly award caps, daily/monthly point caps, cooldown, priority, policy start/end.
- Technical integration identity remains locked: `code`, `source`, `event_name`, `entity_type`, and `conditions_json` are not changed by the edit flow.
- Policy dates are entered/displayed in the configured Rewards policy timezone and stored in UTC.
- The admin UI states that historical Ledger entries are immutable; later first-processing/retry/reconciliation uses the then-current rule policy.

## Safety
- Dedicated capability check + per-rule nonce.
- Stale-form conflict detection using `updated_at` prevents silent lost updates across admin tabs.
- Impossible point caps (non-zero cap lower than one award) and invalid time windows are rejected.
- No DB schema migration. DB version remains `1.1.0`.
- Existing seeded rules are never overwritten on upgrade, so prior administrator customizations are preserved.
