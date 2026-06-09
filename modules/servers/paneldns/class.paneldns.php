<?php

/**
 * paneldns — HostBill server module for selling PanelDNS sub-client DNS hosting.
 *
 * v2.0.0 — reseller-tier Reseller API (/api/v1). Provisions sub-clients.
 *
 * UPGRADE NOTE: v1.x used the Platform API (/platform/v1) to provision
 * reseller orgs. v2.0.0 is a BREAKING rebuild to the reseller tier so
 * this module matches paneldns-reseller-whmcs feature-for-feature.
 *
 * Drives the reseller-tier API (/api/v1). Used by a reseller who has a
 * PanelDNS org and wants to sell DNS hosting to their own customers via
 * HostBill:
 *
 *   - HostBill Create        → POST /api/v1/sub-clients
 *   - HostBill Suspend       → PATCH /api/v1/sub-clients/{id} {status: suspended}
 *   - HostBill Unsuspend     → PATCH /api/v1/sub-clients/{id} {status: active}
 *   - HostBill Terminate     → DELETE /api/v1/sub-clients/{id}
 *   - HostBill ChangePackage → PATCH /api/v1/sub-clients/{id} {zone_limit, max_records}
 *   - Client SSO             → POST /api/v1/sub-clients/{id}/sso-token → 302 redirect
 *
 * Authentication: a per-user Sanctum Bearer token issued by the reseller's
 * PanelDNS dashboard (Dashboard → API Tokens). Scopes required:
 * sub_clients:write, sub_clients:read.
 *
 * HostBill conventions:
 *   - Class name MUST match the file name (without class. prefix and .php).
 *   - Extends HostingModule (HostBill base class for provisioning modules).
 *   - connect($connect) is called before every lifecycle method.
 *   - Return true/false from lifecycle methods; call $this->addError() for
 *     failures, $this->addInfo() for success messages.
 *   - $this->client_data   — client details (email, firstname, lastname, …).
 *   - $this->account_details — service/account details (id, server_id, …).
 *   - $this->options       — product-level configuration (shared across all
 *     accounts on the same product).
 *   - $this->details       — per-account data (stored per service).
 *
 * File: includes/modules/Hosting/paneldns/class.paneldns.php
 *
 * @package paneldns-hostbill
 */

// Require shared helpers. HostBill loads the module class from
// includes/modules/Hosting/paneldns/, so __DIR__ points there.
require_once __DIR__ . '/../../../shared/PanelDnsApi.php';
require_once __DIR__ . '/../../../shared/LicenceCheck.php';

class paneldns extends HostingModule
{
    /** Displayed in HostBill admin when choosing a module. */
    protected $description = 'PanelDNS — Sub-client DNS Hosting (Reseller)';

    /** Module version — bump in lockstep with the repo release tag. */
    protected $version = '2.1.0';

    /**
     * Server fields shown in Settings → Apps when configuring the server.
     * 'hostname' = PanelDNS base URL (e.g. https://my.paneldns.io).
     * 'hash'     = Reseller API key (Sanctum Bearer token).
     * 'ssl'      = TLS certificate verification (keep ON in production).
     */
    protected $serverFields = [
        'hostname'    => true,
        'ip'          => false,
        'maxaccounts' => false,
        'status_url'  => false,
        'username'    => false,
        'password'    => false,
        'hash'        => true,
        'ssl'         => true,
        'nameservers' => false,
    ];

    protected $serverFieldsDescription = [
        'hostname' => 'PanelDNS Base URL (e.g. https://my.paneldns.io)',
        'hash'     => 'Reseller API Key (Sanctum Bearer token from PanelDNS dashboard → API Tokens)',
        'ssl'      => 'Verify TLS Certificate (recommended: ON)',
    ];

    /**
     * Product-level configuration options.
     * These values are the same for all accounts created from a given product.
     *
     * option1  — Zone Limit (0 = inherit org plan limit)
     * option2  — Max Records Per Zone (0 = inherit org plan limit)
     * option3  — Send Welcome Email on Create (yes/no)
     * option4  — NS1 Hostname override (shown in welcome email / client area)
     * option5  — NS2 Hostname override
     * option6  — NS3 Hostname override
     * option7  — NS4 Hostname override
     * option8  — SOA Email (shown in welcome email)
     * option9  — Auto-Create Zone on Domain Order (yes/no)
     * option10 — Auto-Delete Zone on Domain Expiry (yes/no)
     * option11 — Termination Grace Period (days; 0 = delete immediately)
     */
    protected $options = [
        'option1'  => ['name' => 'Zone Limit',                        'value' => '5',   'type' => 'input', 'default' => '5'],
        'option2'  => ['name' => 'Max Records Per Zone',               'value' => '100', 'type' => 'input', 'default' => '100'],
        'option3'  => ['name' => 'Send Welcome Email',                 'value' => '1',   'type' => 'check', 'default' => '1'],
        'option4'  => ['name' => 'NS1 Hostname',                       'value' => '',    'type' => 'input', 'default' => ''],
        'option5'  => ['name' => 'NS2 Hostname',                       'value' => '',    'type' => 'input', 'default' => ''],
        'option6'  => ['name' => 'NS3 Hostname',                       'value' => '',    'type' => 'input', 'default' => ''],
        'option7'  => ['name' => 'NS4 Hostname',                       'value' => '',    'type' => 'input', 'default' => ''],
        'option8'  => ['name' => 'SOA Email',                          'value' => '',    'type' => 'input', 'default' => ''],
        'option9'  => ['name' => 'Auto-Create Zone on Domain Order',   'value' => '1',   'type' => 'check', 'default' => '1'],
        'option10' => ['name' => 'Auto-Delete Zone on Domain Expiry',  'value' => '0',   'type' => 'check', 'default' => '0'],
        'option11' => ['name' => 'Termination Grace Period (Days)',     'value' => '0',   'type' => 'input', 'default' => '0'],
    ];

    /**
     * Per-account details stored by HostBill against each individual service.
     * option1 — PanelDNS Sub-client ID (set after Create; used by all hooks).
     * option2 — Grace Period Deadline (YYYY-MM-DD; set by Terminate when grace > 0).
     */
    protected $details = [
        'option1' => [
            'name'    => 'PanelDNS Sub-client ID',
            'value'   => false,
            'type'    => 'input',
            'default' => false,
        ],
        'option2' => [
            'name'    => 'Grace Period Deadline',
            'value'   => '',
            'type'    => 'input',
            'default' => '',
        ],
    ];

    /**
     * Custom admin buttons shown on the service detail page.
     * HostBill calls the named method on this class when clicked.
     */
    protected $buttons = [
        'Resend Welcome Email' => 'resendWelcome',
        'Resync Status'        => 'resyncStatus',
    ];

    // ── Internal state ────────────────────────────────────────────────────────

    /** @var PanelDnsApiHb|null */ private $api = null;

    /**
     * CACHE-01: 60-second in-process summary cache keyed by sub-client ID.
     * Prevents repeated subClientSummary() API calls when HostBill renders
     * the admin detail panel, client area, and usage graphs in the same request.
     *
     * @var array<int, array{ts: int, resp: array}>
     */
    private static array $summaryCache = [];

    /**
     * CACHE-02: 5-minute in-process nameserver cache keyed by identity hash.
     * Prevents repeated /api/v1/org/nameservers calls within one request.
     *
     * @var array<string, array{ts: int, ns: string[]}>
     */
    private static array $nsCache = [];

    // ── HostBill lifecycle ────────────────────────────────────────────────────

    /**
     * Called by HostBill before every other method.
     * Receives the server app configuration from Settings → Apps.
     *
     * @param array $connect {
     *   'hostname' string  PanelDNS base URL
     *   'hash'     string  Reseller API key (Sanctum Bearer token)
     *   'secure'   int     1 = verify TLS, 0 = skip
     * }
     */
    public function connect(array $connect): void
    {
        $baseUrl   = rtrim((string) ($connect['hostname'] ?? ''), '/');
        $apiKey    = (string) ($connect['hash'] ?? '');
        $tlsVerify = !empty($connect['secure']);

        // SSRF pre-flight: reject if base URL resolves to a private/reserved IP.
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if ($host !== null && $host !== false) {
            $resolved = gethostbyname($host);
            if (self::isPrivateOrUnresolvable($resolved, $host)) {
                $this->api = null;
                return;
            }
        }

        $this->api = new PanelDnsApiHb($baseUrl, $apiKey, PanelDnsApiHb::MODE_RESELLER, $tlsVerify);
    }

    /**
     * Called when admin clicks "Test Connection" on the App configuration page.
     */
    public function testConnection(): bool
    {
        if (!$this->api) {
            $this->addError('PanelDNS: server hostname is invalid or resolves to a private IP.');
            return false;
        }

        // Use /api/v1/summary rather than /ping so the token scopes are verified.
        $resp = $this->api->summary();
        if (!$resp['ok']) {
            $this->addError('PanelDNS: authentication failed — check the Reseller API Key.');
            return false;
        }

        $this->addInfo('PanelDNS: connection OK.');
        return true;
    }

    /**
     * Create a new PanelDNS sub-client for this service.
     *
     * On success: sets $this->details['option1']['value'] to the new sub-client ID.
     * Returns true so HostBill marks the service Active.
     */
    public function Create(): bool
    {
        try {
            return $this->doCreate();
        } catch (\Throwable $e) {
            // ERR-01: never surface stack traces or token values to the HostBill UI.
            error_log('[paneldns-hostbill] Create exception: ' . get_class($e) . ': ' . $e->getMessage());
            $this->addError('PanelDNS: unexpected error during provisioning — check server error log.');
            return false;
        }
    }

