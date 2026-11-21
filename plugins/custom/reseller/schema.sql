-- Reseller Accounts plugin v1 (foundation). Created when the plugin is enabled, kept when it is
-- disabled, dropped when it is uninstalled. Only CREATE TABLE IF NOT EXISTS plg_* statements are
-- allowed here (PluginManager enforces the plg_ sandbox when it applies this file).
-- The manifest lists the tables children-first on purpose: uninstall drops them in
-- manifest order, and the parent (plg_resellers) must go last or the foreign keys
-- block the drop. Creation order in this file is the reverse (parent first).

-- A reseller = one tenant. status: active | suspended (a suspended reseller's domains stop
-- resolving to them). stripe_mode: own = the reseller's own Stripe keys; platform = the PBX
-- Stripe, and the PBX takes its cut (fee_type percent = % of each transaction, monthly = a flat
-- monthly fee; fee_rate is the % 0-100 or the monthly amount in the plugin currency).
-- ext_from/ext_to: the reseller's own extension range (NULL = not assigned yet).
CREATE TABLE IF NOT EXISTS plg_resellers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    stock_subdomain VARCHAR(63) NULL DEFAULT NULL,
    custom_domain VARCHAR(253) NULL DEFAULT NULL,
    display_name VARCHAR(80) NOT NULL DEFAULT '',
    logo_path VARCHAR(255) NOT NULL DEFAULT '',
    primary_color VARCHAR(7) NOT NULL DEFAULT '',
    stripe_mode VARCHAR(8) NOT NULL DEFAULT 'platform',
    fee_type VARCHAR(8) NOT NULL DEFAULT 'percent',
    fee_rate DECIMAL(10, 2) NOT NULL DEFAULT 0,
    ext_from INT UNSIGNED NULL,
    ext_to INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stock_subdomain (stock_subdomain),
    UNIQUE KEY uq_custom_domain (custom_domain),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Domains that resolve to a reseller (their custom domain, mirrored from plg_resellers, plus any
-- extra domains later). cert_status: pending | live | failed — a domain that never validates must
-- fail closed and never take logins. Resolution reads this table, never the reseller row directly.
CREATE TABLE IF NOT EXISTS plg_reseller_domains (
    domain VARCHAR(253) NOT NULL,
    reseller_id INT UNSIGNED NOT NULL,
    cert_status VARCHAR(16) NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (domain),
    KEY idx_reseller (reseller_id),
    CONSTRAINT fk_reseller_domains_reseller FOREIGN KEY (reseller_id) REFERENCES plg_resellers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A reseller's own Stripe keys (only when stripe_mode = own). Keys are encrypted with the same
-- secret box the billing plugin's stripe_secret_key uses (SecretBox) before they are stored, and
-- decrypted on read. Never logged, never rendered back to a page.
CREATE TABLE IF NOT EXISTS plg_reseller_stripe (
    reseller_id INT UNSIGNED NOT NULL,
    mode VARCHAR(8) NOT NULL DEFAULT 'own',
    secret_key TEXT NOT NULL DEFAULT '',
    webhook_secret TEXT NOT NULL DEFAULT '',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (reseller_id),
    CONSTRAINT fk_reseller_stripe_reseller FOREIGN KEY (reseller_id) REFERENCES plg_resellers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Platform-fee ledger (skeleton in v1 — nothing accrues yet). period is YYYY-MM; basis is the fee
-- type that produced the row (percent = % of a transaction, monthly = the flat monthly fee).
-- amount_cents is what the reseller owes the PBX for that period; settled = the admin marked it paid.
CREATE TABLE IF NOT EXISTS plg_reseller_fees (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reseller_id INT UNSIGNED NOT NULL,
    period VARCHAR(7) NOT NULL DEFAULT '',
    basis VARCHAR(8) NOT NULL DEFAULT 'percent',
    amount_cents INT NOT NULL DEFAULT 0,
    settled TINYINT(1) NOT NULL DEFAULT 0,
    note VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reseller_period (reseller_id, period),
    CONSTRAINT fk_reseller_fees_reseller FOREIGN KEY (reseller_id) REFERENCES plg_resellers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
