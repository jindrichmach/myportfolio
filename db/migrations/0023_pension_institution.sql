-- Penzijko a DIP jsou investice (ruční / tržní pozice), ne položka čistého jmění; nový typ instituce penzijní společnost.
ALTER TABLE `net_worth_items` MODIFY COLUMN `kind` enum('real_estate','other','liability') NOT NULL;
ALTER TABLE `institutions` MODIFY COLUMN `type` enum('exchange','broker','wallet','robo','bank','pension') NOT NULL;
