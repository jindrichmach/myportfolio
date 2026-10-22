/**
 * Kontext sdílených dat: čí data UI zobrazuje. Vybraný vlastník je v localStorage
 * (`myportfolio-owner`) a API klient ho posílá v hlavičce `X-Owner`; backend ji u osobních cest (nastavení,
 * přihlášení, sdílení, číselníky) ignoruje a u ostatních ověří proti sdílení. Oprávnění tu slouží jen ke skrytí
 * tlačítek — rozhoduje backend.
 */
import { computed, ref } from 'vue'
import { sharesApi, type Share, type ShareSection } from '@/api/shares'

export const OWNER_KEY = 'myportfolio-owner'

function stored(): number | null {
  try {
    const v = Number(localStorage.getItem(OWNER_KEY))
    return Number.isInteger(v) && v > 0 ? v : null
  } catch {
    return null
  }
}

const ownerId = ref<number | null>(stored())
const received = ref<Share[]>([])
const loaded = ref(false)

function persist(id: number | null) {
  try {
    if (id === null) localStorage.removeItem(OWNER_KEY)
    else localStorage.setItem(OWNER_KEY, String(id))
  } catch {
    // bez localStorage zůstane jen v paměti
  }
  ownerId.value = id
}

/** Načte přijatá sdílení; uložený vlastník, který už nesdílí, se zahodí. */
export async function loadReceivedShares(): Promise<void> {
  try {
    received.value = (await sharesApi.list()).received
  } catch {
    received.value = []
  }
  loaded.value = true
  if (ownerId.value !== null && !received.value.some(s => s.owner_user_id === ownerId.value)) {
    persist(null)
    window.location.reload()
  }
}

export function clearOwnerContext(): void {
  persist(null)
}

export function useOwnerContext() {
  const share = computed(() => received.value.find(s => s.owner_user_id === ownerId.value) ?? null)
  const inShared = computed(() => ownerId.value !== null)
  return {
    ownerId,
    received,
    loaded,
    share,
    inShared,
    /** Zápis transakcí, ocenění, pozic a importů */
    canWrite: computed(() => !inShared.value || share.value?.permission === 'write'),
    /** Správa účtů, portfolií, cílů a čistého jmění — jen vlastní data */
    canManage: computed(() => !inShared.value),
    hasSection: (s: ShareSection) => !inShared.value || (share.value?.scope === 'all' && share.value.sections.includes(s)),
    switchTo(id: number | null) {
      persist(id)
      window.location.assign('/')
    },
  }
}