    private function doCreate(): bool
    {
        if (!$this->api) {
            $this->addError('PanelDNS: server connection not initialised — check App configuration.');
            return false;
        }

        // Licence gate — enable when shipping as a paid module.
        // if ($err = PanelDnsLicenceCheckHb::gateOrError($this->api, 'reseller')) {
        //     $this->addError($err); return false;
        // }

        // Idempotency: if already provisioned, just unsuspend.
        $existing = $this->subClientId();
        if ($existing > 0) {
            $resp = $this->api->patchSubClient($existing, ['status' => 'active']);
            if ($resp['ok']) {
                $this->addInfo("PanelDNS: sub-client #{$existing} unsuspended (idempotent create).");
                return true;
            }
            $this->addError('PanelDNS: unsuspend failed — see module activity log.');
            return false;
        }

        $clientName = trim(
            (($this->client_data['firstname'] ?? '') . ' ' . ($this->client_data['lastname'] ?? ''))
        );
        if ($clientName === '') {
            $clientName = $this->client_data['email'] ?? 'unknown';
        }

        $zoneLimit  = (int) ($this->options['option1']['value'] ?? 5);
        $maxRecords = (int) ($this->options['option2']['value'] ?? 100);

        // GDPR-LEGAL-01: stamp legal consent at provisioning time so the
        // sub-client account is covered from creation (actor_type=reseller_api).
        $legalResp    = $this->api->getResellerLegalVersion();
        $legalVersion = ($legalResp['ok'] ?? false) ? (string) ($legalResp['data']['version'] ?? '') : '';

        // PASS-01: use the HostBill-provided service password if available (mirrors
        // $params['password'] in the WHMCS module); otherwise generate a random one.
        $servicePassword = trim((string) ($this->account_details['password'] ?? ''));
        $password        = $servicePassword !== '' ? $servicePassword : bin2hex(random_bytes(12));

        $payload = [
            'name'               => $clientName,
            'email'              => $this->client_data['email'] ?? '',
            'password'           => $password,
            'zone_limit'         => $zoneLimit,
            'max_records'        => $maxRecords,
            'status'             => 'active',
            'terms_acknowledged' => true,
        ];
        if ($legalVersion !== '') {
            $payload['terms_version'] = $legalVersion;
        }

        $resp = $this->api->createSubClient($payload);
        if (!$resp['ok']) {
            $this->addError('PanelDNS: sub-client creation failed — see module activity log.');
            return false;
        }

        $newId = (int) ($resp['data']['id'] ?? 0);
        if ($newId <= 0) {
            $this->addError('PanelDNS: sub-client created but no ID returned.');
            return false;
        }

        // Persist sub-client ID in per-account details so all future hooks can find it.
        $this->details['option1']['value'] = (string) $newId;

        // NS-NOTES-01: surface assigned nameservers to admin via addInfo() so
        // support staff can tell the client where to point their domain without
        // opening PanelDNS. Mirrors WHMCS writeNameserversToServiceNotes().
        $ns = $this->resolveNameservers();
        if (!empty($ns)) {
            $this->addInfo('PanelDNS nameservers: ' . implode(', ', $ns));
        }

        if (!empty($this->options['option3']['value'])) {
            $this->sendWelcomeEmail($newId);
        }

        $this->addInfo("PanelDNS: sub-client #{$newId} created successfully.");
        return true;
    }

    /**
     * Suspend the sub-client's PanelDNS account (e.g. overdue invoice).
     */
    public function Suspend(): bool
    {
        try {
            if (!$this->api) { $this->addError('PanelDNS: server connection not initialised.'); return false; }
            $id = $this->subClientId();
            if ($id <= 0) { $this->addError('PanelDNS: no Sub-client ID found — cannot suspend (was the service provisioned?).'); return false; }
            $resp = $this->api->patchSubClient($id, ['status' => 'suspended']);
            if (!$resp['ok']) { $this->addError('PanelDNS: suspend failed — see module activity log.'); return false; }
            $this->addInfo("PanelDNS: sub-client #{$id} suspended.");
            return true;
        } catch (\Throwable $e) {
            error_log('[paneldns-hostbill] Suspend exception: ' . get_class($e) . ': ' . $e->getMessage());
            $this->addError('PanelDNS: unexpected error during suspend — check server error log.');
            return false;
        }
    }

    /**
     * Unsuspend the sub-client's PanelDNS account (e.g. invoice paid).
     */
    public function Unsuspend(): bool
    {
        try {
            if (!$this->api) { $this->addError('PanelDNS: server connection not initialised.'); return false; }
            $id = $this->subClientId();
            if ($id <= 0) { $this->addError('PanelDNS: no Sub-client ID found — cannot unsuspend.'); return false; }
            $resp = $this->api->patchSubClient($id, ['status' => 'active']);
            if (!$resp['ok']) { $this->addError('PanelDNS: unsuspend failed — see module activity log.'); return false; }
            $this->addInfo("PanelDNS: sub-client #{$id} unsuspended.");
            return true;
        } catch (\Throwable $e) {
            error_log('[paneldns-hostbill] Unsuspend exception: ' . get_class($e) . ': ' . $e->getMessage());
            $this->addError('PanelDNS: unexpected error during unsuspend — check server error log.');
            return false;
        }
    }

    /**
     * Terminate (delete) the sub-client's PanelDNS account.
     *
     * GRACE-01: if option11 (Termination Grace Period) > 0, the sub-client is
     * suspended now rather than deleted. The deadline is stored in
     * $this->details['option2']['value'] (visible in HostBill admin) so the
     * HostBill Task Scheduler can identify and process expired grace accounts.
     * Wire driftSync() into HostBill Task Scheduler to run nightly.
     */
    public function Terminate(): bool
    {
        try {
            if (!$this->api) { $this->addError('PanelDNS: server connection not initialised.'); return false; }

            $id = $this->subClientId();
            if ($id <= 0) {
                $this->addInfo('PanelDNS: no Sub-client ID to terminate (already deleted or never provisioned).');
                return true;
            }

            // FIX-L4: clamp grace period to prevent absurdly large values.
            $graceDays = min(365, max(0, (int) ($this->options['option11']['value'] ?? 0)));

            if ($graceDays > 0) {
                $resp = $this->api->patchSubClient($id, ['status' => 'suspended']);
                if (!$resp['ok']) {
                    $this->addError('PanelDNS: grace-period suspend failed — see module activity log.');
                    return false;
                }
                $deadline = date('Y-m-d', strtotime("+{$graceDays} days"));
                // GRACE-01: persist deadline in per-account details so Task Scheduler
                // and admins can see when the account should be hard-deleted.
                // Mirrors [paneldns-grace:{deadline}] written to tblhosting.notes in WHMCS.
                $this->details['option2']['value'] = $deadline;
                $this->addInfo("PanelDNS: sub-client #{$id} suspended. Grace period ends {$deadline} "
                    . '(stored in Grace Period Deadline field). Wire driftSync() into Task Scheduler to delete after deadline.');
                return true;
            }

            $resp = $this->api->deleteSubClient($id);
            if (!$resp['ok']) { $this->addError('PanelDNS: terminate failed — see module activity log.'); return false; }

            $this->details['option1']['value'] = '';
            $this->details['option2']['value'] = '';
            $this->addInfo("PanelDNS: sub-client #{$id} deleted.");
            return true;
        } catch (\Throwable $e) {
            error_log('[paneldns-hostbill] Terminate exception: ' . get_class($e) . ': ' . $e->getMessage());
            $this->addError('PanelDNS: unexpected error during termination — check server error log.');
            return false;
        }
    }

    /**
     * Upgrade or downgrade the zone/record limits for this sub-client.
     */
    public function ChangePackage(): bool
    {
        try {
            if (!$this->api) { $this->addError('PanelDNS: server connection not initialised.'); return false; }

            $id = $this->subClientId();
            if ($id <= 0) { $this->addError('PanelDNS: no Sub-client ID — cannot change package.'); return false; }

            $zoneLimit  = (int) ($this->options['option1']['value'] ?? 5);
            $maxRecords = (int) ($this->options['option2']['value'] ?? 100);

            $resp = $this->api->patchSubClient($id, [
                'zone_limit'  => $zoneLimit,
                'max_records' => $maxRecords,
            ]);
            if (!$resp['ok']) { $this->addError('PanelDNS: package change failed — see module activity log.'); return false; }

            $this->addInfo("PanelDNS: sub-client #{$id} updated (zones: {$zoneLimit}, records/zone: {$maxRecords}).");
            return true;
        } catch (\Throwable $e) {
            error_log('[paneldns-hostbill] ChangePackage exception: ' . get_class($e) . ': ' . $e->getMessage());
            $this->addError('PanelDNS: unexpected error during package change — check server error log.');
            return false;
        }
    }

    // ── Admin buttons ─────────────────────────────────────────────────────────

    /**
     * Re-mint a one-time SSO login URL and resend the welcome email.
     * Shown as an admin button ("Resend Welcome Email") on the service page.
     */
    public function resendWelcome(): bool
    {
        if (!$this->api) {
            $this->addError('PanelDNS: server connection not initialised.');
            return false;
        }

        $id = $this->subClientId();
        if ($id <= 0) {
            $this->addError('PanelDNS: service not provisioned — cannot send welcome email.');
            return false;
        }

        $sso = $this->api->mintSubClientSsoToken($id);
        if (
            !$sso['ok']
            || empty($sso['data']['login_url'])
            || !str_starts_with((string) ($sso['data']['login_url'] ?? ''), 'https://')
        ) {
            $this->addError('PanelDNS: could not generate portal login link.');
            return false;
        }

        $this->sendWelcomeEmail($id);
        $email = $this->client_data['email'] ?? '';
        $this->addInfo("PanelDNS: welcome email resent to {$email}.");
        return true;
    }

