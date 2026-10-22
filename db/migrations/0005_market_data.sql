-- M2 — kurzy ČNB a ceny instrumentů. Základní měna CZK; nikdy FLOAT.

CREATE TABLE IF NOT EXISTS `fx_rates` (
  `date` date NOT NULL COMMENT 'datum platnosti kurzu (hlavička souboru ČNB)',
  `currency` char(3) NOT NULL,
  `amount` int(10) unsigned NOT NULL DEFAULT 1 COMMENT 'ČNB „množství" — kurz je za tolik jednotek',
  `rate` decimal(28,10) NOT NULL COMMENT 'Kč za `amount` jednotek',
  `fetched_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`date`,`currency`),
  KEY `idx_fx_rates_currency_date` (`currency`,`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `prices` (
  `instrument_id` bigint(20) unsigned NOT NULL,
  `date` date NOT NULL,
  `close` decimal(28,10) NOT NULL,
  `currency` char(3) NOT NULL,
  `source` enum('coingecko','xtb','yahoo','manual') NOT NULL,
  `fetched_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`instrument_id`,`date`),
  KEY `idx_prices_date` (`date`),
  CONSTRAINT `fk_prices_instrument` FOREIGN KEY (`instrument_id`) REFERENCES `instruments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
