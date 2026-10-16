# UX/UI Phase 7 — Reading Screen & Completion Confirmation

Status: implemented on top of the existing Reading/Completion contract.

## Foundation boundary

This phase changes the participant reading experience only. It does **not** add a database table, domain status, claim mutation, completion endpoint, permission rule, OTP rule, quota bypass, migration or destructive workflow.

The existing `reading_progress` record remains the source of truth for the last reading position. The existing claimed part remains claimed until the participant explicitly confirms completion. `ready` is a Reading/UI state; it is not a new part lifecycle state.

## Unified flow

`Start / Continue -> Reader -> automatic position save -> end reached -> Ready to complete -> explicit completion -> inline success / next action`

### Start and continue

- A never-started share shows `شروع قرائت`.
- A started share shows `ادامه از محل ذخیره‌شده`.
- Resume returns to the last saved verse itself so the participant gets reading context instead of being dropped after it.
- The resume verse gets a temporary visual cue: `از اینجا ادامه دهید`.

### Automatic reading position

For ordinary campaign shares, per-verse `تا اینجا خواندم` buttons are removed from the reader. JavaScript observes the reading band and persists the furthest meaningful verse through the existing `hamnoor_save_reading_progress` action. Saves remain monotonic and use the existing offline queue.

This automatic behavior is scoped to `data-reading-mode="part"`. The Personal Quran reader retains its current manual-save contract in this phase.

### Ready to complete

Reaching the end never calls completion. After the final reading position is persisted, the inline completion surface appears:

- `آماده تکمیل`
- `قرائت تمام شد`
- `بعداً ادامه می‌دهم`

The share remains claimed until the participant explicitly presses `قرائت تمام شد`.

### Completion

Completion still goes through `Hamnoor_Reading::ajax_finish_reading()` and the existing canonical `Hamnoor_DB::complete_part()` path. Double submission is disabled while the request is running. Failure leaves the participant on the same reader and allows retry.

Successful completion no longer forces an automatic redirect for ordinary campaign shares. The same inline surface becomes a success state and offers:

1. the next already-claimed share when one exists;
2. otherwise `انتخاب سهم بعدی` through the normal campaign page;
3. `بازگشت به پویش` / `سهم‌های من` as contextual secondary routes.

No share is auto-claimed after completion.

### Review mode

A `done` share opens as `مرور سهم`. It shows `تکمیل‌شده ✓`, has no completion mutation control, and re-reading cannot record another completion.

## Reading screen information architecture

The ordinary share reader is now a focused reading canvas with five zones:

1. compact Reader Header;
2. share-specific progress;
3. Quran content;
4. minimal reading/settings controls;
5. inline completion area.

The sticky header condenses while scrolling down and expands when the user scrolls back up. Mobile uses the same hierarchy with a compact `Aa` settings trigger and bottom-sheet settings. Desktop is constrained to an approximately 900px single-column reading canvas instead of a dashboard-like wide layout.

## Error and offline behavior

- Reading-position failure queues the latest position locally and does not interrupt reading.
- Offline queued progress must synchronize before completion can be submitted.
- Completion failure never marks the UI completed optimistically.
- Audio progress remains independent from text reading progress and never completes a share.

## Automated acceptance contract

The Phase 7 focused regression suite freezes these invariants:

- public plugin version stays `4.0.0`;
- Foundation architecture and current DB version stay unchanged from the Phase 6 baseline;
- ordinary share reader has no manual per-verse save buttons;
- Personal Quran keeps its existing manual save buttons;
- end detection saves progress only and never invokes completion;
- inline completion requires explicit `data-reading-finish` interaction;
- completion uses the existing canonical completion call;
- successful ordinary-share completion stays inline instead of auto-redirecting;
- review mode contains no completion control;
- post-completion next action never auto-claims a share;
- mobile/desktop Phase 7 layout markers are present.
