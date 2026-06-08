<?php

/**
 * paneldns — HostBill server module for selling PanelDNS reseller accounts.
 *
 * Drives the operator-tier Platform API (/platform/v1). Used by an operator
 * running PanelDNS-as-a-SaaS to onboard new resellers via their HostBill:
 *
 *   - HostBill Create        → POST /platform/v1/orgs
 *   - HostBill Suspend       → POST /platform/v1/orgs/{id}/suspend
 *   - HostBill Unsuspend     → POST /platform/v1/orgs/{id}/unsuspend
 *   - HostBill Terminate     → DELETE /platform/v1/orgs/{id}
 *   - HostBill ChangePackage → PUT /platform/v1/orgs/{id}/plan
 *
 * See ../../../shared/PanelDnsApi.php for the HTTP client.
 * See README.md in the repo root for installation and configuration.
 *
 * File naming: class.paneldns.php in
 *   includes/modules/Hosting/paneldns/
 *
 * HostBill conventions:
 *   - Class name MUST match the file name (without class. prefix and .php).
 *   - Extends HostingModule (HostBill base class for provisioning modules).
 *   - connect($connect) is called before every lifecycle method.
 *   - Return true/false from lifecycle methods; call $this->addError() for failures,
 *     $this->addInfo() for success messages.
 *   - $this->client_data   — client details (email, firstname, lastname, id, ...).
 *   - $this->account_details — service/account details (id, server_id, ...).
 *   - $this->options       — product-level configuration (same key for all accounts).
 *   - $this->details       — per-account data (stored per service).
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
    protected $description = 'PanelDNS — Reseller DNS Management Platform';

    /** Module version — bump in lockstep with the repo release tag. */
    protected $version = '1.0.0';

    /**
     * Server fields shown in Settings -> Apps when configuring the server.
     * 'hostname' maps to the PanelDNS base URL (e.g. https://my.paneldns.io).
     * 'hash'     holds the Platform API key (Bearer token).
     * 'ssl'      enables/disables TLS verification (keep ON in production).
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

    /**
     * Human-readable labels for the server fields above (replaces HostBill defaults).
     */
    protected $serverFieldsDescription = [
        'hostname' => 'PanelDNS Base URL (e.g. https://my.paneldns.io)',
        'hash'     => 'Platform API Key',
        'ssl'      => 'Verify TLS Certificate (recommended: ON)',
    ];

    /**
     * Product-level configuration options.
     * These values are the same for all accounts created from a given product.
     *
     * option1 — PanelDNS Plan ID (numeric ID from /admin/plans on your PanelDNS).
     * option2 — Partner Source (optional; marks the org as a partner plan).
     * option3 — Send Welcome Email (yes/no checkbox).
     * option4 — NS1 Hostname (optional vanity nameserver).
     * option5 — NS2 Hostname.
     * option6 — NS3 Hostname.
     * option7 — NS4 Hostname.
     * option8 — SOA Email.
     * option9 — Termination Grace Period (days; 0 = immediate delete).
     */
    protected $options = [
        'option1' => [
            'name'    => 'PanelDNS Plan ID',
            'value'   => '',
            'type'    => 'input',
            'default' => '',
        ],
        'option2' => [
            'name'    => 'Partner Source',
            'value'   => '',
            'type'    => 'input',
            'default' => '',
        ],
        'option3' => [
            'name'    => 'Send Welcome Email',
            'value'   => '1',
            'type'    => 'check',
            'default' => '',
        ],
        'option4' => [
            'name'    => 'NS1 Hostname',
            'value'   => '',
            'type'    => 'input',
            'default' => '',
        ],
        'option5' => [
            'name'    => 'NS2 Hostname',
            'value'   => '',
            'type'    => 'input',
            'default' => '',
        ],
        'option6' => [
            'name'    => 'NS3 Hostname',
            'value'   => '',
            'type'    => 'input',
            'default' => '',
        ],
        'option7' => [
            'name'    => 'NS4 Hostname',
            'value'   => '',
            'type'    => 'input',
            'default' => '',
        ],
        'option8' => [
            'name'    => 'SOA Email',
            'value'   => '',
            'type'    => 'input',
            'default' => '',
        ],
        'option9' => [
            'name'    => 'Termination Grace Period (Days)',
            'value'   => '0',
            'type'    => 'input',
            'default' => '0',
        ],
    ];

    /**
     * Per-account details stored by HostBill against each individual service.
     *
     * option1 — PanelDNS Org ID (set after Create succeeds; used by all other hooks).
     */
    protected $details = [
        'option1' => [
            'name'    => 'PanelDNS Org ID',
            'value'   => false,
            'type'    => 'input',
            'default' => false,
        ],
    ];

    // ── Internal state ────────────────────────────────────────────────────────

    /** @var PanelDnsApiHb|null */ private $api = null;

    // ── HostBill lifecycle ────────────────────────────────────────────────────

    /**
     * Called by HostBill before every other method. Receives the server app
     * configuration from Settings -> Apps.
     *
     * @param array $connect {
     *   'hostname' string  PanelDNS base URL
     *   'hash'     string  Platform API key
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
                // Store null api so every lifecycle method fails gracefully.
                $this->api = null;
                return;
            }
        }

        $logger = function (string $action, array $request, mixed $response): void {
            // HostBill does not have a logModuleCall equivalent; use the built-in
            // activity logger if available, otherwise silently skip.
            // Module-level logging can be added here for enterprise deployments.
        };

        $this->api = new PanelDnsApiHb($baseUrl, $apiKey, PanelDnsApiHb::MODE_PLATFORM, $tlsVerify, $logger);
    }

    /**
     * Called when admin clicks "Test Connection" on the App configuration page.
     * @return bool true on success.
     */
    public function testConnection(): bool
    {
        if (!$this->api) {
            $this->addError('PanelDNS: server hostname is invalid or resolves to a private IP.');
            return false;
        }

        $ping = $this->api->ping();
        if (!$ping['ok']) {
            $this->addError('PanelDNS: server unreachable — check the Base URL.');
            return false;
        }

        $plans = $this->api->plans();
        if (!$plans['ok']) {
            $this->addError('PanelDNS: authentication failed — check the Platform API Key.');
            return false;
        }

        $this->addInfo('PanelDNS: connection OK.');
        return true;
    }

    /**
     * Create a new PanelDNS reseller org for this service.
     *
     * On success: sets $this->details['option1']['value'] to the new org ID.
     * Returns true so HostBill marks the service Active.
     */
    public function Create(): bool
    {
        if (!$this->api) {
            $this->addError('PanelDNS: server connection not initialised — check App configuration.');
            return false;
        }

        // Licence gate — only platform module required for operators who self-host PanelDNS.
        // Comment this out if you ship this module to operators who do NOT need a licence check.
        // $error = PanelDnsLicenceCheckHb::gateOrError($this->api, PanelDnsLicenceCheckHb::REQUIRED_MODULE_PLATFORM);
        // if ($error !== null) { $this->addError($error); return false; }

        // Idempotency: if we already provisioned this service, just unsuspend.
        $existingId = $this->orgId();
        if ($existingId > 0) {
            $resp = $this->api->unsuspendOrg($existingId);
            if ($resp['ok']) {
                $this->addInfo('PanelDNS: org unsuspended (idempotent create).');
                return true;
            }
            $this->addError('PanelDNS: unsuspend failed — see module activity log.');
            return false;
        }

        $planId = (int) ($this->options['option1']['value'] ?? 0);
        if ($planId <= 0) {
            $this->addError('PanelDNS: Plan ID is required. Set it in the product module settings (option1).');
            return false;
        }

        $clientEmail     = $this->client_data['email']     ?? '';
        $clientFirstname = $this->client_data['firstname'] ?? '';
        $clientLastname  = $this->client_data['lastname']  ?? '';
        $clientCompany   = $this->client_data['company']   ?? '';

        $orgName = $clientCompany ?: trim("{$clientFirstname} {$clientLastname}") ?: $clientEmail;

        $payload = array_filter([
            'name'           => $orgName,
            'plan_id'        => $planId,
            'partner_source' => trim((string) ($this->options['option2']['value'] ?? '')) ?: null,
            'ns1_hostname'   => trim((string) ($this->options['option4']['value'] ?? '')) ?: null,
            'ns2_hostname'   => trim((string) ($this->options['option5']['value'] ?? '')) ?: null,
            'ns3_hostname'   => trim((string) ($this->options['option6']['value'] ?? '')) ?: null,
            'ns4_hostname'   => trim((string) ($this->options['option7']['value'] ?? '')) ?: null,
            'soa_email'      => trim((string) ($this->options['option8']['value'] ?? '')) ?: null,
            'owner' => [
                'name'     => trim("{$clientFirstname} {$clientLastname}") ?: $clientEmail,
                'email'    => $clientEmail,
                'password' => bin2hex(random_bytes(12)),
            ],
        ], fn ($v) => $v !== null);

        // Convey legal consent: HostBill order forms include a mandatory "agree to
        // terms" step — pass acknowledgement through so the owner account is marked
        // as consented immediately (actor_type=reseller_api).
        $legalVersion = $this->fetchLegalVersion();
        if ($legalVersion !== null) {
            $payload['terms_acknowledged'] = true;
            $payload['terms_version']      = $legalVersion;
        }

        $resp = $this->api->createOrg($payload);
        if (!$resp['ok']) {
            $this->addError('PanelDNS: org creation failed — see module activity log.');
            return false;
        }

        $newId = (int) ($resp['data']['id'] ?? 0);
        if ($newId <= 0) {
            $this->addError('PanelDNS: org created but no ID returned.');
            return false;
        }

        // Persist org ID in per-account details so all future hooks can find it.
        $this->details['option1']['value'] = (string) $newId;

        // Optionally send welcome email with SSO link.
        if (!empty($this->options['option3']['value'])) {
            $this->sendWelcomeEmail($newId, $clientEmail);
        }

        $this->addInfo("PanelDNS: org #{$newId} created successfully.");
        return true;
    }

    /**
     * Suspend the reseller's PanelDNS org (e.g. overdue invoice).
     */
    public function Suspend(): bool
    {
        if (!$this->api) {
            $this->addError('PanelDNS: server connection not initialised.');
            return false;
        }

        $id = $this->orgId();
        if ($id <= 0) {
            $this->addError('PanelDNS: no Org ID found — cannot suspend (was the service provisioned?).');
            return false;
        }

        $resp = $this->api->suspendOrg($id);
        if (!$resp['ok']) {
            $this->addError('PanelDNS: suspend failed — see module activity log.');
            return false;
        }

        $this->addInfo("PanelDNS: org #{$id} suspended.");
        return true;
    }

    /**
     * Unsuspend the reseller's PanelDNS org (e.g. invoice paid).
     */
    public function Unsuspend(): bool
    {
        if (!$this->api) {
            $this->addError('PanelDNS: server connection not initialised.');
            return false;
        }

        $id = $this->orgId();
        if ($id <= 0) {
            $this->addError('PanelDNS: no Org ID found — cannot unsuspend.');
            return false;
        }

        $resp = $this->api->unsuspendOrg($id);
        if (!$resp['ok']) {
            $this->addError('PanelDNS: unsuspend failed — see module activity log.');
            return false;
        }

        $this->addInfo("PanelDNS: org #{$id} unsuspended.");
        return true;
    }

    /**
     * Terminate (delete) the reseller's PanelDNS org.
     *
     * If option9 (Termination Grace Period) is > 0, the org is suspended now
     * and a note is written. A separate cron/script would need to handle deletion
     * after the grace period — HostBill does not have a built-in deferred delete
     * mechanism like WHMCS DailyCronJob hooks.
     */
    public function Terminate(): bool
    {
        if (!$this->api) {
            $this->addError('PanelDNS: server connection not initialised.');
            return false;
        }

        $id = $this->orgId();
        if ($id <= 0) {
            // Nothing to delete — already gone or never provisioned.
            $this->addInfo('PanelDNS: no Org ID to terminate (already deleted or never provisioned).');
            return true;
        }

        $graceDays = (int) ($this->options['option9']['value'] ?? 0);

        if ($graceDays > 0) {
            // Suspend now; store grace-period deadline note.
            $resp = $this->api->suspendOrg($id);
            if (!$resp['ok']) {
                $this->addError('PanelDNS: grace-period suspend failed — see module activity log.');
                return false;
            }
            $deadline = date('Y-m-d', strtotime("+{$graceDays} days"));
            $this->addInfo("PanelDNS: org #{$id} suspended (grace period ends {$deadline}).");
            return true;
        }

        $resp = $this->api->terminateOrg($id);
        if (!$resp['ok']) {
            $this->addError('PanelDNS: terminate failed — see module activity log.');
            return false;
        }

        $this->details['option1']['value'] = '';
        $this->addInfo("PanelDNS: org #{$id} deleted.");
        return true;
    }

    /**
     * Upgrade or downgrade the plan assigned to this org.
     * Called by HostBill when a client upgrades/downgrades their product.
     */
    public function ChangePackage(): bool
    {
        if (!$this->api) {
            $this->addError('PanelDNS: server connection not initialised.');
            return false;
        }

        $id = $this->orgId();
        if ($id <= 0) {
            $this->addError('PanelDNS: no Org ID — cannot change package.');
            return false;
        }

        $planId = (int) ($this->options['option1']['value'] ?? 0);
        if ($planId <= 0) {
            $this->addError('PanelDNS: Plan ID missing on new product configuration.');
            return false;
        }

        $resp = $this->api->changePlan($id, $planId);
        if (!$resp['ok']) {
            $this->addError('PanelDNS: plan change failed — see module activity log.');
            return false;
        }

        $this->addInfo("PanelDNS: org #{$id} moved to plan #{$planId}.");
        return true;
    }

    /**
     * Loadable field helper — returns available plans for the option1 dropdown.
     * HostBill calls this when rendering the product module settings page because
     * option1 is set to type='loadable' with default='getPlans'.
     *
     * Returns an array of [plan_id, plan_name] pairs or false on failure.
     */
    public function getPlans(): array|bool
    {
        if (!$this->api) return false;

        $resp = $this->api->plans();
        if (!$resp['ok'] || !is_array($resp['data'])) return false;

        $plans = [];
        foreach ((array) $resp['data'] as $plan) {
            $id   = $plan['id']   ?? null;
            $name = $plan['name'] ?? null;
            if ($id !== null && $name !== null) {
                $plans[] = [(string) $id, (string) $name];
            }
        }
        return empty($plans) ? false : $plans;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Return the stored PanelDNS Org ID for this service, or 0 if not set.
     * The org ID is persisted in $this->details['option1']['value'].
     */
    private function orgId(): int
    {
        $v = $this->details['option1']['value'] ?? '';
        return is_numeric($v) ? (int) $v : 0;
    }

    /**
     * Attempt to fetch the current platform legal version for consent pass-through.
     * Non-fatal — returns null on any failure.
     */
    private function fetchLegalVersion(): ?string
    {
        try {
            $resp = $this->api->getLegalVersion();
            return ($resp['ok'] ?? false) ? ($resp['data']['version'] ?? null) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Mint a one-time SSO login URL and send a welcome email via HostBill's
     * built-in mail system. Best-effort — failures are logged but do not
     * block provisioning.
     */
    private function sendWelcomeEmail(int $orgId, string $email): void
    {
        $sso = $this->api->mintOrgSsoToken($orgId, $email);

        // Validate the returned login URL scheme — prevents javascript:/data: injection.
        if (
            !$sso['ok']
            || empty($sso['data']['login_url'])
            || !str_starts_with((string) ($sso['data']['login_url'] ?? ''), 'https://')
        ) {
            return;  // logged by PanelDnsApiHb; welcome email is best-effort
        }

        $loginUrl = (string) $sso['data']['login_url'];

        // Pull org metadata for the email body (nameservers, portal URL).
        $org        = $this->api->getOrg($orgId);
        $nameservers = '';
        $portalUrl   = '';
        if ($org['ok']) {
            $ns = array_values(array_filter([
                $org['data']['ns1_hostname'] ?? null,
                $org['data']['ns2_hostname'] ?? null,
                $org['data']['ns3_hostname'] ?? null,
                $org['data']['ns4_hostname'] ?? null,
            ], fn ($v) => is_string($v) && $v !== ''));
            $nameservers = implode("\n", $ns);
            $portalUrl   = $org['data']['links']['portal'] ?? '';
        }

        // HostBill does not expose a global sendMessage() helper the way WHMCS does.
        // Use HostBill's built-in email system via the Emails component if available,
        // otherwise fall back to PHP mail().
        $subject = 'Your PanelDNS Account is Ready';
        $body    = "Hello,\n\n"
            . "Your PanelDNS reseller account has been provisioned.\n\n"
            . "Log in now (link valid for 60 seconds):\n{$loginUrl}\n\n"
            . ($portalUrl   ? "Portal URL:\n{$portalUrl}\n\n" : '')
            . ($nameservers ? "Your nameservers:\n{$nameservers}\n\n" : '')
            . "If you need to log in again later, visit your PanelDNS portal URL.\n\n"
            . "Thank you.";

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

    /**
     * Return true if the resolved hostname is a private or unresolvable IP.
     * Used in connect() as a belt-and-braces SSRF pre-flight check.
     */
    private static function isPrivateOrUnresolvable(string $resolved, string $originalHost): bool
    {
        // gethostbyname() returns the original string unchanged when DNS lookup fails.
        if ($resolved === $originalHost) {
            // Could be a raw IP — check if it's private.
            if (!filter_var($resolved, FILTER_VALIDATE_IP)) {
                return true;  // not an IP and didn't resolve
            }
        }
        return filter_var(
            $resolved,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
