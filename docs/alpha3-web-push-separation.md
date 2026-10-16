# Hamnoor 4.0.0-alpha3.1 — Physical Web Push separation

Alpha3 moves the Web Push implementation and browser JavaScript into the
separate `hamnoor-web-push` WordPress plugin. Core no longer requires or boots
the Web Push class.

## Compatibility

- Database version remains `3.9.1`.
- Existing `hamnoor_webpush_vapid` keys are reused.
- Existing `hamnoor_push_subscriptions` rows are reused.
- Notification Engine and Eitaa/Bale/Rubika behavior are unchanged.
- Without the Web Push plugin, Core, reading, khatms and other channels boot.
- The Web Push admin tab is registered and rendered only by the separate add-on.

## Installation order

1. Upgrade Hamnoor Core to `4.0.0-alpha3.1`.
2. Install and activate `hamnoor-web-push-4.0.0-alpha3.1.zip`.
3. Save permalinks only if `/hamnoor-sw.js` does not resolve automatically.
