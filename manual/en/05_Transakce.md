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

## 5.5 Import from an exchange / broker

**Portfolio → Import** (or the **Import** button on the Transactions page) — you upload a transaction export
from an exchange or broker, check the preview and confirm. Nothing is written until you confirm
the import.

| Source | Which file to download |
|---|---|
| Coinbase | Transaction history (CSV) |
| Anycoin | History export (CSV) |
| Coinmate | Transaction history (CSV, Czech or English) |
| Binance | Transaction History (CSV or XLSX) |
| Kraken | Ledgers (`ledgers.csv`, also in a ZIP) |
| XTB | History → Cash operations → Export (XLSX, older CSV) |
| Trading 212 | History → Export (CSV) |
| Revolut | Stocks → Documents → Account statement (CSV) |
| DEGIRO | `Transactions.csv` (trades) and `Account.csv` (dividends) — separately or together in a ZIP |
| Interactive Brokers | Activity Statement (CSV) or Flex Query (CSV) |

| Field | Meaning |
|---|---|
| Account | The exchange / broker account the export comes from — positions are written into it |
| Portfolio | The portfolio in which new positions are created |
| Source | Usually detected automatically from the file header; otherwise choose it |
| File | CSV, XLSX or ZIP, at most 20 MB |

### 5.5.1 Preview

- **Summary** — how many rows are new, already imported and skipped.
- **Instruments** — for each symbol from the export, choose an **Existing** instrument from the catalog,
  **Create new** (symbol, name, class and quote currency are prefilled) or **Skip** it.
  Known instruments are matched automatically by ISIN or symbol.
- **Sends and receives** (crypto) — a transfer between your own accounts keeps the cost basis and the acquisition
  date. Choose the other side: an existing position, or another account (a position is created). Without a choice
  the row is skipped.
- **Rows** — all movements with their status; skipped ones show the reason.

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

### 5.5.2 Repeated import

Each row remembers its ID from the export (or a fingerprint of the row if the export has no ID). You can upload the same
or an overlapping file again — already imported rows are skipped.

### 5.5.3 Reverting an import

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

The **Verify key** button checks the key before saving; if the key has unnecessarily broad permissions
(trading, withdrawals), you will see a warning.

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
