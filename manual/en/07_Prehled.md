# 7. Dashboard and calculations

The Dashboard is the app's home page (**Dashboard** in the menu). It shows how much your
investments are worth, how much went into them and how much they have earned. Everything is in the
**display currency**, which you set in your [profile](03_Prihlaseni_a_zabezpeceni.md#33-profile)
(CZK, EUR or USD).

## 7.1 How value and gain are calculated

| Figure | Calculation |
|---|---|
| Value | quantity × last price, converted at **today's** CNB exchange rate |
| Invested | cost basis of the units you still hold, converted at the **rate on the purchase date** |
| Unrealized gain | value − invested; for manual positions (savings account, Portu) without income and fees, which are already included in the value |
| Realized gain | proceeds from sales (at the rate on the sale date) − cost basis of the units sold (at the rate on the purchase date) |
| Income | dividends, interest and rewards (staking) at the rate on the date credited |
| Fees | separate fees to brokers and exchanges at the rate on the payment date |
| Total return | unrealized + realized gain + income − fees |
| % | total return / cost basis of all units ever bought |

Because the cost basis is converted at the rate on the purchase date, the gain in the display
currency also includes the effect of the exchange rate. For example, if you bought an ETF in USD and
the dollar has strengthened since, this shows up in the gain in CZK.

> 🛈 The figures on the Dashboard are not a basis for taxes — taxes are always calculated in CZK
> under the rules of the Czech Income Tax Act.

### Missing price or rate

When an instrument has no price or a currency rate is missing, the position's value is **not
computed**. Neither 0 nor a rate of 1 is ever used. The position is marked with a warning triangle in
the table and is not included in the totals. A warning with the number of such positions appears at
the top. You can fill in the price in [Prices & rates](06_Ceny_a_kurzy.md) or manually on the instrument.

## 7.2 Dashboard page

| Part | Content |
|---|---|
| Summary | **Portfolio value** with the total return in CZK and %, below it Invested, Unrealized gain, Realized gain, Income and Fees. Unrealized + realized gain + income − fees add up exactly to the total return; the ⓘ icon on each tile shows what it includes |
| Net worth | A separate panel (only if you keep one): investments + other assets − liabilities, with a link to the detail |
| Warnings | Positions without a price or rate, stale prices, missing manual valuations |
| Value over time | Chart and return metrics for a period — see § 7.5 |
| Allocation | Donut with shares by asset class, portfolio or institution (switch); the total in the middle, and hovering over a slice or legend row shows its amount and share. If you have a target allocation, **Actual vs target** by class is shown next to it |
| Crypto across accounts | Each cryptocurrency summed across all accounts (exchanges, wallets), total return in CZK and % (the same as for the positions in the table below, i.e. including sold units, rewards and fees); clicking a row expands that cryptocurrency's positions on the individual accounts — the same table as for portfolios (account, quantity, value, invested, return, ⋯ menu); the browser remembers expanded rows |
| Portfolios | Each portfolio as one row: number of positions, share of the whole, value and total return in CZK and %. Clicking expands a table of positions (quantity, value, invested, total return; for ETFs and stocks the fund or company name is shown under the ticker — the same ticker can mean a different fund on another exchange); sold positions with zero quantity are left out (the totals still include them, the Positions page shows all positions); the browser remembers expanded portfolios. **All positions** leads to the Positions page |

The **Add** button in the header offers **New investment** (a wizard: instrument by ticker, ISIN or name —
or creating a new one, where decimal places are prefilled by asset class (crypto 8, ETF, stock and fund 4)
and identifiers are offered only for the selected price source and its fallback, see [6. Prices & rates](06_Ceny_a_kurzy.md) —,
then account and portfolio; it uses an existing position, otherwise creates one and
immediately opens the entry of the first purchase), **New transaction** and **Import**. The **Allocation**
heading leads to the Allocation page.

Until you have any positions, the Dashboard shows a checklist of first steps instead of numbers (account,
portfolio, import or manual purchase) — the same path is described in [0. First overview in 5 minutes](00_Prvni_kroky.md).

In the allocation, manual positions are classed as **Cash** (a bank account) or **Fund**
(others, e.g. Portu).

## 7.3 FIFO lots

Each purchase or reward creates a **lot**: quantity, acquisition date and cost basis
(for a purchase including the fee). A sale takes units from the oldest lots of the given position
(**FIFO — first in, first out**) by acquisition date — a lot transferred from another account with an
older acquisition date is sold before a newer purchase on this account.

The detail of a market position has two tables:

- **Open lots** — what you still hold, since when and at what cost. A lot created by a transfer is
  marked "transfer (acquisition date kept)".
- **Disposals (FIFO)** — each sale broken down by lots: sale and acquisition date, number of days held,
  proceeds, cost basis and gain in the display currency. A row marked **Fee** is a fee paid in units
  of the instrument.

Lots are recalculated automatically after every transaction change (add, edit, delete, transfer).
On a transfer, the lots move to the target position together with the original acquisition date —
see [Transfer between accounts](05_Transakce.md#53-transfer-between-accounts).

A reward (staking) is valued at the transaction's amount. Without an amount, the instrument's price on
the date credited is used. If even that is missing, the reward has a cost basis of 0 and the position
shows a warning.

## 7.4 Manual valuation (Portu, savings accounts)

For a manually valued position, the value is calculated as follows:

1. The **last manual valuation** is taken (the balance according to a statement as of a date).
2. Deposits and interest **after that date** are added, and withdrawals and fees are subtracted.
3. Without a valuation, the value is net deposits + interest − fees.

Total return = value − net deposits.

You enter a valuation in the position detail in the **Manual valuations** section:

| Field | Meaning |
|---|---|
| Date | The day the balance applies to; not in the future |
| Balance | Value of the position in the position's currency |
| Note | Optional, e.g. "statement 12/2024" |

A new valuation for the same day overwrites the previous one. You delete a valuation with the
**Delete** button (bin) in the list.

> 💡 For a savings account where you record all deposits, withdrawals and interest, you do not need
> a valuation — the value is exact. For an investment service such as Portu, enter the balance from
> the statement, say, once a month; otherwise the position shows only the money deposited and the
> warning **No manual valuation**.

## 7.5 Value over time and return metrics

The **Value over time** chart is on the Dashboard and in the detail of each position. The solid line
is the value, the dashed line is the invested amount (cost basis of the units held, for manual
positions net deposits). The area between them shows a gain (teal, value above the invested amount)
or a loss (red). Each day's value is calculated the same way as on the Dashboard: using the price and
CNB exchange rate valid on that day. On hover (on mobile on touch), a vertical line shows the day and
a label with the values of all lines.

| Control | Meaning |
|---|---|
| Period | 1M, 3M, YTD, 1Y, All (since the first transaction) |
| Selection (Dashboard only) | Total, a single portfolio, or an asset class (crypto, ETF, cash…) |
| Comparison (benchmark) | **No comparison**, or **vs.** any instrument from the catalog (Bitcoin is offered first); the choice applies to all charts |

The metrics above the chart refer to the selected period. They are in two groups, **This period**
(gain, return, net deposits) and **Performance** (TWR, XIRR, inflation, benchmark). Performance is
collapsed under **Details** — you expand it with one click and the choice is remembered; the ⓘ icon
on each metric shows a short explanation (on hover; a tap or click keeps it open, and the next tap,
a click outside or Escape closes it):

| Metric | What it tells you |
|---|---|
| Gain in period | ending value − starting value − net deposits |
| Return | gain / (starting value + deposits in the period) |
| TWR | time-weighted return — how the investments themselves performed regardless of when and how much you put in; comparable with an index. For a period longer than a year, also annualized |
| XIRR | annual rate of return on your money — takes the timing of deposits and withdrawals into account |
| Net deposits | purchases and deposits minus sales, withdrawals and paid-out dividends / interest |
| Czech inflation | price growth over the period (HICP index) and below it the **real TWR** — return after deducting inflation |
| Benchmark (symbol) | TWR of the selected instrument over the period and the gain if your money had gone into it |

> 💡 TWR and XIRR can differ significantly. If you bought a lot before a drop, XIRR will be worse
> than TWR; if you bought more at a discount, it will be better.

### Benchmark and inflation

The **benchmark** shows with a third line how much you would have if the starting value and every
deposit and withdrawal on the same day had gone into the selected instrument (at that day's price and
CNB exchange rate). Thanks to identical flows, only the choice of investments is compared, not the
timing. After you select an instrument, its price history is downloaded from your first transaction
and kept up to date by the `cron-prices` job. When the benchmark has no prices at the start of the
period, a warning appears below the chart. In the legend, the line is labelled **Same deposits into …**
(e.g. Same deposits into VWCE). In the detail of a position in the same instrument (BTC against BTC)
the benchmark is not shown — the comparison would say nothing.

**Czech inflation** is the Harmonised Index of Consumer Prices (HICP) from Eurostat — it differs only
slightly from the national inflation published by ČSÚ (Czech Statistical Office). It is measured from
the month before the start of the period to the last published month (Eurostat publishes it in the
middle of the following month). Real return = (1 + return) / (1 + inflation) − 1, where inflation from
the available months is converted to an annual rate and that to the same length as the return period
(the index for the last months may not be available yet); for a period longer than a year, the real
annualized TWR and real XIRR are also shown below the chart. Without a published index "—" is shown,
never 0 %. In the yearly summary, inflation is measured from the same month from which the return is
calculated.

A transfer between your own accounts does not show up as a deposit in the total or in a portfolio
(the outgoing and incoming transfer cancel out); in the chart of a single position it does.

A day on which some position is missing a price or rate is incomplete in the total — a warning
appears below the chart and the metrics skip such a day.

### When the history is recalculated

The history is stored per day and recalculates itself:

- after adding, editing or deleting a transaction and after a change of a manual valuation —
  from the date of the change, the next time the chart is opened;
- after historical prices are filled in or a manual price is entered;
- every night the `cron-snapshot` job recalculates the last 7 days
  (catching up on prices and rates).

Today is recalculated every time the chart is opened, so it matches the Dashboard.
