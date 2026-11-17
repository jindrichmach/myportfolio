---
name: plan-feature
description: Rozpracuje zadání větší funkce na spec.md a tasks.md v private/features/<slug>/ a zastaví se pro schválení člověkem. Použij, když uživatel zadává větší novou funkci nebo změnu (více vrstev, víc než pár souborů) nebo řekne „naplánuj“. Drobné opravy se neplánují, dělají se rovnou.
---

# Plánování feature

Jsi planner. Nepíšeš aplikační kód – jen spec a tasky.

## Postup

1. **Pochop zadání.** Přečti `AGENTS.md` (hlavně Tvrdá pravidla) a relevantní existující kód (routy, actions, services, repository, migrace, Vue stránky, manuál). Nejasnosti, které mění rozsah, datový model nebo výpočty peněz/daní, **zeptej se** – nedomýšlej je potichu. Drobnosti zapiš jako předpoklady.
2. **Zvol slug** (kebab-case, anglicky, např. `dividend-calendar`) a vytvoř `private/features/<slug>/` (adresář `private/` je gitignorovaný – repozitář je veřejný).
3. **Napiš `spec.md`** podle šablony níže.
4. **Rozpadni na tasky** do `tasks.md`:
   - Každý task je samostatně ověřitelný a jde dokončit jednou implementační session (orientačně do ~5 souborů + testy).
   - Pořadí podle závislostí: migrace → repository/service → action + route (+ `ShareAccess`, `ApiCatalog`) → frontend (API klient, stránka, komponenty, i18n) → manuál cs + en.
   - Každý task má konkrétní akceptační kritéria ověřitelná testem nebo čtením kódu. Žádné „udělat pěkně“.
   - `complexity`: `mechanical` (migrace, CRUD dle vzoru, překlady, manuál, šablona bez logiky) nebo `standard` (výpočty, FIFO/daně, autorizace a izolace uživatelů, importy, složitější UI).
   - Testy patří do stejného tasku jako kód, který testují. Manuál cs + en patří do tasku, který funkci zviditelní uživateli (nebo do samostatného tasku před koncem feature).
   - Vyžaduje-li funkce krok po aktualizaci (přepočet apod.), zahrň zápis do `CHANGELOG.md` → `## [Unreleased]` → `### Po aktualizaci`.
5. **Zastav se.** Shrň uživateli plán (počet tasků, rizika, předpoklady, otevřené otázky) a požádej o schválení. Po schválení doplň do `spec.md` řádek `Schváleno: <YYYY-MM-DD>` a vytvoř větev `feature/<slug>` z `master`.

## Šablona spec.md

```markdown
# <Název feature>

Stav: návrh
Zdroj: <text zadání / issue>

## Cíl
<1–3 věty: co uživatel získá>

## Rozsah
- V rozsahu: …
- Mimo rozsah: …

## Datový model
<tabulky, sloupce, DECIMAL přesnosti, vztahy; co je zdroj pravdy a co odvozená data>

## Přístup a izolace
<scope na user_id / owner(), sdílení (ShareAccess, AccessContext), admin vs user, Bearer token (ApiCatalog)>

## Akceptační kritéria (feature)
- [ ] …

## Předpoklady a otevřené otázky
- …
```

## Šablona tasks.md

```markdown
# Tasks: <slug>

## T1 — <název>
status: todo
complexity: mechanical | standard
round: 0
Soubory: <očekávané soubory>
Kritéria:
- [ ] …
- [ ] Test: …
Poznámky:
```
