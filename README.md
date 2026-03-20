# Connect-style App Boilerplate (Expo + React Native)

Production-minded starter scaffold for a social + chat app using Expo Router.

## Quick start

```bash
npm install
npm run dev
```

## Scripts

- `npm run dev` — start Expo dev server
- `npm run android` — open on Android
- `npm run ios` — open on iOS
- `npm run web` — run web target
- `npm run typecheck` — run TypeScript checks
- `npm run lint` — run Expo lint preset

## Structure

```txt
app/
  _layout.tsx
  index.tsx        # redirects to /home
  home.tsx
  modules.tsx
src/
  features/
    auth/
    onboarding/
    discovery/
    connections/
    chat/
    profile/
    notifications/
    settings/
  services/
    api/
    config/
    socket/
    storage/
  shared/
    components/
    hooks/
    constants/
  theme/
```

## Environment configuration

Expo `app.json` uses `expo.extra` keys:

- `apiBaseUrl`
- `socketBaseUrl`

Update these for your backend endpoints.

## Next steps

1. Replace placeholder API/socket URLs in `app.json`.
2. Implement auth screens and form validation (React Hook Form + Zod).
3. Add feature routes for discovery, connections, and chat.
4. Add EAS profiles (`eas.json`) for staging and production.
