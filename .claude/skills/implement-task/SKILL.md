---
name: implement-task
description: Implementuje přesně jeden task z private/features/<slug>/tasks.md (nový nebo se stavem changes_requested) včetně testů a kontrol. Použij, když máš provést nebo opravit konkrétní task ze schváleného plánu.
---

# Implementace jednoho tasku

Jsi implementer. Děláš **jen jeden task** a nic navíc.

## Než začneš

1. Ověř, že `private/features/<slug>/spec.md` obsahuje `Schváleno:`. Pokud ne, skonči a řekni to.
2. Vyber task: ten, který ti byl zadán, jinak první se stavem `changes_requested`, jinak první `todo`.
3. Pokud je stav `changes_requested`, přečti poslední review `reviews/T<n>-r<round>.md` – opravuješ **jen** body z něj.
4. Přečti `AGENTS.md` a kód, na který task navazuje. Drž se stylu existujícího kódu. Při práci na UI načti skill `project-ui`.
5. Nastav `status: in_progress`.

## Práce

- Nejdřív test(y) podle kritérií (PHPUnit v `api/tests/`, frontend Vitest/node:test), pak kód, dokud neprojdou. Jen syntetická data.
- Měň jen soubory, které task potřebuje. Žádné refaktory okolí, žádná „vylepšení“ mimo zadání.
- Nový balíček, změna spec, chybějící přístupové údaje nebo rozpor v zadání → nastav `status: blocked`, pod task napiš důvod a skonči.
- Migrace jen přes `php8.5 api/bin/migrate.php` (a do test DB s `MYPORTFOLIO_DB_NAME=myportfolio_test`).
- Necommituj.

## Dokončení

1. Kontroly – musí projít celé:
   - `cd api && php8.5 vendor/bin/phpstan analyse`
   - `cmd/test.sh` (PHPUnit + `pnpm build` + `test:pwa` + `test:unit`; při změně jen v PHP stačí `SKIP_WEB=1`, při změně ve `web/` nebo `manual/` vždy celé)

   Pokud po 3 pokusech neprojdou z důvodu mimo tvůj task, nastav `blocked` s výpisem chyby.
2. Odškrtni splněná kritéria v `tasks.md` a nastav `status: review`.
3. Vrať krátký report:
   - task a co je hotové,
   - seznam změněných souborů,
   - výsledek kontrol (počet testů, phpstan OK, build OK),
   - co jsi případně nesplnil nebo udělal jinak než plán a proč.
