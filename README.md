# paneldns-hostbill

HostBill server module for selling **PanelDNS reseller accounts** as orderable products.

This module integrates with the PanelDNS Platform API (`/platform/v1`) so operators
running PanelDNS-as-a-SaaS can onboard new resellers automatically through HostBill's
standard provisioning lifecycle.

## Requirements

- HostBill 5.x or later
- PHP 8.2+
- cURL extension enabled
- An active PanelDNS operator account with Platform API access

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
| **Platform API Key** | The Platform API key from your PanelDNS admin (Settings → API). Must be an operator-level key with platform scope. |
| **Verify TLS Certificate** | Recommended: **ON**. Disable only for self-signed certs in development. |

5. Click **Test Connection** to verify the credentials.

### 4. Create a Product

1. Go to **Settings → Products & Services → Add New Product**.
2. In the **Module** tab, select **PanelDNS** and choose the App you configured above.
3. Configure the product options:

| Option | Key | Description |
|---|---|---|
| **PanelDNS Plan ID** | option1 | Numeric ID of the PanelDNS plan this product maps to. Find plan IDs at `/admin/plans` on your PanelDNS install. |
| **Partner Source** | option2 | Optional partner identifier (e.g. `myhosting`). Marks the org as a partner — hides Stripe billing UI inside PanelDNS. Leave blank for standard resellers. |
| **Send Welcome Email** | option3 | Check to email a one-time SSO login link to the customer immediately after provisioning. |
| **NS1 Hostname** | option4 | Vanity nameserver 1 (e.g. `ns1.myhosting.com`). Optional — leave blank to use PanelDNS defaults. |
| **NS2 Hostname** | option5 | Vanity nameserver 2. |
| **NS3 Hostname** | option6 | Vanity nameserver 3 (optional). |
| **NS4 Hostname** | option7 | Vanity nameserver 4 (optional). |
| **SOA Email** | option8 | SOA contact email for new zones. Optional — defaults to a sensible value if blank. |
| **Termination Grace Period (Days)** | option9 | Days to wait before permanently deleting the org after a Terminate event. `0` = delete immediately (default). During the grace period the org is suspended. |

4. Save the product.

## Provisioning Lifecycle

| HostBill Event | PanelDNS Action |
|---|---|
| Create | `POST /platform/v1/orgs` — provisions a new reseller org |
| Suspend | `POST /platform/v1/orgs/{id}/suspend` |
| Unsuspend | `POST /platform/v1/orgs/{id}/unsuspend` |
| Terminate | `DELETE /platform/v1/orgs/{id}` (or suspend if grace > 0) |
| ChangePackage | `PUT /platform/v1/orgs/{id}/plan` |

The PanelDNS Org ID is stored in the **PanelDNS Org ID** per-account field
(`details.option1`). HostBill displays this in the service detail view.

## Per-Account Details

After provisioning, the service detail page in HostBill admin shows:

| Field | Description |
|---|---|
| **PanelDNS Org ID** | The numeric ID of the reseller's org on the PanelDNS platform |

## PanelDNS Platform API

| Endpoint | Description |
|---|---|
| `POST /platform/v1/orgs` | Create a new reseller org |
| `GET /platform/v1/orgs/{id}/summary` | Usage summary (zones, sub-clients, API calls) |
| `POST /platform/v1/orgs/{id}/sso-token` | Mint a one-time SSO login URL |
| `POST /platform/v1/orgs/{id}/suspend` | Suspend an org |
| `POST /platform/v1/orgs/{id}/unsuspend` | Unsuspend an org |
| `DELETE /platform/v1/orgs/{id}` | Delete an org |
| `PUT /platform/v1/orgs/{id}/plan` | Change the assigned plan |

Authentication: `Authorization: Bearer {PLATFORM_API_KEY}` on every request.

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

- The Platform API key is stored in HostBill's App configuration (encrypted at rest).
- The HTTP client forces `CURLOPT_IPRESOLVE_V4` — cURL never resolves to IPv6, preventing dual-stack SSRF.
- A belt-and-braces SSRF guard in `connect()` rejects server hostnames that resolve to private or reserved IPs.
- TLS certificate verification is enabled by default. Disable only for development environments.
- Minted SSO URLs are validated for the `https://` scheme before use in emails to prevent redirect injection.
- Welcome email content is plain-text only; no HTML rendering of user-supplied data.

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
VERSION=v1.0.0
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
