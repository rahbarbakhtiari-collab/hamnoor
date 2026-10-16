# Hamnoor 4.0.0-alpha4 — Physical Eitaa separation

Alpha4 moves the Eitaa notification adapter, official Mini App bridge and
Mini App JavaScript into the separate `hamnoor-eitaa` WordPress plugin.

## Compatibility

- Database version remains `3.9.1`.
- Existing `hamnoor_eitaa_settings` encrypted tokens are reused.
- Existing `notification_channels` Eitaa connections are reused.
- Web Push, Bale, Rubika and Notification Engine behavior are unchanged.
- Without the Eitaa package, Core, reading, khatms and other channels boot.
- Eitaa admin tab and dashboard card appear only while the add-on is active.

## Installation order

1. Upgrade Hamnoor Core to `4.0.0-alpha4`.
2. Keep `hamnoor-web-push` active; it is compatible without modification.
3. Install and activate `hamnoor-eitaa-4.0.0-alpha4.zip`.
