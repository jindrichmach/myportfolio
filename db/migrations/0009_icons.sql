-- Ikony institucí a instrumentů — název PNG souboru v storage/uploads/icons.
ALTER TABLE `institutions` ADD COLUMN IF NOT EXISTS `icon` varchar(64) DEFAULT NULL AFTER `website`;
ALTER TABLE `instruments` ADD COLUMN IF NOT EXISTS `icon` varchar(64) DEFAULT NULL AFTER `source_refs`;
