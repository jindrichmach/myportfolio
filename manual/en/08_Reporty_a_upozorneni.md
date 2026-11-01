# 8. Reports, alerts and dividends

What the app sends you by e-mail on its own, and where to find summaries by period and received
dividends and interest. How value and return are calculated is in [7. Dashboard and calculations](07_Prehled.md).

## 8.1 Weekly report by e-mail

Every Monday at 8:00 you can receive an e-mail with an overview of the last 7 days. You turn it on in
**Account → Profile → Reports tab**.

| Field / button | Meaning |
|---|---|
| Send the weekly report | Turns on regular sending |
| Detailed overview | Checked = portfolios, accounts and individual instruments; unchecked = portfolios and accounts only |
| Monthly summary | On the 1st of the month you get a summary of the previous month ([8.3](#83-monthly-and-yearly-summary)); the Preview link shows the previous month |
| Yearly summary | On 1 January you get a summary of the previous year including a breakdown by month; the Preview link shows the previous year |
| Recipients | Up to 5 addresses separated by commas; empty = your sign-in e-mail |
| Preview | Opens the e-mail in a new window (nothing is sent) |
| Send now | Sends the report to the configured recipients right away |

What the report contains (in the display currency and language of your profile):

- **Summary** — value of the whole portfolio, change in value over 7 days, deposits and withdrawals, and return.
- **Portfolios → accounts → positions** — for each level the value a week ago and today, the change, deposits and
  withdrawals, and return; for an account with a single position, the position is listed directly under the account
  name. Without the **detailed overview** only portfolios and accounts.
- **Transactions** from the last 7 days.

| Indicator | Calculation |
|---|---|
| Change in value | value today − value 7 days ago |
| Deposits and withdrawals | purchases and deposits − sales and withdrawals in the period |
| Return | change in value − deposits and withdrawals; % = return / (value at start + half of deposits) |

For an account or portfolio without deposits and withdrawals, the return equals the change in value, so only the
change is shown in the row. The return of the whole portfolio is always in the summary at the top.

The figures come from the daily value history (§ 7.5), so they match the chart. A position without a current price
or rate has the value "—" and the totals are marked with an asterisk.

> 🛈 Sending requires SMTP to be configured ([11.4 Settings](11_Administrace.md#114-settings)). The `cron-weekly-report` job sends the report
> every time it runs — Monday 8:00 is set by the cron schedule (see [11.6 Scheduled jobs](11_Administrace.md#116-scheduled-jobs)). The monthly and yearly
> summaries are sent by the `cron-period-report` job (1st day of the month at 8:05); the recipients are the same as for the weekly report.

## 8.2 Alerts by e-mail

Once a day (7:30) you can receive a summary of alerts — only when there is something to report, and each alert
arrives only once. You set it up in **Account → Profile → Alerts tab**.

| Field / button | Meaning |
|---|---|
| Send alerts | Turns on the daily summary |
| Daily portfolio move (%) | Reports a portfolio (and all portfolios together) whose return yesterday was up or down by at least this many percent. Empty = off |
| Daily position move (%) | The same for individual positions. Empty = off |
| Holding test in (days) | Reports a lot that will pass 3 years of holding in this many days — a sale from the stated day is exempt from tax ([12. Tax report](12_Danovy_report.md)). Empty = off |
| Allocation out of band | A class whose share differs from the target by more than the tolerance ([9.1](09_Alokace_a_ciste_jmeni.md#91-target-allocation-and-rebalancing)); a persisting deviation is reminded at most once a month |
| Data issues | A held instrument without a price or with a stale price, stale CNB exchange rates, a bank notice without a linked position or with an error, an IMAP mailbox error |
| Recipients | Up to 5 addresses separated by commas; empty = your sign-in e-mail |
| Check now | Evaluates the alerts right away and sends them if there is something to report |

The return for the daily move is the change in value excluding deposits and withdrawals (the same calculation as in
[8.1](#81-weekly-report-by-e-mail)), for yesterday, in the display currency. The holding-period test alert leaves out
staking rewards — the small daily lots would flood the summary.

### 8.2.1 Price thresholds

In the same card you add a threshold: instrument, **above** / **below** and a price in the instrument's quote
currency (optionally a note). When the last price crosses the threshold, the summary reports it once, and the date
and price are shown at the threshold. The **Re-activate** button sets it again; editing the threshold also
re-activates it.

> 🛈 Sending requires SMTP to be configured ([11.4 Settings](11_Administrace.md#114-settings)) and the `cron-alerts` job
> (daily at 7:30, see [11.6 Scheduled jobs](11_Administrace.md#116-scheduled-jobs)).

## 8.3 Monthly and yearly summary

**Portfolio → Summaries** — a table and chart of return by month or year (the **Months / Years** switch),
optionally for a single portfolio or account only. Everything is in the display currency from the daily value
history (§ 7.5). The monthly table shows the last 12 months; older ones are revealed by **Show all**.

| Column | Meaning |
|---|---|
| Value at end | Value as of the last day of the period (for an ongoing period as of today); below it the value at the start |
| Deposits / withdrawals | Purchases and deposits − sales and withdrawals in the period |
| Return | Change in value − deposits and withdrawals; % = return / (value at start + half of deposits) |
| Dividends + interest | Gross, at the CNB exchange rate on the payment date; below it the withholding tax |
| Fees | Transactions of type Fee and fees on trades in fiat currency |
| Inflation | Czech inflation for the month (year: December / December, for the ongoing year to the last published month) and below it the real return ([7.5](07_Prehled.md#benchmark-and-inflation)) |

An asterisk at a value means that some position had no price or rate for that day and is missing from the total —
the return of such a period (and of the period following it) is not calculated and "—" is shown.
The monthly and yearly summary e-mails send the same figures ([8.1](#81-weekly-report-by-e-mail)).

## 8.4 Dividends and interest

**Portfolio → Dividends & interest** — dividends and interest received in the selected year (or all years):

- **Summary** — dividends and interest gross, withholding tax and the net amount.
- **Chart** by month of the selected year (without a year, by year), dividends and interest separately.
- **By position** and **By year**.
- **Payments** — each payout with the original amount and currency, the net amount and the withholding tax.

Amounts are in the display currency at the CNB exchange rate on the payment date. Withholding tax is a fee on the
dividend ([5.1 Transaction types](05_Transakce.md#51-transaction-types)). For your tax return, use the [12. Tax report](12_Danovy_report.md).
