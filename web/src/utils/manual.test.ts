import { describe, expect, it } from 'vitest'
import { headingSlug, manualChapters, manualSection, renderManual, searchManual } from './manual'

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

  it('výřez oddílu pro dialog: od nadpisu po další stejné úrovně, v angličtině podle čísla', () => {
    const cs = manualSection('cs', '05_Transakce#552-export-z-anycoinu')!
    expect(cs.title).toBe('5.5.2 Export z Anycoinu')
    expect(cs.source).toContain('Můj účet → Transakce')
    expect(cs.source).not.toContain('5.5.3')
    expect(manualSection('en', '05_Transakce#552-export-z-anycoinu')!.title).toBe('5.5.2 Export from Anycoin')
    // H2 zahrnuje své H3, končí u dalšího H2
    const h2 = manualSection('cs', '05_Transakce#55-import-z-burzy--brokera')!
    expect(h2.source).toContain('### 5.5.13')
    expect(h2.source).not.toContain('## 5.6')
    expect(manualSection('cs', '00_Prvni_kroky')!.source).not.toMatch(/^# /m)
    expect(manualSection('cs', '99_Neexistuje')).toBeNull()
  })
})
