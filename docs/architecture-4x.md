# Hamnoor 4.x architecture

## Dependency rule

Dependencies flow toward Core contracts only:

`Add-on -> Core API / contracts`

Core must not call a concrete add-on class. Optional capabilities register services in the Core service container and listen to domain events through the Event Bus.

## Alpha2 boundaries

### Core owns
- users/mobile access, khatm, parts, reading and routing
- Event Bus and service container
- Add-on Manager and safe built-in add-on loader
- notification contracts and channel registry (contracts only, not delivery logic)

### Notifications add-on owns
- Dispatcher
- queue processing
- retry/backoff
- delivery logs and routing decisions
- notification-provider compatibility service
- notification queue cron lifecycle

The Notifications add-on must not reference `Hamnoor_Web_Push`, `Hamnoor_Eitaa`, `Hamnoor_Bale` or `Hamnoor_Rubika` directly. Channels register against `Hamnoor_Notification_Channel_Registry`.

### Transitional code still to extract
- Web Push channel
- Eitaa channel
- Bale channel
- Rubika channel
- Smart Reminder / Engagement
- notification administration UI

## Public notification contract

Consumers use either `Hamnoor_Notifications` (compatibility facade) or `hamnoor_core_api()->notifications()` (service contract). They must never call `Hamnoor_Notification_Dispatcher` directly.
