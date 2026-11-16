-- Poslední přijatý časový krok TOTP (RFC 6238 §5.2): stejný kód nejde v okně ±30 s použít podruhé.
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `totp_last_step` bigint(20) unsigned DEFAULT NULL AFTER `totp_enabled`;
