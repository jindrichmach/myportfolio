import { nextTick } from 'vue'

/**
 * Po přidání nového řádku zaměří jeho první textový vstup (popis).
 *
 * Zaměří POSLEDNÍ VIDITELNÝ prvek odpovídající selektoru — desktop i mobilní
 * varianta řádku se renderují obě zároveň, ta skrytá (Tailwind `hidden`/`md:hidden`)
 * má `offsetParent === null`, takže ji přeskočíme a trefíme tu na aktuální šířce.
 *
 * Selektor cílí na `[data-row-input="<marker>"]`; každý kontext má vlastní marker,
 * aby se např. modal výkazu práce netrefil do inputů editoru pod ním.
 */
export function focusLastRow(selector: string): void {
  void nextTick(() => {
    const els = Array.from(document.querySelectorAll<HTMLElement>(selector))
      .filter(el => el.offsetParent !== null)
    els[els.length - 1]?.focus()
  })
}
