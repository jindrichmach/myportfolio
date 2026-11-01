# 0. Your first overview in 5 minutes

The shortest path from an empty app to a Dashboard showing value and return. The fastest way is to **import** an export
from your broker or exchange — instruments and positions are created automatically, you only create an account and a portfolio by hand.

## 0.1 Terms in one place

| Term | What it is | Example |
|---|---|---|
| Institution | Where your money is — exchange, broker, bank, wallet (shared catalog) | XTB, Coinbase, Fio banka |
| Account | Your account at an institution | "XTB main", "Coinbase" |
| Portfolio | Your own group of positions across accounts | "Long-term", "Speculation" |
| Instrument | What you hold (shared catalog) | VWCE, BTC, ČEZ shares |
| Position | A specific instrument in a specific account within one portfolio | VWCE at XTB in the Long-term portfolio |
| Transaction | Buy, sell, dividend, deposit… — everything else is calculated from them | buy of 3 units of VWCE |

## 0.2 Five steps

1. **Institution** — in **Accounts & catalog → Institutions** check that your broker or exchange is listed; if not, add it
   ([4.1](04_Ucty_a_portfolia.md#41-catalog--institutions)).
2. **Account** — **Accounts & catalog → Accounts → New account**, choose the institution and give the account a name
   ([4.3](04_Ucty_a_portfolia.md#43-accounts)).
3. **Portfolio** — **Accounts & catalog → Portfolios → New portfolio**, e.g. "Long-term"
   ([4.4](04_Ucty_a_portfolia.md#44-portfolios)).
4. **Import** — download the transaction export from your broker (which file it is, see the table in
   [5.5](05_Transakce.md#55-import-from-an-exchange--broker)), in **Portfolio → Import** choose the account and portfolio, upload
   the file, match the instruments in the preview and confirm.
5. **Dashboard** — done. Value, return and the chart are calculated from the transactions; price history is downloaded in the background
   (with a long history this may take a while).

> 💡 Tip: A broker that is not in the import list, or a savings account, is entered manually: in step 4, instead of importing, create
> a new position in **Portfolio → Positions** and add transactions ([4.5](04_Ucty_a_portfolia.md#45-positions),
> [5.2](05_Transakce.md#52-form)).

## 0.3 What next

- [7.1 How value and gain are calculated](07_Prehled.md#71-how-value-and-gain-are-calculated) — what the numbers on the Dashboard mean.
- [9.1 Target allocation and rebalancing](09_Alokace_a_ciste_jmeni.md#91-target-allocation-and-rebalancing) — set a target
  and the app suggests where to put your next deposit.
- [5.6 Connecting an exchange via API](05_Transakce.md#56-connecting-an-exchange-via-api) — transactions from the exchange are then downloaded automatically.
