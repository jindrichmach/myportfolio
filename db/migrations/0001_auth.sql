-- Uživatelé, session, MFA (passkey/TOTP/e-mail OTP), API tokeny.
-- Převzato z MyInvoice (auth vrstva), bez multi-supplier vazeb.

CREATE TABLE IF NOT EXISTS `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL,
  `password_hash` char(60) NOT NULL,
  `totp_secret` varchar(255) DEFAULT NULL,
  `totp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `name` varchar(120) NOT NULL,
  `role` enum('admin','user') NOT NULL DEFAULT 'user',
  `locale` enum('cs','en') NOT NULL DEFAULT 'cs',
  `display_currency` char(3) NOT NULL DEFAULT 'CZK',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `last_login_ip` varbinary(16) DEFAULT NULL,
  `last_login_ua` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `webauthn_user_handle` varbinary(64) DEFAULT NULL,
  `session_lock_after_minutes` smallint(5) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_webauthn_user_handle` (`webauthn_user_handle`),
  CONSTRAINT `chk_users_session_lock_after_minutes` CHECK (`session_lock_after_minutes` is null or `session_lock_after_minutes` between 1 and 1440)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sessions` (
  `id` char(64) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `csrf_token` char(64) NOT NULL,
  `ip` varbinary(16) NOT NULL,
  `user_agent` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_seen` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `expires_at` timestamp NOT NULL,
  `auth_method` varchar(32) NOT NULL DEFAULT 'legacy',
  `assurance_level` varchar(16) NOT NULL DEFAULT 'legacy',
  `mfa_verified_at` datetime(6) DEFAULT NULL,
  `auth_credential_id` bigint(20) unsigned DEFAULT NULL,
  `last_user_activity_at` datetime(6) NOT NULL,
  `locked_at` datetime(6) DEFAULT NULL,
  `lock_reason` varchar(16) DEFAULT NULL,
  `last_unlock_at` datetime(6) DEFAULT NULL,
  `last_unlock_method` varchar(16) DEFAULT NULL,
  `session_family_id` varbinary(32) NOT NULL,
  `generation` int(10) unsigned NOT NULL DEFAULT 1,
  `replaced_at` datetime(6) DEFAULT NULL,
  `revoked_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sessions_family_generation` (`session_family_id`,`generation`),
  KEY `idx_sess_user` (`user_id`,`expires_at`),
  KEY `idx_sessions_family_revocation` (`session_family_id`,`revoked_at`),
  KEY `idx_sessions_user_revocation` (`user_id`,`revoked_at`,`expires_at`),
  CONSTRAINT `fk_sess_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `webauthn_credentials` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `credential_id` varbinary(1024) NOT NULL,
  `credential_id_hash` binary(32) NOT NULL,
  `public_key` blob NOT NULL,
  `sign_count` bigint(20) unsigned NOT NULL DEFAULT 0,
  `transports_json` longtext DEFAULT NULL,
  `aaguid` binary(16) DEFAULT NULL,
  `label` varchar(100) NOT NULL,
  `backup_eligible` tinyint(1) NOT NULL DEFAULT 0,
  `backup_state` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime(6) NOT NULL,
  `last_used_at` datetime(6) DEFAULT NULL,
  `revoked_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_webauthn_credentials_id_hash` (`credential_id_hash`),
  KEY `idx_webauthn_credentials_user_active_created` (`user_id`,`revoked_at`,`created_at`),
  CONSTRAINT `fk_webauthn_credentials_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `webauthn_ceremonies` (
  `flow_token_hash` binary(32) NOT NULL,
  `challenge` varbinary(64) NOT NULL,
  `purpose` varchar(32) NOT NULL,
  `operation` varchar(190) DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `session_id_hash` binary(32) DEFAULT NULL,
  `options_json` longtext NOT NULL,
  `ip` varbinary(16) NOT NULL,
  `user_agent` varchar(255) NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `used_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  PRIMARY KEY (`flow_token_hash`),
  KEY `idx_webauthn_ceremonies_expiry` (`expires_at`,`used_at`),
  KEY `idx_webauthn_ceremonies_user_purpose` (`user_id`,`purpose`,`created_at`),
  CONSTRAINT `fk_webauthn_ceremonies_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bucket_key` varchar(80) NOT NULL,
  `email` varchar(190) NOT NULL,
  `ip_packed` varbinary(16) NOT NULL,
  `success` tinyint(1) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_la_bucket` (`bucket_key`,`created_at`),
  KEY `idx_la_email` (`email`,`created_at`)
) ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_otps` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `code_hash` char(64) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `attempts` smallint(5) unsigned NOT NULL DEFAULT 0,
  `ip` varbinary(16) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_otp_user` (`user_id`,`used_at`,`expires_at`),
  CONSTRAINT `fk_otp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mfa_recovery_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `code_hash` char(64) NOT NULL COMMENT 'SHA-256 normalizovaného kódu (velká písmena, bez oddělovačů)',
  `used_at` datetime DEFAULT NULL COMMENT 'NULL = nepoužitý; jednorázovost drží atomický UPDATE',
  `used_ip` varbinary(16) DEFAULT NULL COMMENT 'inet_pton IP, ze které byl kód uplatněn',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mrc_user_code` (`user_id`,`code_hash`),
  KEY `idx_mrc_user_unused` (`user_id`,`used_at`),
  CONSTRAINT `fk_mrc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mfa_step_up_proofs` (
  `token_hash` binary(32) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `session_id_hash` binary(32) NOT NULL,
  `operation` varchar(190) NOT NULL,
  `auth_method` varchar(32) NOT NULL,
  `auth_credential_id` bigint(20) unsigned DEFAULT NULL,
  `expires_at` datetime(6) NOT NULL,
  `used_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  PRIMARY KEY (`token_hash`),
  KEY `idx_mfa_step_up_proofs_expiry` (`expires_at`,`used_at`),
  KEY `idx_mfa_step_up_proofs_session` (`session_id_hash`,`operation`,`used_at`),
  KEY `fk_mfa_step_up_proofs_user` (`user_id`),
  KEY `fk_mfa_step_up_proofs_credential` (`auth_credential_id`),
  CONSTRAINT `fk_mfa_step_up_proofs_credential` FOREIGN KEY (`auth_credential_id`) REFERENCES `webauthn_credentials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mfa_step_up_proofs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `trusted_devices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `user_agent` varchar(255) NOT NULL DEFAULT '',
  `ip` varbinary(16) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_td_token` (`token_hash`),
  KEY `idx_td_user` (`user_id`,`expires_at`),
  CONSTRAINT `fk_td_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip` varbinary(16) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_reset_token` (`token_hash`),
  KEY `fk_reset_user` (`user_id`),
  CONSTRAINT `fk_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `prefix` varchar(16) NOT NULL,
  `scope` enum('read','read_write') NOT NULL DEFAULT 'read_write',
  `last_used_at` datetime DEFAULT NULL,
  `last_used_ip` varbinary(16) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_apitok_hash` (`token_hash`),
  KEY `idx_apitok_user` (`user_id`),
  CONSTRAINT `fk_apitok_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
