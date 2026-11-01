# 6. Prices & rates

## 6.1 Exchange rates (CNB)

The base currency is **CZK**. Rates are downloaded from the daily exchange rate list of the ČNB
(Czech National Bank, CNB) on business days at 14:45 (the CNB publishes them at 14:30). Weekends
and public holidays use the rate of the last business day.

Every transaction gets the **rate on its execution date** (for the currency of the amount) — it is
later used for the cost basis and taxes in CZK.

> 🛈 If a rate is missing (a CNB outage longer than a week), the app reports it — it never
> substitutes a rate of 1.

## 6.2 Instrument prices

Prices are downloaded **every hour** (today's price is overwritten) and once a day the closing
price is saved to the history. The history is filled in automatically from the position's first
transaction.

| Instrument price source | Order of sources |
|---|---|
| CoinGecko | CoinGecko → Yahoo Finance |
| XTB | XTB → Yahoo Finance |
| Yahoo Finance | Yahoo Finance |
| Manual | manually entered prices only |

When the first source fails, the next one is used. For crypto, the free CoinGecko tier provides
history for the last 365 days only — older days are filled in from Yahoo Finance.

**Stale price** — the "stale" badge appears when the last price is older than 2 days (crypto) or
5 days (ETFs, stocks — because of weekends and holidays). A price is never deleted or reset to
zero; the last known price stays.

## 6.3 Manual price

**Accounts & catalog → Instruments → Set price.** Date, price and currency. A manual price for a
given day overrides the price from the source. Backfilling the history does not overwrite manual
prices; only **today's** price is overwritten by the next hourly update, if the source works.

| Instrument | Who may enter a price |
|---|---|
| Price source "Manual" | every user |
| Automatic source | admin only (to fix an outage) |

## 6.4 Where you see prices

- **Accounts & catalog → Instruments** — the Last price column
- **Positions** and **position detail** — the instrument's last price in the display currency, below it the original market price, date and source
- **System → Prices & rates** — status of all sources (see [11.3](11_Administrace.md#113-prices-and-rates))
