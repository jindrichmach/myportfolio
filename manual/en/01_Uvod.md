# 1. Introduction — what MyPortfolio can do

MyPortfolio is a **self-hosted overview of personal investments** in one place: cryptocurrencies,
ETFs and stocks, the Portu robo-advisor and cash in savings accounts. It runs on your own
server or in Docker, and the data stays with you.

One installation serves **multiple users** — each sees only their own data. Only the
catalog of institutions and instruments, and prices and exchange rates, are shared.

This manual is also available directly in the app: **Account → Manual** (an administrator has it in the **System** section). Chapters
are in vertical tabs on the left, and the open chapter also shows its sections. The **Search the manual** field at the top searches
all chapters (ignoring diacritics) and shows matching sections with a snippet. Chapters 2. Installation and 11. Administration
are visible only to administrators. Many screens have a **How is it calculated?** link leading to the relevant section.

## 1.1 Basic terms

| Term | Meaning | Example |
|---|---|---|
| Institution | Where you hold the asset | Coinbase, XTB, Portu, Air Bank |
| Instrument | What you hold (shared catalog) | BTC, ETH, CSPX, VWCE |
| Account | Your account at an institution | "XTB — Investment account" |
| Portfolio | Your grouping of positions | "Long-term", "Speculation" |
| Position | An instrument in an account within a portfolio, or a manually valued amount | BTC at Coinbase in the Long-term portfolio |
| Transaction | What happened to the position | buy, sell, deposit, transfer, dividend |

## 1.2 Two kinds of positions

- **Market** — crypto, ETFs, stocks. Value = number of units × market price. Units are
  calculated from transactions (buy, sell, reward, transfer, split).
- **Manual valuation** — a Portu strategy, a savings account. Deposits and withdrawals are recorded,
  and you enter the current value manually (optionally with an annual interest rate).

## 1.3 Precision

All amounts, prices and quantities are calculated **exactly in decimal arithmetic**
(up to 18 decimal places for quantities) — no rounding errors as with floating-point
numbers. The app does not let you sell more units than the position held
at that moment.

## 1.4 Controls

On a computer, the left menu can be collapsed to icons only with the **Collapse menu** button at the bottom of the menu (the item name is then shown
in a tooltip on mouse hover); the choice is remembered. Accounts, portfolios and the shared catalog of instruments and institutions are
grouped together in the menu under **Accounts & catalog**.
Menu groups can be collapsed by clicking their heading; this is remembered too. The **System** group (admin only) is
collapsed initially, and the group containing the currently open page expands automatically.

Top bar on the right: the GitHub icon opens the project repository in a new tab, the book icon opens this manual.
The theme icon (monitor / sun / moon according to the current choice) offers **System**, **Light** and **Dark**,
the globe icon with the language code switches **Čeština** / **English**. Clicking your name opens a menu with your name,
e-mail and role, links to the profile tabs (see [3.3 Profile](03_Prihlaseni_a_zabezpeceni.md#33-profile)),
**Lock** and **Sign out**. On a phone the bar is the same, just without the name (the circle with initials remains);
the choice of whose data you view (sharing) is at the top of the opened menu there.

With the keyboard: the first Tab press shows a **Skip to content** link that skips the top bar and the menu.

A whole position row is clickable and opens the detail. Item actions (table row, card) are icon buttons. The action name is shown
in a tooltip on mouse hover or when moving with the Tab key. Rare and irreversible actions (Transfer, Revert import,
Deactivate user, Revoke token) have their name written right next to the icon.

| Icon | Action |
|---|---|
| pencil | Edit / rename |
| trash can (red) | Delete, remove, cancel or leave a share |
| arrow › | Detail |
| three dots ⋯ | Menu of further row actions (for positions: Detail, New transaction, Transfer, Set balance) |
| up / down arrows, handle ⠿ | Move up / down, drag with the mouse (portfolio order) |
| price tag | Set price, Set balance, Valuations / Balances |
| two arrows | Transfer between accounts |
| lightning bolt | Test connection |
| circular arrows | Read now, Sync now, Re-activate, Resend invitation |
| curved back arrow (red) | Revert import |
| play | Run now |
| crossed-out circle (red) | Deactivate user / revoke token |
| shield | Two-factor authentication (TOTP) |

### Lists with filters

Longer lists have a filter bar above the table. Each filter has a label above it. Lists with text search
(accounts, instruments, institutions, users…) have **Search** as the first filter; Positions and Transactions are filtered only
by selection (portfolio, account, type, period). On mobile, all filters except the first are hidden under the **Filters** button
with the number of active ones.
The **Clear filter** button appears only when some filter is set, and resets all of them to their defaults.

Below the table there is the record count ("1–25 of 137"), numbered pages (first, last and a few around the current one,
with "…" in between) and a **Per page** choice (10, 25, 50 or 100). Changing a filter or the page size returns
the list to the first page. Filters are reset when the page is reloaded.

## 1.5 What MyPortfolio can do

| Area | Chapter |
|---|---|
| Accounts, portfolios, positions, transactions, transfers between accounts | [4](04_Ucty_a_portfolia.md), [5](05_Transakce.md) |
| Cash in exchange and broker accounts (entered balance) | [4.5.1](04_Ucty_a_portfolia.md#451-cash-in-an-account) |
| Pension savings and DIP (Czech long-term investment product) | [4.5.2](04_Ucty_a_portfolia.md#452-pension-savings-and-dip) |
| Import from exchanges and brokers (Coinbase, Anycoin, Coinmate, Binance, Kraken, XTB, Trading 212, Revolut, DEGIRO, Interactive Brokers) | [5.5](05_Transakce.md#55-import-from-an-exchange--broker) |
| Connecting exchanges via API (Coinbase, Binance, Kraken, Coinmate) with automatic synchronization | [5.6](05_Transakce.md#56-connecting-an-exchange-via-api) |
| CNB exchange rates, crypto and ETF prices, manual prices | [6](06_Ceny_a_kurzy.md) |
| Valuation, profit/loss (FIFO), dashboard | [7.1](07_Prehled.md#71-how-value-and-gain-are-calculated) |
| Value history, return metrics (TWR, XIRR), benchmark, inflation and real return | [7.5](07_Prehled.md#75-value-over-time-and-return-metrics) |
| Weekly report, monthly and annual summary by email | [8.1](08_Reporty_a_upozorneni.md#81-weekly-report-by-e-mail) |
| Email alerts (price thresholds, daily move, holding-period test, allocation, data problems) | [8.2](08_Reporty_a_upozorneni.md#82-alerts-by-e-mail) |
| Monthly and annual summaries, overview of dividends and interest | [8.3](08_Reporty_a_upozorneni.md#83-monthly-and-yearly-summary), [8.4](08_Reporty_a_upozorneni.md#84-dividends-and-interest) |
| Target allocation and rebalancing | [9.1](09_Alokace_a_ciste_jmeni.md#91-target-allocation-and-rebalancing) |
| Net worth (real estate, other assets, loans) | [9.2](09_Alokace_a_ciste_jmeni.md#92-net-worth) |
| Bank email notices (Fio, Air Bank, Moneta, Raiffeisenbank, ČSOB, Česká spořitelna, CREDITAS, UniCredit) | [10](10_Bankovni_aviza.md) |
| Tax report (securities, crypto, foreign dividends, interest) and supporting data for the tax return | [12](12_Danovy_report.md) |
| Sharing with a partner and household overview | [13](13_Sdileni.md) |
| API tokens and API description (OpenAPI) | [3.6](03_Prihlaseni_a_zabezpeceni.md#36-api) |