    /**
     * Fetch live sub-client summary and surface key metrics as an info message.
     * Shown as an admin button ("Resync Status") on the service page.
     * Bypasses the 60-second cache so the result is always live.
     */
    public function resyncStatus(): bool
    {
        if (!$this->api) {
            $this->addError('PanelDNS: server connection not initialised.');
            return false;
        }

        $id = $this->subClientId();
        if ($id <= 0) {
            $this->addError('PanelDNS: no Sub-client ID found — cannot resync.');
            return false;
        }

        $resp = $this->api->subClientSummary($id);
        if (!$resp['ok']) {
            $this->addError('PanelDNS: resync failed — see module activity log.');
            return false;
        }

        $usage  = $resp['data']['usage']  ?? [];
        $limits = $resp['data']['limits'] ?? [];
        $zones  = (int) ($usage['zones']   ?? 0);
        $recs   = (int) ($usage['records'] ?? 0);
        $this->addInfo("PanelDNS: sub-client #{$id} — {$zones} zones, {$recs} records.");
        return true;
    }

    // ── SSO ───────────────────────────────────────────────────────────────────

    /**
     * Called by HostBill when the client clicks the SSO login link.
     * Mints a 60-second SSO token, validates the returned URL, then redirects.
     */
    public function ssoLogin(): void
    {
        if (!$this->api) {
            echo $this->h('PanelDNS: server connection not initialised.');
            exit();
        }

        $id = $this->subClientId();
        if ($id <= 0) {
            echo $this->h('PanelDNS: service not provisioned.');
            exit();
        }

        $resp = $this->api->mintSubClientSsoToken($id);

        // SEC: validate returned URL scheme — prevents javascript:/data: injection.
        if (
            !$resp['ok']
            || empty($resp['data']['login_url'])
            || !str_starts_with((string) ($resp['data']['login_url'] ?? ''), 'https://')
        ) {
            // LOG-SSO-01: log SSO failures so admins can diagnose token minting errors
            // without exposing details to the client. Mirrors logModuleCall() in WHMCS.
            error_log('[paneldns-hostbill] ssoLogin failed for sub-client #' . $id
                . ': ' . ($resp['error'] ?? 'invalid or missing login_url'));
            echo $this->h('PanelDNS: could not generate portal login link. Please try again or contact support.');
            exit();
        }

        $loginUrl = (string) $resp['data']['login_url'];
        header('Location: ' . $loginUrl, true, 302);
        exit();
    }

    // ── Usage / detail ────────────────────────────────────────────────────────

    /**
     * Called by HostBill to populate usage graphs.
     * Maps zones → disk and records → bandwidth (standard DNS module pattern).
     *
     * @return array{disk: int, bandwidth: int, disk_limit: int, bandwidth_limit: int}
     */
    public function getUsage(): array
    {
        $empty = ['disk' => 0, 'bandwidth' => 0, 'disk_limit' => 0, 'bandwidth_limit' => 0];

        if (!$this->api) return $empty;

        $id = $this->subClientId();
        if ($id <= 0) return $empty;

        $resp = $this->cachedSubClientSummary($id);
        if (!$resp['ok']) return $empty;

        $usage  = $resp['data']['usage']  ?? [];
        $limits = $resp['data']['limits'] ?? [];

        return [
            'disk'            => (int) ($usage['zones']   ?? 0),
            'bandwidth'       => (int) ($usage['records'] ?? 0),
            // 0 = unlimited in HostBill graph rendering.
            'disk_limit'      => (int) ($limits['zones']  ?? 0),
            'bandwidth_limit' => (int) ($limits['records'] ?? 0),
        ];
    }

    /**
     * Called by HostBill to render extra info in the admin service view.
     * Returns a self-contained HTML snippet; all values are escaped.
     * Matches the adminServicesTabFields() detail panel in the WHMCS module.
     *
     * @return string HTML string.
     */
    public function getServiceDetails(): string
    {
        $id = $this->subClientId();
        if ($id <= 0) return '<em>Not provisioned.</em>';
        if (!$this->api) return '<em>PanelDNS: server connection not initialised.</em>';

        $resp = $this->cachedSubClientSummary($id);
        if (!$resp['ok']) {
            return '<em>PanelDNS: could not load service details ('
                . $this->h($resp['error'] ?? 'API error') . ').</em>';
        }

        $sub    = $resp['data']['sub_client'] ?? [];
        $usage  = $resp['data']['usage']      ?? [];
        $limits = $resp['data']['limits']     ?? [];
        $server = $resp['data']['server']     ?? [];

        $status = $sub['status'] ?? 'unknown';
        $colour = match ($status) {
            'active'    => '#0a7',
            'suspended' => '#c80',
            default     => '#888',
        };

        $zonesUsed  = (int) ($usage['zones']   ?? 0);
        $zonesLimit = (int) ($limits['zones']  ?? 0);
        $recsUsed   = (int) ($usage['records'] ?? 0);
        $recsLimit  = (int) ($limits['records'] ?? 0);

        $zoneBar = $this->progressBar($zonesUsed, $zonesLimit);
        $recsBar = $this->progressBar($recsUsed, $recsLimit);

        $lastSync = $usage['last_synced_at']
            ?? $sub['last_synced_at']
            ?? $server['last_synced_at']
            ?? null;
        $lastSyncStr = $lastSync
            ? $this->h($lastSync)
            : '<span style="color:#9ca3af;">—</span>';

        // ZONE-LIST-01: fetch up to 20 zone names for this sub-client.
        $zoneNames = '<span style="color:#9ca3af;">—</span>';
        $zonesResp = $this->api->get('/api/v1/zones', ['sub_client_id' => $id, 'per_page' => 20]);
        if ($zonesResp['ok'] && !empty($zonesResp['data'])) {
            $names     = array_map(fn ($z) => $this->h($z['name'] ?? ''), $zonesResp['data']);
            $zoneNames = implode('<br>', $names);
            if (count($names) >= 20) {
                $zoneNames .= '<br><em style="color:#9ca3af;font-size:11px;">… and more</em>';
            }
        }

        return '<table style="border-collapse:collapse;font-size:13px;width:100%;">'
            . '<tr><th style="text-align:left;padding:3px 8px;">Module version</th>'
            .     '<td style="padding:3px 8px;color:#6b7280;">paneldns-hostbill v' . $this->h($this->version) . '</td></tr>'
            . '<tr><th style="text-align:left;padding:3px 8px;">Sub-client ID</th>'
            .     '<td style="padding:3px 8px;font-weight:600;">' . $this->h($id) . '</td></tr>'
            . '<tr><th style="text-align:left;padding:3px 8px;">Status</th>'
            .     '<td style="padding:3px 8px;color:' . $this->h($colour) . ';font-weight:600;text-transform:capitalize;">' . $this->h($status) . '</td></tr>'
            . '<tr><th style="text-align:left;padding:3px 8px;">Sub-client Email</th>'
            .     '<td style="padding:3px 8px;">' . $this->h($sub['email'] ?? '') . '</td></tr>'
            . '<tr><th style="text-align:left;padding:3px 8px;">Zones used / limit</th>'
            .     '<td style="padding:3px 8px;">'
            .         $this->h("{$zonesUsed} / " . ($zonesLimit > 0 ? $zonesLimit : '∞'))
            .         $zoneBar
            .     '</td></tr>'
            . '<tr><th style="text-align:left;padding:3px 8px;">Records used / limit</th>'
            .     '<td style="padding:3px 8px;">'
            .         $this->h("{$recsUsed} / " . ($recsLimit > 0 ? $recsLimit : '∞'))
            .         $recsBar
            .     '</td></tr>'
            . '<tr><th style="text-align:left;padding:3px 8px;">Last sync</th>'
            .     '<td style="padding:3px 8px;">' . $lastSyncStr . '</td></tr>'
            . '<tr><th style="text-align:left;padding:3px 8px;vertical-align:top;">Zones</th>'
            .     '<td style="padding:3px 8px;font-size:12px;">' . $zoneNames . '</td></tr>'
            . '</table>';
    }

    // ── Client area ───────────────────────────────────────────────────────────

    /**
     * Called by HostBill to render HTML in the client portal service view.
     * Returns a self-contained HTML block embedded in HostBill's page.
     *
     * Navigation is GET-based (?pdns=zones, ?pdns=records&zone=N, etc.).
     * Mutations are POST-based (hidden pdns_action field in each form).
     * All server-supplied values are escaped with htmlspecialchars().
     */
    public function clientArea(): string
    {
        if (!$this->api) {
            return '<div class="alert alert-danger">PanelDNS: server connection not configured. Please contact support.</div>';
        }

        if ($this->subClientId() <= 0) {
            return '<div class="alert alert-info">Your DNS hosting account is being set up. Please check back shortly or contact support.</div>';
        }

        // RATE-01: rate-limit all client-area requests to 60 per minute per service.
        // Uses a session counter since \WHMCS\Cache\Store is not available in HostBill.
        // Mirrors the FIX-M6 rate limit in EmbeddedDnsManager::handle() in the WHMCS module.
        if ($this->rateLimitExceeded()) {
            return '<div class="alert alert-danger">Too many requests. Please wait a moment and try again.</div>';
        }

        // POST mutations take priority over GET page renders.
        $postAction = trim((string) ($_POST['pdns_action'] ?? ''));
        if ($postAction !== '') {
            return $this->dispatchPostAction($postAction);
        }

        $getPage = trim((string) ($_GET['pdns'] ?? ''));

        // SSO redirect: mint a token and use a JS redirect (since we return HTML).
        if ($getPage === 'sso') {
            return $this->doClientSsoRedirect();
        }

        // EXPORT-01: BIND zone download — streams file and exits (never returns HTML).
        if ($getPage === 'zone-export') {
            return $this->doZoneExport();
        }

        return match ($getPage) {
            'zones'       => $this->renderZonesList(),
            'records'     => $this->renderRecords(),
            'zone-create' => $this->renderZoneCreate(),
            'zone-import' => $this->renderZoneImport(),
            default       => $this->renderOverview(),
        };
    }

