# 6. Ceny a kurzy

## 6.1 Kurzy měn (ČNB)

Základní měnou je **CZK**. Kurzy se stahují z denního kurzového lístku ČNB
v pracovní dny ve 14:45 (ČNB vyhlašuje ve 14:30). Pro víkend a svátek platí
kurz posledního pracovního dne.

Každá transakce dostane **kurz ke dni provedení** (pro měnu částky) — ten se
později použije pro nákupní cenu a daně v CZK.

> 🛈 Chybí-li kurz (výpadek ČNB delší než týden), aplikace to ohlásí — nikdy
> nedosadí kurz 1.

## 6.2 Ceny instrumentů

Ceny se stahují **každou hodinu** (přepisuje se dnešní cena) a jednou za den
se ukládá uzavírací cena do historie. Historie se automaticky doplní od první
transakce pozice.

| Zdroj ceny instrumentu | Pořadí zdrojů |
|---|---|
| CoinGecko | CoinGecko → Yahoo Finance |
| XTB | XTB → Yahoo Finance |
| Yahoo Finance | Yahoo Finance |
| Ručně | jen ručně zadané ceny |

Když první zdroj selže, použije se další. U krypta CoinGecko zdarma poskytuje
historii jen za posledních 365 dní — starší dny doplní Yahoo Finance.

**Zastaralá cena** — badge „zastaralé" se zobrazí, když poslední cena je starší
než 2 dny (krypto) nebo 5 dní (ETF, akcie — kvůli víkendům a svátkům). Cena
se nikdy nesmaže ani nevynuluje; zůstane poslední známá.

## 6.3 Ruční cena

**Účty a katalog → Instrumenty → Zadat cenu.** Datum, cena a měna. Ruční cena daného
dne přepíše cenu ze zdroje. Doplňování historie ruční ceny nepřepisuje;
jen **dnešní** cenu přepíše nejbližší hodinová aktualizace, pokud zdroj funguje.

| Instrument | Kdo smí zadat cenu |
|---|---|
| Zdroj ceny „Ručně" | každý uživatel |
| Automatický zdroj | jen admin (oprava výpadku) |

## 6.4 Kde ceny uvidíš

- **Účty a katalog → Instrumenty** — sloupec Poslední cena
- **Pozice** a **detail pozice** — poslední cena instrumentu, datum a zdroj
- **Systém → Ceny a kurzy** — stav všech zdrojů (viz [11.3](11_Administrace.md#113-ceny-a-kurzy))
