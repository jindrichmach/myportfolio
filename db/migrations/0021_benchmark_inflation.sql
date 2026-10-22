-- Benchmark a inflace.
-- Měsíční index spotřebitelských cen ČR (HICP Eurostat, 2015 = 100) — globální číselník.
CREATE TABLE IF NOT EXISTS `cpi_index` (
  `month` char(7) NOT NULL COMMENT 'YYYY-MM',
  `value` decimal(12,4) NOT NULL,
  `source` varchar(20) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Instrument, se kterým uživatel porovnává výnos (graf historie)
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `benchmark_instrument_id` bigint(20) unsigned DEFAULT NULL AFTER `display_currency`;
ALTER TABLE `users` ADD CONSTRAINT `fk_users_benchmark_instrument` FOREIGN KEY IF NOT EXISTS (`benchmark_instrument_id`) REFERENCES `instruments` (`id`) ON DELETE SET NULL;
