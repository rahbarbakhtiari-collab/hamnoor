# Hamnoor 4.0.0-alpha10.1 — Notifications Suite

The deployable notification packages are consolidated into one modular plugin:
Notification Engine, Web Push, Eitaa, Bale and Rubika, plus the reserved
WhatsApp, Telegram and App Push boundaries. Each channel remains a separate
internal module registered through the existing add-on manager.

The Suite entrypoint declares no Core-dependent implementation class during
the raw WordPress plugin-file load phase. At early `plugins_loaded`, it verifies
Core contracts, loads the Engine, and loads every channel bootstrap behind its
own `Throwable` boundary. Runtime channel boot remains isolated by the Core
add-on manager.

Core keeps only generic contracts, service facade, channel registry and data
tables. Existing option names, channel identities, subscriptions, connected
users, queue records and Rubika behavior are unchanged. DB remains `3.9.1`.
