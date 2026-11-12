<?php
/** Administrator forms on /plugins/invites: make an invite, send it again, withdraw or delete one. */
require_once dirname(__DIR__) . '/lib/Invites.php';

return static function (string $slug, array $settings): array {
    Invites::setSettings($settings);
    $action = (string) ($_POST['invite_action'] ?? '');
    if ($action === '' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return [];
    }
    if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        return ['error' => t('common.invalid_csrf')];
    }
    try {
        $id = (int) ($_POST['invite_id'] ?? 0);
        switch ($action) {
            case 'create':
                $row = Invites::create($_POST, isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null);
                $_SESSION['invites_new'] = (int) $row['id'];
                return ['message' => $row['email'] !== ''
                    ? 'Invite e-mailed to ' . $row['email'] . '. The link is also in the list below.'
                    : 'Invite made. Copy its link from the list below.'];
            case 'resend':
                Invites::resend($id);
                return ['message' => 'Invite e-mailed again.'];
            case 'revoke':
                Invites::revoke($id);
                return ['message' => 'Invite withdrawn: the link no longer works.'];
            case 'delete':
                Invites::delete($id);
                return ['message' => 'Invite deleted. Accounts made with it stay.'];
        }
        return ['error' => 'Unknown action.'];
    } catch (\InvalidArgumentException $e) {
        return ['error' => $e->getMessage()];
    } catch (\Throwable $e) {
        error_log('invites admin: ' . $e->getMessage());
        return ['error' => 'Something went wrong: ' . $e->getMessage()];
    }
};
