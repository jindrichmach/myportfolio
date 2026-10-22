## Co a proč / What and why


## Jak otestováno / How tested
- [ ] `cd api && php8.5 vendor/bin/phpunit`
- [ ] `cd web && pnpm build && pnpm test:pwa`

## Checklist
- [ ] Dodržuje [AGENTS.md](../AGENTS.md) (BigDecimal pro peníze, scoping na `user_id`, idempotentní migrace)
- [ ] Nové UI texty v `cs.json` i `en.json`
- [ ] Manuál (`manual/`) aktualizovaný u funkce viditelné uživatelem
- [ ] Jen syntetická testovací data (e-maily `@example.invalid`)
