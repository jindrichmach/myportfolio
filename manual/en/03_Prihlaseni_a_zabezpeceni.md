# 3. Sign-in and security

## 3.1 Sign-in

Enter your email and password. If the account has multi-factor authentication enabled, this is followed by
confirmation with a **passkey** (fingerprint, face, device PIN or security key),
or a six-digit **authenticator code** (TOTP). The code is submitted automatically after you enter
the sixth digit. Each code works only once — if you confirm another action right afterwards
(e.g. creating an API token), wait for the next code.

If the administrator has enabled passwordless sign-in, you can use
**Sign in with a passkey** directly.

**Protection against password guessing:** after 5 failed attempts within 5 minutes a
CAPTCHA appears (if configured), after 10 attempts within 15 minutes sign-in is
blocked for 15 minutes, and after 30 attempts within an hour for 24 hours. These limits apply to attempts from
the same network; the account is also protected by a shared limit of 50 failed attempts within 15 minutes from any
address — then sign-in to the account is blocked for 15 minutes for everyone (passkey sign-in still works).

## 3.2 Forgotten password

**Forgot password?** below the sign-in form sends a link to your email for setting a new
password, valid for **60 minutes**. The app does not reveal whether the email exists.

If the email did not arrive, check your spam folder. Otherwise ask the administrator — they will check email sending or set the password for you.

## 3.3 Profile

**Profile** in the menu (Account section) — or the menu under your name in the top bar — is a single page with the tabs
**Account**, **Change password**, **2FA / TOTP**, **Passkeys**, **App lock**, **Reports** and **Alerts**.
The selected tab is part of the page address (`?tab=`), so you can link to it. On a phone, the tab is chosen from a list.

On the **Account** tab you can edit:

| Field | Meaning |
|---|---|
| Name | Shown in the app |
| Language | Czech or English |
| Display currency | CZK, EUR or USD — portfolio values are converted to this currency |
| Color scheme | Colors of the app, logo, favicons and emails. **Instance default** = the scheme set by the administrator (see [11.4 Settings](11_Administrace.md#114-settings)) |

Light / dark mode is switched separately with the theme icon in the top bar and applies to every scheme.

The **Reports** tab sets up the weekly, monthly and annual email report — see [8.1 Weekly report](08_Reporty_a_upozorneni.md#81-weekly-report-by-e-mail).
The **Alerts** tab sets up the daily alert summary — see [8.2 Alerts by e-mail](08_Reporty_a_upozorneni.md#82-alerts-by-e-mail).

Security tabs:

| Tab | What it contains |
|---|---|
| Change password | Requires the current password; other sessions are signed out |
| 2FA / TOTP | Activating an authenticator via a QR code and a verification code |
| Passkeys | Adding, renaming and revoking passkeys, backup codes |
| App lock | Automatic locking after inactivity |

> 💡 It is recommended to have **two passkeys**, or one passkey and active TOTP —
> if you lose one device, you can still sign in.

## 3.4 Backup codes

On the Passkeys tab you can generate a set of **ten one-time codes**.
They are shown **only once** — store them outside the computer (print, password manager). You use
them when signing in via the "No key or authenticator — use a backup code" link. A new set invalidates
the previous one.

If you have neither a passkey, nor TOTP, nor a backup code, ask the administrator for an emergency reset of
multi-factor authentication — it disables all factors and signs out all devices.

## 3.5 App lock

**Lock** in the menu under your name in the top bar (shown if you have a passkey) locks the app immediately.
On the App lock tab you set automatic locking after inactivity
(1–1440 minutes, at most the administrator's limit). The lock also applies on the server — a locked
session can neither read nor change any data. It is unlocked with a passkey (**Unlock with a
passkey**); otherwise choose **Sign out** and sign in again.

## 3.6 API

**Account → API** — tokens for scripts and integrations without a browser (Home Assistant, spreadsheets, custom scripts)
and an overview of everything they can call. The page has two tabs.

### 3.6.1 Tokens

| Field | Meaning |
|---|---|
| Name | For your own orientation |
| Scope | **read** — read only (GET); **read & write** — also writes (POST, PUT, DELETE) |
| Expires | Optional expiry date |

Creating a token requires fresh verification with a passkey or TOTP. The token is shown **only once** — save it
in a password manager. It is sent in the `Authorization: Bearer mp_pat_…` header. A token is like a password: whoever has it
can see your data; revoke tokens you no longer need.

### 3.6.2 Endpoints

A list of all endpoints that can be called with a token, grouped by area (system, catalog, prices and rates, accounts, portfolios,
positions, transactions, manual valuation and cash, overviews, tax report, imports, price thresholds). For each one:

| Part | Meaning |
|---|---|
| Method and path | e.g. `GET /api/positions/{id}` |
| Scope | `read`, or `read_write` for writes |
| Parameters | where they are sent (path, query, JSON body, form), type, whether required, description, allowed values and default value |
| Response | status code, description and example |
| Example (curl) | a ready-made command with your instance's address — just fill in the token |
| Try it | calls the endpoint directly from the page using your sign-in (not a token); for writes it asks first |

API rules:

- Amounts, prices and quantities are sent and returned as **decimal strings** (`"1250.50"`), never as floating-point
  numbers — the API rejects those.
- An error returns `{"error": {"code", "message", "fields"}}`; `fields` only for validation errors (400).
- A token has a limit on requests per minute (`X-RateLimit-*` headers, 429 when exceeded).
- Managing users, tokens and account settings is not available via a token.

The **Download OpenAPI (JSON)** button downloads the API description in OpenAPI 3.1 format (`/api/openapi.json`) for Swagger,
Postman or client generation; **Open OpenAPI** shows it in the browser.

## 3.7 Sign-out

**Sign out** ends the session immediately, including on the server. An inactive session
expires after 30 days at the latest.
