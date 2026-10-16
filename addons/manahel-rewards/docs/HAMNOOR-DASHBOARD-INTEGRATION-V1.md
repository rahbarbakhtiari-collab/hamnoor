# Manahel Rewards ↔ Hamnoor Dashboard Integration — Contract v1

## Boundary

Hamnoor exposes the generic WordPress action:

```php
do_action('hamnoor_dashboard_summary_extension', $hamnoor_user, $surface);
```

Manahel Rewards listens to it. Hamnoor does not call a Manahel Rewards function, does not query Rewards tables, and remains fully functional when Rewards is disabled or removed.

## Identity

Hamnoor dashboard sessions are domain sessions and may exist without a WordPress auth cookie. The listener accepts only the server-resolved `$hamnoor_user` object supplied by Hamnoor and uses its existing `wp_user_id` bridge. No request parameter can select another Reward Account.

The same optional identity resolution is exposed to Rewards' existing My Points shortcodes through the `manahel_rewards_viewer_wp_user_id` filter. The dashboard details link adds only `mr_context=hamnoor`; this flag selects the already-authenticated Hamnoor session as the viewer source and never carries a user/account identifier. Without that context flag, a normal WordPress login keeps precedence. This avoids identity mismatch during administrator/testing sessions while preserving generic WordPress behavior.

## Read model

The dashboard card uses `Manahel_Rewards_User_Summary_Service::get_compact_for_wp_user()`:

- account lookup / cached balance;
- current policy-day positive earned total;
- no policy grid;
- no Ledger history;
- no Event feed;
- no mutation and no Account creation.

The result is cached in memory for the request because Hamnoor renders desktop and mobile dashboard surfaces from the same summary template.

## Destination

`manahel_rewards_my_points_page_id` stores the administrator-selected published WordPress page containing `[manahel_rewards_my_points]`. If no valid page is configured, the compact card still renders but omits the details link.

## Failure/optional behavior

- Rewards disabled: Hamnoor hook has no listener and renders no gap/content.
- Hamnoor unavailable: Rewards continues to provide its normal WordPress shortcodes/admin UI.
- Missing `wp_user_id` bridge: the dashboard card renders nothing rather than inventing identity.
- User with no Reward Account: card displays zero; viewing does not create an Account.
