# 3. Přihlášení a zabezpečení

## 3.1 Úvodní nastavení

Při prvním spuštění (prázdná databáze) aplikace zobrazí průvodce o pěti krocích. Vše se uloží
až tlačítkem **Dokončit** v posledním kroku.

| Krok | Pole | Význam |
|---|---|---|
| Správce | Jméno, e-mail, heslo | První uživatel s rolí **admin**; heslo nejméně 12 znaků (doporučená je delší věta) |
| | Jazyk, měna zobrazení | Jazyk aplikace a e-mailů a měna, ve které vidíš hodnoty; obojí jde změnit v Profilu |
| Zabezpečení | Vynutit vícefaktorové ověření | Všichni uživatelé si po přihlášení musí nastavit passkey nebo TOTP |
| E-mail | Nastavit odesílání e-mailů teď | SMTP server, port, šifrování, přihlášení a odesílatel. **Poslat zkušební e-mail** ověří nastavení ještě před dokončením. Nastavit jde i později ([9.3 Nastavení](09_Administrace.md#93-nastavení)) |
| Data | Vygenerovat ukázková data | Asi 5 let ukázkové historie do účtu správce ([2.3 Ukázková data](02_Instalace.md#23-ukázková-data)) |
| Shrnutí | | Přehled voleb před dokončením |

Po dokončení jsi rovnou přihlášen (při vynuceném MFA nejdřív nastavíš druhý faktor).
Další uživatele zakládá admin v [Administraci](09_Administrace.md#91-uživatelé).

## 3.2 Přihlášení

Zadej e-mail a heslo. Má-li účet zapnuté vícefaktorové ověření, následuje
potvrzení **passkey** (otisk, obličej, PIN zařízení nebo bezpečnostní klíč),
nebo šestimístný **kód z autentikátoru** (TOTP). Kód se odešle sám po zadání
šesté číslice.

Pokud správce povolil přihlášení bez hesla, můžeš použít rovnou
**Přihlásit přístupovým klíčem**.

**Ochrana proti hádání hesla:** po 5 neúspěšných pokusech za 5 minut se objeví
CAPTCHA (je-li nastavená), po 10 pokusech za 15 minut se přihlášení na 15 minut
zablokuje, po 30 pokusech za hodinu na 24 hodin.

## 3.3 Zapomenuté heslo

**Zapomenuté heslo?** pod přihlášením pošle na e-mail odkaz pro nastavení nového
hesla, platný **60 minut**. Aplikace neprozradí, zda e-mail existuje.

Nepřišel-li e-mail, zkontroluj spam a nastavení SMTP ([9.3](09_Administrace.md#93-nastavení)). Správce může heslo nastavit
na serveru: `php8.5 api/bin/set-password.php <email>`.

## 3.4 Profil

**Profil** v menu (sekce Účet) má tři záložky: **Účet**, **Reporty** a **Upozornění**. Vybraná záložka
je v adrese stránky (`?tab=`), takže na ni jde odkázat.

Na záložce **Účet** upravíš:

| Pole | Význam |
|---|---|
| Jméno | Zobrazení v aplikaci |
| Jazyk | Čeština nebo angličtina |
| Měna zobrazení | CZK, EUR nebo USD — v této měně se budou přepočítávat hodnoty portfolia |
| Barevné schéma | Barvy aplikace, loga, favikony a e-mailů. **Podle instance** = schéma, které nastavil administrátor (viz [9.3 Nastavení](09_Administrace.md#93-nastavení)) |

Světlý / tmavý režim se přepíná zvlášť ikonami v horní liště a platí pro každé schéma.

Záložka **Reporty** nastavuje týdenní, měsíční a roční report e-mailem — viz [7.6 Týdenní report](07_Prehled.md#76-týdenní-report-e-mailem).
Záložka **Upozornění** nastavuje denní souhrn upozornění — viz [7.7 Upozornění e-mailem](07_Prehled.md#77-upozornění-e-mailem).

Odkaz **Heslo, 2FA a passkeys** (nebo kliknutí na své jméno v horní liště)
otevře záložky zabezpečení:

| Záložka | Co tam je |
|---|---|
| Změna hesla | Vyžaduje současné heslo; ostatní přihlášení se odhlásí |
| 2FA / TOTP | Aktivace autentikátoru přes QR kód a ověřovací kód |
| Přístupové klíče | Přidání, přejmenování a odvolání passkeys, záložní kódy |
| Zámek aplikace | Automatické zamčení po nečinnosti |

> 💡 Doporučené je mít **dvě passkeys**, nebo jednu passkey a aktivní TOTP —
> při ztrátě jednoho zařízení se pořád přihlásíš.

## 3.5 Záložní kódy

Na záložce Přístupové klíče vygeneruješ sadu **deseti jednorázových kódů**.
Zobrazí se **jen jednou** — ulož je mimo počítač (tisk, správce hesel). Použiješ
je při přihlášení odkazem „Nemám klíč ani autentikátor". Nová sada ruší
předchozí.

Když nemáš ani passkey, ani TOTP, ani záložní kód, správce na serveru spustí
`php8.5 api/bin/reset-mfa.php <email>` — vypne všechny faktory a odhlásí
všechna zařízení.

## 3.6 Zámek aplikace

**Zamknout** v horní liště (zobrazí se, máš-li passkey) aplikaci okamžitě
zamkne. Na záložce Zámek aplikace nastavíš automatické zamčení po nečinnosti
(1–1440 minut, nejvýše limit správce). Zámek platí i na serveru — zamčená
session nepřečte ani nezmění žádná data. Odemyká se passkey (**Odemknout přístupovým
klíčem**); jinak zvol **Odhlásit** a přihlas se znovu.

## 3.7 API

**Účet → API** — tokeny pro skripty a integrace bez prohlížeče (Home Assistant, tabulky, vlastní skripty)
a přehled všeho, co s nimi jde volat. Stránka má dvě záložky.

### 3.7.1 Tokeny

| Pole | Význam |
|---|---|
| Název | Pro tvou orientaci |
| Rozsah | **read** — jen čtení (GET); **read & write** — i zápis (POST, PUT, DELETE) |
| Platnost | Volitelné datum vypršení |

Vytvoření tokenu vyžaduje čerstvé ověření passkey nebo TOTP. Token se zobrazí **jen jednou** — ulož si ho
do správce hesel. Posílá se v hlavičce `Authorization: Bearer mp_pat_…`. Token je jako heslo: kdo ho má,
vidí tvá data; nepotřebný token zruš.

### 3.7.2 Endpointy

Seznam všech endpointů, které jde tokenem volat, po oblastech (systém, katalog, ceny a kurzy, účty, portfolia,
pozice, transakce, ruční ocenění a hotovost, přehledy, daňový report, importy, cenové hranice). U každého:

| Část | Význam |
|---|---|
| Metoda a cesta | např. `GET /api/positions/{id}` |
| Rozsah | `read`, nebo `read_write` pro zápis |
| Parametry | kde se posílají (cesta, query, tělo JSON, formulář), typ, povinnost, popis, povolené hodnoty a výchozí hodnota |
| Odpověď | stavový kód, popis a ukázka |
| Příklad (curl) | hotový příkaz s adresou tvé instance — stačí doplnit token |
| Vyzkoušet | zavolá endpoint přímo ze stránky tvým přihlášením (ne tokenem); u zápisu se nejdřív zeptá |

Pravidla API:

- Částky, ceny a množství se posílají i vracejí jako **desetinné řetězce** (`"1250.50"`), nikdy jako čísla
  s plovoucí čárkou — ta API odmítne.
- Chyba vrací `{"error": {"code", "message", "fields"}}`; `fields` jen u chyb validace (400).
- Token má limit požadavků za minutu (hlavičky `X-RateLimit-*`, při překročení 429).
- Správa uživatelů, tokenů a nastavení účtu tokenem dostupná není.

Tlačítko **Stáhnout OpenAPI (JSON)** stáhne popis API ve formátu OpenAPI 3.1 (`/api/openapi.json`) pro Swagger,
Postman nebo generování klientů; **Otevřít OpenAPI** ho zobrazí v prohlížeči.

## 3.8 Odhlášení

**Odhlásit** ukončí session okamžitě i na serveru. Nepřihlášená session
vyprší nejpozději po 30 dnech.
