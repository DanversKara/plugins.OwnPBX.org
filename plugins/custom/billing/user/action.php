<?php
/**
 * Billing page forms (the core has checked CSRF): subscribe (Stripe Checkout), switch plan,
 * cancel at the end of the month / undo it, manage the card (Stripe Customer Portal).
 */
require_once dirname(__DIR__) . '/lib/Billing.php';

return static function (string $slug, array $settings, array $user): array {
    Billing::setSettings($settings);
    $uid = (int) ($user['id'] ?? 0);
    $go = static function (string $url): array {
        header('Location: ' . $url, true, 303);
        exit;
    };
    try {
        switch ((string) ($_POST['billing_action'] ?? '')) {
            case 'subscribe':
                return $go(Billing::checkoutUrl($uid, (int) ($_POST['plan_id'] ?? 0)));
            case 'switch':
                Billing::switchPlan($uid, (int) ($_POST['plan_id'] ?? 0));
                return ['message' => 'Your plan was changed. The difference is prorated on your next invoice.'];
            case 'cancel':
                Billing::setCancel($uid, true);
                return ['message' => 'Your plan ends at the end of the paid month.'];
            case 'resume':
                Billing::setCancel($uid, false);
                return ['message' => 'Your plan continues.'];
            case 'portal':
                return $go(Billing::portalUrl($uid));
        }
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
    return [];
};
