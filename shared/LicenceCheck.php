<?php

/**
 * PanelDnsLicenceCheckHb — verifies the HostBill install is paired with an
 * active PanelDNS subscription, with a 7-day grace period for past_due.
 *
 * Behaviour:
 *   - Calls GET /api/v1/licence-status on the configured PanelDNS server.
 *   - Result is cached in a flat JSON file under sys_get_temp_dir() keyed by
 *     the server identity hash (no WHMCS Cache dependency).
 *   - sub_status='active' or 'trialing'       → unlocked
 *   - sub_status='past_due' + grace < 7 days  → still unlocked
 *   - past 7-day grace OR cache age > 2 days  → locked (CreateAccount blocked)
 *   - Existing services keep working (Suspend / Unsuspend / Terminate not gated)
 *   - 'free' sub_status                       → locked from day one
 *
 * This file is adapted from shared/LicenceCheck.php in the paneldns-whmcs
 * monorepo with the WHMCS\Cache\Store and WHMCS\Database\Capsule calls replaced
 * by a plain file-based cache.
 */

if (!defined('HOSTBILL_PANELDNS_API_VERSION')) {
    define('HOSTBILL_PANELDNS_API_VERSION', '1.0.0');
}

class PanelDnsLicenceCheckHb
{
    const REQUIRED_MODULE_PLATFORM = 'whmcs-platform'; // same module slug as WHMCS
    const REQUIRED_MODULE_RESELLER = 'whmcs-reseller';

    /** Past-due grace period — past this, lock provisioning. */
    const GRACE_SECONDS = 7 * 86400;
    /** How long a fresh licence response is considered valid. */
    const CACHE_TTL = 86400;
    /** If PanelDNS has been unreachable longer than this, lock. */
    const STALE_HARD_LOCK = 2 * 86400;

    /**
     * Check whether the named module is unlocked for the configured server.
     *
     * @return array{unlocked:bool, reason:string, sub_status:string, expires_at:?string}
     */
    public static function check(PanelDnsApiHb $api, string $requiredModule): array
    {
        $cacheKey = self::cacheKey($api);
        $cached   = self::readCache($cacheKey);
        $now      = time();

        // Cache fresh? Use it.
        if ($cached && ($now - $cached['fetched_at']) < self::CACHE_TTL) {
            return self::interpret($cached, $requiredModule, $now);
        }

        // Fetch fresh.
        $resp = $api->licenceStatus();
        if ($resp['ok']) {
            $payload   = $resp['data'] ?? [];
            $subStatus = $payload['sub_status'] ?? 'unknown';

            // GRACE-CLOCK-01: measure grace from when past_due was FIRST observed, and
            // preserve that timestamp across refreshes. Measuring from fetched_at makes
            // the window unreachable: fetched_at resets to now on every successful fetch
            // and the cache refreshes daily, so elapsed time never exceeds CACHE_TTL
            // (1 day) and never reaches GRACE_SECONDS (7 days). A past-due subscription
            // then stays unlocked forever while still reporting "7 day(s) left".
            //
            // Null when not past due, so paying up resets the clock and a later lapse
            // gets a fresh window instead of inheriting the old one.
            $firstPastDueAt = $subStatus === 'past_due'
                ? ($cached['first_past_due_at'] ?? $now)
                : null;

            $entry   = [
                'fetched_at'        => $now,
                'first_past_due_at' => $firstPastDueAt,
                'sub_status'   => $subStatus,
                'modules'      => $payload['modules_unlocked']   ?? [],
                'expires_at'   => $payload['expires_at']         ?? null,
                'current_plan' => $payload['current_plan']       ?? null,
            ];
            self::writeCache($cacheKey, $entry);
            return self::interpret($entry, $requiredModule, $now);
        }

        // Could not reach the server. Fall back to last-known cache if recent enough.
        if ($cached && ($now - $cached['fetched_at']) < self::STALE_HARD_LOCK) {
            $stale           = self::interpret($cached, $requiredModule, $now);
            $stale['reason'] = 'Stale (PanelDNS unreachable; using cached licence). ' . $stale['reason'];
            return $stale;
        }

        return [
            'unlocked'   => false,
            'reason'     => self::failureReason($resp),
            'sub_status' => 'unknown',
            'expires_at' => null,
        ];
    }

    /**
     * Convenience wrapper for lifecycle hooks.
     * Returns null (proceed) or an error string (block + show to admin).
     */
    public static function gateOrError(PanelDnsApiHb $api, string $requiredModule): ?string
    {
        $result = self::check($api, $requiredModule);
        if ($result['unlocked']) return null;

        return self::formatErrorBanner($result);
    }

