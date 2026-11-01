# 13. Sharing for the household

**Account → Sharing** — you share your data with a partner (read only, or read and write) and you both see a joint
household overview. Each of you still sees and manages only your own settings; sharing works in each direction separately.

## 13.1 Share with a partner

The **Share** button:

| Field | Meaning |
|---|---|
| E-mail | If the partner has an account in the app, the share applies immediately and they get an e-mail notification. Otherwise they get an **invitation** with a link to create an account (valid for 14 days) |
| Permission | **Read only** — overviews, positions, transactions, history, summaries, allocation. **Read and write** — additionally recording transactions and transfers, manual valuations and cash balances, creating and editing positions, imports |
| What to share | **Whole account**, or **Selected portfolios** — the partner then does not see other portfolios, nor accounts in which the shared portfolios have no position |
| Also share | **Net worth**, **Tax report**, **Dividends and interest** — only with a whole-account share (they span all portfolios) |

In the **I share** list you edit or revoke a share, or resend a pending invitation (**Resend invitation**). A partner can never
change accounts, portfolios, allocation targets, net worth or any settings (profile, alerts, API, exchanges,
bank notices).

## 13.2 Data shared by a partner

Whoever shares with you is in the **Shared with me** list, and a **My data / name** switcher appears in the app header (on a phone at the top of the opened menu).
After switching, the whole app shows the partner's data within the scope of the share — at the top is a bar showing whose data
you are viewing, and a **Back to my data** button. Without write permission, editing buttons are hidden; parts the
partner does not share disappear from the menu. Amounts are in **your** display currency, and the value history chart uses your benchmark too.

With **Leave share** you end the share from your side.

## 13.3 Who changed what

A transaction that a partner recorded in your data is recognisable in the transaction list by the **by name** label.
All changes by partners (recording, editing, deleting a transaction, valuation, position, import) are on the Sharing page
in the **Changes by partners in my data** section.

## 13.4 Household

**Portfolio → Household** (shown when someone shares with you) — your data and your partners' data together:
net worth, investment value, total return, assets and liabilities, breakdown by member and allocation by class.
Each member is counted within the scope of their share; net worth includes assets and liabilities only for members who
share net worth.

> 🛈 Note: Shared data can only be read in the app after signing in — not with an API token.

> 💡 Tip: An invitation creates a regular account with the user role. If the administrator requires two-factor authentication,
> the new user sets it up at first sign-in ([3. Sign-in and security](03_Prihlaseni_a_zabezpeceni.md)).
