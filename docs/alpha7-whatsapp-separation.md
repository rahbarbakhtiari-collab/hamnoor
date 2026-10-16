# Hamnoor 4.0.0-alpha7 — WhatsApp boundary

The reserved WhatsApp admin tab is now supplied by a separately installable
first-party add-on. Without that add-on, the tab is absent from Core.

WhatsApp did not have an operational adapter, settings screen or dashboard
card in alpha6. Alpha7 intentionally adds none: notification priority keys,
historical delivery labels and shared reporting remain in Core so existing
records and dispatcher behavior are preserved.

The database schema version remains `3.9.1`.
