# Security follow-ups (OWASP audit, October 2026)

Items from the OWASP audit that need a mobile (Flutter) build and so were not part of the first backend fixes.

## Done in the mobile security batch (needs the next APK, except the token expiry which is server-side)

- **M2 token expiry:** mobile API tokens now expire 60 days after login (`config/sanctum.php`, override with `SANCTUM_EXPIRATION` in minutes). The app already sends any 401 to the login screen. Server-side only; no APK needed.
- **M4a backup:** `android:allowBackup="false"` in `AndroidManifest.xml`.
- **M3 Team Leader hint:** `tl_force_password_screen.dart` and `tl_profile_screen.dart` now use `Validators.password` (12+ characters, upper/lower case, number, symbol), the same rule as the server and the customer screens.
- **Build safety:** `lib/core/api_config.dart` stops a release build whose `API_BASE_URL` is not an `https://` URL on a public host (`main()` calls `assertSecureApiForRelease`). Debug and profile builds are unchanged.
- **M4b permissions:** removed `READ_MEDIA_IMAGES` (image_picker uses the system photo picker) and `USE_BIOMETRIC` / `USE_FINGERPRINT` (no biometric sign-in).

## Permissions deliberately kept

The Google Navigation SDK (used by the Team Leader navigation screen) declares `ACCESS_BACKGROUND_LOCATION`, `FOREGROUND_SERVICE` and `FOREGROUND_SERVICE_LOCATION`, `WRITE_EXTERNAL_STORAGE` (which also makes Android imply `READ_EXTERNAL_STORAGE`), `RECEIVE_BOOT_COMPLETED`, `BLUETOOTH` / `BLUETOOTH_ADMIN`, `VIBRATE` and `WAKE_LOCK`. Google does not document which of these are optional, so none were removed. Revisit only with a device test of Team Leader navigation (including with the app in the background).

## Still open

- Confirm the Google Maps and Firebase API keys in `AndroidManifest.xml` and `google-services.json` are restricted by package name and signing certificate in Google Cloud. (Requests without the app are rejected; the exact binding can only be seen in the console.)
- Optional: Dart symbol obfuscation (`--obfuscate --split-debug-info`). R8 shrinking is already on.
