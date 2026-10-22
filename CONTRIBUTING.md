# Přispívání do MyPortfolio

Účastí v projektu souhlasíte s [Code of Conduct](CODE_OF_CONDUCT.md).

## Licence příspěvků

Odesláním pull requestu poskytujete svůj příspěvek pod licencí **MIT** a potvrzujete,
že jste jeho autorem (nebo máte právo jej takto poskytnout) a že neobsahuje kód třetích
stran pod licencí neslučitelnou s MIT.

## Jak poslat pull request

1. Založte tematickou větev z `master`; posílejte menší, zaměřené PR.
2. Dodržujte konvence repozitáře ([AGENTS.md](AGENTS.md)):
   - databázové změny výhradně idempotentními migracemi přes `php8.5 api/bin/migrate.php`,
   - peníze a množství přes `BigDecimal` / `DECIMAL`, nikdy float,
   - každý dotaz na uživatelská data scopovaný na `user_id`,
   - texty UI přes i18n v obou jazycích (`cs.json` + `en.json`),
   - `web/dist/` se necommituje (staví ho CI / Docker build),
   - po změně uživatelské funkce aktualizujte kapitolu manuálu.
3. Před odesláním ověřte:

   ```bash
   cmd/test.sh                 # PHPUnit + pnpm build
   cd web && pnpm test:pwa
   ```

4. V popisu PR stručně uveďte co a proč se mění a jak jste to otestovali.

## Hlášení chyb a bezpečnost

- Chyby a náměty hlaste přes issues — uveďte verzi, prostředí a postup reprodukce.
- **Bezpečnostní chyby nikdy neoznamujte veřejně** — postupujte podle [SECURITY.md](SECURITY.md).
