-- Týdenní e-mailový report.
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `weekly_report_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `display_currency`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `weekly_report_recipients` varchar(1000) DEFAULT NULL COMMENT 'čárkou oddělené e-maily; NULL = přihlašovací e-mail' AFTER `weekly_report_enabled`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `weekly_report_sent_at` timestamp NULL DEFAULT NULL AFTER `weekly_report_recipients`;
