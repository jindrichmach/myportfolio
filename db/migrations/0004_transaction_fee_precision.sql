-- Poplatek může být v kryptu (např. 0.00005 BTC za převod) — přesnost jako množství.
ALTER TABLE `transactions` MODIFY COLUMN `fee` decimal(36,18) DEFAULT NULL;
