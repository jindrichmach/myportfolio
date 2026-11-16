# 10. Bank notices

Banks send an e-mail with the amount and balance for every movement on an account. MyPortfolio can read these
e-mails from an IMAP mailbox and record them in the manual position of a savings account — so you don't have to enter
deposits, withdrawals, interest or valuations by hand.

Supported notices:

| Bank | Notice | Balance → valuation |
|---|---|---|
| Fio banka | "Fio banka – příjem/výdaj na kontě" and the newer "zůstatek účtu … se zvýšil/snížil" | yes |
| Air Bank | "Zvýšení / Snížení zůstatku na účtu" | yes |
| MONETA Money Bank | Info Servis "Přišly peníze" / "Odešly peníze" | yes (book balance) |
| Raiffeisenbank | "Pohyb na účtě" | yes |
| ČSOB | "Moje info – Avízo" | yes |
| Česká spořitelna | notice with a "Směr platby" (payment direction) line | yes (available); date = day the e-mail was delivered |
| Banka CREDITAS | "Notifikace o změně na účtu" | yes |
| UniCredit Bank | "Informace o pohybu na účtu" | no — movement only |

For a bank whose notice does not state the balance, the position value is computed from the last manual valuation
and the movements after it (see [7.4 Manual valuation](07_Prehled.md#74-manual-valuation-portu-savings-accounts)).

## 10.1 What gets recorded from a notice

| Notice | In the position |
|---|---|
| Incoming payment | **Deposit** |
| Outgoing payment | **Withdrawal** |
| Credited interest (no counter-account, movement type containing the word "úrok") | **Interest** — counts towards return |
| Tax on interest (outgoing, no counter-account, movement type containing the word "úrok") | **Fee** |
| Balance after the movement | **Manual valuation** on the posting date |

Transactions from notices have the source "bank_notice" and the same notice is never recorded twice.
When several notices arrive for one day, the balance from the last one applies.

## 10.2 Mailbox

**Account → Bank notices → New mailbox.** Enter the mailbox the bank sends notices to.

| Field | Meaning |
|---|---|
| Name | For your own orientation |
| IMAP server, port, encryption | E.g. `imap.seznam.cz`, 993, SSL/TLS. The server must have a public address — the administrator can allow local and internal networks in `cfg.php` (`bank_notices.allow_private_imap`) |
| Username, password | Login to the mailbox; the password is stored encrypted and is never shown again. When editing, an empty password = unchanged |
| Folder | Where the notices are (default `INBOX`) |
| Verify e-mail authenticity | A notice is recorded only when the mail server verified it (DKIM/DMARC) as sent by the bank — protection against forged e-mails |
| Trusted authserv-id (Advanced) | The name of your receiving server in the verification header (e.g. `mx.google.com`). Without it notices are not recorded — the verification result can also be inserted into the e-mail by the sender. After the first notice the app offers the server at the mailbox with the **Yes, confirm** button; notices rejected so far are then processed again if they are still among the messages being read |
| Read automatically | The mailbox is read every 15 minutes |

The **Test connection** button verifies the login and lists the mailbox folders, **Read now**
processes notices immediately. The mailbox is only read — e-mails stay unread and are not moved anywhere.

> 💡 Tip: A separate mailbox or alias is best for notices, so your other e-mails are not read.
> If you use an app password (Gmail, Seznam with two-step verification), enter it instead of the account password.

## 10.3 Linking to a position

A notice is assigned to a manual position by **account number**. In the savings account detail, click
**Edit** and fill in:

| Field | Meaning |
|---|---|
| Account number for bank notices | In the form `123456789/2010` (with a prefix `19-123456789/0800`) |
| Notices from | Notices posted earlier are skipped. Without a date, the day after the last manual valuation is filled in so nothing is recorded twice |

MONETA sends the account number in the notice partly masked (`238***891`) — it is matched by the visible digits,
length and bank code. When several linked accounts match the same mask, the notice is not assigned (status **unlinked account**) —
enter the movement by hand. The notice currency must match the position currency. The payment direction (incoming / outgoing) is taken from the subject
or from the sentence about the account balance, not from the payment description from the counterparty.

## 10.4 Processed notices

Below the mailboxes is an overview of linked accounts and a list of the most recently processed notices with their status:

| Status | Meaning |
|---|---|
| recorded | The transaction and valuation are in the position |
| before link | The notice is older than the **Notices from** date |
| unlinked account | No manual position has the account number from the notice (or the mask matches several positions) — fill it in and the notice is processed on the next run, if it is still among the fetched messages |
| unreadable | An e-mail from the bank, but in an unexpected format |
| unverified sender | The e-mail failed DKIM/DMARC verification, or the mailbox does not have a confirmed authserv-id yet |
| write error | E.g. a withdrawal would exceed the position's deposits |

E-mails that are not a notice from any of the supported banks are ignored and do not appear in the list.
