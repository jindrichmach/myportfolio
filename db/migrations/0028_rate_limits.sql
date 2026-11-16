-- Počítadla rate limitu bez Redisu (RateCounter): pevné okno na klíč. MEMORY jako login_attempts —
-- restart DB je vynuluje, expirované řádky maže cron-cleanup.
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `bucket_key` varchar(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `hits` int(10) unsigned NOT NULL,
  `expires_at` timestamp NOT NULL,
  PRIMARY KEY (`bucket_key`),
  KEY `idx_rate_limits_expires` (`expires_at`)
) ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
