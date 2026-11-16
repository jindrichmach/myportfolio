-- sessions.id nově drží SHA-256 tokenu z cookie (SessionManager::storageId), ne token samotný.
-- Stávající záznamy obsahují surové tokeny, které by už nikdy neodpovídaly — zneplatní se (jednorázové odhlášení všech).
-- Opakovaný běh smaže jen session vytvořené mezitím: migrace běží při startu před webem, takže žádné nejsou.
DELETE FROM `sessions`;
