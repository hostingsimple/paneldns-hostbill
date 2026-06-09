# paneldns-hostbill

HostBill server module for selling **PanelDNS sub-client DNS hosting** as an orderable product.

This module integrates with the PanelDNS Reseller API (`/api/v1`) so resellers running
PanelDNS can onboard their own customers (sub-clients) automatically through HostBill's
standard provisioning lifecycle — and give each customer a full embedded DNS manager inside
the HostBill client portal.

> **v2.0.0 is a BREAKING change from v1.x.** v1.x used the Platform API to provision
> reseller orgs. v2.0.0 uses the Reseller API to provision sub-clients, matching the WHMCS
> reseller module (`paneldns-reseller-whmcs`) feature-for-feature. See
> [CHANGELOG.md](CHANGELOG.md) for migration notes.

## Requirements

- HostBill 5.x or later
- PHP 8.2+
- cURL extension enabled
- An active PanelDNS **reseller** account with a Sanctum API token
  (PanelDNS Dashboard → API Tokens → Create Token, scope: `sub_clients:read sub_clients:write`)

## Installation

### 1. Download

Download the latest release ZIP from the
[Releases page](https://github.com/Veeau/paneldns-hostbill/releases).

### 2. Extract and copy

```
paneldns-hostbill-vX.X.X.zip
└── includes/
    └── modules/
        └── Hosting/
            └── paneldns/
                ├── class.paneldns.php
                └── shared/
                    ├── PanelDnsApi.php
                    └── LicenceCheck.php
```

Copy the `includes/` folder into your HostBill installation root so that
`includes/modules/Hosting/paneldns/class.paneldns.php` exists.

### 3. Add the App (Server)

1. Log in to HostBill admin.
2. Go to **Settings → Apps → Add New App**.
3. Choose **PanelDNS** from the module list.
4. Fill in the fields:

| Field | Description |
|---|---|
| **PanelDNS Base URL** | Full URL of your PanelDNS install, e.g. `https://my.paneldns.io` |
| **Reseller API Key** | Your Sanctum Bearer token from PanelDNS Dashboard → API Tokens. Scopes required: `sub_clients:read sub_clients:write`. |
| **Verify TLS Certificate** | Recommended: **ON**. Disable only for self-signed certs in development. |

5. Click **Test Connection** to verify the credentials.

### 4. Create a Product

1. Go to **Settings → Products & Services → Add New Product**.
2. In the **Module** tab, select **PanelDNS** and choose the App you configured above.
3. Configure the product options:

| Option | Key | Description |
|---|---|---|
| **Zone Limit** | option1 | Maximum number of DNS zones the sub-client can create. `0` = unlimited (inherits reseller plan). Default: `5`. |
| **Max Records Per Zone** | option2 | Maximum records per zone. `0` = unlimited. Default: `100`. |
| **Send Welcome Email** | option3 | Check to email a one-time SSO login link to the customer immediately after provisioning. |
| **NS1 Hostname** | option4 | Vanity nameserver 1 (e.g. `ns1.myhosting.com`). Optional — leave blank to use PanelDNS defaults. |
| **NS2 Hostname** | option5 | Vanity nameserver 2. |
| **NS3 Hostname** | option6 | Vanity nameserver 3 (optional). |
| **NS4 Hostname** | option7 | Vanity nameserver 4 (optional). |
| **SOA Email** | option8 | SOA contact email for new zones. Optional — defaults to a sensible value if blank. |
| **Auto-Create Zone on Domain Order** | option9 | Check to automatically create a zone matching the service domain when the order is placed. |
| **Auto-Delete Zone on Domain Expiry** | option10 | Check to automatically delete all sub-client zones when the service is terminated. |
| **Termination Grace Period (Days)** | option11 | Days to wait before permanently deleting the sub-client after a Terminate event. `0` = delete immediately (default). During the grace period the sub-client is suspended. Maximum: 365 days. |

4. Save the product.

## Provisioning Lifecycle

| HostBill Event | PanelDNS Action |
|---|---|
| Create | `POST /api/v1/sub-clients` — provisions a new sub-client with zone and record limits |
| Suspend | `PATCH /api/v1/sub-clients/{id}` `{status: "suspended"}` |
| Unsuspend | `PATCH /api/v1/sub-clients/{id}` `{status: "active"}` |
| Terminate | `DELETE /api/v1/sub-clients/{id}` (or suspend if grace > 0) |
| ChangePackage | `PATCH /api/v1/sub-clients/{id}` `{zone_limit, max_records}` |

The PanelDNS Sub-client ID is stored in the **PanelDNS Sub-client ID** per-account field
(`details.option1`). HostBill displays this in the service detail view.

### Idempotent Create

If `Create()` is called on a service that already has a Sub-client ID stored,
the module unsuspends the existing sub-client instead of creating a duplicate.

## Per-Account Details

After provisioning, the service detail page in HostBill admin shows:

| Field | Description |
|---|---|
| **PanelDNS Sub-client ID** | The numeric ID of the sub-client on the PanelDNS platform |

## SSO (Single Sign-On)

When the client clicks the SSO link in their HostBill portal, HostBill calls the module's
`ssoLogin()` method. The module:

1. Fetches a one-time login URL from `POST /api/v1/sub-clients/{id}/sso-token`.
2. Validates that the returned URL starts with `https://` (prevents redirect injection).
3. Issues a `302 Location` redirect to the login URL.

The SSO token is valid for 60 seconds. If the mint request fails, a plain-text error message
is shown and the redirect does not happen.

Clients can also navigate to their portal directly from the embedded DNS manager via the
**Open Full Portal** button (JavaScript `window.location.href` redirect).

Admin staff can trigger a resend of the welcome email (which includes a fresh SSO link)
via the **Resend Welcome Email** button on the service page.

## Admin Detail Panel

When viewing a service in the HostBill admin area, the module renders a usage table via
`getServiceDetails()`:

| Field | Source |
|---|---|
| Module version | `$this->version` |
| Sub-client ID | `$this->details['option1']` — bold |
| Status | `sub_client.status` — green (active), amber (suspended) |
| Sub-client Email | `sub_client.email` |
| Zones used / limit | `usage.zones` / `limits.zones` + colour-coded progress bar |
| Records used / limit | `usage.records` / `limits.records` + colour-coded progress bar |
| Last sync | `usage.last_synced_at` |
| Zones | First 20 zone names fetched via `GET /api/v1/zones` |

**Progress bars** are colour-coded: blue (< 75%), amber (75–90%), red (≥ 90%).

Two admin action buttons are also shown:

| Button | Method | Action |
|---|---|---|
| **Resend Welcome Email** | `resendWelcome()` | Mints a fresh SSO token and resends the welcome email |
| **Resync Status** | `resyncStatus()` | Fetches live sub-client summary and shows zone/record counts |

## Embedded DNS Manager (Client Area)

HostBill calls `clientArea()` to render HTML in the client portal service view. The module
returns a self-contained HTML block containing a full DNS zone and record manager — matching
the embedded DNS manager in `paneldns-reseller-whmcs`.

### Overview Page (default)

- **Usage cards** — zones and records used vs. plan limits with colour-coded progress bars.
- **Nameservers widget** — "Point your domains here" card showing NS1–NS4 hostnames.
- **Zone health widget** — surfaces any zones not in `active` state so problems are visible
  immediately. Non-fatal if the API is unavailable.
- **Suspension notice** — red alert when the sub-client account is suspended.
- **GDPR consent banner** — yellow warning with a portal SSO link when `sub_client.requires_consent`
  is `true` (prompts the client to re-accept updated terms).
- **Manage DNS Zones** button → zones list.
- **Open Full Portal** button → SSO redirect to the full PanelDNS portal.

### Zones List

- Table of all zones (name, status, record count).
- Per-zone **Manage** (records page), **Export** (BIND download), **Delete** (with JS confirm).
- **+ Add Zone** and **Import BIND** buttons.

### Records Page

- Full record table with **Name**, **Type**, **Content**, **TTL**, **Priority** columns.
- **Inline edit**: clicking Edit inline-expands an edit form in the table row.
- **Add Record form** below the table.
- **DNSSEC panel**: shows signing status, DS records (for registrar), enable/disable button.
  Rendered only when the provider supports DNSSEC (null = hidden).
- **NS card** at the top: "Point your domain here" with NS hostnames.

### Zone Create

- Zone name input with client-side and server-side validation:
  - ≤253 characters, no consecutive dots, `^[a-zA-Z0-9]([a-zA-Z0-9_\-]|\.[a-zA-Z0-9])*$` format.
- **Quota pre-flight** (QUOTA-01): checks zones_used < zones_limit before calling the API.

### Zone Import

- BIND-format text TEXTAREA for bulk record import.
- Calls `POST /api/v1/zones/import` with the raw BIND text.

### Zone Export

`?pdns=zone-export&zone=N` streams the zone as `text/plain` (BIND format) and exits.
Ownership is verified before streaming.

### Security

- **CSRF**: every POST form includes a `csrf` hidden field. Tokens are per-service session
  values (`paneldns_csrf_{serviceId}`), generated with `bin2hex(random_bytes(24))`, compared
  with `hash_equals()`, and rotated after each successful mutation.
- **Flash messages**: stored in session (`paneldns_flash_{serviceId}`), capped at 512 chars,
  consumed and cleared on the next page render.
- **Ownership check** (SEC-OWN): `fetchOwnZone()` verifies `sub_client_id === $this->subClientId()`
  on every zone action. Never trusts a client-submitted zone ID alone.
- **Record validation**: 13-type allowlist (A, AAAA, CNAME, MX, TXT, NS, SRV, CAA, PTR, TLSA,
  SSHFP, HTTPS, NAPTR), name ≤253 chars, content ≤4096 chars, TTL ≥60 s.
- **Output escaping**: all server-supplied values are escaped with `htmlspecialchars()` via the
  `h()` helper. No raw user data is injected into HTML.

## Usage Graphs

HostBill calls `getUsage()` to populate usage bar charts on the service detail page.

| HostBill key | PanelDNS field |
|---|---|
| `disk` | `usage.zones` |
| `bandwidth` | `usage.records` |
| `disk_limit` | `limits.zones` (0 = unlimited) |
| `bandwidth_limit` | `limits.records` (0 = unlimited) |

Returns zeros for all keys on API failure (non-fatal). Results are cached for 60 seconds
alongside `getServiceDetails()` and `clientArea()`.

## Drift Sync

`driftSync()` checks whether the PanelDNS sub-client status is consistent with the local
HostBill service status. It accepts an array of `['sub_client_id' => int, 'status' => string]`
pairs and returns:

```php
['checked' => int, 'mismatched' => [['sub_client_id' => int, 'hb_status' => string, 'pdns_status' => string]]]
```

A mismatch is flagged when:
- HostBill says **Active** but PanelDNS says **suspended**.
- HostBill says **Suspended** but PanelDNS says **active**.

Wire this into **Settings → Task Scheduler** in HostBill to run nightly or hourly. The caller
is responsible for acting on returned mismatches (e.g. calling `Suspend()` / `Unsuspend()`).

## PanelDNS Reseller API

| Endpoint | Description |
|---|---|
| `GET /api/v1/summary` | Reseller account summary (used by Test Connection) |
| `GET /api/v1/org/nameservers` | Authoritative nameservers for this reseller org |
| `GET /api/v1/legal-version` | Current ToS version for GDPR stamping at create time |
| `POST /api/v1/sub-clients` | Create a new sub-client |
| `PATCH /api/v1/sub-clients/{id}` | Update sub-client (status, limits) |
| `DELETE /api/v1/sub-clients/{id}` | Delete a sub-client |
| `GET /api/v1/sub-clients/{id}/summary` | Usage summary (zones, records, limits) |
| `POST /api/v1/sub-clients/{id}/sso-token` | Mint a one-time SSO login URL |
| `GET /api/v1/zones` | List zones (filtered by `sub_client_id`) |
| `POST /api/v1/zones` | Create a zone |
| `DELETE /api/v1/zones/{id}` | Delete a zone |
| `GET /api/v1/zones/{id}/records` | List records for a zone |
| `POST /api/v1/zones/{id}/records` | Create a record |
| `PUT /api/v1/zones/{id}/records/{rid}` | Update a record |
| `DELETE /api/v1/zones/{id}/records/{rid}` | Delete a record |
| `GET /api/v1/zones/{id}/export` | Export zone as BIND text |
| `POST /api/v1/zones/import` | Import a zone from BIND text |
| `GET /api/v1/zones/{id}/dnssec` | DNSSEC status + DS records |
| `POST /api/v1/zones/{id}/dnssec` | Enable/disable DNSSEC |

Authentication: `Authorization: Bearer {RESELLER_API_TOKEN}` on every request.

## File Structure

```
paneldns-hostbill/
├── modules/
│   └── servers/
│       └── paneldns/
│           └── class.paneldns.php      # Main module class
├── shared/
│   ├── PanelDnsApi.php                 # HTTP client for PanelDNS APIs
│   └── LicenceCheck.php                # Subscription validation (optional gate)
├── .github/
│   └── workflows/
│       └── release.yml                 # GitHub Actions: ZIP on tag push
├── CHANGELOG.md
└── README.md
```

The release ZIP re-packages the files into HostBill's expected layout:
```
includes/modules/Hosting/paneldns/
├── class.paneldns.php
└── shared/
    ├── PanelDnsApi.php
    └── LicenceCheck.php
```

## Security Notes

- The Reseller API token is stored in HostBill's App configuration (encrypted at rest).
- The HTTP client forces `CURLOPT_IPRESOLVE_V4` — cURL never resolves to IPv6, preventing dual-stack SSRF.
- A belt-and-braces SSRF guard in `connect()` rejects server hostnames that resolve to private or reserved IPs
  (`isPrivateOrUnresolvable()`), in addition to the response-IP check in `PanelDnsApiHb`.
- TLS certificate verification is enabled by default. Disable only for development environments.
- Minted SSO URLs are validated for the `https://` scheme before use in redirects or emails.
- CSRF tokens are per-service session values (`bin2hex(random_bytes(24))`), rotated after each mutation,
  and compared with `hash_equals()` to prevent timing oracle attacks.
- All client-area output is escaped with `htmlspecialchars()` — no raw user data in HTML.
- Zone ownership is verified on every zone action (`fetchOwnZone()` checks `sub_client_id`).
- Record content is validated against a 13-type allowlist and length limits before any API call.

## Development

```bash
git clone https://github.com/Veeau/paneldns-hostbill.git
cd paneldns-hostbill
```

To run a local syntax check:
```bash
php -l modules/servers/paneldns/class.paneldns.php
php -l shared/PanelDnsApi.php
php -l shared/LicenceCheck.php
```

To create a release ZIP manually (mirrors the GitHub Actions workflow):
```bash
VERSION=v2.0.0
mkdir -p dist
STAGING=$(mktemp -d)/paneldns-hostbill
MODDIR="${STAGING}/includes/modules/Hosting/paneldns"
mkdir -p "${MODDIR}/shared"
cp modules/servers/paneldns/class.paneldns.php "${MODDIR}/class.paneldns.php"
cp shared/PanelDnsApi.php   "${MODDIR}/shared/PanelDnsApi.php"
cp shared/LicenceCheck.php  "${MODDIR}/shared/LicenceCheck.php"
sed -i "s|require_once __DIR__ . '/../../../shared/PanelDnsApi.php'|require_once __DIR__ . '/shared/PanelDnsApi.php'|g" "${MODDIR}/class.paneldns.php"
sed -i "s|require_once __DIR__ . '/../../../shared/LicenceCheck.php'|require_once __DIR__ . '/shared/LicenceCheck.php'|g" "${MODDIR}/class.paneldns.php"
(cd "$(dirname "${STAGING}")"; zip -r "${OLDPWD}/dist/paneldns-hostbill-${VERSION}.zip" paneldns-hostbill)
echo "dist/paneldns-hostbill-${VERSION}.zip"
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

Proprietary. Part of the PanelDNS product suite.
