# Hamnoor 4.0.10 — Theme-Compatible Virtual Campaign Template

- Adds a WordPress 6.7+ plugin-registered block template for virtual campaign URLs.
- Keeps the active block theme Header and Footer (including Twenty Twenty-Five).
- Removes generic Blog/Query/Post Title wrappers by rendering only Hamnoor post content between theme template parts.
- Resets conflicting WP_Query flags (`is_home`, archive/search/tax/date flags) so five-character campaign URLs are coherent singular virtual pages.
- Plugin render mode remains unchanged and continues to use Hamnoor's standalone template.
- No database schema migration.
