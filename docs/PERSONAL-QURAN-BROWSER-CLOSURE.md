# Personal Quran Browser Closure

This gate closes the largest remaining browser-level gap after the Phase 7 Reader closure: the owner-only **Personal Quran** journey.

## Scope

The gate is QA-only. It does **not** change Hamnoor Core, Foundation, schema, lifecycle semantics, claim quotas, or the public plugin version.

Frozen product contract:

- Hamnoor Core `4.0.0`
- Foundation `3.5.0`
- DB `4.5.0`
- one owner, one linear Quran completion
- no Parts, Claims, or public share splitting
- monotonic reading position
- pause is a server-side write barrier
- reaching global verse `6236` means `ready`, not completed
- completion requires an explicit owner confirmation

## Quran boundary fixture

The workflow reuses the exact Manahel Quran Core `0.1.0` runtime PHP implementation already used by the Phase 7.5 gate.

Only the data files are overlaid with a compact E2E boundary set:

- Mushaf page 1: Al-Fatiha `1:1..1:7`, global indices `1..7`
- a page-2 division sentinel at global index `8`, used only to close the page-1 range
- Mushaf page 604: Al-Ikhlas, Al-Falaq, and An-Nas, global indices `6222..6236`

The last-page Arabic text is authentic boundary data. Its `translation_makarem` values are intentionally marked as QA placeholders because this gate tests reading lifecycle boundaries, not translation correctness. It must never be described as a full 6236-row Quran dataset.

## Browser journey

Playwright runs against disposable WordPress + MySQL + installed Chrome and proves:

1. start Personal Quran from the real mobile dashboard form;
2. save a real verse position on page 1;
3. leave and resume at exactly the next verse;
4. pause from the real dashboard lifecycle UI and verify progress writes disappear;
5. resume without losing position;
6. open real page-604 boundary data and verify `6222..6236`;
7. save verse `6236` and observe `ready`/100% without auto-completion;
8. explicitly confirm completion;
9. verify the single-open slot is released;
10. reopen the completed record as read-only review;
11. server-side postcheck confirms completed campaign/progress at `6236` and zero Part rows.

## Safety

The fixture and postcheck refuse to run unless:

- `hamnoor_test_lab_enabled=1`;
- the site host is local/test-only;
- Core/Foundation/DB versions match the frozen contract.

Outbound HTTP is mocked during fixture setup. No production site or production database is a valid target for this workflow.
