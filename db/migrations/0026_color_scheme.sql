-- Barevné schéma vzhledu zvolené uživatelem; NULL = výchozí schéma instance (app_meta `color_scheme`).
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `color_scheme` varchar(20) DEFAULT NULL AFTER `display_currency`;
