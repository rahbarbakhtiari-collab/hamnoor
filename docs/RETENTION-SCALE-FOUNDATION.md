# Hamnoor Retention & Scale Foundation

Status: Foundation contract, destructive maintenance disabled by default.

## Goals

- Keep the database searchable as participation volume grows.
- Preserve durable product truth while allowing high-volume operational detail to be summarized and eventually deleted.
- Make every destructive operation bounded, resumable, idempotent and explicitly gated.
- Ensure large-table retention decisions are validated in GitHub Actions before any production activation.

## Data classes

### Durable — never deleted by retention

- `users`
- `khatms`
- `content_items`
- `migrations`
- `retention_rollups`

These are source-of-truth records or the durable statistical projection required after detail cleanup.

### Active state — not automatically purged

- `personal_quran_progress`
- `reading_progress`
- `reminder_schedules`
- `notification_channels`
- `push_subscriptions`
- `rubika_links`

These tables participate directly in current user state, access, reading continuity or channel configuration.

### Ephemeral — bounded automatic housekeeping is allowed

- `sessions`: 30 days after expiry/revocation
- `otps`: 7 days after expiry/consumption

Automatic housekeeping is deliberately limited to these two datasets. It never deletes logs or campaign ledgers.

### Archivable — explicit maintenance only

- `notifications`: read rows older than 180 days
- `notification_deliveries`: terminal rows older than 90 days
- `logs`: older than 180 days, campaign rollup required for campaign-ledger cleanup
- `claim_events`: older than 365 days, terminal campaign + fresh rollup required
- `contributions`: older than 365 days, terminal campaign + fresh rollup required
- `parts`: plan-only in this foundation
- `participants`: plan-only in this foundation

`parts` and `participants` remain queryable because the current dashboard/reporting layer still reads them directly. Their future physical deletion requires a tombstone/summary projection first.

## Safety model

Destructive maintenance is disabled unless **both** are true:

1. WordPress option `hamnoor_retention_destructive_enabled` equals `1`.
2. Filter `hamnoor_retention_destructive_enabled` returns `true`.

This two-key gate prevents accidental activation by a single setting change.

Normal plugin upgrade/runtime housekeeping bypasses neither guard for archivable data. It may only delete bounded batches of expired sessions and OTPs.

## Campaign rollups

`hamnoor_retention_rollups` stores an idempotent `campaign_summary_v1` projection per campaign with:

- campaign lifecycle and participation strategy;
- part counts by current state;
- participant count;
- claim-event counts and quota totals by event type;
- contribution counts and quantity totals by kind;
- source maximum IDs for parts, participants, claim events, contributions and logs.

The source maximum IDs act as watermarks. If any source table receives a newer row after the rollup, the rollup becomes stale and ledger deletion is refused until the rollup is rebuilt.

## Batching and resumability

All delete batches are capped at 500 rows. A batch selects exact IDs first and then deletes only those IDs. Re-running a batch is safe and continues from remaining rows.

Campaign-ledger cleanup additionally requires:

- terminal campaign state;
- retention age threshold reached;
- fresh campaign rollup;
- row ID not newer than the stored rollup watermark;
- two-key destructive gate for execution.

Dry-run does not require the destructive gate.

## Deliberate non-goals

This foundation does **not** introduce sharding, database separation, table partitioning, external analytics storage or automatic campaign-detail deletion. Those are later optimizations and should be considered only after the indexed/batched retention path is measured under realistic load.

## CI gate

The Retention & Scale gate must verify at minimum:

- retention schema exists;
- destructive mode is off by default;
- dry-run never deletes data;
- dual guard blocks destructive execution;
- ephemeral cleanup is bounded and resumable;
- campaign rollup is idempotent and watermark-aware;
- post-rollup writes invalidate purge readiness;
- campaign ledgers cannot purge without terminal/age/rollup gates;
- `parts` and `participants` remain plan-only;
- large synthetic ledgers are handled in bounded batches using the intended indexes.
