-- Billing & Store plugin. Created when the plugin is enabled, kept when it is disabled, dropped when it is uninstalled.

-- A monthly plan. -1 = unlimited, 0 = none for the monthly counts; fax is on/off.
CREATE TABLE IF NOT EXISTS plg_billing_plans (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    price_cents INT UNSIGNED NOT NULL DEFAULT 0,
    currency VARCHAR(3) NOT NULL DEFAULT 'usd',
    call_minutes INT NOT NULL DEFAULT 0,
    messages INT NOT NULL DEFAULT 0,
    fax TINYINT(1) NOT NULL DEFAULT 0,
    faxes INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    in_store TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    stripe_mode VARCHAR(4) NOT NULL DEFAULT '',
    stripe_product_id VARCHAR(64) NOT NULL DEFAULT '',
    stripe_price_id VARCHAR(64) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_price (stripe_price_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A user's Stripe customer and current subscription.
CREATE TABLE IF NOT EXISTS plg_billing_customers (
    user_id INT NOT NULL,
    stripe_mode VARCHAR(4) NOT NULL DEFAULT '',
    stripe_customer_id VARCHAR(64) NOT NULL DEFAULT '',
    subscription_id VARCHAR(64) NOT NULL DEFAULT '',
    subscription_item_id VARCHAR(64) NOT NULL DEFAULT '',
    plan_id INT UNSIGNED NULL,
    status VARCHAR(24) NOT NULL DEFAULT '',
    current_period_end INT UNSIGNED NOT NULL DEFAULT 0,
    cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
    guest_may_upgrade TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    KEY idx_customer (stripe_customer_id),
    KEY idx_subscription (subscription_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stripe events already handled (webhooks are retried and may arrive twice).
CREATE TABLE IF NOT EXISTS plg_billing_events (
    id VARCHAR(255) NOT NULL,
    type VARCHAR(80) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What a user used: seconds of an outside call, one text, one fax.
CREATE TABLE IF NOT EXISTS plg_billing_usage (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    feature VARCHAR(20) NOT NULL,
    amount INT NOT NULL DEFAULT 1,
    detail VARCHAR(120) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_feature (user_id, feature, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user exceptions set by the admin; they win over the plan and the defaults.
CREATE TABLE IF NOT EXISTS plg_billing_overrides (
    user_id INT NOT NULL,
    feature VARCHAR(20) NOT NULL,
    value INT NOT NULL,
    PRIMARY KEY (user_id, feature)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Store orders: the card is saved by Stripe Checkout (setup mode); nothing is charged or created
-- until the admin approves.
CREATE TABLE IF NOT EXISTS plg_billing_orders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    status VARCHAR(16) NOT NULL DEFAULT 'started',
    full_name VARCHAR(80) NOT NULL DEFAULT '',
    email VARCHAR(120) NOT NULL DEFAULT '',
    plan_id INT UNSIGNED NOT NULL,
    number VARCHAR(20) NOT NULL DEFAULT '',
    stripe_mode VARCHAR(4) NOT NULL DEFAULT '',
    stripe_session_id VARCHAR(255) NOT NULL DEFAULT '',
    stripe_customer_id VARCHAR(64) NOT NULL DEFAULT '',
    payment_method VARCHAR(64) NOT NULL DEFAULT '',
    user_id INT NULL,
    note VARCHAR(255) NOT NULL DEFAULT '',
    ip VARCHAR(45) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status (status),
    KEY idx_session (stripe_session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phone numbers offered in the Store with a plan (each one answered by nobody else).
CREATE TABLE IF NOT EXISTS plg_billing_store_numbers (
    number VARCHAR(20) NOT NULL,
    label VARCHAR(60) NOT NULL DEFAULT '',
    PRIMARY KEY (number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
