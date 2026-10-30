import { PRICE_SOURCES, type AssetClass, type InstrumentInput, type PriceSource } from '@/api/catalog'

/** Stav formuláře instrumentu (Katalog → Instrumenty i průvodce Nová investice). */
export const REF_SOURCES: PriceSource[] = PRICE_SOURCES.filter(s => s !== 'manual')
export const emptyInstrumentForm = () => ({
  id: null as number | null,
  asset_class: 'crypto' as AssetClass,
  symbol: '',
  name: '',
  isin: '',
  quote_currency: 'EUR',
  decimals: 8,
  price_source: 'coingecko' as PriceSource,
  refs: { coingecko: '', xtb: '', yahoo: '' } as Record<string, string>,
  is_active: true,
})
export type InstrumentForm = ReturnType<typeof emptyInstrumentForm>

export function instrumentPayload(form: InstrumentForm): InstrumentInput {
  return {
    asset_class: form.asset_class,
    symbol: form.symbol.trim(),
    name: form.name.trim(),
    isin: form.isin.trim() || null,
    quote_currency: form.quote_currency.trim(),
    decimals: Number(form.decimals),
    price_source: form.price_source,
    source_refs: Object.fromEntries(Object.entries(form.refs).filter(([, v]) => v.trim() !== '')),
    is_active: form.is_active,
  }
}
