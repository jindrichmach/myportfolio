-- Nastavení SMTP z administrace. Jediný řádek id = 1.
CREATE TABLE IF NOT EXISTS `smtp_settings` (
  `id` tinyint(3) unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `host` varchar(190) NOT NULL,
  `port` smallint(5) unsigned NOT NULL DEFAULT 587,
  `encryption` enum('ssl','tls','none') NOT NULL DEFAULT 'tls',
  `verify_peer` tinyint(1) NOT NULL DEFAULT 1,
  `auth_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `username` varchar(190) DEFAULT NULL,
  `password_enc` text DEFAULT NULL,
  `from_email` varchar(190) NOT NULL,
  `from_name` varchar(120) NOT NULL DEFAULT 'MyPortfolio',
  `reply_to_email` varchar(190) DEFAULT NULL,
  `reply_to_name` varchar(120) DEFAULT NULL,
  `last_test_at` timestamp NULL DEFAULT NULL,
  `last_test_status` enum('ok','error') DEFAULT NULL,
  `last_test_message` varchar(500) DEFAULT NULL,
  `updated_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_smtp_settings_singleton` CHECK (`id` = 1),
  CONSTRAINT `fk_smtp_settings_user` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
