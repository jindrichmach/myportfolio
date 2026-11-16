# 12. Tax report

**Portfolio → Tax report** — supporting data for the Czech personal income tax return for a calendar
year: sales of securities and crypto assets, foreign dividends and an overview of interest. Everything in CZK.

> ⚠️ Warning: The report is indicative supporting data, not tax advice. It is computed from the transactions in the app —
> a missing or wrongly entered purchase changes the result. Verify it before filing your tax return.
> Nothing is sent to the tax office — you copy the numbers into the tax return yourself, or hand them to an advisor.

| Option | Meaning |
|---|---|
| Year | Tax period (calendar year). The default is last year — that is the year the tax return is filed for. When there was nothing last year but there is something this year, the report offers the link **This year so far** |
| Rate | **Daily CNB rate** on the day of each trade, or the **Unified annual rate** for the year (average of CNB rates on the last day of each month — the same values the GFŘ (General Financial Directorate) publishes). A purchase is converted at the unified rate of the year of purchase. Within one year, use only one method for everything |
| CSV | Per-lot sales detail and dividends for Excel |
| Print | Print version without the menu |

The unified rate can only be computed for a closed year for which the app has CNB rates for all
12 months. When they are missing, the report says so.

## 12.1 Sales

Securities (stocks, ETFs, funds) and crypto assets are two separate blocks — gains and losses are not added up
between them. Within a block they offset each other in the year (a loss on one sale reduces the gain on another);
an overall loss is not carried forward and the base is 0.

Each sale is split into **lots** (purchases) using the FIFO method by acquisition date
(see [7.3 FIFO lots](07_Prehled.md#73-fifo-lots)). Each lot shows:

| Column | Meaning |
|---|---|
| Proceeds | Share of the gross sale price |
| Expense | Purchase price of the lot including the purchase fee + share of the sale fee. A fee paid in units of the asset being sold is a separate row "units spent on the sale fee" (proceeds 0, expense = their purchase price). A fee in a different crypto (e.g. BNB) cannot be valued at the CNB rate — it is not in the expenses and the row points this out |
| Acquired | Purchase date; a transfer between accounts keeps the acquisition date |
| Exemption | Why the lot is exempt, or "taxable" |

Exemption rules:

| Rule | Securities | Crypto assets |
|---|---|---|
| Limit | Total **gross proceeds** from sales in the year up to CZK 100,000 → all sales are exempt | The same, but only sales from 15 Feb 2025 count |
| Holding-period test | Lot held **longer than 3 years** (the sale date is after the 3-year anniversary) | The same, only sales from 15 Feb 2025 |
| Order | The limit first (the total includes lots that would pass the holding-period test); when it is exceeded, only lots passing the holding-period test are exempt | The same |
| CZK 40,000,000 cap | Year 2025: shared with crypto | Year 2025 shared with securities, from 2026 crypto only |

Sales of crypto assets until 14 Feb 2025 and in earlier years are taxable. Exchanging a cryptocurrency for a
stablecoin is a sale. Staking rewards enter the lots at their value when credited.

**Incomplete acquisition cost.** When the app does not know the full purchase price of a lot, the expense is zero or
lower and the tax comes out higher. The report then shows the number of such sales above it, and the reason on the
row in the detail:

| Note on the row | Cause | Fix |
|---|---|---|
| purchase missing | The sale is for more units than the position held at the time according to the transactions | Add the older purchases or a transfer from another account |
| reward without a price | A reward without an amount, and the instrument has no price on the day it was credited | Enter the amount on the reward |
| transfer without the outgoing side | An incoming transfer (typically from an import) without a matching outgoing one | Record the transfer from the original account with Transfer |
| purchase fee missing from the acquisition cost | The purchase fee is in a different crypto (e.g. BNB), which cannot be valued at the CNB rate | Claim it manually at its CZK value |

The note also carries over to the CSV export and the filing guide.

The partial tax base belongs in Příloha č. 2 (Attachment 2) of the tax return (other income under § 10).

> 🛈 Note: Positions with manual valuation (Portu, savings accounts) have no lots and are not among the sales.
> Portu issues its own tax statement.

## 12.2 Foreign dividends

Dividends by country — the country comes from the instrument's ISIN (first two letters), or from the **withholding country**
set on the instrument (it differs for ADRs, see [4. Accounts, portfolios and positions](04_Ucty_a_portfolia.md)); the report marks such a country "set on the instrument". For each country:

| Column | Meaning |
|---|---|
| Gross | Dividends before withholding tax |
| Withheld | Withholding tax (fee on a dividend — see [5.1 Transaction types](05_Transakce.md#51-transaction-types)) |
| Creditable | Withheld tax, at most 15 % of the gross dividend |
| Czech tax 15 % | Indicative tax on the gross dividend |
| To pay | Czech tax − creditable part |

A double taxation treaty may limit the credit below 15 % — check for the specific country.
It belongs in Příloha č. 4 (Attachment 4, separate tax base) or on row 38 with Příloha č. 3 (Attachment 3) — see [12.4](#124-tax-return-guide). Czech dividends (ISIN `CZ…`) were taxed by the payer through withholding; the report only mentions them.

## 12.3 Interest

Interest from savings accounts by position. A Czech bank has withheld the tax itself; it is not reported in the tax return —
the overview is for information only.

## 12.4 Tax return guide

Below the sales summary is the **Tax return guide** card. First you choose how you file your tax return — the app remembers
the choice and offers the output accordingly:

| Option | What you get |
|---|---|
| Myself in EPO / Moje daně | Values to copy into the rows of the DPFO form, in whole CZK |
| Through a tax advisor | A summary (taxable and exempt income, dividends, interest) and files to hand over: per-lot sales detail and the guide in CSV |
| With other software | CSV with form rows (section; row; description; CZK) and the sales detail |

If you have foreign dividends, you also choose the taxation method:

- **Separate tax base (§ 16a)** — Příloha č. 4: row 401a (gross dividends), 406, 409 (rounded down to hundreds of CZK),
  410 (15 % tax), 411–413 (credit for tax withheld abroad, at most 15 % and at most the tax from row 410), 414 → row 74a. No effect on progression and allowances,
  but deductions and tax credits cannot be applied against this tax.
- **General tax base** — dividends on row 38, credit via Příloha č. 3 for each country: row 321 (income) and 323 (tax
  paid abroad); row 322 (expenses) is not filled in for dividends. Rows 324–329 are computed by EPO from the whole tax return.

Sales go into Příloha č. 2: each type on a separate row of the table — **D** sale of securities, **C** crypto assets
(the form instructions do not list a code for crypto assets; C — sale of movable things is commonly used), then row 207 (income),
row 208 (expenses, for each type at most up to the income) and row 209 → row 40 of the main part. Exempt sales (holding-period
test, CZK 100,000 limit) are not reported in the tax return.

> ⚠️ Warning: Row numbers are verified against the forms for the years 2023–2025; for another year the guide warns you. The guide
> does not fill in the rest of the tax return (employment, tax credits, personal details) and EPO cannot load CSV — copy the values over and check them
> before filing. Interest from foreign accounts (at a broker) also belongs under § 8 — the report does not distinguish it from domestic interest.
