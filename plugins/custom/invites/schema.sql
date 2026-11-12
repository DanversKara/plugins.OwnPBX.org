-- Invites plugin. Created when the plugin is enabled, kept when it is disabled, dropped when it is uninstalled.

-- One invite link. max_uses 1 = one person; more = a reusable link. guest_rules: JSON of the
-- guest switches and phone modes the new account gets when is_guest = 1.
CREATE TABLE IF NOT EXISTS plg_invites (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    token CHAR(32) NOT NULL,
    label VARCHAR(80) NOT NULL DEFAULT '',
    email VARCHAR(120) NOT NULL DEFAULT '',
    max_uses INT UNSIGNED NOT NULL DEFAULT 1,
    uses INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    is_guest TINYINT(1) NOT NULL DEFAULT 0,
    guest_rules VARCHAR(255) NOT NULL DEFAULT '',
    revoked TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who joined through which invite.
CREATE TABLE IF NOT EXISTS plg_invite_uses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    invite_id INT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    ip VARCHAR(45) NOT NULL DEFAULT '',
    used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_invite (invite_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
