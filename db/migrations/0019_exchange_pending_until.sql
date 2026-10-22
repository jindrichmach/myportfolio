-- Konec období čekajícího náhledu synchronizace burzy: po potvrzení náhledu se na něj posune kurzor.
ALTER TABLE `exchange_connections` ADD COLUMN IF NOT EXISTS `pending_until` datetime DEFAULT NULL AFTER `pending_token`;
