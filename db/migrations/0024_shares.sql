-- Sdílení pro domácnost.
CREATE TABLE IF NOT EXISTS `shares` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `owner_user_id` bigint(20) unsigned NOT NULL,
  `grantee_user_id` bigint(20) unsigned DEFAULT NULL COMMENT 'NULL = pozvánka čeká na založení účtu',
  `invite_email` varchar(190) NOT NULL,
  `invite_token_hash` char(64) DEFAULT NULL,
  `invite_expires_at` datetime DEFAULT NULL,
  `permission` enum('read','write') NOT NULL DEFAULT 'read',
  `scope` enum('all','portfolios') NOT NULL DEFAULT 'all',
  `sections` varchar(255) NOT NULL DEFAULT '' COMMENT 'čárkou: net_worth, tax, income (jen scope all)',
  `accepted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shares_owner_email` (`owner_user_id`,`invite_email`),
  UNIQUE KEY `uq_shares_token` (`invite_token_hash`),
  KEY `idx_shares_grantee` (`grantee_user_id`),
  CONSTRAINT `fk_shares_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shares_grantee` FOREIGN KEY (`grantee_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `share_portfolios` (
  `share_id` bigint(20) unsigned NOT NULL,
  `portfolio_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`share_id`,`portfolio_id`),
  KEY `fk_share_portfolios_portfolio` (`portfolio_id`),
  CONSTRAINT `fk_share_portfolios_share` FOREIGN KEY (`share_id`) REFERENCES `shares` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_share_portfolios_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kdo záznam zapsal, když to nebyl vlastník (partner se sdílením pro zápis)
ALTER TABLE `transactions` ADD COLUMN IF NOT EXISTS `created_by_user_id` bigint(20) unsigned DEFAULT NULL;
ALTER TABLE `positions` ADD COLUMN IF NOT EXISTS `created_by_user_id` bigint(20) unsigned DEFAULT NULL;
ALTER TABLE `manual_valuations` ADD COLUMN IF NOT EXISTS `created_by_user_id` bigint(20) unsigned DEFAULT NULL;
ALTER TABLE `import_batches` ADD COLUMN IF NOT EXISTS `created_by_user_id` bigint(20) unsigned DEFAULT NULL;
