# 9. Allocation and net worth

How your assets are spread compared with your target, a suggestion of where to put more money, and your total wealth including real estate and loans.

## 9.1 Target allocation and rebalancing

**Portfolio → Allocation** — set a target asset allocation and see how far you are from it and where to put
more money. At the top you choose the scope: **All holdings**, or a single portfolio (each can have its own targets).
Everything is included, manual positions (Portu, savings accounts) and cash too, in the display currency.

| Class | What belongs to it |
|---|---|
| Crypto, ETF, Stocks | Market positions by instrument class |
| Funds and manual positions | Manually valued positions (Portu, funds) |
| Cash | Cash in accounts and bank savings accounts |

Classes have colours by risk — from the most volatile crypto (red) through stocks (orange), ETFs (yellow)
and funds (turquoise) to conservative cash (blue). The allocation donut on the Dashboard uses the same colours.

**Set targets** (**Edit targets** once targets are saved) — for each class a target share (summing to 100 %) and a tolerance in percentage points
(empty = 5). For crypto, ETFs and stocks you can tick **split by instrument** and enter the shares of
instruments within the class (also summing to 100 %); a held instrument without a target has a target of 0 %. Without targets the form
is prefilled with today's allocation. **Delete targets** removes the whole set for the selected scope.

- **Actual and target** — the class value and share, target ± tolerance and the deviation in currency (+ above target).
  The bar shows the share, the vertical tick the target. A class outside tolerance has a label, and the **Dashboard** shows,
  next to the allocation, the number of classes outside tolerance (all holdings). Click a class to expand its instruments.
- **I plan to deposit** — enter an amount and get a suggestion of purchases without selling: the deposit is split among the classes
  (and instruments) that are furthest below target after the deposit, in proportion to the shortfall.
- **Full rebalancing** — sales and purchases after which the allocation will be exactly on target. Selling a class without
  instrument targets is split in proportion to the position values. Each position being sold shows an estimate of units
  and how many of them come from lots younger than 3 years (FIFO) — such a sale may be taxable
  ([12. Tax report](12_Danovy_report.md)).

> 🛈 Note: Positions without a price or exchange rate are not included in the allocation — the page lists them at the top.
> The suggestions are indicative: they do not account for fees, and a purchase per class (without instrument targets) leaves the choice
> of instrument to you.

## 9.2 Net worth

**Portfolio → Net worth** — your total wealth: **investments** (valuation of all positions including cash, the same as on the
Dashboard) + **other assets** − **liabilities**, in the display currency. Supplementary pension savings (penzijko) and DIP (long-term investment product) are investments — they are kept as
positions ([4.5.2](04_Ucty_a_portfolia.md#452-pension-savings-and-dip)) and so count towards net worth automatically. At the top are tiles, below them a chart of the history
(net worth, investments and liabilities by day) and the items by kind. Once you have at least one item,
net worth is also shown as a tile on the **Dashboard**.

| Kind | How it is valued |
|---|---|
| Real estate | Manually entered value on a date (estimate, appraisal); between valuations the last one holds |
| Other assets | Manually entered value on a date (car, stake in a company, collection…) |
| Liabilities | Loan with annuity payments — the balance is computed for each day |

**Add item** — kind, name, currency (a foreign currency is converted at the CNB exchange rate) and optionally a first valuation. For a liability
you enter the **Drawdown date**, **Principal**, **Interest % p.a.** and **Monthly payment**: payments are deducted monthly on the day
of drawdown (interest = balance × rate / 12), and the balance never drops below zero. A loan shows an estimated payoff date; when
the payment does not cover the interest, a warning is shown.

The **Valuations** button (**Balances** for a liability) records a new value on a date — the same day is overwritten:

- **Asset** — the new value; when selling, enter 0 on the sale date and then archive the item (Edit → Archive),
  so the history is correct.
- **Liability** — the balance on a date after an extra payment, refixing, or per the bank statement; optionally a new interest rate
  and payment (empty = unchanged). Amortization continues from this date. Below the list is a 12-month balance outlook.

A paid-off loan (balance 0) has a **Paid off – archive** button: it disappears from the list, while the net worth history
stays. Show archived items with the **Show archived** toggle — they are greyed out and not included in totals;
you undo archiving in Edit.

> 🛈 Note: Return metrics (TWR, XIRR, summaries), allocation and the tax report do not include net worth items —
> they are only an overview of your assets. The net worth chart is based on the daily investment history, so it starts with the first transaction.
