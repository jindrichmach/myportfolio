import { api } from './client'
import type { LastPrice } from './market'

export type InstitutionType = 'exchange' | 'broker' | 'wallet' | 'robo' | 'bank' | 'pension'
export type AssetClass = 'crypto' | 'etf' | 'stock' | 'fund' | 'cash'
export type PriceSource = 'coingecko' | 'xtb' | 'yahoo' | 'manual'

export const INSTITUTION_TYPES: InstitutionType[] = ['exchange', 'broker', 'wallet', 'robo', 'bank', 'pension']
export const ASSET_CLASSES: AssetClass[] = ['crypto', 'etf', 'stock', 'fund', 'cash']
export const PRICE_SOURCES: PriceSource[] = ['coingecko', 'xtb', 'yahoo', 'manual']

export interface Institution {
  id: number
  name: string
  type: InstitutionType
  website: string | null
  is_active: boolean
  icon_url: string | null
  can_edit_icon: boolean
}

export interface Instrument {
  id: number
  asset_class: AssetClass
  symbol: string
  name: string
  isin: string | null
  quote_currency: string
  decimals: number
  price_source: PriceSource
  source_refs: Partial<Record<PriceSource, string>>
  is_active: boolean
  last_price?: LastPrice | null
  icon_url: string | null
  can_edit_icon: boolean
}

export type InstitutionInput = Pick<Institution, 'name' | 'type' | 'website'> & { is_active?: boolean }
export type InstrumentInput = Omit<Instrument, 'id' | 'is_active' | 'last_price' | 'icon_url' | 'can_edit_icon'> & { is_active?: boolean }
export type CatalogKind = 'institutions' | 'instruments'

export interface IconLibrary {
  crypto: { symbol: string; name: string; url: string }[]
  /** Loga správců ETF (iShares, Vanguard…). */
  etf: { slug: string; name: string; url: string }[]
  /** `icon`/`url` = přibalené logo (weby, odkud stažení nejde); jinak se logo stahuje z `website`. */
  institutions: { name: string; type: InstitutionType; website: string; icon: string | null; url: string | null }[]
}

export const catalogApi = {
  institutions: (all = false) =>
    api.get<Institution[]>('/institutions', { params: all ? { all: 1 } : {} }).then(r => r.data),
  createInstitution: (payload: InstitutionInput) =>
    api.post<Institution>('/institutions', payload).then(r => r.data),
  updateInstitution: (id: number, payload: InstitutionInput) =>
    api.put<Institution>(`/admin/institutions/${id}`, payload).then(r => r.data),
  deleteInstitution: (id: number) => api.delete(`/admin/institutions/${id}`),

  instruments: (params: { q?: string; asset_class?: AssetClass; all?: boolean } = {}) =>
    api.get<Instrument[]>('/instruments', {
      params: { q: params.q || undefined, asset_class: params.asset_class, all: params.all ? 1 : undefined },
    }).then(r => r.data),
  createInstrument: (payload: InstrumentInput) =>
    api.post<Instrument>('/instruments', payload).then(r => r.data),
  updateInstrument: (id: number, payload: InstrumentInput) =>
    api.put<Instrument>(`/admin/instruments/${id}`, payload).then(r => r.data),
  deleteInstrument: (id: number) => api.delete(`/admin/instruments/${id}`),

  uploadIcon: (kind: CatalogKind, id: number, file: File) => {
    const form = new FormData()
    form.append('icon', file)
    return api.post<{ icon_url: string }>(`/${kind}/${id}/icon`, form, { headers: { 'Content-Type': 'multipart/form-data' } })
      .then(r => r.data.icon_url)
  },
  removeIcon: (kind: CatalogKind, id: number) => api.delete(`/${kind}/${id}/icon`),
  /** Přibalená knihovna: ikony kryptoměn a české instituce s webem (loga se stahují z webu). */
  iconLibrary: () => api.get<IconLibrary>('/icon-library').then(r => r.data),
  setIconFromLibrary: (kind: CatalogKind, id: number, pick: { symbol: string } | { etf: string } | { institution: string }) =>
    api.post<{ icon_url: string }>(`/${kind}/${id}/icon/library`, pick).then(r => r.data.icon_url),
  /** Logo z webu instituce (`url` = jiný web než u instituce, např. z knihovny). */
  setIconFromWebsite: (id: number, url?: string) =>
    api.post<{ icon_url: string }>(`/institutions/${id}/icon/website`, url ? { url } : {}).then(r => r.data.icon_url),
}
