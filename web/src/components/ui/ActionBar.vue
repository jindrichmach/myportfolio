<script setup lang="ts">
import { computed, ref, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import { useI18n } from 'vue-i18n'

/**
 * Sdílená lišta akcí pro detailní stránky.
 *
 * 3 úrovně (tier) řeší „action overload":
 *  - primary    → plné tlačítko (1 akce dle stavu = další logický krok)
 *  - secondary  → outline tlačítko (podpůrné akce, na mobilu spadnou do „…")
 *  - overflow   → vždy jen v „…" dropdownu (utility, destruktivní akce)
 *
 * Dropdown je teleportovaný do <body> (neořezává overflow), zavírá se na Esc / scroll /
 * resize / klik mimo. Položky jsou data-driven (pole `actions`), takže přidání akce =
 * jeden řádek v poli, ne další tlačítko do tří různých toolbarů.
 *
 * Mobil: inline zůstanou jen primary; secondary + overflow jsou v „…" menu.
 */

export type ActionVariant = 'primary' | 'success' | 'warning' | 'danger' | 'neutral' | 'accent'
// 'advanced' = méně časté / admin / destruktivní akce — v dropdownu schované pod rozbalovacím „Pokročilé"
export type ActionTier = 'primary' | 'secondary' | 'overflow' | 'advanced'

export interface ActionItem {
  /** unikátní klíč pro v-for */
  key: string
  /** popisek (už přeložený přes t()) */
  label: string
  /** název ikony z ICONS mapy níže (volitelné) */
  icon?: keyof typeof ICONS
  tier?: ActionTier          // default 'secondary'
  variant?: ActionVariant    // default 'neutral'
  show?: unknown             // default true; truthy = zobrazit, falsy (false/null/0/'') = skrýt
  disabled?: boolean
  loading?: boolean          // nahradí popisek „…"
  title?: string             // tooltip
  to?: RouteLocationRaw      // RouterLink cíl
  href?: string              // externí odkaz (target=_blank)
  download?: boolean
  run?: () => void           // click handler
}

const props = defineProps<{
  actions: ActionItem[]
}>()

const { t } = useI18n()

// ─── ikony (stroke, viewBox 24); sjednocené z původních toolbarů ───
const ICONS = {
  edit:      'M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.414-9.414a2 2 0 1 1 2.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
  send:      'M3 8l7.89 5.26a2 2 0 0 0 2.22 0L21 8M5 19h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2z',
  check:     'M5 13l4 4L19 7',
  chart:     'M9 17v-6m3 6v-4m3 4v-2M5 21h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2z',
  trash:     'M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3',
  doc:       'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z',
  checkCircle: 'M9 14l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  coin:      'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  bell:      'M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 0 0-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z',
  copy:      'M8 16H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2m-6 12h8a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-8a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2z',
  download:  'M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4',
  qr:        'M4 4h6v6H4V4zm0 10h6v6H4v-6zM14 4h6v6h-6V4zm2 10h2m2 0v2m-4 2v2m4 0h2m-2-6h.01M14 18h.01',
  inbox:     'M19 11H5m14 0a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2',
  uturn:     'M3 10h10a8 8 0 0 1 8 8v2M3 10l6 6m-6-6l6-6',
  x:         'M6 18L18 6M6 6l12 12',
  badgeCheck: 'M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  link:      'M13.828 10.172a4 4 0 0 1 0 5.656l-3 3a4 4 0 0 1-5.656-5.656l1.5-1.5M10.172 13.828a4 4 0 0 1 0-5.656l3-3a4 4 0 0 1 5.656 5.656l-1.5 1.5',
  archive:   'M5 8h14M5 8a2 2 0 1 1 0-4h14a2 2 0 1 1 0 4M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8m-9 4h4',
  user:      'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM12 14a7 7 0 0 0-7 7h14a7 7 0 0 0-7-7z',
  play:      'M14.752 11.168l-3.197-2.132A1 1 0 0 0 10 9.87v4.263a1 1 0 0 0 1.555.832l3.197-2.132a1 1 0 0 0 0-1.664z M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  pause:     'M10 9v6m4-6v6m7-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  plus:      'M12 6v6m0 0v6m0-6h6m-6 0H6',
  cycle:     'M4 4v5h5M4 9a8 8 0 0 1 14.13-4.06M20 20v-5h-5M20 15a8 8 0 0 1-14.13 4.06',
} as const

const DOTS = 'M6.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0zM12.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0zM18.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0z'

// barevné varianty pro plné / outline / ikonu v menu
const FILLED: Record<ActionVariant, string> = {
  primary: 'bg-action hover:bg-action-hover text-on-action',
  success: 'bg-success-600 hover:bg-success-700 text-white',
  warning: 'bg-warning-500 hover:bg-warning-600 text-white',
  danger:  'bg-danger-600 hover:bg-danger-700 text-white',
  neutral: 'bg-neutral-700 hover:bg-neutral-800 text-white',
  accent:  'bg-accent-600 hover:bg-accent-700 text-white',
}
const OUTLINE: Record<ActionVariant, string> = {
  primary: 'border border-primary-500/40 text-primary-700 hover:bg-primary-50',
  success: 'border border-success-500/50 text-success-600 hover:bg-success-50',
  warning: 'border border-warning-500/50 text-warning-600 hover:bg-warning-50',
  danger:  'border border-danger-500/50 text-danger-500 hover:bg-danger-50',
  neutral: 'border border-neutral-300 text-neutral-700 hover:bg-neutral-50',
  accent:  'border border-accent-500/40 text-accent-700 hover:bg-accent-50',
}
const MENU_ICON: Record<ActionVariant, string> = {
  primary: 'text-primary-700',
  success: 'text-success-600',
  warning: 'text-warning-600',
  danger:  'text-danger-600',
  neutral: 'text-neutral-400',
  accent:  'text-accent-600',
}

const visible = computed(() => props.actions.filter(a => a.show === undefined || !!a.show))
const primary = computed(() => visible.value.filter(a => (a.tier ?? 'secondary') === 'primary'))
const secondary = computed(() => visible.value.filter(a => (a.tier ?? 'secondary') === 'secondary'))
const overflow = computed(() => visible.value.filter(a => a.tier === 'overflow'))
const advanced = computed(() => visible.value.filter(a => a.tier === 'advanced'))

// Na mobilu necháme inline jen první 2 akce (primary + sekundární), zbytek spadne do „…".
const MOBILE_INLINE = 2
const mobileInlineKeys = computed(() => new Set(
  [...primary.value, ...secondary.value].slice(0, MOBILE_INLINE).map(a => a.key),
))
// sekundární, které zůstávají inline i na mobilu (jsou mezi prvními 2)
const secondaryMobile = computed(() => secondary.value.filter(a => mobileInlineKeys.value.has(a.key)))
// sekundární jen pro desktop (na mobilu jdou do menu)
const secondaryDesktop = computed(() => secondary.value.filter(a => !mobileInlineKeys.value.has(a.key)))

const hasSecondary = computed(() => secondary.value.length > 0)
const hasSecondaryDesktop = computed(() => secondaryDesktop.value.length > 0)
const hasOverflow = computed(() => overflow.value.length > 0)
const hasAdvanced = computed(() => advanced.value.length > 0)
// „…" trigger: vždy když je overflow/advanced; jinak (jen collapsnuté secondary) jen na mobilu
const showTrigger = computed(() => hasOverflow.value || hasAdvanced.value || hasSecondary.value)
// na desktopu má „…" smysl jen pro overflow/advanced (secondary jsou inline)
const triggerDesktop = computed(() => hasOverflow.value || hasAdvanced.value)
const advancedOpen = ref(false)

function tagOf(a: ActionItem) {
  if (a.to) return RouterLink
  if (a.href) return 'a'
  return 'button'
}
function attrsOf(a: ActionItem): Record<string, unknown> {
  if (a.to) return { to: a.to }
  if (a.href) return { href: a.href, target: '_blank', rel: 'noopener', ...(a.download ? { download: '' } : {}) }
  return { type: 'button' }
}

const inlineBase = 'cursor-pointer px-3 h-9 text-sm font-medium rounded-md inline-flex items-center gap-1.5 disabled:opacity-50 disabled:cursor-not-allowed'

// ─── dropdown ───
const open = ref(false)
const triggerRef = ref<HTMLElement | null>(null)
const menuRef = ref<HTMLElement | null>(null)
const pos = ref({ top: 0, left: 0 })

function reposition() {
  const tr = triggerRef.value?.getBoundingClientRect()
  if (!tr) return
  const mw = menuRef.value?.offsetWidth ?? 224
  const mh = menuRef.value?.offsetHeight ?? 320
  let left = tr.right - mw
  let top = tr.bottom + 4
  if (left < 8) left = 8
  if (top + mh > window.innerHeight - 8) top = Math.max(8, tr.top - mh - 4)
  pos.value = { top, left }
}
async function toggle() {
  if (open.value) { open.value = false; return }
  reposition(); open.value = true; await nextTick(); reposition()
}
function close() { open.value = false; advancedOpen.value = false }
function runItem(a: ActionItem) {
  close()
  if (a.run) a.run()
}

function onKey(e: KeyboardEvent) { if (e.key === 'Escape') close() }
onMounted(() => {
  window.addEventListener('keydown', onKey)
  window.addEventListener('scroll', close, true)
  window.addEventListener('resize', close)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  window.removeEventListener('scroll', close, true)
  window.removeEventListener('resize', close)
})
</script>

<template>
  <div class="flex flex-wrap md:flex-nowrap md:shrink-0 gap-2 md:justify-end items-center">
    <!-- PRIMARY: vždy inline, plné -->
    <component :is="tagOf(a)" v-for="a in primary" :key="a.key" v-bind="attrsOf(a)"
      :class="[inlineBase, FILLED[a.variant ?? 'primary']]"
      :disabled="a.disabled" :title="a.title || undefined"
      @click="a.run && a.run()">
      <svg v-if="a.icon" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[a.icon]" />
      </svg>
      {{ a.loading ? '…' : a.label }}
    </component>

    <!-- SECONDARY (mezi prvními 2): inline i na mobilu -->
    <component :is="tagOf(a)" v-for="a in secondaryMobile" :key="a.key" v-bind="attrsOf(a)"
      :class="[inlineBase, OUTLINE[a.variant ?? 'neutral']]"
      :disabled="a.disabled" :title="a.title || undefined"
      @click="a.run && a.run()">
      <svg v-if="a.icon" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[a.icon]" />
      </svg>
      {{ a.loading ? '…' : a.label }}
    </component>

    <!-- SECONDARY (zbytek): inline jen na sm+; na mobilu jsou v menu -->
    <component :is="tagOf(a)" v-for="a in secondaryDesktop" :key="a.key" v-bind="attrsOf(a)"
      :class="['hidden sm:inline-flex', inlineBase, OUTLINE[a.variant ?? 'neutral']]"
      :disabled="a.disabled" :title="a.title || undefined"
      @click="a.run && a.run()">
      <svg v-if="a.icon" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[a.icon]" />
      </svg>
      {{ a.loading ? '…' : a.label }}
    </component>

    <!-- „…" trigger — light: decentní neutral ghost; dark: vyplněný accent „pill" (drží kontrast na tmavém pozadí) -->
    <button v-if="showTrigger" ref="triggerRef" type="button" @click.stop="toggle"
      :class="['cursor-pointer w-9 h-9 shrink-0 inline-flex items-center justify-center rounded-md ring-1 ring-inset transition-colors',
               open ? 'bg-neutral-100 text-neutral-700 ring-neutral-400 dark:bg-primary-600/40 dark:text-primary-900 dark:ring-primary-500/60'
                    : 'text-neutral-500 ring-neutral-300 hover:bg-neutral-100 hover:text-neutral-700 dark:text-primary-700 dark:bg-primary-100 dark:ring-primary-500/30 dark:hover:bg-primary-600/30 dark:hover:text-primary-900 dark:hover:ring-primary-500/50',
               triggerDesktop ? '' : 'sm:hidden']"
      :aria-expanded="open" :title="t('common.more_actions')">
      <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor"><path :d="DOTS" /></svg>
    </button>

    <Teleport to="body">
      <template v-if="open">
        <div class="fixed inset-0 z-[60]" @click="close" @contextmenu.prevent="close" aria-hidden="true"></div>
        <div ref="menuRef" class="fixed z-[61] w-60 max-w-[calc(100vw-16px)] bg-surface border border-neutral-200 rounded-lg shadow-xl py-1 text-sm"
          :style="{ top: pos.top + 'px', left: pos.left + 'px' }">
          <div class="px-3 py-2 text-xs font-semibold text-neutral-500 text-center border-b border-neutral-100 truncate">
            {{ t('common.more_actions') }}
          </div>

          <!-- collapsnuté secondary jen na mobilu (na desktopu jsou inline) -->
          <div v-if="hasSecondaryDesktop" class="sm:hidden">
            <component :is="tagOf(a)" v-for="a in secondaryDesktop" :key="a.key" v-bind="attrsOf(a)"
              :class="['w-full flex items-center gap-2.5 px-3 py-2 cursor-pointer text-left',
                       a.variant === 'danger' ? 'text-danger-600 hover:bg-danger-50' : 'text-neutral-700 hover:bg-neutral-50',
                       a.disabled ? 'opacity-50 pointer-events-none' : '']"
              :title="a.title || undefined" @click="runItem(a)">
              <svg v-if="a.icon" :class="['w-4 h-4 shrink-0', a.variant === 'danger' ? 'text-danger-600' : MENU_ICON[a.variant ?? 'neutral']]"
                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[a.icon]" />
              </svg>
              <span>{{ a.loading ? '…' : a.label }}</span>
            </component>
            <div v-if="hasOverflow" class="my-1 border-t border-neutral-100"></div>
          </div>

          <!-- overflow vždy -->
          <component :is="tagOf(a)" v-for="a in overflow" :key="a.key" v-bind="attrsOf(a)"
            :class="['w-full flex items-center gap-2.5 px-3 py-2 cursor-pointer text-left',
                     a.variant === 'danger' ? 'text-danger-600 hover:bg-danger-50' : 'text-neutral-700 hover:bg-neutral-50',
                     a.disabled ? 'opacity-50 pointer-events-none' : '']"
            :title="a.title || undefined" @click="runItem(a)">
            <svg v-if="a.icon" :class="['w-4 h-4 shrink-0', a.variant === 'danger' ? 'text-danger-600' : MENU_ICON[a.variant ?? 'neutral']]"
              fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[a.icon]" />
            </svg>
            <span>{{ a.loading ? '…' : a.label }}</span>
          </component>

          <!-- advanced → rozbalovací „Pokročilé" (méně časté / admin / destruktivní akce) -->
          <template v-if="hasAdvanced">
            <div v-if="hasOverflow || hasSecondary" class="my-1 border-t border-neutral-100"></div>
            <button type="button" @click.stop="advancedOpen = !advancedOpen"
              class="w-full flex items-center justify-between gap-2 px-3 py-2 text-neutral-500 hover:bg-neutral-50 cursor-pointer text-left">
              <span class="inline-flex items-center gap-2.5">
                <svg class="w-4 h-4 shrink-0 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 0 1 1.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.929.78l.893.15c.543.09.94.56.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.893.149c-.424.07-.764.383-.929.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 0 1-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.397.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 0 1-.12-1.45l.527-.737c.25-.35.273-.806.108-1.204-.165-.397-.505-.71-.93-.78l-.893-.15c-.543-.09-.94-.56-.94-1.109v-1.094c0-.55.397-1.02.94-1.11l.893-.149c.425-.07.765-.383.93-.78.165-.398.143-.854-.108-1.204l-.526-.738a1.125 1.125 0 0 1 .12-1.45l.773-.773a1.125 1.125 0 0 1 1.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894z" />
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z" />
                </svg>
                {{ t('common.advanced') }}
              </span>
              <svg class="w-3.5 h-3.5 text-neutral-400 transition-transform" :class="{ 'rotate-180': advancedOpen }"
                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
              </svg>
            </button>
            <template v-if="advancedOpen">
              <component :is="tagOf(a)" v-for="a in advanced" :key="a.key" v-bind="attrsOf(a)"
                :class="['w-full flex items-center gap-2.5 pl-6 pr-3 py-2 cursor-pointer text-left',
                         a.variant === 'danger' ? 'text-danger-600 hover:bg-danger-50' : 'text-neutral-700 hover:bg-neutral-50',
                         a.disabled ? 'opacity-50 pointer-events-none' : '']"
                :title="a.title || undefined" @click="runItem(a)">
                <svg v-if="a.icon" :class="['w-4 h-4 shrink-0', a.variant === 'danger' ? 'text-danger-600' : MENU_ICON[a.variant ?? 'neutral']]"
                  fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[a.icon]" />
                </svg>
                <span>{{ a.loading ? '…' : a.label }}</span>
              </component>
            </template>
          </template>
        </div>
      </template>
    </Teleport>
  </div>
</template>
