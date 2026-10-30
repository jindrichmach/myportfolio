# 0. Za 5 minut k prvnímu přehledu

Nejkratší cesta od prázdné aplikace k Přehledu s hodnotou a výnosem. Nejrychleji to jde **importem** exportu
od brokera nebo burzy — instrumenty i pozice se při něm založí samy, ručně zakládáš jen účet a portfolio.

## 0.1 Pojmy na jednom místě

| Pojem | Co to je | Příklad |
|---|---|---|
| Instituce | Kde máš peníze — burza, broker, banka, peněženka (sdílený katalog) | XTB, Coinbase, Fio banka |
| Účet | Tvůj účet u instituce | „XTB hlavní", „Coinbase" |
| Portfolio | Tvoje vlastní skupina pozic napříč účty | „Dlouhodobé", „Spekulace" |
| Instrument | Co držíš (sdílený katalog) | VWCE, BTC, akcie ČEZ |
| Pozice | Konkrétní instrument na konkrétním účtu v jednom portfoliu | VWCE na XTB v portfoliu Dlouhodobé |
| Transakce | Nákup, prodej, dividenda, vklad… — z nich se počítá vše ostatní | nákup 3 ks VWCE |

## 0.2 Pět kroků

1. **Instituce** — v **Katalog → Instituce** zkontroluj, že tam tvůj broker nebo burza je; když ne, přidej ji
   ([4.1](04_Ucty_a_portfolia.md#41-katalog--instituce)).
2. **Účet** — **Portfolio → Účty → Nový účet**, vyber instituci a dej účtu název
   ([4.3](04_Ucty_a_portfolia.md#43-účty)).
3. **Portfolio** — **Portfolio → Portfolia → Nové portfolio**, třeba „Dlouhodobé"
   ([4.4](04_Ucty_a_portfolia.md#44-portfolia)).
4. **Import** — stáhni export transakcí od brokera (který soubor, je v tabulce v
   [5.5](05_Transakce.md#55-import-z-burzy--brokera)), v **Portfolio → Import** vyber účet a portfolio, nahraj
   soubor, v náhledu spáruj instrumenty a potvrď.
5. **Přehled** — hotovo. Hodnota, výnos a graf se dopočítají z transakcí; historie cen se stáhne na pozadí
   (u dlouhé historie to může chvíli trvat).

> 💡 Tip: Broker, který v seznamu importu není, nebo spořicí účet zapíšeš ručně: v kroku 4 místo importu založ
> v **Portfolio → Pozice** novou pozici a přidej transakce ([4.5](04_Ucty_a_portfolia.md#45-pozice),
> [5.2](05_Transakce.md#52-formulář)).

## 0.3 Co dál

- [7.1 Jak se počítá hodnota a zisk](07_Prehled.md#71-jak-se-počítá-hodnota-a-zisk) — co znamenají čísla na Přehledu.
- [9.1 Cílová alokace a rebalancing](09_Alokace_a_ciste_jmeni.md#91-cílová-alokace-a-rebalancing) — nastav si cíl
  a aplikace navrhne, kam dát další vklad.
- [5.6 Napojení burzy přes API](05_Transakce.md#56-napojení-burzy-přes-api) — transakce z burzy se pak stahují samy.
