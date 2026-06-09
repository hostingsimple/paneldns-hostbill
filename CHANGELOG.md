# Changelog

## v2.0.0 — 2026-06-09

**BREAKING CHANGE — complete rebuild. v1.x used the Platform API (`/platform/v1`) to
provision reseller orgs. v2.0.0 switches to the Reseller API (`/api/v1`) to provision
sub-clients — matching `paneldns-reseller-whmcs` feature-for-feature.**

### Migration from v1.x

v1.x and v2.0.0 are incompatible at the server/product level:

- v1.x required an **operator-level Platform API key**; v2.0.0 requires a **reseller-level
  Sanctum Bearer token** (from the reseller's own PanelDNS dashboard → API Tokens).
- v1.x stored a **PanelDNS Org ID** per service; v2.0.0 stores a **Sub-client ID**.
- Product options have changed (plan_id → zone_limit + max_records).
- Existing services provisioned by v1.x must be migrated manually: create matching
  sub-clients via the PanelDNS reseller portal, then update the Sub-client ID in the
  HostBill per-account detail field.

### Changed

- **API tier**: Platform (`/platform/v1`, operator key) → Reseller (`/api/v1`, Sanctum token).
- **Entity**: Reseller orgs → Sub-clients. All lifecycle hooks now call `/api/v1/sub-clients`.
- **Authentication**: operator Platform API key → per-reseller Sanctum Bearer token.
- **Server field**: `hash` field labelled "Reseller API Key" (was "Platform API Key").
- **Product options** completely redesigned to match `paneldns-reseller-whmcs` v1.7.2:
  - option1: Zone Limit (was: PanelDNS Plan ID)
  - option2: Max Records Per Zone (new)
  - option3: Send Welcome Email (unchanged)
  - option4–7: NS1–NS4 Hostname overrides (unchanged)
  - option8: SOA Email (unchanged)
  - option9: Auto-Create Zone on Domain Order (new)
  - option10: Auto-Delete Zone on Domain Expiry (new; was Portal ToS URL)
  - option11: Termination Grace Period (Days) (was option9)
- **Per-account detail**: "PanelDNS Sub-client ID" (was "PanelDNS Org ID").
- **`testConnection()`**: now calls `/api/v1/summary` instead of `/ping` + `/plans`, so
  the token's reseller scopes are verified.
- **`Create()`**: calls `POST /api/v1/sub-clients` with zone_limit, max_records, GDPR
  consent stamping, and idempotent create (unsuspend if already provisioned).
- **`Suspend()` / `Unsuspend()`**: `PATCH /api/v1/sub-clients/{id}` `{status: suspended/active}`
  (was POST `.../suspend` and `.../unsuspend`).
- **`Terminate()`**: `DELETE /api/v1/sub-clients/{id}` with configurable grace period.
  Grace period suspends the account; documented to use HostBill Task Scheduler for cleanup.
- **`ChangePackage()`**: `PATCH /api/v1/sub-clients/{id}` `{zone_limit, max_records}`
  (was `PUT /platform/v1/orgs/{id}/plan`).
- **`getUsage()`**: maps zones → disk, records → bandwidth (was zones → disk, sub_clients → bandwidth).
- **`getServiceDetails()`**: now shows Sub-client ID, Sub-client Email, Zones used/limit and
  Records used/limit with colour-coded progress bars, Last sync, and the first 20 zone names.
- **`clientArea()`**: completely rewritten as a full embedded DNS manager (see Added below).
- **`driftSync()`**: now accepts `['sub_client_id' => int, 'status' => string]` pairs
  (was `['org_id' => int, 'status' => string]`).
- **`ssoLogin()`**: now calls `POST /api/v1/sub-clients/{id}/sso-token`
  (was `/platform/v1/orgs/{id}/sso-token`).
- **`sendWelcomeEmail()`**: context updated to reseller sub-client (portal_url, org_slug,
  nameservers, soa_email) matching the WHMCS reseller module's welcome email.
- **`resendWelcome()`**: calls `mintSubClientSsoToken()` (was `mintOrgSsoToken()`).
- **`resyncStatus()`**: calls `subClientSummary()` (was `orgSummary()`).
- **`shared/PanelDnsApi.php`**: added `getResellerLegalVersion()`, `createSubClient()`,
  `patchSubClient()`, `deleteSubClient()`, `subClientSummary()`, `mintSubClientSsoToken()`,
  `searchSubClients()` reseller-tier helper methods.

### Added

- **Full embedded DNS manager in `clientArea()`** — matches `EmbeddedDnsManager` in
  `paneldns-reseller-whmcs`. All pages return raw HTML (HostBill does not use Smarty):
  - **Overview page**: usage cards (zones, records) with progress bars, nameservers widget,
    zone health widget (surfaces non-active zones), SSO and Manage DNS Zones buttons.
  - **Zones list**: table of all zones with status, record count, Manage/Export/Delete actions.
  - **Records page**: full record table with inline edit form, Add Record form, and DNSSEC
    panel (sign/unsigned toggle + DS records for registrar).
  - **Zone create form**: name input with validation (≤253 chars, no `..`, alphanumeric
    format), quota pre-flight check before API call.
  - **Zone import form**: BIND text TEXTAREA for bulk zone import via `/api/v1/zones/import`.
  - **Zone export (BIND)**: `?pdns=zone-export&zone=N` streams the zone as `text/plain` and
    exits; ownership-checked before stream.
  - **GDPR consent banner** (CONSENT-R-02): yellow warning with portal SSO link when
    `sub_client.requires_consent` is true.
  - **Suspension notice** rendered in overview when status is suspended.
  - **CSRF protection**: per-service session token (`paneldns_csrf_{serviceId}`),
    `bin2hex(random_bytes(24))`, rotated via `rotateCsrf()` after each successful mutation.
    All POST forms include a `csrf` hidden field; `requireCsrf()` validates with `hash_equals()`.
  - **Flash messages**: session key `paneldns_flash_{serviceId}`, capped at 512 chars;
    shown at the top of the next page render.
  - **Record validation**: 13-type allowlist (A, AAAA, CNAME, MX, TXT, NS, SRV, CAA, PTR,
    TLSA, SSHFP, HTTPS, NAPTR), name ≤253 chars, content ≤4096 chars, TTL ≥60 s.
  - **Zone validation**: name ≤253 chars, no consecutive dots, regex-validated format.
  - **QUOTA-01 pre-flight**: checks zones_used vs zones_limit before `POST /api/v1/zones`.
  - **Ownership check** (SEC-OWN): `fetchOwnZone()` verifies `sub_client_id` on every zone
    action — never trusts a client-submitted zone ID alone.
  - **DNSSEC**: `fetchDnssecStatus()` / `doDnssecToggle()` — null = provider unsupported.
  - **Client SSO page**: `?pdns=sso` mints a token and uses a JS `window.location.href`
    redirect (HostBill `clientArea()` returns HTML; `header()` redirect is not used).
  - **NS-CARD-01**: nameservers "point your domain here" card shown at the top of the
    records page.
- **Progress bars** in `getServiceDetails()` and overview usage cards — colour-coded:
  blue (< 75%), amber (75–90%), red (≥ 90%).
- **`resolveNameservers()`**: prefers option4–7 product overrides; falls back to
  `/api/v1/org/nameservers`.
- **`cachedNameservers()`**: 5-minute in-process cache keyed by `identityHash()`.
- **`cachedSubClientSummary()`**: 60-second in-process cache keyed by sub-client ID;
  `resyncStatus()` and zone export bypass the cache.
- **`isPrivateOrUnresolvable()`** SSRF guard: blocks connections to private, loopback,
  link-local, and shared-address-space IPs at `connect()` time, in addition to the
  `CURLOPT_IPRESOLVE_V4` + response-IP guard in `PanelDnsApiHb`.

---

## v1.2.0 — 2026-06-09

### Added

- **60-second in-process summary cache** (`$summaryCache` static property + `cachedOrgSummary()` helper) —
  `getServiceDetails()`, `clientArea()`, and `getUsage()` now share a 60-second static cache on
  `orgSummary()` responses, preventing repeated API calls when HostBill renders multiple panels in
  the same request. `resyncStatus()` bypasses the cache and always fetches live data.
- **GDPR consent banner in `clientArea()`** — when `org.requires_consent` is `true` and
  `option10` (Portal Terms of Service URL) is configured, a yellow warning banner is rendered
  below the usage line prompting the reseller to review and accept updated terms. Mirrors the
  WHMCS module's `paneldns_requires_consent` template variable.
- **Nameservers surfaced after `Create()`** — after a successful org creation, the assigned
  NS hostnames are fetched via `getOrg()` and surfaced as an `addInfo()` message so the admin
  can immediately give the client their nameserver details without opening PanelDNS. Mirrors
  WHMCS `writeNameserversToServiceNotes()`.
- **Plan name in `clientArea()`** — a "Plan: {name}" line is now shown above the zones/sub-clients
  usage summary when a plan name is available.
- **Usage limits in `getUsage()`** — `disk_limit` and `bandwidth_limit` keys are now returned
  alongside `disk` and `bandwidth`, populated from `plan.zones` and `plan.clients` respectively.
  Returns `0` (unlimited in HostBill graph) when the plan has no cap on that dimension.
- **Dynamic version in `getServiceDetails()`** — the module version row now reads from
  `$this->version` rather than a hardcoded string, so it will be correct for all future releases.
- Version bumped to `1.2.0`.

## v1.1.0 — 2026-06-09

### Added

- **option10 / option11** — Product-level options for the Portal Terms of Service URL and
  Portal Privacy Policy URL. Both are PATCHed onto the new org immediately after `Create()`
  succeeds (non-fatal — provisioning is not rolled back on PATCH failure).
- **`$buttons` property** — `'Resend Welcome Email' => 'resendWelcome'` and
  `'Resync Status' => 'resyncStatus'` buttons appear on the HostBill admin service page.
- **`resendWelcome(): bool`** — Mints a fresh SSO token, validates the `https://` scheme,
  and resends the welcome email via the HostBill `Emails` component (falls back to `@mail()`).
- **`resyncStatus(): bool`** — Calls `orgSummary()` and surfaces zone/sub-client counts as an
  admin info message.
- **`ssoLogin(): void`** — Mints an SSO token and performs a `302` redirect to the validated
  `https://` login URL. Called by HostBill when the client clicks the SSO link.
- **`getUsage(): array`** — Returns `['disk' => active_zones, 'bandwidth' => sub_clients]`
  for HostBill's usage graph. Non-fatal — returns zeros on API failure.
- **`getServiceDetails(): string`** — Returns a minimal HTML table (Org ID, status, plan name,
  zones, sub-clients, API calls, module version) for the HostBill admin service detail panel.
  All values escaped with `htmlspecialchars()`.
- **`clientArea(): string`** — Returns a self-contained HTML block with an SSO login button
  and live usage summary (zones, sub-clients). Non-fatal if `orgSummary()` fails; shows just
  the button. Suspended orgs receive a plain-text note.
- **`driftSync(): array`** — Compares PanelDNS org statuses against a caller-supplied
  `['org_id' => int, 'status' => string]` map and returns mismatched pairs. Intended for
  HostBill Task Scheduler integration.
- Version bumped to `1.1.0`.

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
