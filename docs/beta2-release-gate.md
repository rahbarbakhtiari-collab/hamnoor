# Hamnoor 4.0.0-beta2 — Release gate

## Scope

- Allow a valid Iranian mobile number to create and enter an empty Hamnoor dashboard without a previous part and without OTP.
- Keep OTP verification required for creating or editing a khatm and changing profile data.
- Leave database version `3.9.1`, Notifications Suite, and Rubika unchanged.

## Runtime checks

1. Enter a valid unused mobile on `/hamnoor-dashboard/`.
2. Confirm an empty dashboard opens and no SMS is sent.
3. Log out and enter the same mobile again; confirm the same dashboard opens.
4. Confirm participation can later attach a name and part to that panel.
5. Confirm creating a khatm asks for OTP.
6. Confirm opening an owned khatm for editing asks for OTP when the mobile is unverified.
7. Confirm changing the display name is rejected until the mobile is verified.
8. Confirm changing the mobile still verifies the new number by OTP.
9. Confirm existing users, parts, sessions, tokens, and channel settings remain intact.
10. Confirm Notifications Suite behavior, including the known Rubika issue, is unchanged.
