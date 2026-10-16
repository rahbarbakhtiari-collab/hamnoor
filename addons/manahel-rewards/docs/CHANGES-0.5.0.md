# Manahel Rewards 0.5.0

- Added optional Hamnoor immediate Reward Feedback integration.
- Added request-local event processor result cache in the Hamnoor adapter.
- Event processor now returns exact post-award balance in its in-memory result; financial persistence is unchanged.
- Added accessible reward/limit toast UI and live dashboard balance/today update.
- Duplicate receipt feedback is suppressed.
- Dhikr remains hard-excluded and does not show positive reward feedback.
- DB schema remains 1.1.0; no migration, table or column change.
