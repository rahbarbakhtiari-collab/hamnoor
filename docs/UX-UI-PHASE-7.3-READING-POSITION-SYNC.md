# UX/UI Phase 7.3 — Reading Position, Cross-tab Sync & Reconciliation

Status: implemented on the existing Reading/Completion foundation.

## Boundary

Phase 7.3 adds no database table, migration, lifecycle state, claim mutation, completion mutation or quota bypass. The server-side `reading_progress` record remains the source of truth. The browser queue is provisional resilience state only.

A new read-only AJAX state probe (`hamnoor_reading_state`) exposes the current accessible reader state so an already-open reader can reconcile with changes made in another tab or device. It does not mutate a part.

## Reconciliation contract

1. Server-confirmed reading position is authoritative.
2. Reading position is monotonic: neither a local queue nor another tab can move it backward.
3. A local position ahead of the server is shown as provisional (`sync_pending`) and cannot enable Completion until the server confirms it.
4. When connectivity returns, the highest queued position is flushed first and then the reader refreshes server state.
5. Completion remains explicit and canonical; cross-tab/device synchronization never completes a share.

## Multiple tabs

The reader uses `BroadcastChannel` when available and a localStorage event fallback. Only server-confirmed events are broadcast as authoritative:

- `progress`
- `completed`
- `unavailable`

A tab receiving newer confirmed progress advances its UI without scrolling the user away from their current place. If another tab completes the share, the current tab becomes completed/review state and disables further completion.

The offline progress queue itself is shared through localStorage. Queue writes preserve the highest position instead of allowing a stale tab to overwrite newer queued progress.

## Multiple devices / external changes

Because browser messaging cannot cross devices, the reader performs the read-only state probe:

- on initial load;
- when the browser returns online;
- when the window receives focus;
- when the document becomes visible;
- when a Back/Forward Cache page is restored;
- periodically while visible (45 seconds).

If the share was completed elsewhere, the reader changes to completed/review state. If it is no longer readable (for example released or otherwise invalidated), the reader stops automatic position tracking and presents a contextual return action.

## Offline-ready boundary

Offline reading may advance the local provisional position, including to the end of the share. This does not expose an active Completion action. The UI remains `sync_pending` until the queued position is accepted by the server. Completion can only run after the server confirms `ready`.

## Personal Quran isolation

Cross-tab/device reconciliation in this phase is scoped to `data-reading-mode="part"`. The Personal Quran reader retains its existing contract and is not moved onto the campaign-share state probe.

## Acceptance cases

- Two tabs open the same share; progress saved in tab A advances tab B without a reload.
- A stale tab cannot overwrite a newer queued position with a lower one.
- Offline end-of-share shows sync pending, not canonical ready-to-complete.
- Returning online flushes the queue and only then enables Completion when the server confirms the final position.
- Completion in another tab/device transitions the open reader to completed/review state without a second completion mutation.
- Release/invalidation elsewhere transitions the open reader to unavailable and stops automatic progress writes.
- Refresh resumes from the highest reconciled position without moving server state backward.
