<?php
/**
 * Administrator forms on /plugins/billing: plans (save, publish to Stripe), per-user exceptions,
 * guest upgrade permission, syncing a user from Stripe, Store numbers and orders.
 */
require_once dirname(__DIR__) . '/lib/Store.php';

return static function (string $slug, array $settings): array {
    Billing::setSettings($settings);
    $action = (string) ($_POST['billing_action'] ?? '');
    if ($action === '' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return [];
    }
    if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        return ['error' => t('common.invalid_csrf')];
    }
    try {
        switch ($action) {
            case 'save_plan':
                $id = Billing::savePlan($_POST);
                if (!empty($_POST['publish']) && Billing::mode() !== '') {
                    Billing::publishPlan($id);
                    return ['message' => 'Plan saved and published to Stripe (' . Billing::mode() . ' mode).'];
                }
                return ['message' => 'Plan saved.' . (Billing::mode() === '' ? ' Add your Stripe secret key above, then publish it.' : ' Publish it to offer it.')];
            case 'publish_plan':
                Billing::publishPlan((int) ($_POST['plan_id'] ?? 0));
                return ['message' => 'Plan published to Stripe (' . Billing::mode() . ' mode).'];
            case 'override':
                $uid = (int) ($_POST['user_id'] ?? 0);
                $feature = (string) ($_POST['feature'] ?? '');
                $raw = trim((string) ($_POST['value'] ?? ''));
                Billing::setOverride($uid, $feature, $raw === '' ? null : (int) $raw);
                return ['message' => $raw === '' ? 'Exception removed.' : 'Exception saved.'];
            case 'guest_upgrade':
                Billing::setGuestMayUpgrade((int) ($_POST['user_id'] ?? 0), !empty($_POST['may']));
                return ['message' => 'Saved.'];
            case 'sync_user':
                Billing::syncUser((int) ($_POST['user_id'] ?? 0));
                return ['message' => 'Synced from Stripe.'];
            case 'store_numbers':
                BillingStore::setNumbers((string) ($_POST['numbers'] ?? ''));
                return ['message' => 'Store numbers saved.'];
            case 'approve_order':
                return ['message' => BillingStore::approve((int) ($_POST['order_id'] ?? 0))];
            case 'reject_order':
                BillingStore::reject((int) ($_POST['order_id'] ?? 0), (string) ($_POST['reason'] ?? ''));
                return ['message' => 'Order rejected (the card was never charged).'];
        }
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
    return [];
};
