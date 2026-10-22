-- Hotovost na účtech: ruční pozice se zadávaným zůstatkem.
-- Nastavení zůstatku zapíše rozdíl jako vklad / výběr (investováno = hodnota, výnos 0).
ALTER TABLE `positions` ADD COLUMN IF NOT EXISTS `is_cash` tinyint(1) NOT NULL DEFAULT 0 AFTER `valuation_mode`;
