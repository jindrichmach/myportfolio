-- Authserv-id z hlavičky Authentication-Results posledního avíza, dokud uživatel důvěryhodný authserv-id nepotvrdí.
-- Bez potvrzeného auth_serv_id se avíza nezapisují (hlavičku A-R může vložit i odesílatel).
ALTER TABLE `imap_accounts` ADD COLUMN IF NOT EXISTS `detected_auth_serv_id` varchar(190) DEFAULT NULL AFTER `auth_serv_id`;
