---
name: run-tasks
description: Orchestrátor – projde schválený private/features/<slug>/tasks.md a pro každý task opakuje implementaci (levný model), review (silný model) a opravy, commituje hotové tasky a na konci předloží feature ke schválení. Použij, když uživatel řekne „spusť“, „pokračuj“ nebo „implementuj feature <slug>“.
---

# Orchestrace feature

Jsi orchestrátor. Sám neimplementuješ a nereviewuješ – delegaci a stav řídíš ty.

## Předpoklady

- `spec.md` obsahuje `Schváleno:`. Pokud ne, zastav se a vyžádej schválení (viz `plan-feature`).
- Jsi na větvi `feature/<slug>` a pracovní strom je čistý (`git status`).

## Smyčka

Pro každý task v pořadí, který není `done`:

1. **Blokace:** task `blocked` → zastav celou smyčku a předlož uživateli důvod.
2. **Implementace:** deleguj na implementera (skill `implement-task`) s instrukcí „slug `<slug>`, task T<n>“.
   - Claude Code: subagent `implementer`; pro `complexity: mechanical` mu předej `model: haiku`, jinak výchozí (sonnet).
   - Jiné nástroje: řekni uživateli, ať spustí novou session s levnějším modelem a tímto pokynem, a počkej.
3. **Review:** deleguj na reviewera (skill `review-task`) – vždy čistý kontext.
   - Claude Code: subagent `reviewer`.
4. **Podle verdiktu:**
   - APPROVED → commit podle konvencí repozitáře: česky, conventional commits, číslo tasku na konci, např. `git add -A && git commit -m "feat(dividendy): kalendář výplat (T2)"`. Pokračuj dalším taskem.
   - CHANGES_REQUESTED → zpět na krok 2 se stejným taskem.
   - task přešel do `blocked` → krok 1.
5. Po každém tasku napiš uživateli jeden řádek: `T<n> <název>: done (kola: k)`.

Nikdy neměň verdikt reviewera a neodškrtávej kritéria sám. Pokud implementer vrátí report, že nesplnil část tasku, pošli to reviewerovi beze změny.

## Konec feature

Když jsou všechny tasky `done`:

1. Spusť `cd api && php8.5 vendor/bin/phpstan analyse` a `cmd/test.sh` na celé větvi.
2. Předlož uživateli: co feature obsahuje (proti akceptačním kritériím ve `spec.md`), `git log master..HEAD --oneline`, výsledek kontrol, souhrn `minor` nálezů z review, které zůstaly neopravené.
3. **Čekej na schválení.** Merge do `master` (`git merge --no-ff feature/<slug>`) jen po výslovném souhlasu; push a kontrola CI také jen na pokyn. Do `spec.md` nastav `Stav: hotovo`.
