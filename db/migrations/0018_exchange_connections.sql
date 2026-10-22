-- Synchronizace transakcí přes API burz.
-- Přihlašovací údaje (API klíč, secret…) jen šifrovaně (SecretEncryption) jako JSON.
CREATE TABLE IF NOT EXISTS `exchange_connections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `provider` varchar(32) NOT NULL COMMENT 'coinbase | binance | kraken | coinmate',
  `account_id` bigint(20) unsigned NOT NULL,
  `portfolio_id` bigint(20) unsigned NOT NULL COMMENT 'portfolio pro nově založené pozice',
  `credentials_enc` text NOT NULL,
  `key_hint` varchar(32) DEFAULT NULL COMMENT 'začátek API klíče pro rozpoznání',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `sync_from` datetime DEFAULT NULL COMMENT 'od kdy stahovat (navázání na import / zvolené datum); NULL = celá historie',
  `synced_until` datetime DEFAULT NULL COMMENT 'kurzor: pohyby do tohoto okamžiku jsou zapsané',
  `pending_token` char(32) DEFAULT NULL COMMENT 'náhled čekající na rozhodnutí (neznámé instrumenty, převody)',
  `last_sync_at` datetime DEFAULT NULL,
  `last_status` enum('ok','pending','error') DEFAULT NULL,
  `last_message` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_exchange_connections_user` (`user_id`,`enabled`),
  KEY `fk_exchange_connections_account` (`account_id`),
  KEY `fk_exchange_connections_portfolio` (`portfolio_id`),
  CONSTRAINT `fk_exchange_connections_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_exchange_connections_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_exchange_connections_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
