SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(64) NOT NULL UNIQUE,
  `nickname` VARCHAR(64) NOT NULL,
  `email` VARCHAR(128) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_ip` VARCHAR(45) DEFAULT NULL,
  `last_login_time` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `announcements` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(200) NOT NULL,
  `summary` VARCHAR(500) DEFAULT NULL,
  `content` TEXT NOT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `sort` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `merchants` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `uid` INT UNSIGNED NOT NULL UNIQUE,
  `appid` VARCHAR(32) NOT NULL UNIQUE,
  `mch_key` VARCHAR(64) NOT NULL,
  `rsa_private_key` MEDIUMTEXT DEFAULT NULL,
  `rsa_public_key` MEDIUMTEXT DEFAULT NULL,
  `name` VARCHAR(100) NOT NULL,
  `contact_name` VARCHAR(50) DEFAULT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 0,
  `platform_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `daily_limit` DECIMAL(20,2) NOT NULL DEFAULT 0.00,
  `white_ip` TEXT DEFAULT NULL,
  `notify_url` VARCHAR(255) DEFAULT NULL,
  `return_url` VARCHAR(255) DEFAULT NULL,
  `registered_ip` VARCHAR(45) DEFAULT NULL,
  `last_login_ip` VARCHAR(45) DEFAULT NULL,
  `last_login_time` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_merchants_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `merchant_users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `merchant_id` INT UNSIGNED NOT NULL,
  `username` VARCHAR(64) NOT NULL UNIQUE,
  `nickname` VARCHAR(64) NOT NULL,
  `email` VARCHAR(128) DEFAULT NULL,
  `phone` VARCHAR(32) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_merchant_users_merchant_id` FOREIGN KEY (`merchant_id`) REFERENCES `merchants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `channel_types` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(30) NOT NULL UNIQUE,
  `name` VARCHAR(50) NOT NULL,
  `category` TINYINT(1) NOT NULL COMMENT '1=链上直达 2=码支付',
  `icon` VARCHAR(255) DEFAULT NULL,
  `min_amount` DECIMAL(20,8) NOT NULL DEFAULT 1.00,
  `max_amount` DECIMAL(20,8) NOT NULL DEFAULT 0,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `sort` INT NOT NULL DEFAULT 0,
  `config_schema` JSON DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `merchant_channels` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `merchant_id` INT UNSIGNED NOT NULL,
  `channel_type_id` INT UNSIGNED NOT NULL,
  `config` JSON NOT NULL,
  `rate` DECIMAL(5,2) DEFAULT NULL,
  `daily_limit` DECIMAL(20,2) NOT NULL DEFAULT 0.00,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `remark` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_merchant_channel` (`merchant_id`, `channel_type_id`),
  CONSTRAINT `fk_merchant_channels_merchant_id` FOREIGN KEY (`merchant_id`) REFERENCES `merchants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_merchant_channels_channel_type_id` FOREIGN KEY (`channel_type_id`) REFERENCES `channel_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `orders` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `trade_no` VARCHAR(32) NOT NULL UNIQUE,
  `out_trade_no` VARCHAR(64) NOT NULL,
  `merchant_id` INT UNSIGNED NOT NULL,
  `merchant_channel_id` INT UNSIGNED NOT NULL,
  `channel_code` VARCHAR(32) NOT NULL,
  `channel_category` TINYINT(1) NOT NULL DEFAULT 1,
  `subject` VARCHAR(200) NOT NULL,
  `amount` DECIMAL(20,8) NOT NULL,
  `payable_amount` DECIMAL(20,8) NOT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 0,
  `payment_address` VARCHAR(500) DEFAULT NULL,
  `txid` VARCHAR(120) DEFAULT NULL,
  `confirmations` INT NOT NULL DEFAULT 0,
  `expire_time` TIMESTAMP NOT NULL,
  `pay_time` TIMESTAMP NULL DEFAULT NULL,
  `platform_fee` DECIMAL(20,8) NOT NULL DEFAULT 0.00,
  `fee_deducted` TINYINT(1) NOT NULL DEFAULT 0,
  `callback_status` TINYINT(1) NOT NULL DEFAULT 0,
  `callback_count` INT NOT NULL DEFAULT 0,
  `notify_url` VARCHAR(255) DEFAULT NULL,
  `return_url` VARCHAR(255) DEFAULT NULL,
  `client_ip` VARCHAR(45) DEFAULT NULL,
  `param` VARCHAR(255) DEFAULT NULL,
  `request_payload` JSON DEFAULT NULL,
  `notify_payload` JSON DEFAULT NULL,
  `remark` TEXT DEFAULT NULL,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_orders_merchant_out_trade_no` (`merchant_id`, `out_trade_no`),
  INDEX `idx_orders_status_expire` (`status`, `expire_time`),
  INDEX `idx_orders_created_at` (`created_at`),
  CONSTRAINT `fk_orders_merchant_id` FOREIGN KEY (`merchant_id`) REFERENCES `merchants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_orders_merchant_channel_id` FOREIGN KEY (`merchant_channel_id`) REFERENCES `merchant_channels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `orders_archive` LIKE `orders`;

CREATE TABLE IF NOT EXISTS `merchant_balances` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `merchant_id` INT UNSIGNED NOT NULL UNIQUE,
  `balance` DECIMAL(20,8) NOT NULL DEFAULT 0.00,
  `frozen_balance` DECIMAL(20,8) NOT NULL DEFAULT 0.00,
  `total_recharge` DECIMAL(20,8) NOT NULL DEFAULT 0.00,
  `total_consumption` DECIMAL(20,8) NOT NULL DEFAULT 0.00,
  `last_recharge_time` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_merchant_balances_merchant_id` FOREIGN KEY (`merchant_id`) REFERENCES `merchants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `merchant_balance_logs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `merchant_id` INT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED DEFAULT NULL,
  `type` TINYINT(1) NOT NULL COMMENT '1充值 2手续费 3套餐 4管理员调整',
  `amount` DECIMAL(20,8) NOT NULL,
  `balance_before` DECIMAL(20,8) NOT NULL,
  `balance_after` DECIMAL(20,8) NOT NULL,
  `remark` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_balance_logs_merchant_id` (`merchant_id`),
  INDEX `idx_balance_logs_order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `merchant_recharge_orders` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `trade_no` VARCHAR(32) NOT NULL UNIQUE,
  `merchant_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(20,8) NOT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 0,
  `payment_type` VARCHAR(20) NOT NULL,
  `order_type` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1余额充值 2套餐购买',
  `package_id` INT UNSIGNED DEFAULT NULL,
  `epay_trade_no` VARCHAR(64) DEFAULT NULL,
  `pay_time` TIMESTAMP NULL DEFAULT NULL,
  `expire_time` TIMESTAMP NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_recharge_orders_merchant_id` (`merchant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `fee_rules` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL,
  `type` TINYINT(1) NOT NULL COMMENT '1固定 2阶梯',
  `rate` DECIMAL(5,2) DEFAULT NULL,
  `tiers` JSON DEFAULT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `merchant_fee_rules` (
  `merchant_id` INT UNSIGNED PRIMARY KEY,
  `fee_rule_id` INT UNSIGNED NOT NULL,
  `effective_time` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_merchant_fee_rules_merchant_id` FOREIGN KEY (`merchant_id`) REFERENCES `merchants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_merchant_fee_rules_rule_id` FOREIGN KEY (`fee_rule_id`) REFERENCES `fee_rules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `packages` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL,
  `price` DECIMAL(20,8) NOT NULL,
  `duration_days` INT NOT NULL,
  `benefits` JSON DEFAULT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `merchant_package_orders` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `merchant_id` INT UNSIGNED NOT NULL,
  `package_id` INT UNSIGNED NOT NULL,
  `price` DECIMAL(20,8) NOT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 0,
  `start_time` TIMESTAMP NULL DEFAULT NULL,
  `end_time` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_package_orders_merchant_id` (`merchant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `callback_queue` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `merchant_id` INT UNSIGNED NOT NULL,
  `notify_url` VARCHAR(255) NOT NULL,
  `payload` JSON NOT NULL,
  `retry_count` INT NOT NULL DEFAULT 0,
  `max_retry` INT NOT NULL DEFAULT 10,
  `status` TINYINT(1) NOT NULL DEFAULT 0,
  `next_time` TIMESTAMP NOT NULL,
  `last_error` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_callback_queue_status_next` (`status`, `next_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `operation_logs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `admin_id` INT UNSIGNED DEFAULT NULL,
  `merchant_id` INT UNSIGNED DEFAULT NULL,
  `username` VARCHAR(50) DEFAULT NULL,
  `action` VARCHAR(50) NOT NULL,
  `target_type` VARCHAR(30) DEFAULT NULL,
  `target_id` BIGINT DEFAULT NULL,
  `ip` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `system_configs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(100) NOT NULL UNIQUE,
  `value` TEXT DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `merchant_tg_bindings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `merchant_id` INT UNSIGNED NOT NULL UNIQUE,
  `chat_id` VARCHAR(50) NOT NULL,
  `bind_code` VARCHAR(16) DEFAULT NULL,
  `bind_expire` TIMESTAMP NULL DEFAULT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tickets` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ticket_no` VARCHAR(32) NOT NULL UNIQUE,
  `merchant_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `content` TEXT NOT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 0,
  `priority` TINYINT(1) NOT NULL DEFAULT 0,
  `last_reply_time` TIMESTAMP NULL DEFAULT NULL,
  `admin_id` INT UNSIGNED DEFAULT NULL,
  `closed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ticket_replies` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT UNSIGNED NOT NULL,
  `user_type` TINYINT(1) NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `content` TEXT NOT NULL,
  `attachments` JSON DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `files` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `merchant_id` INT UNSIGNED DEFAULT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_size` INT UNSIGNED NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `file_ext` VARCHAR(20) NOT NULL,
  `category` VARCHAR(30) NOT NULL DEFAULT 'image',
  `uploaded_by` ENUM('merchant', 'admin') NOT NULL,
  `uploader_id` INT UNSIGNED NOT NULL,
  `access_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `plugins` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(30) NOT NULL UNIQUE,
  `name` VARCHAR(50) NOT NULL,
  `version` VARCHAR(20) DEFAULT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `installed_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `admin_users` (`id`, `username`, `nickname`, `email`, `password_hash`, `status`)
VALUES
  (1, 'admin', '平台管理员', 'admin@example.com', '$2y$10$61exabpzYttrqxtq9Wx5bebJ8xZ5TcqjtgVKJYcJ6dnLv4x3.uVgS', 1)
ON DUPLICATE KEY UPDATE `nickname` = VALUES(`nickname`);

INSERT INTO `merchants`
(`id`, `uid`, `appid`, `mch_key`, `rsa_private_key`, `rsa_public_key`, `name`, `contact_name`, `email`, `phone`, `status`, `platform_rate`, `notify_url`, `return_url`)
VALUES
(
  1,
  100001,
  '1000001',
  'epay_v1_key_123456',
  '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',
  '商户一号',
  'Merchant One',
  'merchant@example.com',
  '13800138000',
  1,
  0.80,
  'https://example.com/merchant/notify',
  'https://example.com/merchant/return'
)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

INSERT INTO `merchant_users`
(`id`, `merchant_id`, `username`, `nickname`, `email`, `phone`, `password_hash`, `status`)
VALUES
  (1, 1, 'merchant001', '商户一号账号', 'merchant@example.com', '13800138000', '$2y$10$9DTM8x5ykzOK9FoOpbikaON1BsdWVSxiZFAECrB45CtJmhPxu5K7u', 1)
ON DUPLICATE KEY UPDATE `nickname` = VALUES(`nickname`);

INSERT INTO `merchant_balances` (`id`, `merchant_id`, `balance`, `frozen_balance`, `total_recharge`, `total_consumption`)
VALUES (1, 1, 5000.00, 0.00, 5000.00, 0.00)
ON DUPLICATE KEY UPDATE `balance` = VALUES(`balance`);

INSERT INTO `channel_types` (`id`, `code`, `name`, `category`, `icon`, `status`, `sort`, `config_schema`)
VALUES
  (1, 'trc20', 'USDT-TRC20', 1, '', 1, 10, JSON_OBJECT('fields', JSON_ARRAY(JSON_OBJECT('key', 'address', 'label', '收款地址', 'type', 'text')))),
  (2, 'bsc', 'USDT-BSC', 1, '', 1, 20, JSON_OBJECT('fields', JSON_ARRAY(JSON_OBJECT('key', 'address', 'label', '收款地址', 'type', 'text')))),
  (3, 'polygon', 'USDT-Polygon', 1, '', 1, 30, JSON_OBJECT('fields', JSON_ARRAY(JSON_OBJECT('key', 'address', 'label', '收款地址', 'type', 'text')))),
  (4, 'avaxc', 'USDT-AVAX-C', 1, '', 1, 40, JSON_OBJECT('fields', JSON_ARRAY(JSON_OBJECT('key', 'address', 'label', '收款地址', 'type', 'text')))),
  (5, 'alipay', '支付宝', 2, '', 1, 50, JSON_OBJECT('fields', JSON_ARRAY(JSON_OBJECT('key', 'qrcode_url', 'label', '收款码链接', 'type', 'image')))),
  (6, 'wechat', '微信支付', 2, '', 1, 60, JSON_OBJECT('fields', JSON_ARRAY(JSON_OBJECT('key', 'qrcode_url', 'label', '收款码链接', 'type', 'image')))),
  (7, 'qqpay', 'QQ钱包', 2, '', 1, 70, JSON_OBJECT('fields', JSON_ARRAY(JSON_OBJECT('key', 'qrcode_url', 'label', '收款码链接', 'type', 'image'))))
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

INSERT INTO `announcements` (`id`, `title`, `summary`, `content`, `status`, `sort`)
VALUES
  (1, '系统初始化完成', 'NexPay 第一版项目骨架已经可进入联调。', '后端已具备首页数据接口、商户/管理员登录、易支付 V1/V2 兼容建单/查单骨架。', 1, 10),
  (2, '开发建议', '管理员端与商户端建议共用一个前端控制台壳子。', '三端 UI 已经很明确，首页单独部署，商户与后台共用一套组件体系和权限路由最省成本。', 1, 20)
ON DUPLICATE KEY UPDATE `summary` = VALUES(`summary`);

INSERT INTO `system_configs` (`id`, `key`, `value`, `description`)
VALUES
  (1, 'app_name', 'NexPay 聚合支付系统', '站点名称'),
  (2, 'app_url', 'http://127.0.0.1:5174', '网关基础地址'),
  (3, 'site_logo', '/assets/logo.svg', '站点 Logo'),
  (4, 'tg_notify_enabled', '0', '是否启用 TG 通知')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

SET FOREIGN_KEY_CHECKS = 1;
