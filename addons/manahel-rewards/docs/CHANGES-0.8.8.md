# Manahel Rewards 0.8.8

- Fixes identity shadowing on the Hamnoor Growth Hub when a WordPress login cookie and a Hamnoor session coexist in the same browser.
- `?tab=growth` now always prefers the authenticated Hamnoor-domain user over the WordPress admin/editor account.
- Reward redemption forms carry a server-resolved `mr_context=hamnoor` marker through `admin-post.php`, preserving the same Hamnoor identity during POST.
- Standalone/non-Hamnoor Rewards surfaces keep normal WordPress-user precedence.
- No DB migration; schema remains 1.3.0.