    // ── POST dispatch ─────────────────────────────────────────────────────────

    /**
     * Dispatch a POST mutation after verifying CSRF.
     * All mutating form submissions go through here.
     */
    private function dispatchPostAction(string $action): string
    {
        $this->requireCsrf();

        return match ($action) {
            'do-zone-create'   => $this->doZoneCreate(),
            'do-zone-import'   => $this->doZoneImport(),
            'do-zone-delete'   => $this->doZoneDelete(),
            'do-record-create' => $this->doRecordCreate(),
            'do-record-update' => $this->doRecordUpdate(),
            'do-record-delete' => $this->doRecordDelete(),
            'do-dnssec-toggle' => $this->doDnssecToggle(),
            default            => $this->renderOverview(),
        };
    }

    // ── Client area pages ─────────────────────────────────────────────────────

    /**
     * Overview — default client area view.
     * Shows usage cards, nameservers, zone health widget, and action buttons.
     */
    private function renderOverview(): string
    {
        $id   = $this->subClientId();
        $out  = $this->flashHtml();

        $resp = $this->cachedSubClientSummary($id);
        if (!$resp['ok']) {
            return $out
                . '<div class="alert alert-warning">Could not load account details. Please try again or contact support.</div>'
                . '<a href="' . $this->h($this->pageUrl('sso')) . '" class="btn btn-primary">Login to PanelDNS Portal</a>';
        }

        $sub    = $resp['data']['sub_client'] ?? [];
        $usage  = $resp['data']['usage']      ?? [];
        $limits = $resp['data']['limits']     ?? [];
        $status = $sub['status'] ?? 'unknown';

        // Suspension notice.
        if ($status === 'suspended') {
            $out .= '<div class="alert alert-danger"><strong>Your account is currently suspended.</strong> '
                . 'Please contact support to restore access.</div>';
        }

        // GDPR consent banner (CONSENT-R-02): show when sub-client has not accepted
        // the current terms, mirrors paneldns_requires_consent in the WHMCS template.
        if ((bool) ($sub['requires_consent'] ?? false) && !empty($sub['portal_sso_url'])) {
            $out .= '<div class="alert alert-warning"><strong>Action required:</strong> '
                . 'Please <a href="' . $this->h($sub['portal_sso_url']) . '" target="_blank" rel="noopener noreferrer">'
                . 'review and accept</a> the updated Terms of Service for your PanelDNS portal.</div>';
        }

        // Usage cards.
        $zonesUsed  = (int) ($usage['zones']   ?? 0);
        $zonesLimit = (int) ($limits['zones']  ?? 0);
        $recsUsed   = (int) ($usage['records'] ?? 0);
        $recsLimit  = (int) ($limits['records'] ?? 0);

        $out .= '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">';
        $out .= $this->usageCard('DNS Zones',   $zonesUsed, $zonesLimit);
        $out .= $this->usageCard('DNS Records', $recsUsed,  $recsLimit);
        $out .= '</div>';

        // Nameservers.
        $ns = $this->cachedNameservers();
        if (!empty($ns)) {
            $out .= '<div class="panel panel-default" style="margin-bottom:16px;">'
                . '<div class="panel-heading"><strong>Your Nameservers</strong></div>'
                . '<div class="panel-body"><p style="margin-bottom:8px;color:#6b7280;font-size:13px;">'
                . 'Point your domains to these nameservers:</p>'
                . '<ul style="margin:0;padding-left:20px;">';
            foreach ($ns as $n) {
                $out .= '<li><code>' . $this->h($n) . '</code></li>';
            }
            $out .= '</ul></div></div>';
        }

        // Zone health widget: surface only troubled zones so client sees problems first.
        try {
            $zonesResp = $this->api->get('/api/v1/zones', ['sub_client_id' => $id, 'per_page' => 20]);
            if ($zonesResp['ok'] && !empty($zonesResp['data'])) {
                $troubled = array_filter($zonesResp['data'], fn ($z) => ($z['status'] ?? 'active') !== 'active');
                if (!empty($troubled)) {
                    $out .= '<div class="alert alert-warning"><strong>Zone issues detected:</strong>'
                        . '<ul style="margin:4px 0 0 16px;">';
                    foreach ($troubled as $z) {
                        $out .= '<li>' . $this->h($z['name'] ?? '') . ' — ' . $this->h($z['status'] ?? '') . '</li>';
                    }
                    $out .= '</ul></div>';
                }
            }
        } catch (\Throwable $e) { /* non-fatal — widget simply does not render */ }

        // Action buttons.
        $out .= '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
        $out .= '<a href="' . $this->h($this->pageUrl('zones')) . '" class="btn btn-primary">Manage DNS Zones</a> ';
        $out .= '<a href="' . $this->h($this->pageUrl('sso'))   . '" class="btn btn-default">Open Full Portal</a>';
        $out .= '</div>';

        return $out;
    }

    /**
     * Zones list — table of all zones for this sub-client.
     */
    private function renderZonesList(): string
    {
        $id    = $this->subClientId();
        $resp  = $this->api->get('/api/v1/zones', ['sub_client_id' => $id, 'per_page' => 100]);
        $zones = $resp['ok'] ? ($resp['data'] ?? []) : [];
        $csrf  = $this->csrfToken();
        $out   = $this->flashHtml();

        $out .= '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">'
            . '<h4 style="margin:0;">DNS Zones</h4>'
            . '<div>'
            . '<a href="' . $this->h($this->pageUrl('zone-create')) . '" class="btn btn-sm btn-success">+ Add Zone</a> '
            . '<a href="' . $this->h($this->pageUrl('zone-import')) . '" class="btn btn-sm btn-default">Import BIND</a> '
            . '<a href="' . $this->h($this->pageUrl()) . '" class="btn btn-sm btn-link">← Overview</a>'
            . '</div></div>';

        if (!$resp['ok']) {
            $out .= '<div class="alert alert-danger">Could not load zones: ' . $this->h($resp['error'] ?? 'API error') . '</div>';
            return $out;
        }

        if (empty($zones)) {
            $out .= '<div class="alert alert-info">No zones yet. '
                . '<a href="' . $this->h($this->pageUrl('zone-create')) . '">Add your first zone →</a></div>';
            return $out;
        }

        $out .= '<table class="table table-striped table-hover" style="font-size:13px;">'
            . '<thead><tr><th>Zone Name</th><th>Status</th><th>Records</th><th>Actions</th></tr></thead>'
            . '<tbody>';

        foreach ($zones as $zone) {
            $zid      = (int) ($zone['id'] ?? 0);
            $name     = (string) ($zone['name'] ?? '');
            $zstatus  = (string) ($zone['status'] ?? 'active');
            $recCount = (int) ($zone['record_count'] ?? 0);
            $zcolour  = $zstatus === 'active' ? '#0a7' : '#c80';

            $recordsUrl = $this->pageUrl('records') . '&zone=' . $zid;
            $exportUrl  = $this->pageUrl('zone-export') . '&zone=' . $zid;

            $out .= '<tr>'
                . '<td>' . $this->h($name) . '</td>'
                . '<td><span style="color:' . $this->h($zcolour) . ';font-weight:600;text-transform:capitalize;">'
                .     $this->h($zstatus) . '</span></td>'
                . '<td>' . $this->h($recCount) . '</td>'
                . '<td>'
                .     '<a href="' . $this->h($recordsUrl) . '" class="btn btn-xs btn-primary">Manage</a> '
                .     '<a href="' . $this->h($exportUrl)  . '" class="btn btn-xs btn-default">Export</a> '
                .     '<form method="POST" action="" style="display:inline;" '
                .         'onsubmit="return confirm(\'Delete zone ' . addslashes($this->h($name)) . '? This cannot be undone.\')">'
                .         '<input type="hidden" name="pdns_action" value="do-zone-delete">'
                .         '<input type="hidden" name="zone_id" value="' . $this->h($zid) . '">'
                .         '<input type="hidden" name="csrf" value="' . $this->h($csrf) . '">'
                .         '<button type="submit" class="btn btn-xs btn-danger">Delete</button>'
                .     '</form>'
                . '</td>'
                . '</tr>';
        }

        $out .= '</tbody></table>';
        return $out;
    }

