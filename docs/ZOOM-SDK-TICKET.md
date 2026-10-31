# Zoom: Meeting SDK switched off on the account (ACCOUNT_NOT_ENABLE_SDK)

First seen 2026-10-27 on app 3.11.14. Phones show
`ZOOM_ERROR_AUTHRET_ACCOUNT_NOT_ENABLE_SDK` at SDK authentication, before any
join. `php artisan zoom:check` is fully green, the same Production Client ID
has worked since 2026-08-25, nothing changed on our side.

Zoom has no REST endpoint that exposes the account's Meeting-SDK entitlement,
so this cannot be detected or fixed from the server or the APK. Zoom staff
(chunsiong.zoom) re-enabled it by hand for three other developers in
Sept–Oct 2026, all on Basic or lapsed-Pro accounts:
devforum.zoom.us topics 147257, 147419, 145743.

## Where to post

1. https://devforum.zoom.us/c/meeting-sdk/56 → **New Topic** (fastest; this is
   where the staff replies came from). Log in with the Zoom account that owns
   the apps (m.balasundaram2010@gmail.com).
2. Or https://developers.zoom.us/support/ → Developer Support ticket, same text.

Members keep using **Open in the Zoom app** until Zoom confirms. No rebuild or
deploy is needed afterwards: the SDK token is minted by the server at tap time.

## Title

Meeting SDK auth fails with AUTHRET_ACCOUNT_NOT_ENABLE_SDK on production credentials since 2026-10-27 (Android, same-account meetings)

## Body (paste as-is, fill the timestamps)

Hi Zoom team,

Since 27 October 2026 our Android app can no longer authenticate the Meeting
SDK. `onAuthReturn` reports `ZOOM_ERROR_AUTHRET_ACCOUNT_NOT_ENABLE_SDK` (code 8)
before any join attempt. The same credentials, signer and app build authenticated
and joined successfully from 25 August 2026 until this week; nothing was changed
on our side (no credential regeneration, no app re-publish, no SDK upgrade).

Details:

- Account ID: k6NQAUNsQ5OG-MXuyKP1hA (owner m.balasundaram2010@gmail.com, Workplace Basic)
- General app "lordicl", Embed → Meeting SDK enabled, published privately to the account
- Meeting SDK Client ID (Production): xFGO0KHcQeqyU64xPUXNw
- SDK: Android Meeting SDK 7.0.5 (@zoom/meetingsdk-react-native 7.0.5)
- JWT claims: appKey, sdkKey, mn, role=0, iat, exp, tokenExp (exp − iat = 7200 s), HS256
- Meetings are created with our Server-to-Server OAuth app "lordicl-admin"
  (same account), e.g. meeting 89671681171 created 2026-10-27 17:22 UTC,
  host Jylz587uSWiONRBNyUzZDQ — so every join is inside the app owner's
  account and only the JWT is used (no ZAK / OBF needed per the OBF FAQ).
- Failing attempts (UTC): 2026-10-27 __:__ , __:__ , 2026-10-28 __:__
  (from phone IP ____ )

This looks identical to topics 147257, 147419 and 145743 where the Meeting SDK
had been disabled on the account on your side and was re-enabled by staff.

Could you please:

1. Re-enable the Meeting SDK for account k6NQAUNsQ5OG-MXuyKP1hA / Client ID xFGO0KHcQeqyU64xPUXNw.
2. Tell us what triggered the change, and whether a paid (Pro) licence on the
   owner account is now required to keep the Meeting SDK enabled, so we can
   plan for it rather than have this recur.

Thank you.

## After Zoom answers

- Re-test: open any published meeting from the app → should go straight to
  "Joining the meeting…". No APK or server change.
- Record the answer (cause + plan requirement) in the memory note
  `zoom-two-apps-credentials` and, if Zoom requires Pro, raise it with the board.

## Zoom reply 1 (2026-10-28) and our answer

Zoom's first reply was a generic checklist: (1) Admin Center → Settings →
General → Security → Manage apps, make sure SDK apps are allowed; (2) make sure
the JWT is signed with the Meeting SDK app's credentials, not another app's;
(3) isolate by authenticating with the same production credentials from a
second environment.

Before answering: check (1) in the Zoom web portal as the account owner, and
open the `zoom:check --join=latest` link with `&debug=1` in Chrome to capture
the Web SDK error text for (3).

Answer to paste (fill the two blanks):

```
Thanks. Results of the three checks:

1. Manage apps / SDK apps allowed
   Checked as account owner (m.balasundaram2010@gmail.com) on account
   k6NQAUNsQ5OG-MXuyKP1hA: ____ (what the setting shows). In the Marketplace the
   General app "lordicl" still shows Embed → Meeting SDK enabled and is
   published privately to the account. Nothing there was changed since
   August 2026, when the same credentials worked.

2. Credential type
   The JWT is signed with the Meeting SDK Client ID / Client Secret of the
   General app "lordicl" (Production pair, Client ID xFGO0KHcQeqyU64xPUXNw),
   not with the Server-to-Server app. Our server-side check verifies this
   explicitly: it compares the SDK pair against the S2S Client ID / Secret and
   the webhook token and would flag any mix-up. Claims: appKey, sdkKey, mn,
   role=0, iat, exp, tokenExp (exp − iat = 7200 s), HS256. Server clock is
   NTP-synced (within 1 s of UTC).

3. Isolation test from a second environment
   Same production credentials, same signer, Web Meeting SDK 6.2.0 (embedded)
   in desktop Chrome on https://next.lordicl.com: join also fails with
   "____" (error code ____). So the failure is not an Android build or
   signing issue; it reproduces on web and native with the same Client ID.
   The native Android SDK 7.0.5 error is ZOOM_ERROR_AUTHRET_ACCOUNT_NOT_ENABLE_SDK
   at onAuthReturn, before any join attempt.

The meetings being joined are created by our own Server-to-Server app on the
same account (for example meeting 89671681171, host Jylz587uSWiONRBNyUzZDQ),
so only the JWT is required under the OBF FAQ rules.

Everything on our side is unchanged since the last successful authentication.
This matches devforum topics 147257, 147419 and 145743, where the Meeting SDK
had been disabled on the account on Zoom's side and was re-enabled by your
team. Could you please check the Meeting SDK entitlement for account
k6NQAUNsQ5OG-MXuyKP1hA / Client ID xFGO0KHcQeqyU64xPUXNw on the backend and
re-enable it, and let us know whether a paid licence is now required to keep
it enabled?
```
