# 10. Bankovní avíza

Banky posílají při každém pohybu na účtu e-mail s částkou a zůstatkem. MyPortfolio umí tyto
e-maily číst z IMAP schránky a zapsat je do ruční pozice spořicího účtu — nemusíš pak ručně
zadávat vklady, výběry, úroky ani ocenění.

Podporovaná avíza:

| Banka | Avízo | Zůstatek → ocenění |
|---|---|---|
| Fio banka | „Fio banka – příjem/výdaj na kontě" i novější „zůstatek účtu … se zvýšil/snížil" | ano |
| Air Bank | „Zvýšení / Snížení zůstatku na účtu" | ano |
| MONETA Money Bank | Info Servis „Přišly peníze" / „Odešly peníze" | ano (účetní zůstatek) |
| Raiffeisenbank | „Pohyb na účtě" | ano |
| ČSOB | „Moje info – Avízo" | ano |
| Česká spořitelna | avízo s řádkem „Směr platby" | ano (k dispozici); datum = den doručení e-mailu |
| Banka CREDITAS | „Notifikace o změně na účtu" | ano |
| UniCredit Bank | „Informace o pohybu na účtu" | ne — jen pohyb |

U banky, jejíž avízo zůstatek neuvádí, se hodnota pozice počítá z posledního ručního ocenění
a pohybů po něm (viz [7.4 Ruční ocenění](07_Prehled.md#74-ruční-ocenění-portu-spořicí-účty)).

## 10.1 Co se z avíza zapíše

| Avízo | V pozici |
|---|---|
| Příchozí platba | **Vklad** |
| Odchozí platba | **Výběr** |
| Připsaný úrok (bez protiúčtu, typ pohybu se slovem „úrok") | **Úrok** — počítá se do výnosu |
| Daň z úroku (odchozí, bez protiúčtu, typ pohybu se slovem „úrok") | **Poplatek** |
| Zůstatek po pohybu | **Ruční ocenění** k datu zaúčtování |

Transakce z avíza mají zdroj „bank_notice" a stejné avízo se nikdy nezapíše dvakrát.
Když přijde víc avíz za jeden den, platí zůstatek z posledního.

## 10.2 Schránka

**Účet → Bankovní avíza → Nová schránka.** Zadej schránku, do které banka avíza posílá.

| Pole | Význam |
|---|---|
| Název | Pro tvou orientaci |
| IMAP server, port, šifrování | Např. `imap.seznam.cz`, 993, SSL/TLS. Server musí mít veřejnou adresu — místní a vnitřní síť správce povolí v `cfg.php` (`bank_notices.allow_private_imap`) |
| Uživatel, heslo | Přihlášení do schránky; heslo se ukládá šifrovaně a už se nezobrazí. Při úpravě prázdné heslo = beze změny |
| Složka | Kde avíza leží (výchozí `INBOX`) |
| Ověřovat pravost e-mailu | Avízo se zapíše, jen když ho poštovní server ověřil (DKIM/DMARC) jako odeslané bankou — ochrana proti podvrženým e-mailům |
| Načítat automaticky | Schránka se čte každých 15 minut |

Tlačítko **Test připojení** ověří přihlášení a vypíše složky schránky, **Načíst teď**
zpracuje avíza hned. Schránka se jen čte — e-maily zůstanou nepřečtené a nikam se nepřesouvají.

> 💡 Pro avíza je nejlepší samostatná schránka nebo alias, ať se nečtou ostatní e-maily.
> Pokud používáš heslo pro aplikace (Gmail, Seznam s dvoufázovým ověřením), zadej ho místo hesla k účtu.

## 10.3 Napojení na pozici

Avízo se přiřadí ruční pozici podle **čísla účtu**. V detailu spořicího účtu klikni na
**Upravit** a vyplň:

| Pole | Význam |
|---|---|
| Číslo účtu pro bankovní avíza | Ve tvaru `123456789/2010` (s předčíslím `19-123456789/0800`) |
| Avíza od | Avíza zaúčtovaná dřív se přeskočí. Bez data se doplní den po posledním ručním ocenění, aby se nic nezapsalo dvakrát |

MONETA posílá v avízu číslo účtu zčásti skryté (`238***891`) — přiřadí se podle viditelných číslic,
délky a kódu banky. Když stejné masce odpovídá víc napojených účtů, avízo se nepřiřadí (stav **nenapojený účet**) —
zadej pohyb ručně. Měna avíza musí odpovídat měně pozice. Směr platby (příchozí / odchozí) se bere z předmětu
nebo z věty o zůstatku účtu, ne z popisu platby od protistrany.

## 10.4 Zpracovaná avíza

Pod schránkami je přehled napojených účtů a seznam posledních zpracovaných avíz se stavem:

| Stav | Význam |
|---|---|
| zapsáno | Transakce a ocenění jsou v pozici |
| před napojením | Avízo je starší než datum **Avíza od** |
| nenapojený účet | Číslo účtu z avíza nemá žádná ruční pozice (nebo maska odpovídá víc pozicím) — doplň ho a avízo se zpracuje při dalším běhu, pokud je ještě mezi načítanými zprávami |
| nečitelné | E-mail od banky, ale v nečekaném formátu |
| neověřený odesílatel | E-mail neprošel ověřením DKIM/DMARC |
| chyba zápisu | Např. výběr by převýšil vklady pozice |

E-maily, které nejsou avízem žádné z podporovaných bank, se ignorují a v seznamu nejsou.
