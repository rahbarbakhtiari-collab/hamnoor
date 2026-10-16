# Manahel Rewards 0.2.1

Phase 2 concurrency/audit hardening. DB schema remains 1.1.0.

## P0 fixed: final daily-slot concurrency

The 0.2.0 processor opened a transaction and then called `get_or_create()`, whose ordinary SELECT could establish an InnoDB REPEATABLE READ snapshot before the account row lock. A competing request could wait for the same account lock but later evaluate daily Ledger totals against that older snapshot, allowing both requests to consume the final daily slot.

0.2.1 resolves/creates the non-financial reward-account shell before opening the financial transaction. The first account read after `START TRANSACTION` is now `SELECT ... FOR UPDATE`. Limit evaluation and Ledger insertion remain inside that transaction, so requests for the same account serialize before the first consistent Ledger read is created.

No schema or Ledger semantics changed.

## P1 fixed: auditable exact receipt replay

Receipt deduplication remains the first idempotency wall and still creates no second Event or Ledger row. For a replay of an already rewarded Event, the existing Event's `decision_json` now keeps its original financial decision and adds a bounded `receipt_replay` audit annotation with `decision_code=limit.unique_entity`. Replays of an Event that never earned are annotated as `duplicate_event` instead.

The Events admin shows this replay annotation without replacing the original `awarded` decision.
