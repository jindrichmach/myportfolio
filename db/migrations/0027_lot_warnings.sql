-- Upozornění FIFO enginu (odvozená data jako tax_lots): LotRebuilder je přepisuje při každém přepočtu instrumentu.
-- Daňový report podle nich označí prodeje s neúplnou nabývací cenou. Stávající data naplní api/bin/rebuild-lots.php.
CREATE TABLE IF NOT EXISTS `lot_warnings` (
  `user_id` bigint(20) unsigned NOT NULL,
  `instrument_id` bigint(20) unsigned NOT NULL,
  `transaction_id` bigint(20) unsigned NOT NULL,
  `code` varchar(40) NOT NULL COMMENT 'reward_without_value, transfer_without_pair, insufficient_quantity, fee_not_converted, fee_exceeds_quantity',
  PRIMARY KEY (`user_id`,`instrument_id`,`transaction_id`,`code`),
  KEY `idx_lot_warnings_transaction` (`transaction_id`),
  CONSTRAINT `fk_lot_warnings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lot_warnings_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
