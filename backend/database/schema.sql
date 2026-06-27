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
  INDEX `idx_orders_trade_no_created_at` (`trade_no`, `created_at`),
  INDEX `idx_orders_out_trade_no` (`out_trade_no`),
  INDEX `idx_orders_merchant_status_created` (`merchant_id`, `status`, `created_at`),
  INDEX `idx_orders_merchant_channel_created` (`merchant_channel_id`, `created_at`),
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


SET FOREIGN_KEY_CHECKS = 1;
