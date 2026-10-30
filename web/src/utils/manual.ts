/**
 * Uživatelský manuál (`manual/NN_Nazev.md`, česky) přibalený do frontendu při buildu. Kapitoly se řadí podle čísla,
 * nadpisy dostanou kotvy jako na GitHubu (odkazy `[x](05_Transakce.md#55-import…)` v manuálu tak fungují beze změny)
 * a odkazy mezi kapitolami se přepíšou na routy `/manual/<kapitola>#kotva`.
 */
import MarkdownIt from 'markdown-it'

const sources = import.meta.glob('../../../manual/[0-9][0-9]_*.md', { query: '?raw', import: 'default', eager: true }) as Record<string, string>

/** Kapitoly jen pro správce (instalace, administrace systému) */
const ADMIN_ONLY = ['02', '11']

export interface ManualChapter {
  slug: string
  number: string
  title: string
  source: string
  adminOnly: boolean
  sections: { id: string; title: string }[]
}

/** Kotva nadpisu jako na GitHubu: malá písmena, bez interpunkce, mezery → pomlčky. */
export function headingSlug(text: string): string {
  return text.trim().toLowerCase().replace(/[^\p{L}\p{N}\s-]/gu, '').replace(/\s/g, '-')
}

export const manualChapters: ManualChapter[] = Object.entries(sources)
  .map(([path, source]) => {
    const slug = path.split('/').pop()!.replace(/\.md$/, '')
    const number = slug.slice(0, 2)
    const title = (source.match(/^# (.+)$/m)?.[1] ?? slug).trim()
    const sections = [...source.matchAll(/^## (.+)$/gm)].map(m => ({ id: headingSlug(m[1]), title: m[1].trim() }))
    return { slug, number, title, source, adminOnly: ADMIN_ONLY.includes(number), sections }
  })
  .sort((a, b) => a.slug.localeCompare(b.slug))

/** Text bez diakritiky a velkých písmen — hledání „uroky“ najde „Úroky“. */
const fold = (s: string) => s.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase()
/** Markdown → prostý text pro úryvek (odkazy, zvýraznění, tabulky, kód). */
const plain = (s: string) => s.replace(/\[([^\]]*)\]\([^)]*\)/g, '$1').replace(/[*`>|#_]/g, ' ').replace(/\s+/g, ' ').trim()

export interface ManualHit { slug: string; chapter: string; id: string; section: string; snippet: string }

/** Fulltext přes kapitoly: oddíly (H2), ve kterých se vyskytuje dotaz, s úryvkem kolem nálezu. */
export function searchManual(chapters: ManualChapter[], query: string, limit = 30): ManualHit[] {
  const q = fold(query.trim())
  if (q.length < 2) return []
  const hits: ManualHit[] = []
  for (const c of chapters) {
    const parts = c.source.split(/^(?=## )/m)
    for (const part of parts) {
      const heading = part.match(/^## (.+)$/m)?.[1]?.trim() ?? null
      const text = plain(part.replace(/^#{1,2} .+$/m, ''))
      const at = fold(text).indexOf(q)
      const inTitle = heading !== null && fold(heading).includes(q)
      if (at < 0 && !inTitle) continue
      const from = Math.max(0, at - 60)
      const snippet = at < 0 ? text.slice(0, 140) : `${from > 0 ? '…' : ''}${text.slice(from, at + q.length + 80)}…`
      hits.push({ slug: c.slug, chapter: c.title, id: heading ? headingSlug(heading) : '', section: heading ?? c.title, snippet })
      if (hits.length >= limit) return hits
    }
  }
  return hits
}

const md = new MarkdownIt({ html: false, linkify: false, typographer: false })

// Kotvy nadpisů (duplicity -1, -2 jako na GitHubu)
md.core.ruler.push('heading_ids', state => {
  const seen = new Map<string, number>()
  state.tokens.forEach((token, i) => {
    if (token.type !== 'heading_open') return
    const base = headingSlug(state.tokens[i + 1]?.content ?? '')
    const n = seen.get(base) ?? 0
    seen.set(base, n + 1)
    token.attrSet('id', n === 0 ? base : `${base}-${n}`)
  })
})

// Odkazy: kapitola.md → /manual/kapitola, externí do nového okna
const defaultLink = md.renderer.rules.link_open ?? ((tokens, idx, options, _env, self) => self.renderToken(tokens, idx, options))
md.renderer.rules.link_open = (tokens, idx, options, env, self) => {
  const token = tokens[idx]
  const href = String(token.attrGet('href') ?? '')
  const chapter = href.match(/^([0-9]{2}_[^/#]+)\.md(#.*)?$/)
  if (chapter) {
    token.attrSet('href', `/manual/${chapter[1]}${chapter[2] ?? ''}`)
  } else if (/^https?:\/\//.test(href)) {
    token.attrSet('target', '_blank')
    token.attrSet('rel', 'noopener')
  }
  return defaultLink(tokens, idx, options, env, self)
}

// Tabulky do posuvného obalu (mobil)
md.renderer.rules.table_open = () => '<div class="manual-table"><table>'
md.renderer.rules.table_close = () => '</table></div>'

export function renderManual(source: string): string {
  return md.render(source)
}
