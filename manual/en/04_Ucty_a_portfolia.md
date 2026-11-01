# 4. Accounts, portfolios and positions

Before you record your first transaction, you need: an **institution** and an **instrument**
in the catalog, your own **account** at the institution, a **portfolio** and a **position** in it.

## 4.1 Catalog — institutions

**Accounts & catalog → Institutions.** Exchanges, brokers, wallets, robo-advisors, banks and pension companies.
The catalog is **shared** by all users: anyone can create an institution, only an admin can edit
or delete one.

| Field | Meaning |
|---|---|
| Name | Unique across the whole installation |
| Type | Exchange / Broker / Wallet / Robo-advisor / Bank / Pension company |
| Website | Optional link |

An institution that someone has already linked an account to cannot be deleted — an admin can
deactivate it. The list can be searched by name and filtered by type (see [1.4 Lists with filters](01_Uvod.md#lists-with-filters)).

## 4.2 Catalog — instruments

**Accounts & catalog → Instruments.** What you can hold: crypto, ETFs, stocks, funds.
You can search by symbol, name or ISIN and filter by asset class (see [1.4 Lists with filters](01_Uvod.md#lists-with-filters)).

| Field | Meaning |
|---|---|
| Asset class | Crypto / ETF / Stock / Fund / Cash |
| Symbol | E.g. `BTC`, `CSPX` (converted to upper case) |
| Name | Full name |
| Price currency | The currency the market quotes the instrument in (`EUR`, `USD`) |
| ISIN | Optional; the format is validated |
| Price source | CoinGecko / XTB / Yahoo Finance / Manual — see [6. Prices & rates](06_Ceny_a_kurzy.md) |
| Decimals | How many decimal places of quantity to display |
| Price source identifiers | How the individual price sources know the instrument |

The same symbol in the same currency can be in the catalog only once. The same ETF on
different exchanges in different currencies is two instruments (e.g. `CSPX` in USD).

**Price source identifiers** — examples:

| Source | BTC | CSPX | VWCE |
|---|---|---|---|
| CoinGecko | `bitcoin` | — | — |
| XTB | — | `CSPX.UK` | — |
| Yahoo | (default `BTC-EUR`) | `CSPX.L` | `VWCE.DE` |

> 💡 For crypto you do not have to fill in the Yahoo ticker — `SYMBOL-QUOTE` is used.

### 4.2.1 Icons

Institutions and instruments can have an icon — it is shown in the catalog, next to accounts, in the
position list, in the position detail, on the Dashboard and next to transactions. Manual positions (savings account, Portu)
show the institution's icon. Without an icon, a circle with initials is shown.

You set the icon when editing the item (the pencil in a row of the **Accounts & catalog → Institutions** or
**Accounts & catalog → Instruments** list), in the **Icon** field at the top of the form — it is saved immediately, without the Save button.
For a new item you can set it only after saving. It can be changed by an admin and by the user who created the item (that user
can change only the icon in the form; the other fields are edited by an admin); everyone else just sees the icon.

| Option | What it does |
|---|---|
| Upload image | Your own file from your computer (requirements in the table below) |
| Choose from library — instrument | Logos of ETF issuers (iShares, Vanguard, L&G, Amundi, Xtrackers, SPDR, Invesco, WisdomTree, VanEck, HSBC, UBS, Franklin Templeton, ARK, Lyxor) and about 100 of the most common cryptocurrencies (the cryptocurrency-icons set, CC0 license); search by name or symbol, for ETFs the first word of the name is prefilled |
| Choose from library — institution | **Download logo** from the institution's website, or choose from a list of Czech banks, brokers, exchanges, wallets and pension companies — for some of them the logo is bundled with the app (preview in the list), for the others it is downloaded from their website |
| Remove | Back to the circle with initials |

The app downloads most institution logos on your request from the institution's website (`apple-touch-icon` or the icon from the home
page, only over https and only from public addresses) and stores them like a regular uploaded image; only logos of
institutions whose website does not allow downloading are bundled. Logos are trademarks of their owners and serve only for identification. If the website has no logo in a supported format, upload an image manually.

| Requirement | Value |
|---|---|
| Format | PNG, JPEG, WebP or GIF (not SVG) |
| File size | at most 1 MB |
| Dimensions | at most 4096 × 4096 px; stored scaled down to 128 × 128 px |

> 💡 A square image with a transparent background looks best — transparency is preserved.

## 4.3 Accounts

**Accounts & catalog → Accounts.** Your accounts at institutions.

| Field | Meaning |
|---|---|
| Institution | From the catalog |
| Name | E.g. "Main", "Model T" |
| Currency | The account's base currency |
| Note | Optional |

An account with positions cannot be deleted — **archive** it in the edit form instead. Archived
accounts are shown with the **Show archived** toggle. The list can be searched (name, institution, note)
and filtered by institution (see [1.4 Lists with filters](01_Uvod.md#lists-with-filters)).

## 4.4 Portfolios

**Accounts & catalog → Portfolios.** Your own groupings of positions across accounts, e.g.
"Long-term", "Speculation", "Cash". You change the order (in lists and on the Dashboard) by dragging a row with the mouse by the ⠿ handle on the left, or with the
**Move up / Move down** arrows on the portfolio (keyboard, touch). The **Contents** column shows how many positions the portfolio has ("3 positions", "Empty so far"); clicking it opens its positions.
Clicking a portfolio's name opens its positions.

## 4.5 Positions

**Portfolio → Positions** — an overview grouped by portfolio with the quantity held
and the last price. Below the position name there is always the institution and account, so the same instrument
on two accounts cannot be confused. The last price is in the display currency (at today's rate), the original market price
is in smaller type below it. For a manual position (Portu, savings account) the price is replaced by today's
value and the date of the last manual valuation; the quantity held is the net deposits.
You create a new position with the **New position** button:

| Field | Market position | Manual valuation | Cash |
|---|---|---|---|
| Portfolio, account | required | required | required |
| Instrument | search in the catalog | — | — |
| Name | — | e.g. "Portu — Balanced" | optional, otherwise "Cash CZK" |
| Currency | per the instrument's quote | account's default currency | account's default currency |
| Interest % p.a. | — | optional (savings account) | — |
| Account number for bank notices | — | optional, when editing the position — see [10. Bank notices](10_Bankovni_aviza.md) | — |

The same instrument on the same account can be in two portfolios (two positions).
The instrument, valuation mode and currency of a position cannot be changed after it is created — transactions
depend on them. Only a position without transactions can be deleted — a position with transactions can only be archived (when editing the position).

You open the **position detail** by clicking anywhere on the position's row (in the position list and on the Dashboard; Ctrl/⌘ + click
opens it in a new tab) or on its name. The **⋯** button at the end of the row offers more actions: **Detail**,
**New transaction**, **Transfer** (market position) and **Set balance** (cash). The detail shows the quantity held (net deposits for manual valuation),
the last price (in the display currency, like the average purchase price; the exchange price in the original currency is in smaller
type below it), the valuation (value, cost basis, return), FIFO lots
and sales for a market position, manual valuations for a manual position — see [7.3 FIFO lots](07_Prehled.md#73-fifo-lots) and [7.4 Manual valuation](07_Prehled.md#74-manual-valuation-portu-savings-accounts).
The header has the buttons **Edit**, **Transfer** (market positions only), **Delete position (only without transactions)**, **Set balance**
(cash only) and **New transaction**; all transactions are listed at the bottom.

### 4.5.1 Cash in an account

Free money in an account at an exchange or broker (EUR on Coinbase, CZK on XTB). For cash you do not enter deposits and withdrawals,
but the **balance**: the **Set balance** button in the position list or in the position detail → date and current balance.
The difference from the previous balance is recorded as a deposit (the balance went up) or a withdrawal (it went down). The value and the amount invested
thus match the balance and cash has no return — spending cash on a purchase does not show up as a loss
in the portfolio's return. Create one cash position for each currency in the account.

The balance can be set no earlier than the date of the last movement on the position. Interest and account maintenance fees can be recorded as
regular transactions. Manual valuation is not used for cash — nor is a balance from a bank notice (the notice records only the movement),
so the balance is always the sum of movements. In the dashboard and the allocation, cash belongs to the **Cash** class.

### 4.5.2 Pension savings and DIP

Pension savings and the DIP (dlouhodobý investiční produkt — long-term investment product) are investments — they count towards the return (TWR, XIRR, summaries), the allocation and net worth.

- **Pension savings** (doplňkové penzijní spoření — supplementary pension savings, transformovaný fond — transformed fund): an institution of type **Pension company**,
  an account (e.g. "Supplementary pension savings") and a **manual position**. Record only your own contributions as a **deposit**;
  do not record the state contribution and the employer contribution — they show up in the **manual valuation** per the statement and so count
  as return. Do not record them as interest either (interest goes into the tax report). In the allocation they belong to the
  **Funds and manual positions** class.
- **DIP at a broker** (XTB, Portu…): a separate account at the broker (e.g. "DIP") with regular market positions
  — buys and sells as in any other account, importing an export works the same way.
- **DIP at a pension company or fund**: a manual position like pension savings.
