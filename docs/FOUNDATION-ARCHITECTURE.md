# Hamnoor Foundation Architecture 3.5

This document is the persistence/domain contract for Hamnoor 4.0.0 foundation work.
Visual UX may change without changing these boundaries.

## Canonical campaign axes

- `content_type`: `quran | dua | dhikr`
- `participation_strategy`: `segmented_claim | linear_quran_progress | repeatable_completion | numeric_contribution`
- `access_mode`: `public | unlisted | owner_only`
- `participation_policy`: `anyone | owner_only`
- lifecycle and review are independent state fields.
- A personal campaign is an access/participation policy, not a content type.

## Stable identities

External contracts use generated public IDs (`CMP-`, `PRT-`, `PAR-`, `CNT-`, `CTR-`, `CEV-`).
Internal MySQL integer IDs are repository implementation details and must not become new public API identifiers.

## Content and ledgers

- Dua/Dhikr content is stored as a live, admin-managed `content_items` registry.
- Campaigns reference a content item and always render its current payload.
- Contributions and claim quota history are append-only ledgers.
- Ordinary cleanup must never delete contribution or claim-event ledgers.
- Derived progress may be cached later; ledger rows remain the source of truth.

## Identity and assurance

- Every Hamnoor identity is linked to a low-privilege `WP_User` (`hamnoor_member`).
- L0: normal Hamnoor session; dashboard/claim/read/complete and private Personal Quran reminder preferences.
- L1: trusted session; campaign create/edit.
- L2: recent OTP; name/mobile identity changes.
- A Hamnoor session never creates a WordPress authentication cookie.

## Mutations

New REST/AJAX/UI adapters must call `Hamnoor_Command_Contracts` and canonical domain services. They must not write campaign/content/contribution tables directly.
Public REST v2 is intentionally read-only in the foundation release; this avoids freezing speculative transport details while the mutation command contract is already versioned internally.

## Migrations and rollback

- Schema upgrades are additive.
- `Hamnoor_Migrations` is ordered, locked and idempotent.
- SQL work is separated from post-commit WordPress API work.
- The DB version marker is written only after schema and foundation-data health checks pass.
- A pre-upgrade settings snapshot is retained for rollback/support.
- Durable ledger rows are never rewritten merely to roll code back.

## Extension rule

New feature modules should depend on Core service IDs (`campaigns`, `campaign_repository`, `command_contracts`, `content_repository`, `contributions`, `participation_strategies`, `access_policy`, `reminder_schedules`) rather than `Hamnoor_DB`.
The current Quran UI remains a compatibility adapter until it is migrated incrementally.


## Live content decision
Dua/Dhikr content is a live admin-managed ContentItem. Campaigns reference only `content_item_id`; they do not pin content revisions. Editorial corrections update the same item. Generic admin audit logs may record changes, but no product-level content version entity is required.

## Personal Quran backend
The first personal campaign capability is Quran-only and represents exactly one complete Quran from beginning to end. It uses `linear_quran_progress + owner_only + owner_only participation + review_not_required`. It creates no Parts, has no Claim operation, and exposes no user-selectable Quran division. The owner has one monotonic reading position in `personal_quran_progress`; public discovery and sharing are disabled.

Personal Quran is a low-risk private reading activity, not public Campaign creation. `personal_quran_create` and owner lifecycle controls use L0; ordinary public/unlisted Campaign create/edit remains L1. A user may have at most one open personal Quran completion (`active` or `paused`) at a time. Creation serializes on the Hamnoor owner row inside the SQL transaction before checking/inserting, so concurrent create requests cannot create two open completions. Terminal completions (`completed`, `cancelled`, etc.) do not occupy the slot.


## Personal Quran product adapter (Architecture 3.4)

Personal Quran now has a dedicated frontend/product adapter and is deliberately absent from ordinary Campaign lists. It remains the same canonical `linear_quran_progress` campaign record internally; no parallel persistence model was introduced. The dedicated reader loads one Quran page window at a time only as a presentation/performance concern. Page navigation never creates Parts, Claims, or user-selectable completion units.

- Start is an idempotent L0 action: an existing open personal Quran resumes; otherwise the canonical `personal_quran_create` command creates the single open completion.
- The command owns the fixed product title and ignores user-supplied title/division configuration.
- Dashboard campaign queries exclude `linear_quran_progress`; a dedicated personal-Quran card exposes start/resume and L0 pause/resume/cancel controls.
- Reading progress can be mutated only while lifecycle is `active`; `paused` is a real write barrier. Completion remains possible only through `Hamnoor_Personal_Quran_Progress_Service` after Quran end.
- Reader/AJAX adapters call canonical services and never write progress/campaign tables directly.
- Stable domain hooks (`hamnoor_personal_quran_created`, `hamnoor_personal_quran_progress_saved`, `hamnoor_personal_quran_lifecycle_changed`, `hamnoor_personal_quran_completed`) remain the integration boundary for reminder and future private-reading modules.

## Reminder scheduling boundary (Architecture 3.5)

Reminder preferences are not reading-progress facts. They live in the generic `reminder_schedules` table and are accessed through the Core service ID `reminder_schedules` (`Hamnoor_Reminder_Schedule_Service`). The first supported scope is `personal_quran`, with one reminder rule per user/scope pair. No reminder row is created or enabled by migration: the user must explicitly opt in.

- A rule stores user-local clock time, an IANA timezone, selected ISO weekdays, snooze state, next due UTC, last occurrence identity and delivery counters. Reading progress remains unchanged.
- `paused` suspends `next_due_at_utc` while preserving the user's enabled preference. Returning to `active` recomputes the next local occurrence. `completed`, `cancelled` and `archived` disable the rule for that completion.
- Scheduler execution has a 15-minute WP-Cron fallback and may also be invoked by the CLI scheduler worker. Due rows are claimed under `SELECT ... FOR UPDATE`; an occurrence key derived from schedule identity plus due time prevents duplicate notification creation across concurrent runners.
- The scheduler advances/claims an occurrence before notification dispatch. If internal notification insertion fails, the claimed occurrence is released for retry. Failure to derive optional progress copy degrades to a generic reminder message rather than silently consuming the occurrence.
- Reminder notifications enter the existing Hamnoor notification center as `personal_quran_reminder` with `related_type=personal_quran`; connected external channels continue to use the existing notification delivery engine rather than a parallel sender.
- Snooze is bounded and changes only reminder scheduling; it never mutates Quran progress or lifecycle.
