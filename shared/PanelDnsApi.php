<?php

/**
 * PanelDnsApi — HTTP client wrapping the PanelDNS Platform + Public APIs.
 *
 * Used by the paneldns HostBill server module (operator tier, /platform/v1).
 *
 * Conventions:
 *   - No Composer, no namespaces (lives in shared/ copied into the module).
 *   - cURL only (no Guzzle).
 *   - IPv4 only (CURLOPT_IPRESOLVE_V4) — SSRF guard.
 *   - TLS verify ON by default; per-server tls_verify flag honoured.
 *   - Returns ['ok' => bool, 'status' => int, 'data' => array|null, 'error' => string|null].
 *   - Redacts the API key from any logged payload.
 *
 * This file is adapted from the WHMCS counterpart (shared/PanelDnsApi.php in
 * the paneldns-whmcs monorepo) with all WHMCS-specific calls removed.
 */

if (!defined('HOSTBILL_PANELDNS_API_VERSION')) {
    define('HOSTBILL_PANELDNS_API_VERSION', '1.0.0');
}

class PanelDnsApiHb
{
    const MODE_PLATFORM = 'platform';
    const MODE_RESELLER = 'reseller';

    /** @var string */ private $baseUrl;
    /** @var string */ private $apiKey;
    /** @var string */ private $mode;
    /** @var bool */   private $tlsVerify;
    /** @var int */    private $timeout = 15;

    /** @var callable|null */ private $logger;

    /**
     * @param string        $baseUrl   Full base URL, e.g. https://my.paneldns.io
     * @param string        $apiKey    Platform API key (Bearer token)
     * @param string        $mode      'platform' or 'reseller'
     * @param bool          $tlsVerify Whether to verify TLS certificates
     * @param callable|null $logger    Optional callable(string $action, array $request, mixed $response): void
     */
    public function __construct(
        string $baseUrl,
        string $apiKey,
        string $mode = self::MODE_PLATFORM,
        bool $tlsVerify = true,
        ?callable $logger = null
    ) {
        $this->baseUrl   = rtrim($baseUrl, '/');
        $this->apiKey    = $apiKey;
        $this->mode      = $mode === self::MODE_PLATFORM ? self::MODE_PLATFORM : self::MODE_RESELLER;
        $this->tlsVerify = $tlsVerify;
        $this->logger    = $logger;
    }

    /** Returns the URL prefix used for this mode. */
    public function prefix(): string
    {
        return $this->mode === self::MODE_PLATFORM ? '/platform/v1' : '/api/v1';
    }

    /**
     * Stable opaque identifier for THIS server+key+mode tuple.
     * Used for cache keys so two servers with different keys don't collide.
     * Never includes the raw key.
     */
    public function identityHash(): string
    {
        return substr(hash('sha256', $this->baseUrl . '|' . $this->mode . '|' . $this->apiKey), 0, 16);
    }

    // ── Generic HTTP ──────────────────────────────────────────────────────────

