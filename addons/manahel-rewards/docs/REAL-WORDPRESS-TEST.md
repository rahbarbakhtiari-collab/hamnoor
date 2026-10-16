# Real WordPress acceptance test — Manahel Rewards v0.1.0

Use the hardened Hamnoor Core that emits `participation.completed`.

1. Activate Manahel Rewards.
2. Open **امتیاز مناهل → نمای کلی**.
3. Confirm Storage = PASS/InnoDB and Hamnoor listener = Registered.
4. Before creating rules, complete one Quran participation and one Dua participation. Both should appear under Events as `ignored / no_matching_rule`, with no Ledger entry.
5. Complete a Dhikr participation. It must appear as `ignored / policy_excluded:dhikr`, with no Ledger entry and no balance change.
6. Create a Quran rule with an explicit test point value and matching entity type.
7. Complete a new Quran participation. Exactly one event and one ledger entry should appear and balance should increase exactly once.
8. Repeat/replay the same completion if technically possible. It must not create a second ledger entry.
9. Create a Dua rule and complete a new Dua participation. Verify exactly one ledger entry.
10. Use Manual Adjustment and verify a separate `adjustment` ledger row is created; historical earning rows must not be edited.
11. Rebuild the account balance and verify it remains equal to the ledger sum.
12. Disable Manahel Rewards and verify Hamnoor completion continues to work normally.

Do not test WooCommerce, LearnDash, Referral, Badge, Level or prize redemption in this phase; they are intentionally absent.
