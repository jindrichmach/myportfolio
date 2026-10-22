import { useRouter, type RouteLocationRaw } from 'vue-router'

/**
 * Navigace „klikatelných" řádků v seznamech tak, aby fungoval Ctrl/⌘+klik a klik
 * prostředním tlačítkem (otevření v novém panelu) — což čisté @click + router.push
 * neumí (není to skutečný odkaz).
 *
 * Použití:
 *   const navigate = useRowLink()
 *   function openPosition(pos, e) { navigate(`/positions/${pos.id}`, e) }
 *   // v šabloně: @click="openPosition(pos, $event)" @auxclick.prevent="openPosition(pos, $event)"
 *
 * Chování dle eventu:
 *   - Ctrl/⌘ + klik nebo prostřední tlačítko (button 1) → nový panel (window.open)
 *   - běžný levý klik (button 0) → router.push
 *   - pravé/jiné tlačítko → ignorováno (nenaviguje)
 */
export function useRowLink() {
  const router = useRouter()

  return function navigate(to: RouteLocationRaw, e?: MouseEvent): void {
    const newTab = !!e && (e.metaKey || e.ctrlKey || e.button === 1)
    if (newTab) {
      // router.resolve respektuje base path routeru; otevře SPA na dané route v novém panelu
      window.open(router.resolve(to).href, '_blank', 'noopener')
      return
    }
    // Aux/pravé tlačítko (button != 0) bez modifikátoru — nenaviguj (nechováme se jako odkaz)
    if (e && e.button !== undefined && e.button !== 0) return
    router.push(to)
  }
}
