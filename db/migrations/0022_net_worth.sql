-- Čisté jmění: majetek mimo investice a závazky.
-- Investiční metriky (TWR, alokace, daně, snapshoty) tyto položky nezahrnují.
CREATE TABLE IF NOT EXISTS `net_worth_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `kind` enum('real_estate','pension','other','liability') NOT NULL,
  `name` varchar(120) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'CZK',
  `note` varchar(500) DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_net_worth_items_user` (`user_id`),
  CONSTRAINT `fk_net_worth_items_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ocenění majetku k datu; u závazku zůstatek k datu (počátek úvěru nebo korekce) a volitelně nový úrok / splátka
-- (NULL = beze změny). Mezi záznamy se majetek drží, úvěr se umořuje anuitou měsíčně od data záznamu.
CREATE TABLE IF NOT EXISTS `net_worth_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `date` date NOT NULL,
  `value` decimal(20,4) NOT NULL,
  `rate_pct` decimal(7,4) DEFAULT NULL COMMENT 'úrok p. a. v %',
  `payment` decimal(20,4) DEFAULT NULL COMMENT 'měsíční splátka',
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_net_worth_entries_day` (`item_id`,`date`),
  KEY `idx_net_worth_entries_user` (`user_id`),
  CONSTRAINT `fk_net_worth_entries_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_net_worth_entries_item` FOREIGN KEY (`item_id`) REFERENCES `net_worth_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
