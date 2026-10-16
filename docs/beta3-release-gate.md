# Hamnoor 4.0.0-beta3 — Release gate

## Scope

- Make the prefilled mobile number editable in the unverified-mobile activation box.
- Verify the current number when unchanged.
- Verify and replace the mobile when edited, while retaining the same Hamnoor user identity and all linked activity.
- Keep the Beta2 access policy, database `3.9.1`, Notifications Suite, and Rubika unchanged.

## Runtime checks

1. Open an unverified dashboard and confirm the current mobile is prefilled and editable.
2. Leave it unchanged, request OTP, verify, and confirm the dashboard remains attached to the same user.
3. On a separate unverified test account, edit the mobile to an unused number and request OTP.
4. Confirm OTP is sent to the new number and the successful redirect returns to the requested page.
5. Confirm existing parts, reading progress, created khatms, notifications, and history remain available.
6. Confirm participant and khatm contact numbers now show the new mobile where applicable.
7. Confirm an invalid mobile is rejected.
8. Confirm a mobile already attached to another panel is rejected without changing either panel.
9. Confirm Beta2 phone-only dashboard entry behavior remains unchanged.
10. Confirm Notifications Suite and the known Rubika issue are unchanged.
