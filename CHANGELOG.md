# Changelog

## 1.0.0 — 2026-08-17

- Implemented two REST lifecycle guards for the `/batch/v1` endpoint on wp2shell-affected core versions.
- Added exact-branch version status, fail-closed handling of unknown versions, automatic deactivation after patching, and an SQLi-only warning for WordPress 6.8.0–6.8.5.
- Added optional sampled IP retention (30 days, 500 IPs, approximate hourly global budget), privacy purge and a small Tools screen.
- Included offline smoke tests and publishing documentation.
