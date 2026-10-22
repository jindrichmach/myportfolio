# Security Policy

*English version below.*

## Nahlášení zranitelnosti

Pokud objevíte bezpečnostní zranitelnost v MyPortfolio, **nezveřejňujte ji v public issues**.
Nahlaste ji soukromě:

- přes [GitHub Private vulnerability reporting](https://github.com/jindrichmach/myportfolio/security/advisories/new) (preferováno), nebo
- e-mailem na `jindrichmach@gmail.com` s předmětem `[SECURITY] <stručný popis>`.

### Co nahlásit

- SQL injection / XSS / CSRF
- Obejití autentizace (session, API token, MFA, brute-force lockout)
- Eskalace oprávnění (`user` → `admin`)
- **Únik dat mezi uživateli** (čtení / zápis cizích účtů, transakcí, portfolií, obejití sdílení)
- Information disclosure (enumerace e-mailů, timing útoky)
- Denial of Service umožňující delší výpadek
- Citlivé údaje v logu / cache (hesla, secret keys, API klíče burz, tokeny)

### Co očekávat

- Potvrzení přijetí do **5 pracovních dní**
- Oprava dle závažnosti:
  - **Critical** (plná kompromitace, únik dat): co nejdříve, cíl do 7 dní
  - **High** (eskalace, obejití): do 14 dní
  - **Medium** (info disclosure, DoS): do 30 dní
  - **Low** (best-practice): součástí příští verze

Projekt udržuje jeden člověk ve volném čase — lhůty jsou cíl, ne záruka.

### Coordinated Disclosure

Pokud nahlásíte v dobré víře a dáte čas na opravu, nepublikujte detaily před vydáním
opravy. Po vydání můžete být uvedeni v `CHANGELOG.md` (opt-in).

## Podporované verze

| Verze | Status |
|-------|--------|
| 0.x   | ✅ Vývoj — opravy jen v nejnovější verzi |

## Pravidla pro nový kód

Bezpečnostní pravidla (izolace uživatelů, tajemství jen v konfiguraci, API tokeny, peníze) jsou
v [AGENTS.md](AGENTS.md#tvrdá-pravidla).

---

## Reporting a vulnerability (English)

Please **do not open a public issue** for security problems. Report privately via
[GitHub Private vulnerability reporting](https://github.com/jindrichmach/myportfolio/security/advisories/new)
(preferred) or by e-mail to `jindrichmach@gmail.com` with the subject `[SECURITY] <short description>`.

You can expect an acknowledgement within 5 business days and a fix prioritised by severity
(critical issues target 7 days). Only the latest 0.x version receives fixes. Please keep details
private until a fix is released; reporters can be credited in `CHANGELOG.md` on request.
