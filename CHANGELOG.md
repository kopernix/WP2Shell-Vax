# Changelog

## 1.0.1 — 2026-09-17

- Fixed MariaDB syntax errors when creating the IP logging table by replacing the hyphen in the table name with underscores.
- Made table installation verify the table exists instead of relying only on the stored option.
- Updated uninstall cleanup and stopped displaying the database error during normal operation.

## 1.0.0 — 2026-08-17

- Implemented two REST lifecycle guards for the `/batch/v1` endpoint on wp2shell-affected core versions.
- Added exact-branch version status, fail-closed handling of unknown versions, automatic deactivation after patching, and an SQLi-only warning for WordPress 6.8.0–6.8.5.
- Added optional sampled IP retention (30 days, 500 IPs, approximate hourly global budget), privacy purge and a small Tools screen.
- Included offline smoke tests and publishing documentation.
