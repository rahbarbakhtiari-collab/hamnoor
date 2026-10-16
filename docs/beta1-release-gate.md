# Hamnoor 4.0.0-beta1 — Release gate

Beta1 freezes the two-plugin architecture validated in alpha10.1:

- Hamnoor Core owns khatms, participants, reading, shared contracts and data.
- Hamnoor Notifications Suite owns the Engine and every notification channel.
- Core declares no concrete Dispatcher or channel implementation.
- The Suite declares no Core-dependent implementation class during raw plugin
  file loading; preparation runs at early `plugins_loaded` behind boundaries.
- Every internal channel remains an independently booted add-on module.
- Existing option keys, channel ids, queue records and connected users remain
  compatible. Database schema version remains `3.9.1`.

No feature or notification behavior change is included in beta1.
