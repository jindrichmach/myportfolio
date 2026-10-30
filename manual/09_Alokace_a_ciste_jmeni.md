# 9. Alokace a čisté jmění

Rozložení majetku proti tvému cíli, návrh kam vložit další peníze a celkový majetek včetně nemovitostí a úvěrů.

## 9.1 Cílová alokace a rebalancing

**Portfolio → Alokace** — nastavíš cílové rozložení majetku a uvidíš, jak daleko od něj jsi a kam dát
další peníze. Nahoře vybereš rozsah: **Celý majetek**, nebo jedno portfolio (každé může mít vlastní cíle).
Počítá se vše včetně ručních pozic (Portu, spořicí účty) a hotovosti, v měně zobrazení.

| Třída | Co do ní patří |
|---|---|
| Krypto, ETF, Akcie | Tržní pozice podle třídy instrumentu |
| Fondy a ruční pozice | Ručně oceňované pozice (Portu, fondy) |
| Hotovost | Hotovost na účtech a spořicí účty u banky |

**Nastavit cíle** — ke každé třídě cílový podíl (součet 100 %) a toleranci v procentních bodech
(prázdné = 5). U krypta, ETF a akcií můžeš zaškrtnout **rozepsat po instrumentech** a zadat podíly
instrumentů uvnitř třídy (také součet 100 %); držený instrument bez cíle má cíl 0 %. Bez cílů formulář
předvyplní dnešní rozložení. **Smazat cíle** odstraní celou sadu pro vybraný rozsah.

- **Skutečnost a cíl** — hodnota a podíl třídy, cíl ± tolerance a odchylka v měně (+ nad cílem).
  Pruh ukazuje podíl, svislá čárka cíl. Třída mimo toleranci má štítek a na **Přehledu** se u alokace
  ukáže počet tříd mimo toleranci (celý majetek). Kliknutím na třídu rozbalíš instrumenty.
- **Plánuji vložit** — zadáš částku a dostaneš návrh nákupů bez prodeje: vklad se rozdělí mezi třídy
  (a instrumenty), kterým po vkladu nejvíc chybí do cíle, poměrně podle schodku.
- **Plný rebalancing** — prodeje a nákupy, po kterých bude rozložení přesně na cíli. Prodej třídy bez
  cílů instrumentů se rozdělí poměrně podle hodnoty pozic. U každé prodávané pozice je odhad kusů
  a kolik z nich pochází z lotů mladších 3 let (FIFO) — takový prodej může být zdanitelný
  ([12. Daňový report](12_Danovy_report.md)).

> 🛈 Poznámka: Pozice bez ceny nebo kurzu se do alokace nepočítají — stránka je vypíše nahoře.
> Návrhy jsou orientační: nepočítají s poplatky a nákup za třídu (bez cílů instrumentů) nechává výběr
> instrumentu na tobě.

## 9.2 Čisté jmění

**Portfolio → Čisté jmění** — celkový majetek: **investice** (ocenění všech pozic včetně hotovosti, stejně jako na
Přehledu) + **další majetek** − **závazky**, v měně zobrazení. Penzijko a DIP jsou investice — vedou se jako
pozice ([4.5.2](04_Ucty_a_portfolia.md#452-penzijní-spoření-a-dip)) a do čistého jmění se tak započítají samy. Nahoře jsou dlaždice, pod nimi graf vývoje
(čisté jmění, investice a závazky po dnech) a položky po druzích. Když máš aspoň jednu položku, ukáže se
čisté jmění i jako dlaždice na **Přehledu**.

| Druh | Jak se oceňuje |
|---|---|
| Nemovitosti | Ručně zadaná hodnota k datu (odhad, posudek); mezi oceněními platí poslední |
| Ostatní majetek | Ručně zadaná hodnota k datu (auto, podíl ve firmě, sbírka…) |
| Závazky | Úvěr s anuitními splátkami — zůstatek se dopočítá ke každému dni |

**Přidat položku** — druh, název, měna (cizí měna se přepočítá kurzem ČNB) a volitelně první ocenění. U závazku
zadáš **datum čerpání**, **jistinu**, **úrok % p. a.** a **měsíční splátku**: splátky se odečítají měsíčně ke dni
čerpání (úrok = zůstatek × úrok / 12), zůstatek neklesne pod nulu. U úvěru se zobrazí odhad doplacení; když
splátka nepokryje úrok, je tam upozornění.

Tlačítkem **Ocenění** (u závazku **Zůstatky**) zapíšeš novou hodnotu k datu — stejný den se přepíše:

- **Majetek** — nová hodnota; při prodeji zadej 0 k datu prodeje a položku pak archivuj (Upravit → Archivovat),
  ať historie sedí.
- **Závazek** — zůstatek k datu po mimořádné splátce, refixaci nebo podle výpisu banky; volitelně nový úrok
  a splátku (prázdné = beze změny). Od tohoto data se umořuje dál. Pod seznamem je výhled zůstatku na 12 měsíců.

Splacený úvěr (zůstatek 0) má u sebe tlačítko **Splaceno – archivovat**: zmizí ze seznamu, historie čistého jmění
zůstane. Archivované položky zobrazíš přepínačem **Zobrazit archivované** — jsou zašedlé a do součtů se nepočítají;
archivaci zrušíš v Upravit.

> 🛈 Poznámka: Výnosové metriky (TWR, XIRR, souhrny), alokace a daňový report položky čistého jmění nezahrnují —
> jsou jen přehled majetku. Graf čistého jmění vychází z denní historie investic, takže začíná první transakcí.