    public function get(string $path, array $query = []): array
    {
        $url = $this->baseUrl . $path . (!empty($query) ? '?' . http_build_query($query) : '');
        return $this->request('GET', $url);
    }

    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $this->baseUrl . $path, $body);
    }

    public function put(string $path, array $body = []): array
    {
        return $this->request('PUT', $this->baseUrl . $path, $body);
    }

    public function patch(string $path, array $body = []): array
    {
        return $this->request('PATCH', $this->baseUrl . $path, $body);
    }

    public function delete(string $path): array
    {
        return $this->request('DELETE', $this->baseUrl . $path);
    }

    // ── Health / status ───────────────────────────────────────────────────────

    public function ping(): array
    {
        return $this->mode === self::MODE_PLATFORM
            ? $this->get('/platform/v1/ping')
            : $this->get('/api/ping');
    }

    public function licenceStatus(): array
    {
        return $this->get('/api/v1/licence-status');
    }

    // ── Platform tier helpers ─────────────────────────────────────────────────

    public function plans(): array { return $this->get('/platform/v1/plans'); }

    public function getLegalVersion(): array { return $this->get('/platform/v1/legal-version'); }

    public function createOrg(array $data): array { return $this->post('/platform/v1/orgs', $data); }

    public function getOrg(int $id): array { return $this->get("/platform/v1/orgs/{$id}"); }

    public function patchOrg(int $id, array $d): array { return $this->patch("/platform/v1/orgs/{$id}", $d); }

    public function changePlan(int $id, int $planId): array
    {
        return $this->put("/platform/v1/orgs/{$id}/plan", ['plan_id' => $planId]);
    }

    public function suspendOrg(int $id): array { return $this->post("/platform/v1/orgs/{$id}/suspend"); }

    public function unsuspendOrg(int $id): array { return $this->post("/platform/v1/orgs/{$id}/unsuspend"); }

    public function terminateOrg(int $id): array { return $this->delete("/platform/v1/orgs/{$id}"); }

    public function orgSummary(int $id): array { return $this->get("/platform/v1/orgs/{$id}/summary"); }

    public function mintOrgSsoToken(int $id, ?string $email = null): array
    {
        return $this->post(
            "/platform/v1/orgs/{$id}/sso-token",
            $email ? ['user_email' => $email] : []
        );
    }

    // ── Reseller tier helpers ─────────────────────────────────────────────────

    public function summary(): array { return $this->get('/api/v1/summary'); }

    public function nameservers(): array { return $this->get('/api/v1/org/nameservers'); }

    public function getResellerLegalVersion(): array { return $this->get('/api/v1/legal-version'); }

    public function createSubClient(array $data): array { return $this->post('/api/v1/sub-clients', $data); }

    public function patchSubClient(int $id, array $data): array { return $this->patch("/api/v1/sub-clients/{$id}", $data); }

    public function deleteSubClient(int $id): array { return $this->delete("/api/v1/sub-clients/{$id}"); }

    public function subClientSummary(int $id): array { return $this->get("/api/v1/sub-clients/{$id}/summary"); }

    public function mintSubClientSsoToken(int $id): array { return $this->post("/api/v1/sub-clients/{$id}/sso-token"); }

    public function searchSubClients(string $email): array { return $this->get('/api/v1/sub-clients', ['search' => $email, 'per_page' => 10]); }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function request(string $method, string $url, ?array $body = null): array
    {
        $ch = curl_init();
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'User-Agent: paneldns-hostbill/' . HOSTBILL_PANELDNS_API_VERSION,
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,   // SSRF guard: IPv4 only
            CURLOPT_SSL_VERIFYPEER => $this->tlsVerify ? 1 : 0,
            CURLOPT_SSL_VERIFYHOST => $this->tlsVerify ? 2 : 0,
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        ];

        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;

        curl_setopt_array($ch, $opts);
        $raw       = curl_exec($ch);
        $status    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $primaryIp = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $err       = curl_error($ch);
        curl_close($ch);

        $this->logCall($method, $url, $body, $status, $raw, $err);

        if ($err) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => $err];
        }

        // Belt-and-braces SSRF guard: block responses from private IPs even when
        // CURLOPT_IPRESOLVE_V4 is set (dual-stack environments).
        if ($primaryIp !== '' && self::isPrivateIp($primaryIp)) {
            return [
                'ok'    => false,
                'status' => 0,
                'data'  => null,
                'error' => 'SSRF guard: target resolved to a private IP address',
            ];
        }

        $decoded = json_decode($raw ?: 'null', true);
        $ok      = $status >= 200 && $status < 300 && is_array($decoded) && !empty($decoded['ok']);

        return [
            'ok'       => $ok,
            'status'   => $status,
            'data'     => is_array($decoded) ? ($decoded['data'] ?? $decoded) : null,
            'error'    => $ok ? null : ($decoded['error'] ?? "HTTP {$status}"),
            'raw_body' => $raw,
        ];
    }

    private function logCall(
        string $method,
        string $url,
        ?array $body,
        int $status,
        ?string $rawResponse,
        ?string $curlError
    ): void {
        if ($this->logger === null) return;

        $bodyRedacted = $this->redact($body ?? []);
        $loggedUrl    = $this->redactUrl($url);
        $truncated    = $this->truncate($rawResponse, 4096);

        ($this->logger)(
            "{$method} {$loggedUrl}",
            $bodyRedacted,
            ['status' => $status, 'response' => $truncated, 'curl_error' => $curlError]
        );
    }

    private function redact(array $payload): array
    {
        $copy = $payload;
        foreach (['password', 'api_key', 'token', 'secret', 'authorization', 'access_hash'] as $key) {
            if (isset($copy[$key])) {
                $copy[$key] = '[REDACTED]';
            }
        }
        return $copy;
    }

    private function truncate(?string $s, int $max): ?string
    {
        if ($s === null) return null;
        return strlen($s) > $max ? substr($s, 0, $max) . '...' : $s;
    }

    private function redactUrl(string $url): string
    {
        return preg_replace(
            '/([?&])(search|email|token|key|password|secret)=([^&]*)/i',
            '$1$2=[REDACTED]',
            $url
        );
    }

    /**
     * Return true if the IPv4 address falls within a private, loopback,
     * link-local, or shared-address-space range (RFC 1918 / RFC 5735 / RFC 6598).
     */
    private static function isPrivateIp(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) return true; // unparseable — block to be safe
        foreach ([
            ['10.0.0.0',    8],   // RFC 1918 private
            ['172.16.0.0',  12],  // RFC 1918 private
            ['192.168.0.0', 16],  // RFC 1918 private
            ['127.0.0.0',   8],   // loopback
            ['169.254.0.0', 16],  // link-local
            ['0.0.0.0',     8],   // "this" network
            ['100.64.0.0',  10],  // RFC 6598 shared address space
        ] as [$subnet, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($long & $mask) === (ip2long($subnet) & $mask)) return true;
        }
        return false;
    }
}
