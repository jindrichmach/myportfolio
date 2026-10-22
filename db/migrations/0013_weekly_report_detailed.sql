-- Týdenní report: detailní přehled (i jednotlivé pozice/instrumenty) vs. jen portfolia a účty.
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `weekly_report_detailed` tinyint(1) NOT NULL DEFAULT 1 AFTER `weekly_report_enabled`;
