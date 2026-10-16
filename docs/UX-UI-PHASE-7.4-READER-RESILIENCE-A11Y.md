# UX/UI Phase 7.4 — Reader Resilience, Accessibility & Browser E2E

Baseline: Hamnoor 4.0.0 / Foundation 3.5.0 / DB 4.5.0.

## Boundary

Phase 7.4 is presentation/runtime hardening only. It adds no database table, migration, lifecycle state, claim mutation, completion mutation, quota bypass or alternate Reading/Completion contract. Server-confirmed Reading Position and the existing canonical Completion mutation remain authoritative.

## Resilience states

The Quran text stays readable while a background state probe is slow or fails. A delayed status surface appears only after 450 ms to avoid loading flicker. Probe failure exposes a non-blocking Retry action; it does not invent a new state or write to the database. Offline mode is explicit, preserves the local queue, and Completion still waits for server-confirmed progress.

BFCache restore closes transient dialogs, restores responsive measurements, flushes queued Reading Position when possible, and reconciles the server state. Audio that was playing before pagehide is intentionally left paused after BFCache restore rather than autoplaying without fresh user intent.

## Accessibility contract

- A keyboard-visible skip link moves focus to the reading content region.
- The Reader has an accessible heading/region relationship.
- Settings and any legacy confirmation dialog trap Tab focus while open and restore focus to their trigger on close.
- Status announcements are scoped to atomic live regions rather than the whole toolbar.
- Unavailable state is assertively announced without creating a second mutation path. External completion is announced without stealing keyboard focus; focus moves to the success state only after a completion initiated by the user in the current Reader.
- Audio play state uses `aria-pressed`; previous/next boundaries use real disabled state; the timeline is a keyboard-operable slider supporting Arrow, Home and End keys.
- `prefers-reduced-motion` disables nonessential Reader motion and scripted smooth scrolling.
- forced-colors mode keeps important controls and status boundaries visible.

## Audio/state contract

Audio position is still independent from Reading Position. Audio completion never completes a share. If a share becomes unavailable elsewhere, active audio is stopped, the source is released and audio controls are disabled. A server-confirmed completed share may still be reviewed with audio because Review Mode is read-only.

## Browser E2E matrix

Browser validation covers mobile (390×844) and desktop (1440×900) with no horizontal overflow, keyboard settings/focus behavior, offline/online status, Retry after state-probe failure, keyboard audio timeline, BFCache restore behavior, and unchanged inline Completion semantics.
