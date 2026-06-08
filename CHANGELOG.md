# Changelog

## v1.0.0 — 2026-06-08

Initial release.

### Added

- `class.paneldns.php` — HostBill `HostingModule` extending the PanelDNS Platform API.
  - `connect()` — initialises `PanelDnsApiHb` from App config; includes SSRF pre-flight.
  - `testConnection()` — validates Base URL reachability and API key via `/ping` + `/plans`.
  - `Create()` — provisions a new PanelDNS reseller org; sends welcome email with SSO link.
  - `Suspend()` / `Unsuspend()` — delegate to `/orgs/{id}/suspend|unsuspend`.
  - `Terminate()` — deletes org immediately or suspends with a configurable grace period.
  - `ChangePackage()` — switches the org's plan via `/orgs/{id}/plan`.
  - `getPlans()` — loadable field helper for the Plan ID product option.
- `shared/PanelDnsApi.php` — cURL HTTP client for PanelDNS Platform + Reseller APIs.
  - IPv4-only SSRF guard (`CURLOPT_IPRESOLVE_V4` + response-IP check).
  - TLS verification on by default; per-server override flag.
  - Pluggable logger callback — no hard dependency on HostBill's activity log.
- `shared/LicenceCheck.php` — file-based licence cache (no WHMCS dependency).
  - 7-day grace period for `past_due` subscriptions.
  - 2-day stale hard-lock if PanelDNS is unreachable.
- `.github/workflows/release.yml` — GitHub Actions workflow that builds a HostBill-ready
  ZIP on tag push and attaches it to a GitHub Release.
- `README.md` — installation guide, App config reference, product option reference,
  security notes, and local build instructions.
