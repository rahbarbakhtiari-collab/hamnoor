# Manahel Rewards 0.1.1

Runtime hardening after LocalWP real-environment testing.

- Expected UNIQUE-key races for event receipts, reward accounts and event/rule ledger entries are now executed with wpdb error output temporarily suppressed.
- Callers still verify the authoritative existing row after a failed INSERT; an unexpected DB failure remains an error path.
- After a concurrent UNIQUE collision, account/ledger verification uses a current locking read (`FOR UPDATE`) so MySQL/MariaDB REPEATABLE READ snapshots cannot hide the row that won the race.
- Manual-adjustment idempotency collisions now return the existing ledger entry without applying the balance delta twice.
- Duplicate reward-rule codes fail cleanly without raw `WordPress database error: Duplicate entry` output.
- No schema change; DB version remains 1.0.0.
