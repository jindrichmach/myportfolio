import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

/**
 * Aktivní záložka v `?tab=` (jde na ni odkázat, přežije reload). Výchozí záložka se v URL nepíše.
 * Vrací zapisovatelný computed pro v-model komponenty Tabs.
 */
export function useTabQuery<K extends string>(keys: readonly K[], fallback: K) {
  const route = useRoute()
  const router = useRouter()
  const parse = (value: unknown): K => (keys as readonly unknown[]).includes(value) ? value as K : fallback
  const current = ref<K>(parse(route.query.tab))
  watch(() => route.query.tab, (value) => { current.value = parse(value) })

  return computed<K>({
    get: () => current.value,
    set: (next) => {
      current.value = next
      router.replace({ query: { ...route.query, tab: next === fallback ? undefined : next } })
    },
  })
}