    /**
     * Records page — table of records for one zone, plus add/edit forms and DNSSEC.
     */
    private function renderRecords(): string
    {
        $zoneId = (int) ($_GET['zone'] ?? 0);
        if ($zoneId <= 0) { return $this->renderZonesList(); }

        $zone = $this->fetchOwnZone($zoneId);
        if (!$zone) {
            $this->flash('error', 'Zone not found.');
            return $this->renderZonesList();
        }

        $records = $this->api->get("/api/v1/zones/{$zoneId}/records", ['per_page' => 200]);
        $recs    = $records['ok'] ? ($records['data'] ?? []) : [];
        $dnssec  = $this->fetchDnssecStatus($zoneId);
        $ns      = $this->cachedNameservers();
        $csrf    = $this->csrfToken();
        $editId  = (int) ($_GET['edit'] ?? 0) ?: null;
        $out     = $this->flashHtml();

        // Header.
        $out .= '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">'
            . '<h4 style="margin:0;">Records: <strong>' . $this->h($zone['name'] ?? '') . '</strong></h4>'
            . '<a href="' . $this->h($this->pageUrl('zones')) . '" class="btn btn-sm btn-link">← Zones</a>'
            . '</div>';

        // NS-CARD-01: nameservers "point your domain here" card.
        if (!empty($ns)) {
            $out .= '<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:4px;padding:10px 14px;margin-bottom:14px;font-size:13px;">'
                . '<strong>Point your domain here:</strong> '
                . implode(', ', array_map(fn ($n) => '<code>' . $this->h($n) . '</code>', $ns))
                . '</div>';
        }

        if (!$records['ok']) {
            $out .= '<div class="alert alert-danger">Could not load records: ' . $this->h($records['error'] ?? 'API error') . '</div>';
        } elseif (empty($recs)) {
            $out .= '<div class="alert alert-info">No records yet. Add one below.</div>';
        } else {
            $out .= '<table class="table table-condensed table-hover" style="font-size:12px;">'
                . '<thead><tr><th>Name</th><th>Type</th><th>Content</th><th>TTL</th><th>Prio</th><th>Actions</th></tr></thead>'
                . '<tbody>';

            foreach ($recs as $rec) {
                $rid    = (int) ($rec['id'] ?? 0);
                $isEdit = ($editId !== null && $editId === $rid);

                if ($isEdit) {
                    $out .= '<tr style="background:#fffbeb;"><td colspan="6">'
                        . $this->recordEditFormHtml($zoneId, $rec, $csrf)
                        . '</td></tr>';
                } else {
                    $editUrl = $this->pageUrl('records') . '&zone=' . $zoneId . '&edit=' . $rid;
                    $out .= '<tr>'
                        . '<td>' . $this->h($rec['name'] ?? '') . '</td>'
                        . '<td><span class="label label-default">' . $this->h($rec['type'] ?? '') . '</span></td>'
                        . '<td style="max-width:280px;word-break:break-all;">' . $this->h($rec['content'] ?? '') . '</td>'
                        . '<td>' . $this->h($rec['ttl'] ?? '') . '</td>'
                        . '<td>' . $this->h($rec['priority'] ?? '') . '</td>'
                        . '<td>'
                        .     '<a href="' . $this->h($editUrl) . '" class="btn btn-xs btn-default">Edit</a> '
                        .     '<form method="POST" action="" style="display:inline;" onsubmit="return confirm(\'Delete this record?\')">'
                        .         '<input type="hidden" name="pdns_action" value="do-record-delete">'
                        .         '<input type="hidden" name="zone_id" value="' . $this->h($zoneId) . '">'
                        .         '<input type="hidden" name="record_id" value="' . $this->h($rid) . '">'
                        .         '<input type="hidden" name="csrf" value="' . $this->h($csrf) . '">'
                        .         '<button type="submit" class="btn btn-xs btn-danger">Del</button>'
                        .     '</form>'
                        . '</td></tr>';
                }
            }
            $out .= '</tbody></table>';
        }

        // Add record form.
        $out .= '<div class="panel panel-default" style="margin-top:16px;">'
            . '<div class="panel-heading"><strong>Add Record</strong></div>'
            . '<div class="panel-body">'
            . $this->recordAddFormHtml($zoneId, $csrf)
            . '</div></div>';

        // DNSSEC-01: signing state + DS records card. null if provider doesn't support it.
        if ($dnssec !== null) {
            $enabled = (bool) ($dnssec['enabled'] ?? false);
            $out .= '<div class="panel panel-default" style="margin-top:12px;">'
                . '<div class="panel-heading"><strong>DNSSEC</strong></div>'
                . '<div class="panel-body">'
                . '<p>Signing is currently <strong>' . ($enabled ? 'enabled' : 'disabled') . '</strong>.</p>';
            if ($enabled && !empty($dnssec['ds_records'])) {
                $out .= '<p><strong>DS Records</strong> (add these to your domain registrar):</p>'
                    . '<ul style="font-family:monospace;font-size:12px;">';
                foreach ($dnssec['ds_records'] as $ds) {
                    $out .= '<li>' . $this->h($ds) . '</li>';
                }
                $out .= '</ul>';
            }
            $out .= '<form method="POST" action="">'
                . '<input type="hidden" name="pdns_action" value="do-dnssec-toggle">'
                . '<input type="hidden" name="zone_id" value="' . $this->h($zoneId) . '">'
                . '<input type="hidden" name="enable" value="' . ($enabled ? '0' : '1') . '">'
                . '<input type="hidden" name="csrf" value="' . $this->h($csrf) . '">'
                . '<button type="submit" class="btn btn-sm ' . ($enabled ? 'btn-warning' : 'btn-success') . '">'
                . ($enabled ? 'Disable DNSSEC' : 'Enable DNSSEC')
                . '</button></form>'
                . '</div></div>';
        }

        return $out;
    }

    /**
     * Zone create form.
     */
    private function renderZoneCreate(): string
    {
        $csrf = $this->csrfToken();
        $out  = $this->flashHtml();
        $out .= '<div style="max-width:480px;">'
            . '<div style="display:flex;justify-content:space-between;margin-bottom:12px;">'
            .     '<h4 style="margin:0;">Add DNS Zone</h4>'
            .     '<a href="' . $this->h($this->pageUrl('zones')) . '" class="btn btn-sm btn-link">← Zones</a>'
            . '</div>'
            . '<form method="POST" action="">'
            .     '<input type="hidden" name="pdns_action" value="do-zone-create">'
            .     '<input type="hidden" name="csrf" value="' . $this->h($csrf) . '">'
            .     '<div class="form-group">'
            .         '<label>Zone Name (domain)</label>'
            .         '<input type="text" name="name" class="form-control" placeholder="example.com" required autocomplete="off">'
            .     '</div>'
            .     '<button type="submit" class="btn btn-primary">Create Zone</button>'
            . '</form></div>';
        return $out;
    }

    /**
     * Zone import form (BIND-format text input).
     */
    private function renderZoneImport(): string
    {
        $csrf     = $this->csrfToken();
        $id       = $this->subClientId();
        $zonesRsp = $this->api->get('/api/v1/zones', ['sub_client_id' => $id, 'per_page' => 100]);
        $zones    = $zonesRsp['ok'] ? ($zonesRsp['data'] ?? []) : [];
        $out      = $this->flashHtml();

        $out .= '<div style="max-width:640px;">'
            . '<div style="display:flex;justify-content:space-between;margin-bottom:12px;">'
            .     '<h4 style="margin:0;">Import BIND Zone</h4>'
            .     '<a href="' . $this->h($this->pageUrl('zones')) . '" class="btn btn-sm btn-link">← Zones</a>'
            . '</div>'
            . '<form method="POST" action="">'
            .     '<input type="hidden" name="pdns_action" value="do-zone-import">'
            .     '<input type="hidden" name="csrf" value="' . $this->h($csrf) . '">'
            .     '<div class="form-group">'
            .         '<label>Target Zone</label>'
            .         '<select name="zone_id" class="form-control" required>'
            .         '<option value="">— Select zone —</option>';
        foreach ($zones as $zone) {
            $out .= '<option value="' . $this->h($zone['id'] ?? '') . '">'
                . $this->h($zone['name'] ?? '') . '</option>';
        }
        $out .=     '</select></div>'
            . '<div class="form-group">'
            .     '<label>BIND Zone Data</label>'
            .     '<textarea name="bind" class="form-control" rows="12" '
            .         'placeholder="; paste BIND-format zone text here" required '
            .         'style="font-family:monospace;font-size:12px;"></textarea>'
            .     '<small class="text-muted">Maximum 512 KB. Import adds new records; does not remove existing ones.</small>'
            . '</div>'
            . '<button type="submit" class="btn btn-primary">Import</button>'
            . '</form></div>';
        return $out;
    }

    // ── Mutations ─────────────────────────────────────────────────────────────

