# 5. Transactions

Transactions are the **source of truth** — the quantity held, and from it
the cost basis, gain and taxes, are calculated from them. You create them in the **position detail** (New transaction)
or in the **Portfolio → Transactions** overview, where you also choose the position.

## 5.1 Transaction types

| Type | Market position | Manual valuation | What to fill in |
|---|---|---|---|
| Buy | ✅ | — | quantity + total amount **or** unit price |
| Sell | ✅ | — | quantity + total amount **or** unit price |
| Reward / staking | ✅ | — | quantity, optionally the value at the time it was credited |
| Split | ✅ | — | ratio (10 = 1 unit becomes 10; 0.5 = 2:1 reverse split) |
| Deposit | — | ✅ | amount |
| Withdrawal | — | ✅ | amount |
| Dividend, Interest | ✅ | ✅ | amount |
| Fee | ✅ | ✅ | amount (a standalone fee, e.g. for account maintenance) |
| Transfer | ✅ | — | created with the **Transfer** button — see [5.3](#53-transfer-between-accounts) |

A split divides or merges the units of all lots of the position; the acquisition cost and acquisition date stay. When the
broker pays out a fraction of a unit in cash in a reverse split, record it after the split as a **Sell** of that fraction for
the amount paid.

## 5.2 Form

| Field | Meaning |
|---|---|
| Date | Date and time of execution; must not be in the future |
| Quantity | Units / coins, up to 18 decimal places |
| Total amount / Unit price | For buys and sells, a toggle for how you enter the price: **Total amount** (gross trade amount **without** the fee) or **Unit price**. You fill in only one, the other is calculated from the quantity |
| Amount | For other types (deposit, withdrawal, dividend, interest…) the amount of the movement |
| Currency | A choice of CZK and currencies with a CNB exchange rate; the default is the position's currency (can be changed, e.g. SOL paid in CZK) |
| Fee + Fee currency | E.g. `12.5 EUR` or `0.00005 BTC`; the currency can also be a cryptocurrency symbol |
| Note | Optional |

How the fee is accounted for:

| Fee | Buy | Sell |
|---|---|---|
| In a fiat currency (EUR, CZK…) | added to the cost basis | reduces the sale proceeds |
| In the instrument's currency (e.g. BTC for a BTC position) | reduces the quantity bought | consumes additional units (FIFO) |
| In another cryptocurrency (e.g. BNB) | only recorded | only recorded |

You can write numbers with a decimal **comma or point**; spaces as thousands separators are
allowed (`1 234,56`).

> ⚠️ **You cannot sell more than the position held.** The whole history is checked:
> a sale dated before the purchase is rejected even if today's state is fine.
> The same applies to a withdrawal exceeding the sum of deposits. To fix it, first edit or add
> an earlier transaction.

## 5.3 Transfer between accounts

When you move coins from an exchange to a wallet (or between exchanges), it is not a sale.
In the detail of a market position, click **Transfer**:

| Field | Meaning |
|---|---|
| To position | A position of the **same instrument** in another account (create it beforehand) |
| Quantity | How much is being moved; the **All** button fills in the whole holding (minus a fee in the transferred currency) |
| Fee + currency | Optional; enter the network fee in the transferred currency (e.g. `0.00005 BTC`) |

Two linked transactions are created: **Transfer out** and **Transfer in**.
The cost basis and the acquisition date are carried over (important for the holding-period test for taxes).
A fee in the transferred currency reduces the quantity, but its cost basis passes to the
transferred units — so a transfer creates neither a gain nor a loss. A fee in a fiat currency
is counted as a separate cost of the position.
A transfer cannot be edited; deleting one part deletes the whole transfer.

## 5.4 Transaction overview

**Portfolio → Transactions** — all transactions with the **Portfolio**, **Account**,
**Type** and **Period** filters (see [1.4 Lists with filters](01_Uvod.md#lists-with-filters)). Each row has a link to the position,
Edit and Delete. The transactions of a single position are also in its detail, with the same pagination.

The quantity has a sign by the direction of the movement of units: **+** when units are added to the position (buy,
reward, transfer in), **−** when they are removed (sell, transfer out). The direction is colour-coded by the transaction type badge.
The amount has a sign by the cash flow: **+** when money came in (sell, dividend, interest, deposit), **−** when it went out
(buy, withdrawal, fee). Rewards and transfers have no sign.

## 5.5 Import from an exchange / broker

**Portfolio → Import** (or the **Import** button on the Transactions page) — you upload a transaction export
from an exchange or broker, check the preview and confirm. Nothing is written until you confirm
the import.

| Source | Which file to download |
|---|---|
| Coinbase | Statements → statement (CSV), also a Coinbase Pro account statement — [guide](#551-export-from-coinbase) |
| Anycoin | Transactions → All currencies → Export (CSV), or Orders → Export (CSV) — [guide](#552-export-from-anycoin) |
| Coinmate | History → CSV All (Czech or English) — [guide](#553-export-from-coinmate) |
| Binance | Export Transaction Records (CSV or XLSX) — [guide](#554-export-from-binance) |
| Kraken | Ledgers (`ledgers.csv`, also in a ZIP) — [guide](#555-export-from-kraken) |
| XTB | History → Cash operations → Export (XLSX, older CSV) — [guide](#556-export-from-xtb) |
| Trading 212 | History → Export (CSV) — [guide](#557-export-from-trading-212) |
| Revolut | Invest → Documents → Account statement — [guide](#558-export-from-revolut) |
| DEGIRO | `Transactions.csv` (trades) and `Account.csv` (dividends) — separately or together in a ZIP — [guide](#559-export-from-degiro) |
| Interactive Brokers | Activity Statement (CSV) or Flex Query (CSV) — [guide](#5510-export-from-interactive-brokers) |

| Field | Meaning |
|---|---|
| Account | The exchange / broker account the export comes from — positions are written into it |
| Portfolio | The portfolio in which new positions are created |
| Source | Usually detected automatically from the file header; otherwise choose it — a short hint and a link to the guide appear below the field |
| File | CSV, XLSX or ZIP, at most 20 MB |

The guides below describe how to download the export. Exchanges and brokers rename their menus from time to
time — if a label differs, look for a similarly named option. Always download the **whole history** (since the
account was opened) in **CSV** or **Excel** format, not PDF. Overlapping files are fine — already imported rows
are skipped ([5.5.12](#5512-repeated-import)).

### 5.5.1 Export from Coinbase

Download the transactions CSV from the **Statements** page:

1. Sign in at coinbase.com.
2. Click your profile avatar in the top right → **Settings** → **Accounts** and choose **Statements** in the menu.
   You can also open the page directly: `coinbase.com/statements`.
3. Choose one of the options:
   - **Monthly statement** — pick the month, choose the **CSV** format and download it.
   - **Custom statement** — select the asset (e.g. **All assets**), transaction type (**All transactions**)
     and period, choose **CSV** and click **Generate**. Useful for the whole history at once.

Crypto-to-crypto swaps (Convert, Advanced Trade) are recorded as a sell + buy.

A statement from the former **Coinbase Pro** is on the same page under the **Other** tab. Download the account
statement as CSV (columns `portfolio, type, time, amount, balance, …`) and import it as Coinbase — the format is
detected automatically:

- a trade (`match` and `fee` rows with the same order and trade id) = a buy or sell with a fee,
- a trade for USDT or USDC = a buy + a sell of the stablecoin valued in USD,
- a crypto-to-crypto trade with no fiat or stablecoin side (e.g. DOGE → BTC) is skipped — enter it manually,
- a crypto deposit and withdrawal = an incoming and outgoing transfer; fiat (EUR, USD) deposits and withdrawals are not imported.

Official help:
[Coinbase — statements](https://help.coinbase.com/en/coinbase/taxes/tools/statements),
[Coinbase Pro — cost basis](https://help.coinbase.com/en/coinbase/managing-my-account/tax-locate-cost-basis-document).

### 5.5.2 Export from Anycoin

Anycoin offers two exports; one of them is enough.

- **Transaction history** (recommended): **Můj účet → Transakce → Všechny měny → Export (CSV)**
  (My account → Transactions → All currencies). It contains trades, deposits, withdrawals to a wallet, staking and rewards.
- **Orders**: the **Objednávky** (Orders) page (`anycoin.cz/cs/orders`) → **Export** (CSV). Buys and sells only.

Crypto withdrawals to your own wallet appear in the preview as **Send** rows — in **Sends and receives** choose the other side of the transfer,
or let them be skipped. Cancelled orders, withdrawal blocks and loans are skipped. You can combine both exports:
a trade already imported from the other export is paired and skipped ([5.5.11](#5511-preview)).

### 5.5.3 Export from Coinmate

1. On the web, open the menu in the top right → **History**.
2. Click **CSV All** — the whole history is downloaded.

The file header is in Czech or English depending on the interface language; the import handles both. Crypto-to-crypto
trades (e.g. ETH/BTC) are not imported — enter them manually.

### 5.5.4 Export from Binance

1. On the web, **Assets → Asset History** (or **Wallet → Transaction History**).
2. Hover over the export icon and choose **Export Transaction Records**.
3. Choose the period, **all accounts and all coins** (older periods via **Customize**) → **Generate**.
4. When the file is ready (you get an SMS / e-mail, it can take hours), download it in the
   **Export Transaction Records** window — the link is valid for 7 days.

One export covers at most a year and only a limited number can be created per month — download a longer history
year by year and upload the files one after another. Don't confuse it with **Orders → Spot Order → Export Trade
History**, which is a different file. Futures and margin are not imported. Official help:
[Binance — Transaction History](https://www.binance.com/en/support/faq/990afa0a0a9341f78e7a9298a9575163).

### 5.5.5 Export from Kraken

1. On the web, click your profile → **Documents**.
2. In the **Exports** section choose **Create Export → Ledgers**.
3. Set the start of the period to the date the account was opened, keep all assets and all fields selected,
   format **CSV** → **Generate**.
4. The export takes minutes to days to prepare and no e-mail is sent — come back to **Documents** and download it
   once the download icon is active.

You can upload the downloaded ZIP directly, or the `ledgers.csv` from it. Official help:
[Kraken — export account history](https://support.kraken.com/articles/208267878-how-to-export-your-account-history).

### 5.5.6 Export from XTB

1. In xStation 5 open the **History** tab → **Cash operations**.
2. Set the period from the account opening and export to **Excel**.

In the mobile app: profile → **Transaction history** → **Cash operations** → arrow in the top right →
**New report** → period and account → **Generate report**; the file is in the report history.

Export each account (including currency sub-accounts) separately and upload it to the matching account in
MyPortfolio — amounts are in the account currency. The import reads quantity and price from the operation comment.
Official help:
[XTB — history of cash operations](https://www.xtb.com/int/help-center/our-platforms/history-of-cash-operations-web-and-mobile-app).

### 5.5.7 Export from Trading 212

1. ☰ Menu → **History** → the export icon in the top right.
2. Choose the period (at most 1 year) and tick all data types — orders, dividends, transactions.
3. When the file is ready, download the CSV.

Export a longer history year by year. Export Invest and ISA accounts separately; an export from a CFD account is
not imported. Official help:
[Trading 212 — export trading data](https://helpcentre.trading212.com/hc/en-us/articles/360016898917-Can-I-export-the-trading-data-from-my-account).

### 5.5.8 Export from Revolut

1. In the app open **Invest** → **More** → **Documents** and choose the brokerage account.
2. Choose **Account statement**, format **Excel** and the period from the start of trading.

Dividends are listed at the net amount (without withholding tax) and a split lists only the added units — enter
splits manually. Money market funds (Flexible Cash Funds) are not in the stock statement.

### 5.5.9 Export from DEGIRO

You find both files in the **Inbox** — on the web in the left menu, in the app in the bottom right:

- **Transactions** — trades, file `Transactions.csv`;
- **Account statement** — dividends, file `Account.csv`.

For both, set the **start date** before your first trade (the default is just the last month), click export and
choose **CSV**. Upload the files separately, or both in one ZIP. Tax PDFs are not imported. Official help:
[DEGIRO — reports](https://www.degiro.com/uk/helpdesk/tax/tax-treaties/which-reports-are-there-and-where-can-i-find-them).

### 5.5.10 Export from Interactive Brokers

**Activity Statement** — one-off:

1. In Client Portal, **Performance & Reports → Statements**.
2. Next to **Activity**, click the arrow (Run), period **Annual** and the year.
3. In **Advanced Options** choose format **CSV** → **Download**.

A custom range is at most 365 days — download the history year by year.

**Flex Query** — if you download regularly:

1. **Performance & Reports → Flex Queries** → **+** next to **Activity Flex Query**.
2. Add the **Trades** and **Cash Transactions** sections (select all fields in each), format **CSV** → **Create**.
3. Run the query, choose the period and download the file.

Stocks and ETFs are imported; a dividend is combined with its withholding tax. Official help:
[IBKR — Activity Flex Query](https://www.ibkrguides.com/clientportal/performanceandstatements/activityflex.htm).

### 5.5.11 Preview

- **Summary** — how many rows are new, already imported, paired and skipped.
- **Instruments** — for each symbol from the export, choose an **Existing** instrument from the catalog,
  **Create new** (symbol, name, class and quote currency are prefilled) or **Skip** it.
  Known instruments are matched automatically by ISIN or symbol.
- **Sends and receives** (crypto) — a transfer between your own accounts keeps the cost basis and the acquisition
  date. Choose the other side: an existing position, or another account (a position is created). Without a choice
  the row is skipped.
- **Rows** — all movements with their status; skipped ones show the reason.
- **Paired** — a buy or sell you have already entered manually on the account (same instrument, type and quantity,
  time at most a day apart). The import doesn't write it twice: it overwrites the manually entered transaction with
  the time, amount and fee from the export (the note is kept) and assigns it to the source, so the next import
  recognises it as already imported. Reverting the batch doesn't delete paired transactions. A transaction from
  another import is just skipped.

| In the export | In MyPortfolio |
|---|---|
| Buy, sell | Buy, Sell (amount without the fee + fee) |
| Crypto-to-crypto swap with a fiat value (Coinbase Convert) | Sell + Buy of the same value |
| Crypto-to-stablecoin swap (USDC, USDT…) | Sell + Buy valued in USD |
| Crypto-to-crypto swap without a fiat value | skipped — enter manually |
| Staking, rewards, earn | Reward |
| Dividend with withholding tax | **Dividend** at the gross amount, the tax as a fee |
| Split | Split (skipped for Revolut, which lists only the added units) |
| Cash deposit and withdrawal, currency exchange, interest on cash, card | skipped |
| Futures, margin, options, CFDs, forex | skipped |

> 🛈 Note: Revolut lists dividends only at the net amount — the withholding tax is missing from the statement.
> Trading 212 lists the withholding tax in the instrument's currency; the dividend is then recorded in that currency.

### 5.5.12 Repeated import

Each row remembers its ID from the export (or a fingerprint of the row if the export has no ID). You can upload the same
or an overlapping file again — already imported rows are skipped.

### 5.5.13 Reverting an import

At the bottom of the page there is the **Import history**. The **Revert import** button deletes all transactions
of the batch (both parts of a transfer). If later transactions would stop adding up after the revert (selling
more units than the position holds), the revert is rejected.

> 💡 Tip: The preview is valid for 24 hours. Positions created by the import remain empty after a revert — delete them manually.

## 5.6 Connecting an exchange via API

**Account → Exchanges (API)** — instead of uploading exports manually, MyPortfolio downloads movements directly
from the exchange. Supported exchanges: **Coinbase**, **Binance**, **Kraken** and **Coinmate** (Anycoin has no API —
CSV import remains).

On the exchange, create a **read-only** API key — without permission to trade or withdraw. The key is stored
encrypted and nobody can see it in the app again (the list shows only the beginning of the public part).

| Exchange | What to enter | Required key permissions |
|---|---|---|
| Coinbase | Key name (`organizations/…/apiKeys/…`) and private key (CDP / Advanced Trade) | View |
| Binance | API key and Secret key | Enable Reading |
| Kraken | API key and Private key | Query Ledger Entries |
| Coinmate | Client ID, Public key, Private key | Read only (no trading or withdrawals) |

| Field | Meaning |
|---|---|
| Account | The exchange account — positions are written into it (as with import) |
| Portfolio | Where new positions are created |
| Download from | **Continue from the last import** (from the last transaction in the account, e.g. after an earlier CSV import), **From date**, or **Full history** |
| Sync automatically | A disabled connection downloads data only with the **Sync now** button |

The **Verify key** button checks the key before saving. The app does not save a key with permission to trade or
withdraw (Binance, Kraken and Coinbase can detect it) — create a read-only key on the exchange. The administrator can
allow it in exceptional cases in `cfg.php` (`exchanges.allow_dangerous_keys`); then you only see a warning. Coinmate
cannot verify permissions, so you always see the warning for it.

### 5.6.1 How synchronization works

- Enabled connections are synchronized **every hour** (the `cron-exchange-sync` job), manually
  with the **Sync now** button.
- Movements are converted the same way as when importing a file ([5.5](#55-import-from-an-exchange--broker)) and written
  as an import batch — it can be reverted in the **Import history**.
- When there is nothing to decide, everything is written right away. When a **new instrument** or a crypto
  **send / receive** appears, the synchronization stops in the **Awaiting decision** state: the
  **Open import preview** link opens the preview in Import, where you decide and confirm.
- Each synchronization also re-downloads the last week (movements the exchange completes with a delay);
  already written movements are skipped by ID. Whatever you skipped once in the preview, the connection remembers and does not
  offer again; a new movement that the exchange delivered with a delay, however, always waits for a decision.

> 🛈 Note: Coinbase does not list the fee for a simple buy / sell (Buy / Sell) in its API —
> the amount includes the fee. For Binance, trades are looked up for pairs of assets you hold on the exchange, that appeared
> in the history (deposits, withdrawals, Convert, rewards) or that are already in the connected account in the app (including closed positions).
> Add a trade in a pair whose asset never appeared in the account (bought and sold before connecting) with a CSV import.

> 💡 Tip: If you do not confirm the preview within 24 hours, it expires and the next synchronization downloads the same period again.
