# Goo! Repartidor Android

This is the Android shell for the driver experience. It uses the existing PHP
backend and account system; it does not create a second database or local
copy of the application.

## Requirements

- Node.js 20 or newer
- Android Studio with an Android SDK and emulator/device
- A physical Android device is recommended for GPS testing

## Setup

```powershell
npm install
npx cap add android
npm run cap:sync
npm run cap:open
```

The background location service will be added to the Android project before
the first production build. Do not commit `android/` until the native module
and release signing configuration have been reviewed.
