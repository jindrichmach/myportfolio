-- M3 — úpravy schématu po návrhu lotů.
-- Měna poplatku může být i symbol kryptoměny (USDT, MATIC, …), ne jen ISO kód.
ALTER TABLE `transactions` MODIFY `fee_currency` varchar(10) DEFAULT NULL;

-- Ruční ocenění patří k pozici — smazání pozice (bez transakcí) je smaže taky.
ALTER TABLE `manual_valuations` DROP FOREIGN KEY `fk_manual_valuations_position`;
ALTER TABLE `manual_valuations` ADD CONSTRAINT `fk_manual_valuations_position`
  FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE CASCADE;
