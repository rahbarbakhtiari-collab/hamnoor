# Hamnoor Dashboard Summary Extension — Contract v1

## Purpose

`hamnoor_dashboard_summary_extension` is a generic, presentation-only extension point in the authenticated Hamnoor dashboard summary. It exists so optional plugins can add a compact read-only card without Hamnoor Core knowing the plugin name or querying its storage.

## Signature

```php
do_action('hamnoor_dashboard_summary_extension', $user, $surface);
```

- `$user`: current Hamnoor domain user object resolved by `Hamnoor_Mobile_Access::current_user()`.
- `$surface`: `desktop` or `mobile`.

The hook fires only inside the dashboard `summary` panel, once for each responsive presentation surface. WordPress CSS controls which surface is visible. Integrations that read databases should cache their projection for the request so the second surface does not duplicate expensive queries.

## Contract

Listeners SHOULD:

- render read-only/presentation UI only;
- treat `$user` as server-resolved current Hamnoor identity, never as request input;
- use stable public/read APIs of their own plugin;
- preserve dashboard cache/privacy protections;
- render nothing when unavailable or misconfigured.

Listeners MUST NOT:

- mutate Hamnoor campaign/participation state merely because the dashboard is viewed;
- query or update Hamnoor business tables directly for unrelated integration state;
- require Hamnoor Core to know their plugin-specific classes, tables, or configuration.

## Optionality

With no listener registered, the hook outputs nothing and the dashboard layout/behavior remains unchanged. Disabling or uninstalling an integration plugin must therefore leave Hamnoor functional.
