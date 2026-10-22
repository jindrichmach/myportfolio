# Manuál — konvence

Adresář `manual/` obsahuje uživatelskou dokumentaci v Markdownu (česky).

## Struktura

```
manual/
├── INDEX.md          # navigace + skupiny (podle menu aplikace)
├── 01_Uvod.md        # kapitoly NN_Nazev.md, řazené podle menu
├── …
└── README.md         # tento soubor
```

## Pravidla psaní

- H1 `# NN. Název`, H2 `## NN.X Název`, H3 `### NN.X.Y`.
- Popis polí formuláře tabulkou `| Pole | Význam |`.
- Upozornění: `> 💡 Tip`, `> 🛈 Poznámka`, `> ⚠️ Pozor`.
- Odkazy mezi kapitolami přes název souboru a kotvu nadpisu (`[Převod mezi účty](05_Transakce.md#53-převod-mezi-účty)`).
- Piš **jen aktuální stav** — žádné „od verze X", žádná historie vývoje.
- Screenshoty v manuálu zatím nejsou; pokud přibudou, jen ze syntetických dat (žádné reálné zůstatky, adresy peněženek, čísla účtů).

## Kapitoly

Přehled je v [INDEX.md](INDEX.md). Kapitola se aktualizuje ve stejném commitu
jako funkce, kterou popisuje.

## Manuál v aplikaci

Kapitoly `NN_*.md` se při buildu frontendu přibalí do stránky **Manuál** (`web/src/pages/Manual.vue`,
`web/src/utils/manual.ts`) — po úpravě manuálu je potřeba `pnpm build`. Kotvy nadpisů se tvoří jako na GitHubu,
odkazy `[text](NN_Kapitola.md#kotva)` fungují v repozitáři i v aplikaci; jejich platnost hlídá
`web/tests/manual-links.test.mjs`. HTML v markdownu je vypnuté. Kapitoly 02 a 09 vidí jen správce.
