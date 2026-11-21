<?php
/**
 * Administrator forms on /plugins/reseller: save a reseller, suspend/activate, delete,
 * and save a reseller's own Stripe keys. Admin only — PluginsController::detail gates
 * this page with requireRole('admin') before this file ever runs.
 */
require_once dirname(__DIR__) . '/lib/Reseller.php';

return static function (string $slug, array $settings): array {
    Reseller::setSettings($settings);
    $action = (string) ($_POST['reseller_action'] ?? '');
    if ($action === '' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return [];
    }
    if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        return ['error' => t('common.invalid_csrf')];
    }
    try {
        switch ($action) {
            case 'save_reseller':
                $id = (int) ($_POST['reseller_id'] ?? 0);
                if ($id > 0) {
                    Reseller::save($id, $_POST);
                    return ['message' => 'Reseller saved.'];
                }
                $id = Reseller::create($_POST);
                return ['message' => 'Reseller created (id ' . $id . ').'];
            case 'set_status':
                Reseller::setStatus((int) ($_POST['reseller_id'] ?? 0), (string) ($_POST['status'] ?? ''));
                return ['message' => 'Reseller status saved.'];
            case 'delete_reseller':
                Reseller::delete((int) ($_POST['reseller_id'] ?? 0));
                return ['message' => 'Reseller deleted.'];
            case 'save_stripe_keys':
                Reseller::saveStripeKeys(
                    (int) ($_POST['reseller_id'] ?? 0),
                    (string) ($_POST['stripe_secret_key'] ?? ''),
                    (string) ($_POST['stripe_webhook_secret'] ?? ''),
                    (string) ($_POST['stripe_mode'] ?? 'own')
                );
                return ['message' => 'Stripe keys saved (encrypted).'];
        }
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
    return [];
};
