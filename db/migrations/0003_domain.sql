-- M1 — doménový model: katalog (instituce, instrumenty), účty, portfolia, pozice, transakce.
-- Peníze a množství jako DECIMAL, nikdy FLOAT.

CREATE TABLE IF NOT EXISTS `institutions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `type` enum('exchange','broker','wallet','robo','bank') NOT NULL,
  `website` varchar(190) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_institutions_name` (`name`),
  KEY `fk_institutions_created_by` (`created_by_user_id`),
  CONSTRAINT `fk_institutions_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `instruments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `asset_class` enum('crypto','etf','stock','fund','cash') NOT NULL,
  `symbol` varchar(40) NOT NULL,
  `name` varchar(190) NOT NULL,
  `isin` char(12) DEFAULT NULL,
  `quote_currency` char(3) NOT NULL,
  `decimals` tinyint(3) unsigned NOT NULL DEFAULT 8 COMMENT 'počet desetinných míst množství pro zobrazení',
  `price_source` enum('coingecko','xtb','yahoo','manual') NOT NULL DEFAULT 'manual',
  `source_refs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (`source_refs` IS NULL OR json_valid(`source_refs`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_instruments_class_symbol_ccy` (`asset_class`,`symbol`,`quote_currency`),
  KEY `idx_instruments_isin` (`isin`),
  KEY `fk_instruments_created_by` (`created_by_user_id`),
  CONSTRAINT `fk_instruments_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `institution_id` bigint(20) unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'CZK',
  `note` varchar(500) DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_accounts_user_inst_name` (`user_id`,`institution_id`,`name`),
  KEY `fk_accounts_institution` (`institution_id`),
  CONSTRAINT `fk_accounts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_accounts_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `portfolios` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `sort` int(11) NOT NULL DEFAULT 0,
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_portfolios_user_name` (`user_id`,`name`),
  CONSTRAINT `fk_portfolios_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `positions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `portfolio_id` bigint(20) unsigned NOT NULL,
  `account_id` bigint(20) unsigned NOT NULL,
  `instrument_id` bigint(20) unsigned DEFAULT NULL COMMENT 'NULL = manual pozice (Portu strategie, spořicí účet)',
  `valuation_mode` enum('market','manual') NOT NULL,
  `name` varchar(120) DEFAULT NULL,
  `currency` char(3) NOT NULL,
  `interest_rate_pa` decimal(8,4) DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_positions_portfolio_account_instrument` (`portfolio_id`,`account_id`,`instrument_id`),
  KEY `idx_positions_user` (`user_id`,`archived_at`),
  KEY `fk_positions_account` (`account_id`),
  KEY `fk_positions_instrument` (`instrument_id`),
  CONSTRAINT `fk_positions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_positions_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_positions_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_positions_instrument` FOREIGN KEY (`instrument_id`) REFERENCES `instruments` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `import_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `source` varchar(32) NOT NULL,
  `filename` varchar(255) DEFAULT NULL,
  `rows_total` int(10) unsigned NOT NULL DEFAULT 0,
  `rows_imported` int(10) unsigned NOT NULL DEFAULT 0,
  `rows_skipped` int(10) unsigned NOT NULL DEFAULT 0,
  `reverted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_import_batches_user` (`user_id`,`created_at`),
  CONSTRAINT `fk_import_batches_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `position_id` bigint(20) unsigned NOT NULL,
  `type` enum('buy','sell','deposit','withdrawal','transfer_in','transfer_out','reward','dividend','interest','fee','split') NOT NULL,
  `executed_at` datetime NOT NULL,
  `quantity` decimal(36,18) DEFAULT NULL,
  `unit_price` decimal(28,10) DEFAULT NULL,
  `price_currency` char(3) DEFAULT NULL,
  `amount` decimal(20,4) DEFAULT NULL COMMENT 'hrubá částka v price_currency (bez poplatku)',
  `fee` decimal(20,4) DEFAULT NULL,
  `fee_currency` char(3) DEFAULT NULL,
  `fx_rate_czk` decimal(28,10) DEFAULT NULL COMMENT 'kurz price_currency→CZK ke dni (M2)',
  `split_ratio` decimal(28,10) DEFAULT NULL,
  `transfer_group_id` char(36) DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  `source` varchar(32) NOT NULL DEFAULT 'manual',
  `external_id` varchar(190) DEFAULT NULL,
  `import_batch_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_transactions_external` (`user_id`,`source`,`external_id`),
  KEY `idx_transactions_position_time` (`user_id`,`position_id`,`executed_at`,`id`),
  KEY `idx_transactions_user_time` (`user_id`,`executed_at`),
  KEY `idx_transactions_transfer_group` (`transfer_group_id`),
  KEY `fk_transactions_position` (`position_id`),
  KEY `fk_transactions_import_batch` (`import_batch_id`),
  CONSTRAINT `fk_transactions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_transactions_position` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_transactions_import_batch` FOREIGN KEY (`import_batch_id`) REFERENCES `import_batches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
