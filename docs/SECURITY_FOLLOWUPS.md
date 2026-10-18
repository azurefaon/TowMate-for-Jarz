# Security follow-ups (OWASP audit, October 2026)

Items from the OWASP audit that need a mobile (Flutter) build and so are not part of the backend fixes in this release.

## For the next Flutter APK

### Team Leader password hint is out of date (M3 follow-up)

The server now applies one password rule to every role: at least 12 characters with upper and lower case, a number and a symbol, and a breach check. The Team Leader screens still tell the user "At least 8 characters" and check only the length.

| File | Line | Current | Change to |
|---|---|---|---|
| `towmate_app/lib/screens/team_leader/tl_force_password_screen.dart` | ~122 | `if (v.length < 8) return 'At least 8 characters'` | State the 12-character rule and the character requirements |
| `towmate_app/lib/screens/team_leader/tl_profile_screen.dart` | ~286 | `if (nw.length < 8) {` | Same |

Until then the app still works: the server rejects a weak password with a 422 and the screen shows the server's message. Existing Team Leader passwords are not affected, and nobody is forced to reset.

### Not yet done (need mobile compatibility testing)

- **M2:** mobile API tokens never expire (`config/sanctum.php` `expiration`). Needs the app to handle a 401 by returning to login.
- **M4:** Android hardening — `android:allowBackup="false"`, R8 shrinking and obfuscation for the release build.
- Confirm the Google Maps and Firebase API keys in `AndroidManifest.xml` and `google-services.json` are restricted by package name and signing certificate in Google Cloud.
