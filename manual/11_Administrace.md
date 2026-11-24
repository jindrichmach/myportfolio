# 11. Administrace

Sekce **Systém** v menu je viditelná jen pro roli **admin**.

## 11.1 Úvodní nastavení

Při prvním spuštění (prázdná databáze) aplikace zobrazí průvodce o pěti krocích. Vše se uloží
až tlačítkem **Dokončit** v posledním kroku.

| Krok | Pole | Význam |
|---|---|---|
| Správce | Instalační kód | Kód z výstupu instalace nebo ze souboru `storage/setup-token` ([2. Instalace](02_Instalace.md)); odkaz z instalace ho vyplní sám |
| | Jméno, e-mail, heslo | První uživatel s rolí **admin**; heslo nejméně 12 znaků (doporučená je delší věta) |
| | Jazyk, měna zobrazení | Jazyk aplikace a e-mailů a měna, ve které vidíš hodnoty; obojí jde změnit v Profilu |
| Zabezpečení | Vynutit vícefaktorové ověření | Všichni uživatelé si po přihlášení musí nastavit passkey nebo TOTP |
| E-mail | Nastavit odesílání e-mailů teď | SMTP server, port, šifrování, přihlášení a odesílatel. **Poslat zkušební e-mail** ověří nastavení ještě před dokončením. Nastavit jde i později ([11.4 Nastavení](#114-nastavení)) |
| Data | Vygenerovat ukázková data | Asi 5 let ukázkové historie do účtu správce ([2.3 Ukázková data](02_Instalace.md#23-ukázková-data)) |
| Shrnutí | | Přehled voleb před dokončením |

Po dokončení jsi rovnou přihlášen (při vynuceném MFA nejdřív nastavíš druhý faktor).
Další uživatele zakládá admin v [Administraci](#112-uživatelé).

## 11.2 Uživatelé

**Systém → Uživatelé.** Založení, úprava a deaktivace účtů. Seznam jde prohledat podle e-mailu nebo jména
a filtrovat podle role a stavu (aktivní, neaktivní) — viz [1.4 Seznamy s filtry](01_Uvod.md#seznamy-s-filtry).

| Role | Co smí |
|---|---|
| admin | vše: správa uživatelů, úpravy katalogu, ruční ceny, plánované úlohy, log |
| user | vlastní účty, portfolia, pozice a transakce; zakládání institucí a instrumentů |

Data uživatelů jsou oddělená — admin cizí pozice ani transakce nevidí.
Posledního aktivního admina nelze deaktivovat ani mu odebrat roli.

Když uživatel zapomene heslo a e-mail s odkazem nedorazí, nastavíš mu heslo na serveru
`php8.5 api/bin/set-password.php <email>`. Kdo ztratil passkey, autentikátor i záložní kódy, tomu vícefaktorové
ověření vypne `php8.5 api/bin/reset-mfa.php <email>` (zároveň odhlásí všechna zařízení) — viz
[11.9 Příkazy na serveru](#119-příkazy-na-serveru).

## 11.3 Ceny a kurzy

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

## 11.4 Nastavení

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
(viz [3.3 Profil](03_Prihlaseni_a_zabezpeceni.md#33-profil)).

Na výběr je deset schémat seřazených podle barvy: Malina, Terakota, Bronz, Oliva, **Les** (výchozí, zelené),
Oceán, Indigo, Ametyst, Orchidej a Grafit. Každé má světlý i tmavý režim a vlastní barvu loga a favikony.
Barvy zisku, ztráty a varování jsou ve všech schématech stejné.

Ikona nainstalované aplikace (PWA) zůstává ve výchozí zelené.

## 11.5 Záznam aktivit

**Systém → Záznam aktivit** — auditní záznam přihlášení, změn zabezpečení a správy
uživatelů s IP adresou. Akce jsou popsané slovy; technický kód akce (např. `auth.login`) ukáže tooltip.

Filtry: **Hledat** (kód akce, e-mail nebo jméno uživatele), **Akce**, **Entita** a **Období**
(od–do, včetně celého posledního dne). Ovládání seznamu viz [1.4 Seznamy s filtry](01_Uvod.md#seznamy-s-filtry).

## 11.6 Plánované úlohy

**Systém → Plánované úlohy** — poslední běh, stav a doporučená frekvence úloh
(`cron-prices`, `cron-fx`, `cron-snapshot`, `cron-bank-notices`, `cron-weekly-report`, `cron-period-report`, `cron-exchange-sync`, `cron-cpi`, `cron-alerts`, `cron-backup`, `cron-cleanup`). Úloha, která dlouho
neproběhla nebo selhává, je označená. Tlačítkem **Spustit nyní** ji spustíš ručně.

## 11.7 Aktualizace

**Systém → Aktualizace** ukáže nainstalovanou verzi a nejnovější vydanou verzi z GitHubu (vydání
repozitáře, včetně vývojových verzí `-dev`). Je-li k dispozici novější verze, sekce **Co se aktualizací změní**
vypíše popis každé verze mezi nainstalovanou a nejnovější — při přeskočení několika verzí tak vidíš všechny
změny. Výsledek kontroly se drží
6 hodin, tlačítko **Zkontrolovat znovu** se zeptá hned. Kontrola funguje jen pro veřejný repozitář;
jiný repozitář (např. fork) nastavíš v `cfg.php` klíčem `update.github_repo` nebo ENV
`MYPORTFOLIO_UPDATE_GITHUB_REPO`.

Pod tím je postup aktualizace pro **Docker** i **nativní instalaci** — stránka předvybere typ této
instalace a doplní cestu k aplikaci a k PHP, příkazy jdou zkopírovat. Aktualizaci spustíš na serveru sám,
nebo u nativní instalace tlačítkem z prohlížeče (níže). Postup je stejný jako
v [2.4 Aktualizace](02_Instalace.md#24-aktualizace).

Když některá z verzí vyžaduje krok navíc (např. jednorázový přepočet dat), přidá stránka bod
**3. Po aktualizaci ještě spusť** se zvýrazněnými příkazy z popisu té verze. Spusť je až po dokončení aktualizace.

### Aktualizace z prohlížeče

U **nativní instalace** se v sekci **Aktualizace z prohlížeče** zobrazí tlačítko **Aktualizovat na X**, je-li
k dispozici novější verze. Aktualizace proběhne bez SSH. Aplikace na pozadí spustí tyto kroky v tomto pořadí:

1. záloha databáze (`cron-backup`),
2. zapnutí údržby (web dočasně vrací hlášku o údržbě),
3. `git fetch --tags` a `git checkout vX.Y.Z`,
4. `composer install`,
5. migrace databáze,
6. `pnpm install` a `pnpm build`,
7. kontrola souboru `VERSION` a vypnutí údržby.

Před spuštěním se otevře potvrzení (záloha, krátká nedostupnost, žádný rollback). Průběh se ukazuje na stránce,
výstup příkazů se zapisuje do `log/update.log` a spuštění se zapíše do záznamu aktivit ([11.5](11_Administrace.md#115-záznam-aktivit)).

> ⚠️ Pozor: aktualizace z prohlížeče dává webovému procesu právo měnit kód aplikace. Kdo by ovládl účet správce,
> mohl by tak kód trvale změnit. Proto je funkce ve výchozím stavu vypnutá a zapíná se záměrně v `cfg.php`.

### Předpoklady

Funkci zapneš v `cfg.php` klíčem `update.web_update = true` (výchozí `false`). Funguje jen za těchto podmínek:

- Instalace je **git klon** repozitáře s tagy verzí (`vX.Y.Z`), ne kopie souborů z archivu.
- **Webový uživatel** (ten, pod kterým běží PHP-FPM) vlastní **celý** adresář aplikace rekurzivně, např.
  `sudo chown -R <webový-uživatel> <adresář-aplikace>`. Průvodce instalací nastavuje vlastníka jen u `storage/` a `log/`,
  to nestačí: aktualizace přepisuje soubory i v `api/src`, `db/migrations`, `web/src` a `manual`. Kontrola zápisu
  pokrývá jen kořen, `.git`, `api/vendor`, `web/node_modules` a `web/dist`. Chybějící vlastnictví jinde se projeví
  až při checkoutu, tedy po zapnutí údržby, a běh pak skončí chybou.
- `git`, `composer` a `pnpm` jsou dostupné webovému uživateli — buď na PATH PHP-FPM, nebo cestou v `cfg.php`
  (`update.git_bin`, `update.composer_bin`, `update.pnpm_bin`). PHP-FPM mívá minimální PATH, proto jsou cesty v `cfg.php`
  spolehlivější. Stejně musí PHP-FPM najít `php` a `node` (pnpm běží na `node`).
- Není-li HOME webového uživatele zapisovatelný, runner použije `storage/update-home`; globální konfigurace gitu
  (např. `safe.directory`) se pak nenačte.
- Běh je chráněný MFA: před spuštěním potvrdíš kódem z autentikátoru (TOTP), nebo přístupovým klíčem (passkey).
  Záložní kód nestačí. Správce bez zaregistrovaného TOTP ani passkey ověření nepotřebuje.

### Proč je tlačítko neaktivní

Pokud některá podmínka není splněna, je tlačítko neaktivní a pod ním je seznam důvodů:

| Důvod na stránce | Co znamená a co udělat |
|---|---|
| Aktualizace z prohlížeče je vypnutá (update.web_update v cfg.php). | V `cfg.php` nastav `update.web_update = true`. |
| Adresář aplikace není git klon. | Instalace musí být `git clone` repozitáře; kopii z archivu z prohlížeče neaktualizuješ. |
| V adresáři aplikace jsou necommitnuté změny. | Zjisti je příkazem `git status` v adresáři aplikace a změny v kódu vrať nebo ulož mimo něj. Soubory ignorované gitem (`cfg.php`, `storage/`, `log/`) to nebrání. |
| Webový uživatel nemůže zapisovat do adresářů aplikace (kořen, .git, api/vendor, web/node_modules, web/dist). | Vlastníkem adresáře aplikace musí být webový uživatel (viz Předpoklady). |
| Na serveru se nenašel příkaz git. | Nainstaluj git, nebo jeho cestu zadej v `update.git_bin`. |
| Na serveru se nenašel příkaz composer. | Nainstaluj composer, nebo jeho cestu zadej v `update.composer_bin`. |
| Na serveru se nenašel příkaz pnpm. | Nainstaluj pnpm, nebo jeho cestu zadej v `update.pnpm_bin`. |
| Aktualizace už běží. | Počkej, až běh skončí. Zámek drží jen běžící proces; běh, který skončil, zámek neblokuje. |

### Když aktualizace selže

Stránka ukáže krok, ve kterém se aktualizace zastavila, a konec logu. Údržba se vypne sama, ale **nic se nevrací
zpět** — ani kód, ani databáze. Co dělat:

1. Přečti `log/update.log`. Na začátku je zapsané HOME a PATH, pod kterými běh proběhl; na konci je chyba.
   Nejčastější příčina je chybějící binárka nebo špatná cesta.
2. Oprav příčinu na serveru (např. chybějící `php` nebo `node` pro PHP-FPM, výpadek sítě při `git fetch`,
   adresář nezapisovatelný pro webového uživatele).
3. Dokonči aktualizaci ručně podle [2.4 Aktualizace](02_Instalace.md#24-aktualizace), od kroku, který selhal.
   Kroky lze bezpečně opakovat.
4. Zkontroluj, že stránka ukazuje novou verzi a web funguje.

Záloha z `cron-backup` vznikla před prvním zásahem do kódu. Pokud běh selhal až po migracích, je schéma databáze
už nové a záloha z [11.8](11_Administrace.md#118-zálohy-a-obnova) je k dispozici pro obnovu.

## 11.8 Zálohy a obnova

Úloha `cron-backup` každou noc uloží do `storage/backup/` (v Dockeru `/data/storage/backup/`) ZIP
s výpisem databáze a ikonami katalogu. Drží se 30 denních a 12 měsíčních záloh. Pokud je
v `cfg.php` nastavené `cron.backup.password`, je ZIP šifrovaný (AES-256) — bez hesla ho neotevřeš,
heslo si proto ulož i mimo server.

Zálohy spravuje správce na stránce **Systém → Zálohy**. Obnovu jde provést i na serveru z příkazové řádky,
viz [Obnova z příkazové řádky](#obnova-z-příkazové-řádky).

### Seznam záloh

Stránka ukáže zálohy ze složky záloh, od nejnovější: soubor, datum vytvoření, velikost a stav **Šifrovaná**
nebo **Nešifrovaná**. Zobrazí se jen ZIP zálohy celé instance; PDF zálohy a SQL soubory v seznamu nejsou.

Nad seznamem jsou tlačítka:

- **Zálohovat teď** spustí úlohu `cron-backup` (stejně jako **Spustit nyní** v [11.6](11_Administrace.md#116-plánované-úlohy)).
  Průběh se ukazuje na stránce a po dokončení se seznam obnoví. Když záloha selže, podrobnosti jsou v **Systém → Plánované úlohy**.
- **Stáhnout** u každé zálohy stáhne ZIP celé instance. ZIP obsahuje data všech uživatelů, ulož ho bezpečně.
- **Obnovit…** otevře dialog pro obnovu instance z této zálohy ([níže](#obnova-z-webu)).

Stažení i obnova jsou chráněné MFA: potvrdíš je kódem z aplikace pro ověřování (TOTP), nebo přístupovým klíčem (passkey).
Záložní kód nestačí. Správce bez zaregistrovaného TOTP ani passkey ověření nepotřebuje.

Šifrovanou zálohu nejde obnovit, dokud v `cfg.php` chybí `cron.backup.password`; tlačítko **Obnovit…** je u ní
neaktivní a stažení funguje dál.

Stažení i obnova se zapisují do [záznamu aktivit](11_Administrace.md#115-záznam-aktivit).

### Obnova z webu

Obnova přepíše celou instanci. Před spuštěním se otevře dialog s varováním, že:

- přepíše data **všech** uživatelů, ne jen tvoje,
- všechno, co vzniklo po vytvoření zálohy, se nenávratně ztratí,
- všichni uživatelé včetně tebe budou odhlášeni; přihlásíš se údaji platnými v záloze (uživatel nemusí existovat
  nebo může mít jiné heslo),
- ožijí stará hesla, smazané nebo zneplatněné API tokeny a důvěryhodná zařízení pro MFA (vrátí se ve stavu ze zálohy),
- před obnovou se automaticky vytvoří pojistná záloha aktuálního stavu.

Pak do pole **Pro potvrzení opiš název zálohy** napiš přesný název souboru. Tlačítko **Obnovit instanci** je aktivní
až při shodě. Potvrdíš MFA a obnova se spustí.

Obnova běží na pozadí a trvá jednotky minut. Po tu dobu je aplikace v údržbě a stránka ukazuje aktuální krok:
pojistnou zálohu, rozbalení a ověření zálohy, mazání tabulek, import databáze, obnovu ikon, migrace a zneplatnění
přihlášení. Po úspěšném dokončení tě aplikace odhlásí a přesměruje na přihlášení se zprávou o obnově.

### Proč je obnova neaktivní

Pokud platí některý z důvodů, jsou tlačítka **Obnovit…** neaktivní a stránka důvody vypíše:

| Důvod na stránce | Co znamená a co udělat |
|---|---|
| V PHP chybí rozšíření zip. | Nainstaluj a zapni rozšíření `zip` pro PHP. |
| Na serveru se nenašel klient mariadb ani mysql. | Nainstaluj klienta databáze, aby byl dostupný webovému procesu. |
| Na serveru se nenašel PHP CLI. | Nainstaluj PHP CLI ve stejné verzi jako PHP-FPM. |
| Webový uživatel nemůže zapisovat do adresáře storage. | Vlastníkem `storage/` musí být webový uživatel. |
| Obnova už běží. | Počkej, až skončí. |
| Probíhá aktualizace aplikace. | Počkej, až skončí ([11.7](11_Administrace.md#117-aktualizace)). |

### Během obnovy

Aplikace je v údržbě: web zobrazí hlášku o údržbě a API vrací 503. **Plánované úlohy (cron) neběží** — každý jejich
skript se hned ukončí a nic nezapíše do rozpracované databáze ani do historie úloh ([11.6](11_Administrace.md#116-plánované-úlohy)).
Po skončení obnovy úlohy běží znovu podle rozvrhu.

### Když obnova selže

Obnova se může zastavit v kterémkoli kroku. Údržba se pak vypne sama, ale **obnova nic nevrací zpět** — databáze
může zůstat napůl naimportovaná. Stránka ukáže krok, ve kterém obnova selhala, konec logu a název pojistné zálohy.
Stejně vypadá, když proces obnovy přestal běžet (např. restart serveru uprostřed) — stránka pak hlásí přerušenou obnovu.
Pokud aplikace přestane odpovídat, stránka ukáže poslední známý krok.

Pokud obnova selhala v kroku **spuštění procesu obnovy**, **pojistná záloha** nebo **rozbalení a ověření zálohy**, databáze zůstala beze změny.
Nic nevracej, jen oprav příčinu (krok 3) a obnovu zopakuj. Pojistná záloha v tom případě buď nevznikla (stránka pak
neukáže její název), nebo není potřeba — příkaz níže v tom případě nepoužívej, protože by přepsal nedotčená data
starší zálohou. Postup pro ostatní případy:

1. Přečti `log/restore.log` (v Dockeru `/data/log/restore.log`); na jeho konci je chyba.
2. Pokud obnova selhala později, vrať stav z pojistné zálohy příkazem níže. Její název je na stránce u řádku
   „Pojistná záloha stavu před obnovou“. Pojistná záloha vznikla těsně před obnovou, takže vrátíš stav,
   jaký byl před pokusem.
3. Pokud příčinou bylo prostředí (místo na disku, nedostupná databáze), oprav je před dalším pokusem.
4. Zkontroluj přihlášení a data.

```bash
php8.5 api/bin/restore-backup.php storage/backup/<pojistná-záloha>.zip --yes
# Docker:
docker compose exec app php api/bin/restore-backup.php /data/storage/backup/<pojistná-záloha>.zip --yes
```

### Obnova z příkazové řádky

Obnovu jde spustit na serveru bez dialogu a bez MFA; hodí se, když web nejde použít. Příkaz bez `--yes` jen vypíše
obsah zálohy a nic nezmění:

```bash
php8.5 api/bin/restore-backup.php storage/backup/<soubor>.zip          # jen vypíše obsah zálohy
php8.5 api/bin/restore-backup.php storage/backup/<soubor>.zip --yes    # obnoví databázi a ikony
# Docker:
docker compose exec app php api/bin/restore-backup.php /data/storage/backup/<soubor>.zip --yes
```

Obnova nejdřív sama udělá **pojistnou zálohu** aktuálního stavu (najdeš ji v téže složce jako nejnovější soubor),
pak smaže tabulky a pohledy v databázi, naimportuje zálohu, vrátí ikony, spustí migrace a zneplatní všechna přihlášení —
záloha tak může být i ze starší verze aplikace. Při obnově se ztratí všechno, co vzniklo po vytvoření zálohy.

Ostrou databázi obnovuje příkazová řádka stejným průběhem jako web: zapne údržbu, drží zámek `storage/restore.lock`
(druhá obnova se nespustí, dokud první neskončí) a průběh zapisuje do `log/restore.log`. Po obnově zapíše záznam
do záznamu aktivit (akce `admin.backup.restore`, bez přihlášeného uživatele).

> 💡 Zálohu si můžeš bez rizika vyzkoušet obnovou do jiné databáze:
> `… restore-backup.php <soubor>.zip --yes --db=myportfolio_zkouska` (ikony se v tom případě nepřepisují).
> Zkušební databázi pak smaž.

## 11.9 Příkazy na serveru

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
| `php8.5 api/bin/restore-backup.php <zip> [--yes] [--db=…]` | Obnova ze zálohy ([11.8](#118-zálohy-a-obnova)) |
| `php8.5 api/bin/rotate-secret-key.php [--dry-run]` | Přešifrování uložených tajemství (klíče burz, hesla IMAP a SMTP, TOTP) aktuálním šifrovacím klíčem — viz níže |

**Výměna šifrovacího klíče** (`app.secret_encryption_key`, např. při podezření na únik): dosavadní klíč přesuň
do `app.secret_encryption_previous_keys` (seznam; v Dockeru ENV `MYPORTFOLIO_SECRET_PREVIOUS_KEYS`, oddělené čárkou), jako nový klíč dej výstup `openssl rand -base64 32`, spusť
`rotate-secret-key.php` a po úspěchu starý klíč ze seznamu odeber. Bez starého klíče v seznamu uložená tajemství
nepřečteš — uživatelé by museli znovu zadat hesla a klíče a nastavit TOTP. V produkci musí být klíč nastavený;
bez něj aplikace tajemství neuloží (upozornění v Systém → Nastavení).
