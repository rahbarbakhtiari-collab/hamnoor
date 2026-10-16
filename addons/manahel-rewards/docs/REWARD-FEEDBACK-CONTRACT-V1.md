# Manahel Rewards — Reward Feedback Contract v1

## Scope

Immediate, non-financial UX feedback for a Hamnoor completion whose Rewards decision has already been committed in the same PHP request.

## Data flow

1. Hamnoor commits completion.
2. Hamnoor emits `participation.completed`.
3. Manahel Rewards receives/processes the event synchronously.
4. Hamnoor requests optional completion feedback for the emitted entity.
5. Manahel Rewards returns a namespaced response envelope.
6. Hamnoor browser code dispatches `hamnoor:completion-feedback`.
7. `reward-feedback.js` renders an accessible toast.

## Safety

- No Rewards failure may invalidate Hamnoor completion.
- No new Event/Ledger/Balance write is performed by the feedback layer.
- Duplicate receipts are not announced.
- Dhikr and no-matching-rule outcomes are silent in Reward Feedback v1.
- Daily limit and cooldown outcomes are explanatory, not errors.
- Client toast deduplication uses a request result feedback id.

## `manahel_rewards` feedback shape

- `version`: 1
- `feedback_id`: opaque UI dedupe id
- `announce`: 1 when UI should announce
- `status`: `awarded` or `limited`
- `tone`: `success` or `info`
- `points`: integer delta awarded by this event
- `balance`: exact post-award cached balance when available
- `decision_code`: stable internal decision code
- `title`: localized user-facing title
- `message`: localized user-facing explanation

## Visible v1 outcomes

- awarded: `+X امتیاز`
- `limit.daily_awards`
- `limit.daily_points`
- `limit.cooldown`
- `limit.unique_entity` (supported by contract; normal Hamnoor replay does not emit a new event)

## Silent outcomes

- `policy_excluded:dhikr`
- `no_matching_rule`
- unresolved identity
- processor failure
- duplicate receipt
