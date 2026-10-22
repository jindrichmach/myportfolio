-- Bankovní e-mailová avíza přes IMAP.

CREATE TABLE IF NOT EXISTS `imap_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `host` varchar(190) NOT NULL,
  `port` smallint(5) unsigned NOT NULL DEFAULT 993,
  `encryption` enum('ssl','tls','none') NOT NULL DEFAULT 'ssl',
  `validate_cert` tinyint(1) NOT NULL DEFAULT 1,
  `username` varchar(190) NOT NULL,
  `password_enc` text NOT NULL,
  `folder` varchar(190) NOT NULL DEFAULT 'INBOX',
  `max_messages_per_run` smallint(5) unsigned NOT NULL DEFAULT 50,
  `require_email_auth` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'vyžadovat DMARC/DKIM pass z Authentication-Results',
  `auth_serv_id` varchar(190) DEFAULT NULL COMMENT 'authserv-id přijímacího serveru, kterému věříme',
  `last_scan_at` timestamp NULL DEFAULT NULL,
  `last_scan_status` enum('ok','error') DEFAULT NULL,
  `last_scan_message` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_imap_accounts_user` (`user_id`, `enabled`),
  CONSTRAINT `fk_imap_accounts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bank_notice_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `imap_account_id` bigint(20) unsigned NOT NULL,
  `message_id` varchar(255) DEFAULT NULL,
  `fallback_hash` char(64) NOT NULL,
  `message_date` datetime DEFAULT NULL,
  `sender` varchar(255) DEFAULT NULL,
  `subject` varchar(500) DEFAULT NULL,
  `parser` varchar(40) DEFAULT NULL,
  `status` enum('imported','skipped_old','unmapped','parse_failed','security_rejected','import_failed') NOT NULL,
  `position_id` bigint(20) unsigned DEFAULT NULL,
  `transaction_id` bigint(20) unsigned DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (`payload` IS NULL OR json_valid(`payload`)),
  `error` varchar(1000) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bank_notice_message_id` (`imap_account_id`, `message_id`),
  UNIQUE KEY `uq_bank_notice_hash` (`imap_account_id`, `fallback_hash`),
  KEY `idx_bank_notice_user` (`user_id`, `created_at`),
  KEY `fk_bank_notice_position` (`position_id`),
  KEY `fk_bank_notice_transaction` (`transaction_id`),
  CONSTRAINT `fk_bank_notice_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bank_notice_imap` FOREIGN KEY (`imap_account_id`) REFERENCES `imap_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bank_notice_position` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bank_notice_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `positions` ADD COLUMN IF NOT EXISTS `bank_account` varchar(40) DEFAULT NULL COMMENT 'číslo účtu/kód banky pro bankovní avíza (jen ruční pozice)' AFTER `interest_rate_pa`;
ALTER TABLE `positions` ADD COLUMN IF NOT EXISTS `bank_notices_from` date DEFAULT NULL COMMENT 'avíza s datem zaúčtování před tímto dnem se přeskočí' AFTER `bank_account`;
