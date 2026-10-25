# 9. Administrace

Sekce **Systém** v menu je viditelná jen pro roli **admin**.

## 9.1 Uživatelé

**Systém → Uživatelé.** Založení, úprava a deaktivace účtů. Seznam jde prohledat podle e-mailu nebo jména
a filtrovat podle role a stavu (aktivní, neaktivní) — viz [1.4 Seznamy s filtry](01_Uvod.md#seznamy-s-filtry).

| Role | Co smí |
|---|---|
| admin | vše: správa uživatelů, úpravy katalogu, ruční ceny, plánované úlohy, log |
| user | vlastní účty, portfolia, pozice a transakce; zakládání institucí a instrumentů |

Data uživatelů jsou oddělená — admin cizí pozice ani transakce nevidí.
Posledního aktivního admina nelze deaktivovat ani mu odebrat roli.

## 9.2 Ceny a kurzy

**Systém → Ceny a kurzy.** Přehled pro kontrolu, že ceny chodí:

| Sloupec | Význam |
|---|---|
| Zdroje | Pořadí zdrojů pro instrument (např. XTB → Yahoo Finance) |
| Poslední cena | Cena, datum, zdroj; badge „zastaralé" |
| Historie | Od kdy a kolik dní cen je uloženo |

Instrumenty jde prohledat podle symbolu nebo názvu a filtrovat podle **Stavu ceny** (aktuální, zastaralá,
chybí) — viz [1.4 Seznamy s filtry](01_Uvod.md#seznamy-s-filtry).

Pod tabulkou je poslední kurzový lístek ČNB a poslední měsíc indexu cen pro inflaci (úloha `cron-cpi`). Tlačítka **Stáhnout kurzy ČNB**
a **Aktualizovat ceny** spustí úlohy hned na pozadí.

## 9.3 Nastavení

Stránka **Systém → Nastavení** drží nastavení celé instance na záložkách. Každá záložka má vlastní
tlačítko **Uložit** a ukládá se samostatně. Vybraná záložka je v adrese stránky (`?tab=`), takže na ni
jde odkázat a po obnovení stránky zůstane otevřená.

### E-mail (SMTP)

Záložka **E-mail (SMTP)** — server, přes který aplikace posílá e-maily (obnova hesla,
ověřovací kódy, týdenní reporty).

| Pole | Význam |
|---|---|
| SMTP server, šifrování, port | STARTTLS obvykle port 587, SSL/TLS 465, bez šifrování 25 (jen lokální server) |
| Přihlašovat se jménem a heslem | Uživatel a heslo k SMTP; heslo se ukládá šifrovaně a nezobrazuje se. Prázdné heslo při úpravě = beze změny |
| E-mail a jméno odesílatele | Adresa „Od" — musí ji server povolit odesílat |
| Odpovědi na e-mail | Volitelná adresa pro odpovědi (Reply-To) |
| Ověřovat certifikát serveru | Vypni jen pro server s vlastním (self-signed) certifikátem |
| Používat toto nastavení | Zapnuté nastavení má přednost před `cfg.php`; vypnuté = platí `cfg.php` |

Při STARTTLS aplikace šifrování vyžaduje — pokud ho server nenabídne, e-mail se neodešle
(heslo by jinak šlo sítí nešifrovaně).

**Zkušební e-mail** odešle e-mail s hodnotami z formuláře (i neuloženými) na zadanou adresu
a ukáže odpověď serveru nebo chybu. Před uložením si tak ověříš, že nastavení funguje.

> 💡 Gmail, Seznam a další služby s dvoufázovým ověřením vyžadují místo hesla k účtu
> **heslo pro aplikace**.

### Vzhled

Záložka **Vzhled** — výchozí barevné schéma instance. Platí pro přihlašovací stránku, pro e-maily
(barvy i logo) a pro všechny uživatele, kteří si v profilu nevybrali vlastní schéma
(viz [3.4 Profil](03_Prihlaseni_a_zabezpeceni.md#34-profil)).

Na výběr je deset schémat seřazených podle barvy: Malina, Terakota, Bronz, Oliva, **Les** (výchozí, zelené),
Oceán, Indigo, Ametyst, Orchidej a Grafit. Každé má světlý i tmavý režim a vlastní barvu loga a favikony.
Barvy zisku, ztráty a varování jsou ve všech schématech stejné.

Ikona nainstalované aplikace (PWA) zůstává ve výchozí zelené.

## 9.4 Log

**Systém → Log** — auditní záznam přihlášení, změn zabezpečení a správy
uživatelů s IP adresou.

Filtry: **Hledat** (název akce, e-mail nebo jméno uživatele), **Akce**, **Entita** a **Období**
(od–do, včetně celého posledního dne). Ovládání seznamu viz [1.4 Seznamy s filtry](01_Uvod.md#seznamy-s-filtry).

## 9.5 Plánované úlohy

**Systém → Plánované úlohy** — poslední běh, stav a doporučená frekvence úloh
(`cron-prices`, `cron-fx`, `cron-snapshot`, `cron-bank-notices`, `cron-weekly-report`, `cron-period-report`, `cron-exchange-sync`, `cron-cpi`, `cron-alerts`, `cron-backup`, `cron-cleanup`). Úloha, která dlouho
neproběhla nebo selhává, je označená. Tlačítkem **Spustit nyní** ji spustíš ručně.

## 9.6 Aktualizace

**Systém → Aktualizace** ukáže nainstalovanou verzi a nejnovější vydanou verzi z GitHubu (vydání
repozitáře, včetně vývojových verzí `-dev`) a u nové verze i její popis. Výsledek kontroly se drží
6 hodin, tlačítko **Zkontrolovat znovu** se zeptá hned. Kontrola funguje jen pro veřejný repozitář;
jiný repozitář (např. fork) nastavíš v `cfg.php` klíčem `update.github_repo` nebo ENV
`MYPORTFOLIO_UPDATE_GITHUB_REPO`.

Pod tím je postup aktualizace pro **Docker** i **nativní instalaci** — stránka předvybere typ této
instalace a doplní cestu k aplikaci a k PHP, příkazy jdou zkopírovat. Aktualizaci spouštíš na serveru
sám; aplikace nemá práva měnit vlastní soubory ani Docker image. Postup je stejný jako
v [2.4 Aktualizace](02_Instalace.md#24-aktualizace).

## 9.7 Zálohy a obnova

Úloha `cron-backup` každou noc uloží do `storage/backup/` (v Dockeru `/data/storage/backup/`) ZIP
s výpisem databáze a ikonami katalogu. Drží se 30 denních a 12 měsíčních záloh. Pokud je
v `cfg.php` nastavené `cron.backup.password`, je ZIP šifrovaný (AES-256) — bez hesla ho neotevřeš,
heslo si proto ulož i mimo server.

Obnova ze zálohy:

```bash
php8.5 api/bin/restore-backup.php storage/backup/<soubor>.zip          # jen vypíše obsah zálohy
php8.5 api/bin/restore-backup.php storage/backup/<soubor>.zip --yes    # obnoví databázi a ikony
# Docker:
docker compose exec app php api/bin/restore-backup.php /data/storage/backup/<soubor>.zip --yes
```

Obnova nejdřív sama udělá **pojistnou zálohu** aktuálního stavu (najdeš ji v téže složce jako
nejnovější soubor), pak databázi smaže, naimportuje zálohu, vrátí ikony a spustí migrace — záloha
tak může být i ze starší verze aplikace. Při obnově se ztratí všechno, co vzniklo po vytvoření zálohy.

> 💡 Zálohu si můžeš bez rizika vyzkoušet obnovou do jiné databáze:
> `… restore-backup.php <soubor>.zip --yes --db=myportfolio_zkouska` (ikony se v tom případě nepřepisují).
> Zkušební databázi pak smaž.

## 9.8 Příkazy na serveru

| Příkaz | Účel |
|---|---|
| `php8.5 api/bin/migrate.php [--status]` | Migrace databáze |
| `php8.5 api/bin/set-password.php <email>` | Nastavení hesla |
| `php8.5 api/bin/reset-mfa.php <email>` | Nouzové vypnutí MFA účtu |
| `php8.5 api/bin/cron-fx.php [--from=RRRR-MM-DD]` | Kurzy ČNB (i zpětně) |
| `php8.5 api/bin/backfill-prices.php --all [--from=…]` | Doplnění historie cen |
| `php8.5 api/bin/backfill-market.php --from=RRRR-MM-DD` | Kurzy ČNB, inflace a historie cen od data |
| `php8.5 api/bin/cron-cpi.php` | Index cen ČR pro inflaci (první běh celá řada od 1996) |
| `php8.5 api/bin/rebuild-lots.php [--user=<id>]` | Přepočet FIFO lotů z transakcí (po importu nebo opravě dat; běžně proběhne sám) |
| `php8.5 api/bin/backfill-snapshots.php [--user=<id>] [--from=RRRR-MM-DD]` | Přepočet denní historie hodnoty (po importu nebo doplnění cen; běžně proběhne sám) |
| `php8.5 api/bin/seed-demo.php --user=<email> [--backfill] [--reset] [--no-partner]` | Ukázková data do uživatele bez účtů včetně demo partnera se sdílením ([2.3](02_Instalace.md#23-ukázková-data)); `--reset` jen pro `app.env` = development |
| `php8.5 api/bin/restore-backup.php <zip> [--yes] [--db=…]` | Obnova ze zálohy ([9.7](#97-zálohy-a-obnova)) |
