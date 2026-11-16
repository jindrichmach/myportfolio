-- Stát, který daň z dividendy srazil (§ 38f), když se liší od prefixu ISIN — např. ADR tchajwanské firmy má ISIN US….
-- NULL = podle ISIN.
ALTER TABLE `instruments` ADD COLUMN IF NOT EXISTS `tax_country` char(2) DEFAULT NULL AFTER `isin`;
