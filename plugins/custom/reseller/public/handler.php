<?php
/**
 * Public endpoints, no sign-in:
 *   GET /api/plugins/reseller/tenant   which reseller (if any) owns this request host
 * Nothing here throws: the core turns five errors in a row into Safe Mode, and a stranger must not
 * be able to switch the plugin off with bad requests. The answer carries public tenant facts only
 * (id, name, status, domains) — never Stripe keys or anything else.
 */
require_once dirname(__DIR__) . '/lib/Reseller.php';

return static function (string $subPath, array $settings): void {
    Reseller::setSettings($settings);
    $parts = explode('/', trim($subPath, '/'));
    $answer = static function (int $code, string $type, string $body): void {
        http_response_code($code);
        header('Content-Type: ' . $type);
        echo $body;
    };
    try {
        // ---- which tenant owns this host?
        if ($parts[0] === 'tenant' && count($parts) === 1) {
            $tenant = Reseller::resolveTenantByHost((string) ($_SERVER['HTTP_HOST'] ?? ''));
            if ($tenant === null) {
                $answer(200, 'application/json', json_encode(['success' => true, 'reseller' => null]));
                return;
            }
            $answer(200, 'application/json', json_encode(['success' => true, 'reseller' => [
                'id' => (int) $tenant['id'],
                'name' => (string) $tenant['name'],
                'display_name' => (string) ($tenant['display_name'] ?? ''),
                'status' => (string) $tenant['status'],
                'stock_subdomain' => (string) ($tenant['stock_subdomain'] ?? ''),
                'custom_domain' => (string) ($tenant['custom_domain'] ?? ''),
            ]]));
            return;
        }
        $answer(404, 'text/plain', 'not found');
    } catch (\Throwable $e) {
        error_log('reseller public: ' . $e->getMessage());
        $answer(500, 'text/plain', 'error');
    }
};
