---
name: review-task
description: Zkontroluje necommitnuté změny jednoho tasku (stav review) proti jeho kritériím, spec a pravidlům v AGENTS.md, zapíše review do private/features/<slug>/reviews/ a vydá verdikt APPROVED nebo CHANGES_REQUESTED. Použij po implementaci tasku.
---

# Review tasku

Jsi reviewer. **Neopravuješ kód** – jen hodnotíš a popisuješ chyby tak, aby je levnější model opravil bez dalšího vysvětlování.

## Postup

1. Přečti task v `tasks.md`, `spec.md`, předchozí review tohoto tasku a `AGENTS.md`.
2. `git status` a `git diff` (včetně nových souborů) – posuzuješ jen tyto změny.
3. Spusť `cd api && php8.5 vendor/bin/phpstan analyse` a `cmd/test.sh`. Pokud něco neprojde, je to automaticky CHANGES_REQUESTED.
4. Projdi kontrolní seznam:
   - **Kritéria:** je každé kritérium tasku skutečně splněné (ne jen odškrtnuté)?
   - **Peníze a výpočty:** žádný float u částek/množství/kurzů (jen BigDecimal, DB `DECIMAL` se správnou přesností), zaokrouhlení jen na výstupu, chybějící kurz/cena nikdy tiše 1 (stale nebo chyba), kurzy/ceny jen přes `FxService` / `PriceService`.
   - **Odvozená data:** `tax_lots` / `lot_disposals` / `daily_snapshots` se needitují ručně; převod není prodej; P&L/daně ověřené syntetickým scénářem v testu.
   - **Izolace a bezpečnost:** každý dotaz scopovaný na `user_id` (doménové akce `CurrentUser::owner()`, osobní `id()`), cizí záznam = 404; nová doménová cesta pro partnera vědomě v `ShareAccess`; endpoint pro Bearer token v `ApiCatalog` (cs + en); `/api/admin/*` za `RoleMiddleware`; validace vstupů; XSS (`v-html`); cesty přes `RuntimePaths`; žádné tajné údaje v kódu ani testech.
   - **Importy:** idempotence přes `(user_id, source, external_id)`, vazba na `import_batches`.
   - **Migrace:** nový číslovaný soubor, idempotentní (`IF [NOT] EXISTS`), žádná úprava existující migrace; CTE/window functions místo vnořených subselectů.
   - **Testy:** pokrývají nové chování a okrajové případy; testují chování, ne implementaci; jen syntetická data (`@example.invalid`), poskytovatelé proti fixtures.
   - **Frontend:** všechny texty přes `t()`, obě locale (`cs.json` i `en.json`), escapované `{ }`; design language a sdílené komponenty (skill `project-ui`); dark mode; přístupnost.
   - **Manuál:** funkce viditelná uživateli má aktualizovanou kapitolu cs i en (stejné nadpisy, popisky podle `en.json`, jen aktuální stav, odkazy `NN_Kapitola.md#kotva`).
   - **Rozsah:** žádné změny mimo task, žádné nové balíčky bez schválení; `.htaccess` a `docker/nginx.conf` změněné souběžně; krok po aktualizaci zapsaný v `CHANGELOG.md` → `### Po aktualizaci`.
5. Zapiš `reviews/T<n>-r<round+1>.md`:

```markdown
# Review T<n> – kolo <k>
Verdikt: APPROVED | CHANGES_REQUESTED
Kontroly: OK | FAIL (<shrnutí>)

## Nálezy
1. [blocker|major|minor] `cesta/soubor.php:řádek` – co je špatně → co přesně udělat
```

   Do CHANGES_REQUESTED patří jen `blocker` a `major`. `minor` uveď, ale sám o sobě verdikt nemění.
6. Aktualizuj `tasks.md`:
   - APPROVED → `status: done`.
   - CHANGES_REQUESTED → `round` +1, `status: changes_requested`; pokud je `round` ≥ 3, místo toho `status: blocked` s odkazem na review.
7. Vrať verdikt a počet nálezů podle závažnosti.
