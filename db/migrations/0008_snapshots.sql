-- M4 — denní snapshoty pozic (odvozená data) a fronta pozic k přepočtu.
-- Hodnoty ve třech měnách zobrazení: hodnota kurzem dne, investováno kurzem ke dni nabytí.

CREATE TABLE IF NOT EXISTS `daily_snapshots` (
  `user_id` bigint(20) unsigned NOT NULL,
  `position_id` bigint(20) unsigned NOT NULL,
  `date` date NOT NULL,
  `quantity` decimal(36,18) DEFAULT NULL COMMENT 'tržní pozice; u ruční NULL',
  `value_czk` decimal(20,4) DEFAULT NULL COMMENT 'NULL = chybí cena nebo kurz',
  `value_eur` decimal(20,4) DEFAULT NULL,
  `value_usd` decimal(20,4) DEFAULT NULL,
  `invested_czk` decimal(20,4) DEFAULT NULL,
  `invested_eur` decimal(20,4) DEFAULT NULL,
  `invested_usd` decimal(20,4) DEFAULT NULL,
  `flow_czk` decimal(20,4) NOT NULL DEFAULT 0 COMMENT 'čistý vnější vklad (+) / výběr (−) za den',
  `flow_eur` decimal(20,4) NOT NULL DEFAULT 0,
  `flow_usd` decimal(20,4) NOT NULL DEFAULT 0,
  `is_stale` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'cena starší než limit',
  PRIMARY KEY (`position_id`,`date`),
  KEY `idx_daily_snapshots_user_date` (`user_id`,`date`),
  CONSTRAINT `fk_daily_snapshots_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_daily_snapshots_position` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snapshot_dirty` (
  `position_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `from_date` date NOT NULL COMMENT 'přepočítat snapshoty od tohoto dne',
  PRIMARY KEY (`position_id`),
  KEY `idx_snapshot_dirty_user` (`user_id`),
  CONSTRAINT `fk_snapshot_dirty_position` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
