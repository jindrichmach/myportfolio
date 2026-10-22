import { Chart, Tooltip, type Plugin } from 'chart.js'

/**
 * Společné nastavení chart.js — importuje každá komponenta s grafem.
 *
 * Why: tooltip si drží aktivní body i přes chart.update() (změna dat, období, motivu), takže po
 * novém načtení zůstal na neplatném bodě; a přerušená animace jeho mizení ho nechávala viset
 * poloprůhledný i bez hoveru. Při každém update tooltip vynulujeme a jeho animaci vypneme.
 */
const resetTooltip: Plugin = {
  id: 'myportfolioResetTooltip',
  beforeUpdate(chart) {
    chart.tooltip?.setActiveElements([], { x: 0, y: 0 })
    chart.setActiveElements([])
  },
}

/** Svislá čára na aktivním dni (myš i dotyk) v barvě popisků osy X — pro čárové grafy přes `:plugins`. */
export const crosshair: Plugin<'line'> = {
  id: 'myportfolioCrosshair',
  afterDatasetsDraw(chart) {
    const active = chart.tooltip?.getActiveElements() ?? []
    if (!active.length) return
    const x = active[0].element.x
    const { top, bottom } = chart.chartArea
    const ctx = chart.ctx
    ctx.save()
    ctx.strokeStyle = String(chart.options.scales?.x?.ticks?.color ?? '#888')
    ctx.globalAlpha = 0.6
    ctx.lineWidth = 1
    ctx.setLineDash([3, 3])
    ctx.beginPath()
    ctx.moveTo(x, top)
    ctx.lineTo(x, bottom)
    ctx.stroke()
    ctx.restore()
  },
}

Chart.register(Tooltip, resetTooltip)
Chart.defaults.plugins.tooltip.animation = false
