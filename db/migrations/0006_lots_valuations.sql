-- M3 — FIFO loty (odvozená data, přepočítávají se z transakcí) a ruční ocenění.
-- Částky v původní měně; přepočty do CZK / měny zobrazení se dělají při čtení.

CREATE TABLE IF NOT EXISTS `tax_lots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `position_id` bigint(20) unsigned NOT NULL,
  `instrument_id` bigint(20) unsigned NOT NULL,
  `source_transaction_id` bigint(20) unsigned NOT NULL COMMENT 'nákup / odměna, kterým lot vznikl (u převodu původní nabytí)',
  `origin_lot_id` bigint(20) unsigned DEFAULT NULL COMMENT 'lot, ze kterého vznikl převodem',
  `acquired_at` datetime NOT NULL COMMENT 'datum nabytí — převod ho zachovává',
  `quantity` decimal(36,18) NOT NULL,
  `remaining_quantity` decimal(36,18) NOT NULL,
  `cost_amount` decimal(28,10) NOT NULL COMMENT 'nákupní cena celého lotu v cost_currency',
  `cost_currency` char(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tax_lots_position` (`user_id`,`position_id`,`acquired_at`),
  KEY `idx_tax_lots_instrument` (`user_id`,`instrument_id`),
  CONSTRAINT `fk_tax_lots_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tax_lots_position` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lot_disposals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `lot_id` bigint(20) unsigned NOT NULL,
  `transaction_id` bigint(20) unsigned NOT NULL,
  `kind` enum('sell','fee') NOT NULL,
  `disposed_at` datetime NOT NULL,
  `quantity` decimal(36,18) NOT NULL,
  `cost_amount` decimal(28,10) NOT NULL,
  `cost_currency` char(3) NOT NULL,
  `proceeds_amount` decimal(28,10) NOT NULL,
  `proceeds_currency` char(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lot_disposals_user_time` (`user_id`,`disposed_at`),
  KEY `idx_lot_disposals_transaction` (`transaction_id`),
  KEY `fk_lot_disposals_lot` (`lot_id`),
  CONSTRAINT `fk_lot_disposals_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lot_disposals_lot` FOREIGN KEY (`lot_id`) REFERENCES `tax_lots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `manual_valuations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `position_id` bigint(20) unsigned NOT NULL,
  `date` date NOT NULL,
  `value` decimal(20,4) NOT NULL,
  `currency` char(3) NOT NULL,
  `note` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_manual_valuations_position_date` (`position_id`,`date`),
  KEY `idx_manual_valuations_user` (`user_id`,`position_id`,`date`),
  CONSTRAINT `fk_manual_valuations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_manual_valuations_position` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
