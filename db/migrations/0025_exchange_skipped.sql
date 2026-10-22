-- Pohyby, které uživatel v náhledu synchronizace burzy vynechal (neznámý instrument bez založení, odeslání / příjem
-- bez cíle). Další synchronizace je přeskočí bez ptaní; ostatní nové pohyby z překryvu (zpožděné) čekají na rozhodnutí.
CREATE TABLE IF NOT EXISTS `exchange_skipped` (
  `user_id` bigint(20) unsigned NOT NULL,
  `connection_id` bigint(20) unsigned NOT NULL,
  `external_id` varchar(190) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`connection_id`,`external_id`),
  KEY `idx_exchange_skipped_user` (`user_id`),
  CONSTRAINT `fk_exchange_skipped_connection` FOREIGN KEY (`connection_id`) REFERENCES `exchange_connections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_exchange_skipped_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