    private function doZoneCreate(): string
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $this->flash('error', 'Zone name is required.');
            return $this->renderZoneCreate();
        }

        // FIX-H4: validate zone name — 253 char limit, no consecutive dots, RFC-safe chars.
        if (
            strlen($name) > 253
            || str_contains($name, '..')
            || !preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9_\-]|\.[a-zA-Z0-9])*$/', $name)
        ) {
            $this->flash('error', 'Invalid zone name.');
            return $this->renderZoneCreate();
        }

        $id = $this->subClientId();

        // QUOTA-01: pre-flight check — friendly message rather than a raw API error.
        $summary = $this->api->subClientSummary($id);
        if ($summary['ok']) {
            $used  = (int) ($summary['data']['usage']['zones']  ?? 0);
            $limit = (int) ($summary['data']['limits']['zones'] ?? 0);
            if ($limit > 0 && $used >= $limit) {
                $this->flash('error', "You've reached your zone limit ({$used}/{$limit}). Please contact support to upgrade.");
                return $this->renderZoneCreate();
            }
        }

        $resp = $this->api->post('/api/v1/zones', [
            'name'          => $name,
            'sub_client_id' => $id,
        ]);

        if (!$resp['ok']) {
            $this->flash('error', 'Failed to create zone: ' . $this->apiError($resp));
            return $this->renderZoneCreate();
        }

        $this->rotateCsrf();
        $this->flash('success', 'Zone ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ' created.');
        return $this->renderZonesList();
    }

    /**
     * EXPORT-01: stream the zone's BIND-format text as a file download.
     * Must be triggered via GET (?pdns=zone-export&zone=N). Outputs headers
     * + body and exits; never returns to the client area template.
     */
    private function doZoneExport(): string
    {
        $zoneId = (int) ($_GET['zone'] ?? 0);
        if ($zoneId <= 0) {
            $this->flash('error', 'Zone ID required for export.');
            return $this->renderZonesList();
        }

        $zone = $this->fetchOwnZone($zoneId);
        if (!$zone) {
            $this->flash('error', 'Zone not found.');
            return $this->renderZonesList();
        }

        $resp = $this->api->get("/api/v1/zones/{$zoneId}/export");

        // Export returns text/plain — check HTTP status, not 'ok' (which requires JSON).
        if (
            ($resp['status'] ?? 0) < 200
            || ($resp['status'] ?? 0) >= 300
            || trim((string) ($resp['raw_body'] ?? '')) === ''
        ) {
            $this->flash('error', 'Export failed. The zone may not have a DNS provider configured yet.');
            return $this->renderZonesList();
        }

        $zoneName = preg_replace('/[^a-z0-9._-]/i', '_', (string) ($zone['name'] ?? 'zone'));
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $zoneName . '.zone"');
        header('Content-Length: ' . strlen((string) $resp['raw_body']));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $resp['raw_body'];
        exit;
    }

    private function doZoneImport(): string
    {
        $zoneId   = (int) ($_POST['zone_id'] ?? 0);
        $bindText = (string) ($_POST['bind'] ?? '');

        if ($zoneId <= 0) { $this->flash('error', 'Pick a zone first.'); return $this->renderZoneImport(); }
        // SEC-H9: ownership check BEFORE size cap — prevents large payload allocation for non-owned zones.
        if (!$this->fetchOwnZone($zoneId)) { $this->flash('error', 'Zone not found.'); return $this->renderZoneImport(); }
        if (trim($bindText) === '') { $this->flash('error', 'Paste BIND-format zone text.'); return $this->renderZoneImport(); }
        // SEC-M03: cap import payload to prevent memory-exhaustion DoS.
        if (strlen($bindText) > 512 * 1024) { $this->flash('error', 'Import data too large (max 512 KB).'); return $this->renderZoneImport(); }

        $resp = $this->api->post("/api/v1/zones/{$zoneId}/import", ['bind' => $bindText]);
        if (!$resp['ok']) {
            $this->flash('error', 'Import failed: ' . $this->apiError($resp));
            return $this->renderZoneImport();
        }

        $count = $resp['data']['imported'] ?? '?';
        $this->rotateCsrf();
        $this->flash('success', "Imported {$count} records into the zone.");
        // Redirect to the records page for the just-imported zone, matching WHMCS behaviour
        // (EmbeddedDnsManager::doZoneImport() calls redirectTo('records', "&zone={$zoneId}")).
        $_GET['zone'] = $zoneId;
        return $this->renderRecords();
    }

    private function doZoneDelete(): string
    {
        $zoneId = (int) ($_POST['zone_id'] ?? 0);
        if ($zoneId <= 0 || !$this->fetchOwnZone($zoneId)) {
            $this->flash('error', 'Zone not found.');
            return $this->renderZonesList();
        }

        $resp = $this->api->delete("/api/v1/zones/{$zoneId}");
        if (!$resp['ok']) {
            $this->flash('error', 'Delete failed: ' . $this->apiError($resp));
            return $this->renderZonesList();
        }

        $this->rotateCsrf();
        $this->flash('success', 'Zone deleted.');
        return $this->renderZonesList();
    }

    private function doRecordCreate(): string
    {
        $zoneId = (int) ($_POST['zone_id'] ?? 0);
        if (!$this->fetchOwnZone($zoneId)) {
            $this->flash('error', 'Zone not found.');
            return $this->renderZonesList();
        }

        try {
            $payload = $this->recordPayloadFromPost();
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->renderRecords();
        }

        $resp = $this->api->post("/api/v1/zones/{$zoneId}/records", $payload);
        if (!$resp['ok']) {
            $this->flash('error', 'Add record failed: ' . $this->apiError($resp));
        } else {
            $this->rotateCsrf();
            $this->flash('success', 'Record added.');
        }
        return $this->renderRecords();
    }

    private function doRecordUpdate(): string
    {
        $zoneId   = (int) ($_POST['zone_id']   ?? 0);
        $recordId = (int) ($_POST['record_id'] ?? 0);

        if (!$this->fetchOwnZone($zoneId) || $recordId <= 0) {
            $this->flash('error', 'Record not found.');
            return $this->renderZonesList();
        }

        // FIX-H3: verify the record belongs to this zone before mutating it.
        $rec = $this->api->get("/api/v1/zones/{$zoneId}/records/{$recordId}");
        if (!$rec['ok']) {
            $this->flash('error', 'Record not found.');
            return $this->renderZonesList();
        }

        try {
            $payload = $this->recordPayloadFromPost();
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->renderRecords();
        }

        $resp = $this->api->patch("/api/v1/zones/{$zoneId}/records/{$recordId}", $payload);
        if (!$resp['ok']) {
            $this->flash('error', 'Update failed: ' . $this->apiError($resp));
        } else {
            $this->rotateCsrf();
            $this->flash('success', 'Record updated.');
        }
        return $this->renderRecords();
    }

    private function doRecordDelete(): string
    {
        $zoneId   = (int) ($_POST['zone_id']   ?? 0);
        $recordId = (int) ($_POST['record_id'] ?? 0);

        if (!$this->fetchOwnZone($zoneId) || $recordId <= 0) {
            $this->flash('error', 'Record not found.');
            return $this->renderZonesList();
        }

        // FIX-H3: verify the record belongs to this zone before deleting it.
        $rec = $this->api->get("/api/v1/zones/{$zoneId}/records/{$recordId}");
        if (!$rec['ok']) {
            $this->flash('error', 'Record not found.');
            return $this->renderZonesList();
        }

        $resp = $this->api->delete("/api/v1/zones/{$zoneId}/records/{$recordId}");
        if (!$resp['ok']) {
            $this->flash('error', 'Delete failed: ' . $this->apiError($resp));
        } else {
            $this->rotateCsrf();
            $this->flash('success', 'Record deleted.');
        }
        return $this->renderRecords();
    }

    /**
     * DNSSEC-01: toggle DNSSEC signing on a zone.
     */
    private function doDnssecToggle(): string
    {
        $zoneId = (int) ($_POST['zone_id'] ?? 0);
        if (!$this->fetchOwnZone($zoneId)) {
            $this->flash('error', 'Zone not found.');
            return $this->renderZonesList();
        }

        $enable = isset($_POST['enable']) && (string) $_POST['enable'] === '1';
        $resp   = $this->api->post("/api/v1/zones/{$zoneId}/dnssec", ['enable' => $enable]);

        if (!$resp['ok']) {
            $this->flash('error', 'DNSSEC ' . ($enable ? 'enable' : 'disable') . ' failed: ' . $this->apiError($resp));
        } else {
            $this->rotateCsrf();
            $this->flash(
                'success',
                $enable
                    ? 'DNSSEC enabled. Add the DS records shown below to your domain registrar to complete setup.'
                    : 'DNSSEC disabled.'
            );
        }
        return $this->renderRecords();
    }

    /**
     * SSO redirect from within the client area (GET ?pdns=sso).
     * Uses JavaScript window.location since we are returning HTML — cannot
     * issue a real 302 from inside clientArea() without exiting.
     */
    private function doClientSsoRedirect(): string
    {
        $id   = $this->subClientId();
        $resp = $this->api->mintSubClientSsoToken($id);

        if (
            $resp['ok']
            && !empty($resp['data']['login_url'])
            && str_starts_with((string) ($resp['data']['login_url'] ?? ''), 'https://')
        ) {
            $url = $this->h($resp['data']['login_url']);
            return '<script>window.location.href="' . $url . '";</script>'
                . '<p>Redirecting to PanelDNS portal…</p>'
                . '<p><a href="' . $url . '">Click here if not redirected automatically</a>.</p>';
        }

        $this->flash('error', 'Could not generate portal login link. Please try again or contact support.');
        return $this->renderOverview();
    }

    // ── Drift sync ────────────────────────────────────────────────────────────

    /**
     * Drift-sync check: compare PanelDNS sub-client statuses against the
     * caller-supplied HostBill service status map and report mismatches.
     *
     * Wire this into HostBill's Task Scheduler to run nightly or hourly.
     * Pass the service map via $this->options['drift_sync_map']:
     *
     *   [
     *     ['sub_client_id' => 123, 'status' => 'Active'],
     *     ['sub_client_id' => 456, 'status' => 'Suspended'],
     *   ]
     *
     * @return array{
     *   checked: int,
     *   mismatched: list<array{sub_client_id: int, hb_status: string, pdns_status: string}>
     * }
     */
    public function driftSync(): array
    {
        $map = $this->options['drift_sync_map'] ?? [];
        if (!is_array($map) || empty($map)) {
            return ['checked' => 0, 'mismatched' => []];
        }

        $checked    = 0;
        $mismatched = [];

        foreach ($map as $entry) {
            $scId     = (int)   ($entry['sub_client_id'] ?? 0);
            $hbStatus = strtolower(trim((string) ($entry['status'] ?? '')));
            if ($scId <= 0 || $hbStatus === '') continue;
            if (!$this->api) continue;

            $resp = $this->api->subClientSummary($scId);
            if (!$resp['ok']) continue;

            $checked++;
            $pdnsStatus = strtolower((string) ($resp['data']['sub_client']['status'] ?? ''));

            // Mismatch: PanelDNS says suspended but HostBill considers active, or vice versa.
            if (
                ($hbStatus === 'active'    && $pdnsStatus === 'suspended')
                || ($hbStatus === 'suspended' && $pdnsStatus === 'active')
            ) {
                $mismatched[] = [
                    'sub_client_id' => $scId,
                    'hb_status'     => $hbStatus,
                    'pdns_status'   => $pdnsStatus,
                ];
            }
        }

        return ['checked' => $checked, 'mismatched' => $mismatched];
    }

    // ── HTML component helpers ────────────────────────────────────────────────

    private function usageCard(string $label, int $used, int $limit): string
    {
        $limitStr = $limit > 0 ? (string) $limit : '∞';
        $bar      = $this->progressBar($used, $limit);
        return '<div style="border:1px solid #e5e7eb;border-radius:6px;padding:12px 16px;min-width:160px;">'
            . '<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;">'
            .     $this->h($label)
            . '</div>'
            . '<div style="font-size:22px;font-weight:700;margin:4px 0;">'
            .     $this->h((string) $used)
            .     '<span style="font-size:14px;color:#9ca3af;">/' . $this->h($limitStr) . '</span>'
            . '</div>'
            . $bar
            . '</div>';
    }

    private function progressBar(int $used, int $limit): string
    {
        if ($limit <= 0) return '';
        $pct   = min(100, (int) round($used * 100 / $limit));
        $color = $pct >= 90 ? '#dc2626' : ($pct >= 75 ? '#f59e0b' : '#0891b2');
        return ' <span style="display:inline-block;width:80px;height:8px;background:#e5e7eb;border-radius:4px;vertical-align:middle;overflow:hidden;">'
            . '<span style="display:block;height:100%;width:' . $pct . '%;background:' . $color . ';border-radius:4px;"></span>'
            . '</span>'
            . ' <span style="color:#6b7280;font-size:11px;">' . $pct . '%</span>';
    }

    private function recordAddFormHtml(int $zoneId, string $csrf): string
    {
        $types    = ['A','AAAA','CNAME','MX','TXT','NS','SRV','CAA','PTR','TLSA','SSHFP','HTTPS','NAPTR'];
        $typeOpts = implode('', array_map(fn ($t) => '<option>' . $this->h($t) . '</option>', $types));

        return '<div style="display:grid;grid-template-columns:1fr 90px 1fr 80px 70px auto;gap:6px;align-items:end;">'
            . '<form method="POST" action="" style="display:contents;">'
            .   '<input type="hidden" name="pdns_action" value="do-record-create">'
            .   '<input type="hidden" name="zone_id" value="' . $this->h($zoneId) . '">'
            .   '<input type="hidden" name="csrf" value="' . $this->h($csrf) . '">'
            .   '<div><label style="font-size:11px;display:block;margin-bottom:2px;">Name</label>'
            .       '<input type="text" name="name" value="@" class="form-control input-sm" required></div>'
            .   '<div><label style="font-size:11px;display:block;margin-bottom:2px;">Type</label>'
            .       '<select name="type" class="form-control input-sm">' . $typeOpts . '</select></div>'
            .   '<div><label style="font-size:11px;display:block;margin-bottom:2px;">Content</label>'
            .       '<input type="text" name="content" class="form-control input-sm" required></div>'
            .   '<div><label style="font-size:11px;display:block;margin-bottom:2px;">TTL</label>'
            .       '<input type="number" name="ttl" value="3600" min="60" class="form-control input-sm"></div>'
            .   '<div><label style="font-size:11px;display:block;margin-bottom:2px;">Prio</label>'
            .       '<input type="number" name="priority" value="" class="form-control input-sm"></div>'
            .   '<div><button type="submit" class="btn btn-sm btn-primary">Add</button></div>'
            . '</form>'
            . '</div>';
    }

    private function recordEditFormHtml(int $zoneId, array $rec, string $csrf): string
    {
        $rid      = (int) ($rec['id'] ?? 0);
        $types    = ['A','AAAA','CNAME','MX','TXT','NS','SRV','CAA','PTR','TLSA','SSHFP','HTTPS','NAPTR'];
        $current  = strtoupper((string) ($rec['type'] ?? 'A'));
        $typeOpts = implode('', array_map(
            fn ($t) => '<option' . ($t === $current ? ' selected' : '') . '>' . $this->h($t) . '</option>',
            $types
        ));
        $cancelUrl = $this->pageUrl('records') . '&zone=' . $zoneId;

        return '<div style="display:grid;grid-template-columns:1fr 90px 1fr 80px 70px auto;gap:6px;align-items:end;">'
            . '<form method="POST" action="" style="display:contents;">'
            .   '<input type="hidden" name="pdns_action" value="do-record-update">'
            .   '<input type="hidden" name="zone_id" value="' . $this->h($zoneId) . '">'
            .   '<input type="hidden" name="record_id" value="' . $this->h($rid) . '">'
            .   '<input type="hidden" name="csrf" value="' . $this->h($csrf) . '">'
            .   '<div><label style="font-size:11px;">Name</label>'
            .       '<input type="text" name="name" value="' . $this->h($rec['name'] ?? '@') . '" class="form-control input-sm" required></div>'
            .   '<div><label style="font-size:11px;">Type</label>'
            .       '<select name="type" class="form-control input-sm">' . $typeOpts . '</select></div>'
            .   '<div><label style="font-size:11px;">Content</label>'
            .       '<input type="text" name="content" value="' . $this->h($rec['content'] ?? '') . '" class="form-control input-sm" required></div>'
            .   '<div><label style="font-size:11px;">TTL</label>'
            .       '<input type="number" name="ttl" value="' . $this->h($rec['ttl'] ?? 3600) . '" min="60" class="form-control input-sm"></div>'
            .   '<div><label style="font-size:11px;">Prio</label>'
            .       '<input type="number" name="priority" value="' . $this->h($rec['priority'] ?? '') . '" class="form-control input-sm"></div>'
            .   '<div style="display:flex;gap:4px;">'
            .       '<button type="submit" class="btn btn-sm btn-success">Save</button>'
            .       '<a href="' . $this->h($cancelUrl) . '" class="btn btn-sm btn-default">Cancel</a>'
            .   '</div>'
            . '</form>'
            . '</div>';
    }

    // ── Core helpers ──────────────────────────────────────────────────────────

    /**
     * Return the stored PanelDNS Sub-client ID for this service, or 0 if not set.
     */
    private function subClientId(): int
    {
        $v = $this->details['option1']['value'] ?? '';
        return is_numeric($v) ? (int) $v : 0;
    }

    /**
     * Return the HostBill service ID (used as CSRF session key discriminator).
     */
    private function serviceId(): int
    {
        return (int) ($this->account_details['id'] ?? 0);
    }

    /**
     * Resolve the nameserver list for this product/service.
     * Prefers per-product NS overrides (option4-7); falls back to the org's
     * configured nameservers from /api/v1/org/nameservers. Mirrors
     * PanelDnsResellerService::resolveNameservers() in the WHMCS module.
     *
     * @return string[]
     */
    private function resolveNameservers(): array
    {
        $overrides = array_values(array_filter([
            trim((string) ($this->options['option4']['value'] ?? '')),
            trim((string) ($this->options['option5']['value'] ?? '')),
            trim((string) ($this->options['option6']['value'] ?? '')),
            trim((string) ($this->options['option7']['value'] ?? '')),
        ], fn ($v) => $v !== ''));

        if (!empty($overrides)) return $overrides;

        if (!$this->api) return [];
        $ns = $this->api->nameservers();
        if ($ns['ok'] && !empty($ns['data']['nameservers'])) {
            return (array) $ns['data']['nameservers'];
        }
        return [];
    }

    /**
     * CACHE-01: fetch sub-client summary, returning the cached result if younger
     * than 60 seconds. Falls through to a live call on miss.
     * resyncStatus() bypasses this and always calls the API directly.
     */
    private function cachedSubClientSummary(int $id): array
    {
        $now = time();
        if (
            isset(self::$summaryCache[$id])
            && ($now - self::$summaryCache[$id]['ts']) < 60
        ) {
            return self::$summaryCache[$id]['resp'];
        }
        $resp = $this->api->subClientSummary($id);
        if ($resp['ok']) {
            self::$summaryCache[$id] = ['ts' => $now, 'resp' => $resp];
        }
        return $resp;
    }

    /**
     * CACHE-02: fetch nameservers with a 5-minute in-process cache.
     * @return string[]
     */
    private function cachedNameservers(): array
    {
        if (!$this->api) return [];
        $key = $this->api->identityHash();
        $now = time();
        if (isset(self::$nsCache[$key]) && ($now - self::$nsCache[$key]['ts']) < 300) {
            return self::$nsCache[$key]['ns'];
        }
        $ns = $this->resolveNameservers();
        self::$nsCache[$key] = ['ts' => $now, 'ns' => $ns];
        return $ns;
    }

    /**
     * Verify a zone belongs to the current sub-client before any mutation.
     * SEC-OWN: never trust a zone ID submitted by the client alone.
     */
    private function fetchOwnZone(int $zoneId): ?array
    {
        if ($zoneId <= 0) return null;
        $resp = $this->api->get("/api/v1/zones/{$zoneId}");
        if (!$resp['ok']) return null;
        $z = $resp['data'] ?? null;
        if (!$z) return null;
        if ((int) ($z['sub_client_id'] ?? 0) !== $this->subClientId()) return null;
        return $z;
    }

    /**
     * DNSSEC-01: fetch current DNSSEC state for a zone.
     * Returns null if zone has no provider or provider doesn't support DNSSEC.
     *
     * @return array{enabled: bool, algorithm: string|null, ds_records: string[]}|null
     */
    private function fetchDnssecStatus(int $zoneId): ?array
    {
        $resp = $this->api->get("/api/v1/zones/{$zoneId}/dnssec");
        if (!$resp['ok']) return null;
        $d = $resp['data'] ?? null;
        if (!is_array($d)) return null;
        return [
            'enabled'    => (bool) ($d['enabled'] ?? false),
            'algorithm'  => isset($d['algorithm']) && $d['algorithm'] !== '' ? (string) $d['algorithm'] : null,
            'ds_records' => is_array($d['ds_records']) ? array_values($d['ds_records']) : [],
        ];
    }

    /**
     * Validate and extract a DNS record payload from $_POST.
     * SEC-M02: allowlists record type; validates name/content length and chars.
     *
     * @throws \InvalidArgumentException on invalid input.
     */
    private function recordPayloadFromPost(): array
    {
        static $allowed = [
            'A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA',
            'PTR', 'TLSA', 'SSHFP', 'HTTPS', 'NAPTR',
        ];

        $type = strtoupper(trim((string) ($_POST['type'] ?? 'A')));
        if (!in_array($type, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid record type: ' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8'));
        }

        $name    = trim((string) ($_POST['name']    ?? '@'));
        $content = trim((string) ($_POST['content'] ?? ''));

        // FIX-M3/M4: validate name and content lengths; reject control characters.
        if (strlen($name) > 253 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new \InvalidArgumentException('Record name is invalid or too long.');
        }
        if (strlen($content) > 4096 || preg_match('/[\x00\r\n]/', $content)) {
            throw new \InvalidArgumentException('Record content is invalid or too long.');
        }

        return array_filter([
            'name'     => $name,
            'type'     => $type,
            'content'  => $content,
            // FIX-M4: enforce minimum TTL of 60 seconds server-side.
            'ttl'      => max(60, (int) ($_POST['ttl'] ?? 3600)),
            'priority' => isset($_POST['priority']) && $_POST['priority'] !== ''
                ? (int) $_POST['priority']
                : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Build a URL for a given page, preserving current query-string params
     * (minus the existing pdns= param) and appending the new page.
     */
    private function pageUrl(string $page = ''): string
    {
        $base = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
        $qs   = [];
        parse_str((string) ($_SERVER['QUERY_STRING'] ?? ''), $qs);
        unset($qs['pdns'], $qs['edit']);
        if ($page !== '') {
            $qs['pdns'] = $page;
        }
        return $base . ($qs ? '?' . http_build_query($qs) : '');
    }

    // ── Rate limiting ─────────────────────────────────────────────────────────

    /**
     * RATE-01: sliding-window rate limit, 60 requests per minute per service.
     *
     * Uses session counters since \WHMCS\Cache\Store is not available in HostBill.
     * The window resets when more than 60 seconds have elapsed since the first hit.
     * Mirrors FIX-M6 (EmbeddedDnsManager::handle()) in the WHMCS reseller module.
     *
     * NOTE: session-based counters are per-PHP-process, not shared across workers.
     * For a shared-cache backend, replace with a HostBill cache API call when one
     * becomes available.
     */
    private function rateLimitExceeded(): bool
    {
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $hitKey = 'paneldns_rl_' . $this->serviceId();
        $tsKey  = 'paneldns_rl_ts_' . $this->serviceId();
        $now    = time();

        // Reset the window if more than 60 seconds have passed.
        if (empty($_SESSION[$tsKey]) || ($now - (int) $_SESSION[$tsKey]) >= 60) {
            $_SESSION[$tsKey] = $now;
            $_SESSION[$hitKey] = 1;
            return false;
        }

        $hits = (int) ($_SESSION[$hitKey] ?? 0) + 1;
        $_SESSION[$hitKey] = $hits;
        return $hits > 60;
    }

    // ── CSRF ─────────────────────────────────────────────────────────────────

    /**
     * Generate a per-session CSRF token tied to the customer's session and the
     * specific service ID. Bound to service so a token from one product can't be
     * used to mutate another's DNS.
     */
    private function csrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $key = 'paneldns_csrf_' . $this->serviceId();
        if (empty($_SESSION[$key])) {
            $_SESSION[$key] = bin2hex(random_bytes(24));
        }
        return $_SESSION[$key];
    }

    /**
     * FIX-M5: rotate the CSRF token after each successful mutation to prevent
     * replay attacks. The new token is stored in session for the next render.
     */
    private function rotateCsrf(): void
    {
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $_SESSION['paneldns_csrf_' . $this->serviceId()] = bin2hex(random_bytes(24));
    }

    private function requireCsrf(): void
    {
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $expected = $_SESSION['paneldns_csrf_' . $this->serviceId()] ?? '';
        $supplied = (string) ($_POST['csrf'] ?? '');
        if ($expected === '' || !hash_equals($expected, $supplied)) {
            http_response_code(403);
            die('CSRF token mismatch. Please return to the previous page and try again.');
        }
    }

    // ── Flash messages ────────────────────────────────────────────────────────

    private function flash(string $type, string $msg): void
    {
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        // FIX-M1: cap flash message length — prevents excessively long API errors
        // from leaking to the client area.
        $_SESSION['paneldns_flash'] = ['type' => $type, 'msg' => substr((string) $msg, 0, 512)];
    }

    private function popFlash(): ?array
    {
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $f = $_SESSION['paneldns_flash'] ?? null;
        unset($_SESSION['paneldns_flash']);
        return $f;
    }

    private function flashHtml(): string
    {
        $f = $this->popFlash();
        if (!$f) return '';
        $cls = $f['type'] === 'success' ? 'alert-success' : 'alert-danger';
        return '<div class="alert ' . $cls . '" style="margin-bottom:12px;">'
            . $this->h((string) ($f['msg'] ?? ''))
            . '</div>';
    }

    /** FIX-M17: cap API error strings before use in flash messages. */
    private function apiError(array $resp, string $fallback = 'Unknown error.'): string
    {
        return substr((string) ($resp['error'] ?? $fallback), 0, 256);
    }

    // ── Welcome email ─────────────────────────────────────────────────────────

    /**
     * Mint a one-time SSO login URL and send the welcome email via HostBill's
     * built-in mail system (or PHP mail() as a fallback). Best-effort — failures
     * are non-fatal and do not block provisioning.
     *
     * Matches PanelDnsResellerService::sendWelcomeEmail() in the WHMCS module
     * using the same context fields (portal_url, org_slug, nameservers, soa_email).
     */
    private function sendWelcomeEmail(int $subClientId): void
    {
        $sso = $this->api->mintSubClientSsoToken($subClientId);

        // SEC: validate returned URL scheme — prevents javascript:/data: injection.
        if (
            !$sso['ok']
            || empty($sso['data']['login_url'])
            || !str_starts_with((string) ($sso['data']['login_url'] ?? ''), 'https://')
        ) {
            return; // logged by PanelDnsApiHb; welcome email is best-effort
        }

        $loginUrl = (string) $sso['data']['login_url'];

        // Pull portal URL + org slug from /api/v1/summary.
        $portalUrl = '';
        $orgSlug   = '';
        $sum       = $this->api->summary();
        if ($sum['ok']) {
            $portalUrl = (string) ($sum['data']['links']['portal'] ?? '');
            $orgSlug   = (string) ($sum['data']['org']['slug']    ?? '');
        }

        // NS-01: prefer per-product NS overrides; fallback to org nameservers.
        $ns       = $this->resolveNameservers();
        $soaEmail = trim((string) ($this->options['option8']['value'] ?? ''));
        $email    = $this->client_data['email'] ?? '';

        $subject = 'Your PanelDNS DNS Hosting Account is Ready';
        $body    = "Hello,\n\n"
            . "Your PanelDNS DNS hosting account has been set up.\n\n"
            . "Log in now (link valid for 60 seconds):\n{$loginUrl}\n\n"
            . ($portalUrl ? "Portal URL:\n{$portalUrl}\n\n" : '')
            . ($orgSlug   ? "Organisation slug: {$orgSlug}\n\n" : '')
            . (!empty($ns) ? "Your nameservers:\n" . implode("\n", $ns) . "\n\n" : '')
            . ($soaEmail   ? "SOA Contact Email:\n{$soaEmail}\n\n" : '')
            . "To log in again later, use your portal URL directly.\n\nThank you.";

        if (class_exists('Emails')) {
            // HostBill Emails component — preferred.
            try {
                $emails = new Emails();
                $emails->send([
                    'to'      => $email,
                    'subject' => $subject,
                    'body'    => nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')),
                ]);
            } catch (\Throwable $e) {
                // Non-fatal — provisioning succeeded even if the email failed.
            }
        } else {
            // Last-resort fallback — plain PHP mail().
            @mail($email, $subject, $body);
        }
    }

    // ── HTML helpers ──────────────────────────────────────────────────────────

    /** Shorthand for htmlspecialchars with ENT_QUOTES + UTF-8. */
    private function h(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    // ── SSRF guard ────────────────────────────────────────────────────────────

    /**
     * Return true if the resolved hostname is a private or unresolvable IP.
     * Used in connect() as a belt-and-braces SSRF pre-flight check.
     */
    private static function isPrivateOrUnresolvable(string $resolved, string $originalHost): bool
    {
        // gethostbyname() returns the original string unchanged when DNS lookup fails.
        if ($resolved === $originalHost) {
            if (!filter_var($resolved, FILTER_VALIDATE_IP)) {
                return true; // not an IP and did not resolve
            }
        }
        return filter_var(
            $resolved,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
