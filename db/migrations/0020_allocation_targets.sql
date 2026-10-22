-- Cílová alokace.
-- Řádek bez instrument_id = cílový podíl třídy na celku (součet 100 %), s instrument_id = podíl instrumentu uvnitř třídy
-- (součet 100 % v rámci třídy). portfolio_id NULL = celý majetek.
CREATE TABLE IF NOT EXISTS `allocation_targets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `portfolio_id` bigint(20) unsigned DEFAULT NULL,
  `category` varchar(20) NOT NULL,
  `instrument_id` bigint(20) unsigned DEFAULT NULL,
  `target_pct` decimal(6,2) NOT NULL,
  `band_pct` decimal(5,2) DEFAULT NULL COMMENT 'tolerance v procentních bodech (jen třída)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_allocation_targets_user` (`user_id`,`portfolio_id`),
  KEY `fk_allocation_targets_portfolio` (`portfolio_id`),
  KEY `fk_allocation_targets_instrument` (`instrument_id`),
  CONSTRAINT `fk_allocation_targets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_allocation_targets_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_allocation_targets_instrument` FOREIGN KEY (`instrument_id`) REFERENCES `instruments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `alerts_allocation` tinyint(1) NOT NULL DEFAULT 1 AFTER `alerts_data_issues`;