    /**
     * Render a human-readable error banner for the HostBill admin UI.
     * @internal exposed for unit testing.
     */
    public static function formatErrorBanner(array $result): string
    {
        $sub       = $result['sub_status'] ?? 'unknown';
        $expiresAt = $result['expires_at'] ?? null;

        $headline = match (true) {
            $sub === 'cancelled' => 'PanelDNS subscription cancelled',
            $sub === 'past_due'  => 'PanelDNS subscription past due (grace expired)',
            $sub === 'free'      => 'No active PanelDNS subscription',
            $sub === 'unknown'   => 'Could not verify PanelDNS subscription',
            default              => 'PanelDNS licence check failed',
        };

        $explainer = match (true) {
            $sub === 'cancelled' => 'New provisioning is disabled. Existing customers keep working.',
            $sub === 'past_due'  => 'The 7-day grace period after a past-due subscription has expired. Provisioning is paused.',
            $sub === 'free'      => 'The paneldns-hostbill module requires an active PanelDNS subscription.',
            $sub === 'unknown'   => 'The PanelDNS server could not be reached. Check the App hostname and API key in Settings -> Apps.',
            default              => $result['reason'] ?? '',
        };

        $expiry = $expiresAt ? "Subscription expired: {$expiresAt}" : '';

        $lines = array_filter([$headline, '', $explainer, $expiry], fn ($l) => $l !== '');

        return implode("\n", $lines);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * Pure decision function. @internal Public for unit testing.
     */
    public static function interpret(array $cached, string $requiredModule, int $now): array
    {
        $sub     = $cached['sub_status'] ?? 'unknown';
        $mods    = $cached['modules']    ?? [];
        $expAt   = $cached['expires_at'] ?? null;
        $fetched = $cached['fetched_at'] ?? 0;

        $hasModule = in_array($requiredModule, $mods, true);

        if (in_array($sub, ['active', 'trialing'], true) && $hasModule) {
            return [
                'unlocked'   => true,
                'reason'     => "Subscription {$sub}",
                'sub_status' => $sub,
                'expires_at' => $expAt,
            ];
        }

        if ($sub === 'past_due' && $hasModule) {
            // Fall back to fetched_at only for a cache written before this field existed:
            // that starts the clock now rather than locking immediately, which is the
            // right way to be wrong when we do not know when the lapse began.
            $firstPastDueAt = $cached['first_past_due_at'] ?? $fetched;
            $secondsPastDue = $now - $firstPastDueAt;
            if ($secondsPastDue < self::GRACE_SECONDS) {
                $daysLeft = (int) ceil((self::GRACE_SECONDS - $secondsPastDue) / 86400);
                return [
                    'unlocked'   => true,
                    'reason'     => "Subscription past due (grace: {$daysLeft} day(s) left)",
                    'sub_status' => $sub,
                    'expires_at' => $expAt,
                ];
            }
            return [
                'unlocked'   => false,
                'reason'     => 'Subscription past due — grace period expired',
                'sub_status' => $sub,
                'expires_at' => $expAt,
            ];
        }

        return [
            'unlocked'   => false,
            'reason'     => "Subscription status: {$sub}" . ($hasModule ? '' : ' (module not unlocked)'),
            'sub_status' => $sub,
            'expires_at' => $expAt,
        ];
    }

    private static function cacheKey(PanelDnsApiHb $api): string
    {
        return 'paneldns-hb-licence-' . $api->identityHash();
    }

    private static function cachePath(string $key): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'paneldns_hb_cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir . DIRECTORY_SEPARATOR . $key . '.json';
    }

    private static function readCache(string $key): ?array
    {
        $path = self::cachePath($key);
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        if ($raw === false) return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private static function writeCache(string $key, array $value): void
    {
        // Only cache responses with a valid shape.
        if (
            !isset($value['sub_status']) || !is_string($value['sub_status']) || $value['sub_status'] === '' ||
            !isset($value['modules'])    || !is_array($value['modules'])
        ) {
            return;
        }
        $path = self::cachePath($key);
        @file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Describe WHY the licence check failed, distinguishing a server we could not
     * reach from one that reached us and refused.
     *
     * LICENCE-DIAG-01: every failure previously reported "Cannot reach PanelDNS".
     * A 401/403 is not unreachability - the server answered. Since PanelDNS v3.91.6
     * a suspended org receives 403 with an actionable message ("settle any
     * outstanding balance in the billing area"), and reporting that as a
     * connectivity fault sends the reseller to debug their network instead of their
     * invoice. PanelDNS v3.91.7 exempts licence-status from the suspension gate so
     * this path should no longer see a suspension 403, but the same reasoning holds
     * for an expired or revoked token.
     */
    private static function failureReason(array $resp): string
    {
        $status = (int) ($resp['status'] ?? 0);
        $error  = trim((string) ($resp['error'] ?? ''));

        if ($status >= 400 && $status < 500) {
            return $error !== ''
                ? "PanelDNS refused the licence check (HTTP {$status}): {$error}"
                : "PanelDNS refused the licence check (HTTP {$status}). Check the API token.";
        }

        return $error !== ''
            ? "Cannot reach PanelDNS to verify licence: {$error}"
            : 'Cannot reach PanelDNS to verify licence. Please try again later.';
    }
}
