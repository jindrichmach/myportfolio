-- M6: rozparsovaný soubor importu mezi náhledem a potvrzením.
CREATE TABLE IF NOT EXISTS `import_previews` (
  `token` char(32) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `source` varchar(32) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `account_id` bigint(20) unsigned NOT NULL,
  `portfolio_id` bigint(20) unsigned NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`token`),
  KEY `idx_import_previews_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_import_previews_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_import_previews_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_import_previews_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
