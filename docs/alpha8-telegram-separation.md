# Hamnoor 4.0.0-alpha8 — Telegram boundary

The reserved Telegram admin tab is now supplied by a separately installable
first-party add-on. Without that add-on, the tab is absent from Core.

Telegram did not have an operational adapter, settings screen or dashboard
card in alpha7. Alpha8 intentionally adds none: notification priority keys,
historical delivery labels and shared reporting remain in Core so existing
records and dispatcher behavior are preserved.

The database schema version remains `3.9.1`.
