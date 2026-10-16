# Hamnoor 4.0.0-alpha6 — Physical Rubika separation

Rubika is now a separately installable first-party add-on. Core exposes only
the notification-channel contracts, admin extension points, diagnostics filter
and dashboard data filter.

Without the Rubika add-on, its admin tab and dashboard card are absent. Once
the add-on is active, the previous implementation, settings keys, database
records, webhook routes and worker behavior are restored unchanged.

This architecture checkpoint does not include a behavioral fix for Rubika
polling, webhook delivery or `TOO_REQUESTS` responses. The database schema
version remains `3.9.1`.
