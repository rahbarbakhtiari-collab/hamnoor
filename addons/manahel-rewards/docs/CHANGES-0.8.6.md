# Changes 0.8.6

- Fixes replenishment UX for auto-code rewards after the previous code pool is exhausted.
- Keeps the code-entry textarea append-only but labels it clearly as «افزودن کدهای جدید».
- Adds an administrator-visible inventory table for previously stored codes with available/assigned status.
- Reports exact import results: added, duplicate, invalid, and currently available code counts.
- Adds early no-cache protection for the Hamnoor Growth/Rewards route, including LiteSpeed no-cache signaling.
- Frontend uses exact available-code count and shows a specific out-of-code message.
- No database migration; schema remains 1.3.0.
