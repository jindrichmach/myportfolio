-- E-mailová upozornění — denní souhrn.
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `alerts_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `weekly_report_sent_at`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `alerts_recipients` varchar(1000) DEFAULT NULL COMMENT 'čárkou oddělené e-maily; NULL = přihlašovací e-mail' AFTER `alerts_enabled`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `alerts_move_portfolio_pct` decimal(6,2) DEFAULT 5.00 COMMENT 'NULL = vypnuto' AFTER `alerts_recipients`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `alerts_move_position_pct` decimal(6,2) DEFAULT NULL COMMENT 'NULL = vypnuto' AFTER `alerts_move_portfolio_pct`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `alerts_holding_days` smallint(5) unsigned DEFAULT 30 COMMENT 'NULL = vypnuto' AFTER `alerts_move_position_pct`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `alerts_data_issues` tinyint(1) NOT NULL DEFAULT 1 AFTER `alerts_holding_days`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `alerts_sent_at` timestamp NULL DEFAULT NULL AFTER `alerts_data_issues`;

CREATE TABLE IF NOT EXISTS `price_alerts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `instrument_id` bigint(20) unsigned NOT NULL,
  `direction` enum('above','below') NOT NULL,
  `price` decimal(28,10) NOT NULL,
  `currency` char(3) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `triggered_at` datetime DEFAULT NULL COMMENT 'NULL = čeká; po překročení se jednou ohlásí',
  `triggered_price` decimal(28,10) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_price_alerts_user` (`user_id`,`triggered_at`),
  KEY `fk_price_alerts_instrument` (`instrument_id`),
  CONSTRAINT `fk_price_alerts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_price_alerts_instrument` FOREIGN KEY (`instrument_id`) REFERENCES `instruments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Co už bylo ohlášeno (deduplikace mezi denními souhrny)
CREATE TABLE IF NOT EXISTS `alert_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `alert_key` varchar(190) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_alert_log_key` (`user_id`,`alert_key`),
  KEY `idx_alert_log_created` (`created_at`),
  CONSTRAINT `fk_alert_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
