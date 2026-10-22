-- Měsíční a roční souhrn e-mailem; příjemci společní s týdenním reportem.
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `monthly_report_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `weekly_report_detailed`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `yearly_report_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `monthly_report_enabled`;
