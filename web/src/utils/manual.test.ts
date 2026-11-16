import { describe, expect, it } from 'vitest'
import { headingSlug, manualChapters, renderManual, searchManual } from './manual'

describe('manuál', () => {
  it('kotvy nadpisů jako na GitHubu (diakritika zůstává)', () => {
    expect(headingSlug('12.2 Zahraniční dividendy')).toBe('122-zahraniční-dividendy')
    expect(headingSlug('  Daňový report (CSV)  ')).toBe('daňový-report-csv')
  })

  it('odkazy mezi kapitolami vedou na routy, HTML v textu se escapuje', () => {
    const html = renderManual('[Daně](12_Danovy_report.md#122-zahraniční-dividendy) <script>alert(1)</script> [web](https://example.invalid)')
    expect(html).toContain(`href="/manual/12_Danovy_report#${encodeURIComponent('122-zahraniční-dividendy')}"`)
    expect(html).toContain('&lt;script&gt;')
    expect(html).toContain('target="_blank" rel="noopener"')
  })

  it('hledání bez ohledu na diakritiku najde oddíl s kotvou', () => {
    const hits = searchManual(manualChapters('cs'), 'zahranicni dividendy')
    expect(hits.some(h => h.slug === '12_Danovy_report' && h.id === '122-zahraniční-dividendy')).toBe(true)
    expect(searchManual(manualChapters('cs'), 'x')).toEqual([])
  })

  it('anglický manuál má stejné kapitoly', () => {
    expect(manualChapters('en').map(c => c.slug)).toEqual(manualChapters('cs').map(c => c.slug))
  })
})
