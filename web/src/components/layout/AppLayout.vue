<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { RouterLink, RouterView, useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { systemApi } from '@/api/system'
import TopbarMenu from './TopbarMenu.vue'
import { THEME_OPTIONS } from './themeOptions'
import { useTheme } from '@/composables/useTheme'
import Badge from '@/components/ui/Badge.vue'
import AppLogo from './AppLogo.vue'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'
import { useToast } from '@/composables/useToast'
import { loadReceivedShares, useOwnerContext } from '@/composables/useOwnerContext'

const { t, locale } = useI18n()
// Názvy jazyků se nepřekládají — každý v jazyce, který zapíná.
const LOCALES = [{ code: 'cs', name: 'Čeština' }, { code: 'en', name: 'English' }] as const
function setLocale(l: 'cs' | 'en') {
  locale.value = l
  localStorage.setItem('locale', l)
}

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const sessionSecurity = useSessionSecurityStore()
const toast = useToast()
const owner = useOwnerContext()
const { preference: themePreference } = useTheme()
const currentTheme = computed(() => THEME_OPTIONS.find(o => o.value === themePreference.value) ?? THEME_OPTIONS[0])
const initials = computed(() => (auth.user?.name ?? '').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]!.toUpperCase()).join(''))

const mobileOpen = ref(false)
const logoutBusy = ref(false)
const canLockSession = computed(() => sessionSecurity.state?.session_state === 'active'
  && sessionSecurity.state.unlock_methods.includes('passkey'))

async function logout() {
  if (logoutBusy.value) return
  logoutBusy.value = true
  try {
    await auth.logout()
    sessionSecurity.clear()
    mobileOpen.value = false
    await router.replace('/login')
  } catch {
    sessionSecurity.markLocked()
    sessionSecurity.error = 'logout_failed'
    toast.error(t('auth.logout_failed'))
  } finally {
    logoutBusy.value = false
  }
}

interface NavItem {
  to: string
  label: string
  icon: string
}
interface NavSection {
  /** Klíč sbalitelné skupiny (stav v localStorage); bez klíče je sekce vždy rozbalená */
  key?: NavGroupKey
  /** Hlavička sekce; pokud chybí, položky jsou bez visual grouping */
  title?: string
  /** Color accent pro vertikální pruh + text. Tailwind utility class group. */
  accent?: 'primary' | 'warning' | 'success' | 'danger' | 'neutral'
  items: NavItem[]
}

/** Mapování accent → soft pill (background + text) per sekce. */
const ACCENT_CLASSES: Record<NonNullable<NavSection['accent']>, string> = {
  primary: 'bg-primary-50  text-primary-700',
  warning: 'bg-warning-50  text-warning-600',
  success: 'bg-success-50  text-success-600',
  danger:  'bg-danger-50   text-danger-500',
  neutral: 'bg-neutral-100 text-neutral-600',
}

/** Outline icon paths — Heroicons style, stroke 2, viewBox 24, currentColor */
const ICONS = {
  dashboard:  'M3 12l9-9 9 9M5 10v10h14V10',
  users:      'M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a3 3 0 0 1 5.356-1.857M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0z',
  log:        'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2M9 12h6m-6 4h4',
  cron:       'M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  api_tokens: 'M15 7a2 2 0 0 1 2 2m4 0a6 6 0 0 1-7.743 5.743L11 17H9v2H7v2H4a1 1 0 0 1-1-1v-2.586a1 1 0 0 1 .293-.707l5.964-5.964A6 6 0 1 1 21 9z',
  exchanges:  'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4',
  market:     'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6',
  mail:       'M3 8l7.89 5.26a2 2 0 0 0 2.22 0L21 8M5 19h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2z',
  profile:    'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM12 14a7 7 0 0 0-7 7h14a7 7 0 0 0-7-7z',
  positions:  'M11 3.055A9.001 9.001 0 1 0 20.945 13H11V3.055zM20.488 9H15V3.512A9.025 9.025 0 0 1 20.488 9z',
  transactions: 'M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4',
  import:     'M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-8l-4-4m0 0L8 8m4-4v12',
  allocation: 'M3 6h18M3 12h18M3 18h18M9 3v6m6 3v6m-3 0v3',
  update:     'M4 4v5h.582m15.356 2A8.001 8.001 0 0 0 4.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 0 1-15.357-2m15.357 2H15',
  settings:   'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 0 0-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 0 0-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 0 0-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 0 0-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 0 0 1.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z',
  manual:     'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253',
  household:  'M17 20h5v-2a3 3 0 0 0-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 0 1 5.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 0 1 9.288 0M15 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0z',
  sharing:    'M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 1 1 0-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 1 0 5.367-2.684 3 3 0 0 0-5.367 2.684zm0 9.316a3 3 0 1 0 5.367 2.684 3 3 0 0 0-5.367-2.684z',
  net_worth:  'M3 21h18M5 21V10l7-6 7 6v11M9 21v-6h6v6',
  summary:    'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2z',
  income:     'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  tax:        'M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z',
  accounts:   'M3 9l9-7 9 7m-2 0v9a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V9m4 11V13h4v7',
  portfolios: 'M19 11H5m14 0a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2m14 0V9a2 2 0 0 0-2-2M5 11V9a2 2 0 0 1 2-2m0 0V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2M7 7h10',
  instruments: 'M3 3v18h18M7 14l4-4 4 4 5-5',
  institutions: 'M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4',
}

const GITHUB_URL = 'https://github.com/jindrichmach/myportfolio'
const GITHUB_ICON = 'M12 .5C5.65.5.5 5.65.5 12a11.5 11.5 0 0 0 7.86 10.92c.58.1.79-.25.79-.56v-2c-3.2.7-3.88-1.37-3.88-1.37-.52-1.33-1.28-1.69-1.28-1.69-1.04-.71.08-.7.08-.7 1.16.08 1.77 1.19 1.77 1.19 1.03 1.77 2.7 1.26 3.36.96.1-.75.4-1.26.73-1.55-2.55-.29-5.24-1.28-5.24-5.69 0-1.26.45-2.29 1.19-3.1-.12-.29-.52-1.46.11-3.05 0 0 .97-.31 3.17 1.18a11 11 0 0 1 5.78 0c2.2-1.49 3.17-1.18 3.17-1.18.63 1.59.23 2.76.11 3.05.74.81 1.19 1.84 1.19 3.1 0 4.42-2.69 5.39-5.25 5.68.41.36.78 1.06.78 2.14v3.17c0 .31.21.67.8.56A11.5 11.5 0 0 0 23.5 12C23.5 5.65 18.35.5 12 .5z'
const GLOBE_ICON = 'M3.055 11H5a2 2 0 0 1 2 2v1a2 2 0 0 0 2 2 2 2 0 0 1 2 2v2.945M8 3.935V5.5A2.5 2.5 0 0 0 10.5 8h.5a2 2 0 0 1 2 2 2 2 0 1 0 4 0 2 2 0 0 1 2-2h1.064M15 20.488V18a2 2 0 0 1 2-2h3.064M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z'
const LOGOUT_ICON = 'M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3h4a3 3 0 0 1 3 3v1'
const LOCK_ICON = 'M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2zm10-10V7a4 4 0 0 0-8 0v4h8z'

/** Uživatelské menu v horní liště = záložky Profilu (`?tab=`, viz pages/Profile.vue). */
const profileTabs = computed(() => [
  { tab: 'account', label: t('profile.tab_account'), icon: ICONS.profile },
  { tab: 'password', label: t('auth.change_password_title'), icon: ICONS.api_tokens },
  { tab: 'totp', label: t('auth.totp_tab'), icon: 'M12 18h.01M8 21h8a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2z',
    badge: auth.user?.mfa_methods?.includes('totp') ? t('auth.totp_badge_on') : null },
  { tab: 'passkeys', label: t('passkeys.title'), icon: 'M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 0 0 8 11a4 4 0 1 1 8 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0 0 15.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 0 0 8 4.07M3 15.364c.64-1.319 1-2.8 1-4.364 0-1.457.39-2.823 1.07-4' },
  { tab: 'session-lock', label: t('session_lock.preference_tab'), icon: LOCK_ICON },
  { tab: 'reports', label: t('profile.tab_reports'), icon: 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z' },
  { tab: 'alerts', label: t('profile.tab_alerts'), icon: 'M15 17h5l-1.405-1.405A2.032 2.032 0 0 1 18 14.158V11a6.002 6.002 0 0 0-4-5.659V5a2 2 0 1 0-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 1 1-6 0v-1m6 0H9' },
])

// Sbalené menu (jen ikony) na desktopu; volba se pamatuje v prohlížeči.
const COLLAPSED_KEY = 'myportfolio-sidebar-collapsed'
const collapsed = ref((() => { try { return localStorage.getItem(COLLAPSED_KEY) === '1' } catch { return false } })())
function toggleCollapsed() {
  collapsed.value = !collapsed.value
  try { localStorage.setItem(COLLAPSED_KEY, collapsed.value ? '1' : '0') } catch { /* jen do obnovení stránky */ }
}

// Sbalitelné skupiny menu — stav per skupina v prohlížeči; Systém (jen admin) je výchozí sbalený.
type NavGroupKey = 'portfolio' | 'setup' | 'account' | 'system'
const NAV_GROUP_KEY = 'myportfolio-nav-'
function readGroup(key: NavGroupKey, fallback: boolean): boolean {
  try {
    const v = localStorage.getItem(NAV_GROUP_KEY + key)
    return v === null ? fallback : v === '1'
  } catch { return fallback }
}
const groupOpen = ref<Record<NavGroupKey, boolean>>({
  portfolio: readGroup('portfolio', true),
  setup: readGroup('setup', true),
  account: readGroup('account', true),
  system: readGroup('system', false),
})
function toggleGroup(key: NavGroupKey) {
  groupOpen.value[key] = !groupOpen.value[key]
  try { localStorage.setItem(NAV_GROUP_KEY + key, groupOpen.value[key] ? '1' : '0') } catch { /* jen do obnovení stránky */ }
}
/** V úzkém menu (jen ikony) se skupiny nesbalují — nadpisy tam nejsou vidět. */
const sectionOpen = (section: NavSection) => !section.key || collapsed.value || groupOpen.value[section.key]

const navSections = computed<NavSection[]>(() => {
  const sections: NavSection[] = [
    { items: [{ to: '/', label: t('nav.dashboard'), icon: ICONS.dashboard }] },
    {
      key: 'portfolio',
      title: t('nav.section_portfolio'),
      accent: 'success',
      items: [
        { to: '/positions',    label: t('nav.positions'),    icon: ICONS.positions },
        { to: '/transactions', label: t('nav.transactions'), icon: ICONS.transactions },
        { to: '/transactions/import', label: t('nav.import'), icon: ICONS.import },
        { to: '/allocation',   label: t('nav.allocation'),   icon: ICONS.allocation },
        { to: '/net-worth',    label: t('nav.net_worth'),    icon: ICONS.net_worth },
        ...(owner.received.value.length ? [{ to: '/household', label: t('nav.household'), icon: ICONS.household }] : []),
        { to: '/summary',      label: t('nav.summary'),      icon: ICONS.summary },
        { to: '/income',       label: t('nav.income'),       icon: ICONS.income },
        { to: '/tax-report',   label: t('nav.tax_report'),   icon: ICONS.tax },
      ],
    },
    {
      // Kde a v čem držíš: účty, portfolia a sdílený katalog — pro nováčka na jednom místě
      key: 'setup',
      title: t('nav.section_setup'),
      accent: 'warning',
      items: [
        { to: '/accounts',     label: t('nav.accounts'),     icon: ICONS.accounts },
        { to: '/portfolios',   label: t('nav.portfolios'),   icon: ICONS.portfolios },
        { to: '/catalog/instruments',  label: t('nav.instruments'),  icon: ICONS.instruments },
        { to: '/catalog/institutions', label: t('nav.institutions'), icon: ICONS.institutions },
      ],
    },
    {
      key: 'account',
      title: t('nav.section_account'),
      accent: 'primary',
      items: [
        { to: '/profile',            label: t('nav.profile'),    icon: ICONS.profile },
        { to: '/profile/sharing',    label: t('nav.sharing'),    icon: ICONS.sharing },
        { to: '/profile/exchanges',  label: t('nav.exchanges'),  icon: ICONS.exchanges },
        { to: '/profile/bank-notices', label: t('nav.bank_notices'), icon: ICONS.mail },
        { to: '/profile/api',        label: t('nav.api'),        icon: ICONS.api_tokens },
        ...(auth.user?.role === 'admin' ? [] : [{ to: '/manual', label: t('nav.manual'), icon: ICONS.manual }]),
      ],
    },
  ]

  // Sdílená data partnera: skryj, co sdílení nepovoluje (backend to stejně odmítne)
  if (owner.inShared.value) {
    const hidden: Record<string, boolean> = {
      '/transactions/import': !owner.canWrite.value,
      '/tax-report': !owner.hasSection('tax'),
      '/income': !owner.hasSection('income'),
      '/net-worth': !owner.hasSection('net_worth'),
    }
    for (const section of sections) section.items = section.items.filter(i => !hidden[i.to])
  }

  if (auth.user?.role === 'admin') {
    sections.push({
      key: 'system',
      title: t('nav.system'),
      accent: 'neutral',
      items: [
        { to: '/admin/users',        label: t('nav.users'),     icon: ICONS.users },
        { to: '/admin/market',       label: t('nav.market'),    icon: ICONS.market },
        { to: '/admin/settings',     label: t('nav.settings'),  icon: ICONS.settings },
        { to: '/admin/activity-log', label: t('nav.log'),       icon: ICONS.log },
        { to: '/admin/cron-jobs',    label: t('nav.cron_jobs'), icon: ICONS.cron },
        { to: '/admin/update',       label: t('nav.update'),    icon: ICONS.update },
        { to: '/manual',             label: t('nav.manual'),    icon: ICONS.manual },
      ],
    })
  }

  return sections
})

/**
 * „Pokrývá" URL (path + případná query) současnou route?
 * Path musí sedět přesně nebo jako rodič skutečného child segmentu — prostý
 * startsWith by matchoval i sourozence se stejným prefixem (např. /reports/dph
 * by matchoval /reports/dph-book). Query klíče z URL musí všechny sedět
 * s route.query; `queried` říká, že shoda vznikla i přes query (= specifičtější).
 */
function urlCoversRoute(url: string): { covers: boolean; queried: boolean } {
  const [path, qs] = url.split('?', 2)
  if (route.path !== path && !route.path.startsWith(path + '/')) return { covers: false, queried: false }
  if (!qs) return { covers: true, queried: false }
  for (const [k, v] of new URLSearchParams(qs)) {
    if (String(route.query[k] ?? '') !== v) return { covers: false, queried: false }
  }
  return { covers: true, queried: true }
}

function isActive(item: NavItem): boolean {
  const to = item.to
  if (to === '/') return route.path === '/'

  const [toPath] = to.split('?', 2)

  if (!urlCoversRoute(to).covers) return false

  // Delší `to` v menu má přednost (např. /profile vs /profile/api).
  for (const section of navSections.value) {
    for (const it of section.items) {
      if (it.to !== to && it.to.startsWith(toPath + '/') && route.path.startsWith(it.to.split('?')[0])) {
        return false
      }
    }
  }
  return true
}

// Zavři mobile drawer po navigaci; skupinu s aktivní stránkou rozbal (bez uložení), ať je vidět, kde jsem.
watch(() => route.fullPath, () => {
  mobileOpen.value = false
  for (const section of navSections.value) {
    if (section.key && section.items.some(isActive)) groupOpen.value[section.key] = true
  }
}, { immediate: true })

/** Přeskočit na obsah: fokus na <main> (odkaz s #main by v historii routeru přidal hash). */
const mainEl = ref<HTMLElement | null>(null)
function skipToMain() {
  mainEl.value?.focus()
}

const version = ref<string | null>(null)
onMounted(loadReceivedShares)
onMounted(async () => {
  try { version.value = (await systemApi.version()).current } catch {}
})
</script>

<template>
  <div class="min-h-screen flex flex-col bg-neutral-50">
    <a href="#main" @click.prevent="skipToMain"
       class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:px-3 focus:py-2 focus:rounded-md focus:bg-surface focus:text-primary-700 focus:shadow-md focus:ring-2 focus:ring-primary-500 text-sm font-medium">
      {{ t('nav.skip_to_content') }}
    </a>

    <!-- ═════════════════════ TOPBAR ═════════════════════ -->
    <header class="sticky top-0 z-30 bg-surface border-b border-neutral-200 print:hidden">
      <div class="h-14 px-4 flex items-center justify-between gap-3">
        <!-- Logo -->
        <RouterLink to="/" class="flex items-center gap-2.5 shrink-0" @click="mobileOpen = false">
          <AppLogo class="w-8 h-8" />
          <span class="hidden sm:inline text-sm font-semibold leading-tight select-none">
            My<span class="text-primary-700">Portfolio</span>
          </span>
        </RouterLink>

        <!-- Pravá strana topbaru -->
        <div class="flex items-center gap-1 sm:gap-2 text-sm min-w-0">
          <!-- Čí data zobrazuji (sdílení pro domácnost) -->
          <select v-if="owner.received.value.length" :value="owner.ownerId.value ?? ''" :aria-label="t('sharing.context_label')"
                  class="hidden sm:block h-8 px-2 border border-field rounded-md bg-surface text-sm max-w-44"
                  @change="owner.switchTo(($event.target as HTMLSelectElement).value === '' ? null : Number(($event.target as HTMLSelectElement).value))">
            <option value="">{{ t('sharing.my_data') }}</option>
            <option v-for="s in owner.received.value" :key="s.id" :value="s.owner_user_id">{{ s.owner_name }}</option>
          </select>
          <!-- Ikony a rozbalovací menu (i na mobilu) -->
          <a :href="GITHUB_URL" target="_blank" rel="noopener" :title="t('nav.github')" :aria-label="t('nav.github')"
             class="inline-flex items-center justify-center w-8 h-8 shrink-0 rounded-md text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900">
            <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path :d="GITHUB_ICON" /></svg>
          </a>
          <RouterLink to="/manual" :title="t('nav.manual')" :aria-label="t('nav.manual')"
             class="inline-flex items-center justify-center w-8 h-8 shrink-0 rounded-md text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900">
            <svg class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.manual" />
            </svg>
          </RouterLink>

          <!-- Motiv (System / Light / Dark) -->
          <TopbarMenu class="shrink-0" :label="t('theme.label')" @open="mobileOpen = false" :title="t(currentTheme.key)">
            <template #trigger>
              <svg class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" :d="currentTheme.icon" />
              </svg>
            </template>
            <template #default="{ close }">
              <button v-for="opt in THEME_OPTIONS" :key="opt.value" type="button" role="menuitemradio" tabindex="-1"
                :aria-checked="themePreference === opt.value" @click="themePreference = opt.value; close()"
                class="cursor-pointer w-full flex items-center gap-2.5 px-3 py-2 text-left hover:bg-neutral-50 focus:bg-neutral-50 focus:outline-none"
                :class="themePreference === opt.value ? 'text-primary-700 font-medium' : 'text-neutral-700'">
                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="opt.icon" />
                </svg>
                {{ t(opt.key) }}
              </button>
            </template>
          </TopbarMenu>

          <!-- Jazyk textem (vlajka znamená zemi, ne jazyk); názvy jazyků v jazyce, který zapínají -->
          <TopbarMenu class="shrink-0" :label="t('common.language')" @open="mobileOpen = false">
            <template #trigger>
              <svg class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" :d="GLOBE_ICON" />
              </svg>
              <span class="text-xs font-semibold uppercase">{{ locale }}</span>
            </template>
            <template #default="{ close }">
              <button v-for="l in LOCALES" :key="l.code" type="button" role="menuitemradio" tabindex="-1" :lang="l.code"
                :aria-checked="locale === l.code" @click="setLocale(l.code); close()"
                class="cursor-pointer w-full flex items-center justify-between gap-6 px-3 py-2 text-left hover:bg-neutral-50 focus:bg-neutral-50 focus:outline-none"
                :class="locale === l.code ? 'text-primary-700 font-medium' : 'text-neutral-700'">
                {{ l.name }}
                <span class="text-xs uppercase text-neutral-400">{{ l.code }}</span>
              </button>
            </template>
          </TopbarMenu>

          <!-- Uživatel: jméno, e-mail, role, záložky profilu, zámek, odhlášení -->
          <TopbarMenu class="shrink-0" wide :label="t('nav.user_menu')" @open="mobileOpen = false">
            <template #trigger>
              <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-primary-50 text-primary-700 text-xs font-semibold" aria-hidden="true">{{ initials }}</span>
              <span class="hidden lg:inline text-sm font-medium max-w-40 truncate">{{ auth.user?.name }}</span>
              <svg class="hidden sm:block w-3.5 h-3.5 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
              </svg>
            </template>
            <template #default="{ close }">
              <div class="px-3 pt-2 pb-3 sm:min-w-60">
                <div class="font-semibold text-neutral-900 truncate">{{ auth.user?.name }}</div>
                <div class="text-xs text-neutral-500 truncate">{{ auth.user?.email }}</div>
                <Badge class="mt-1.5" :tone="auth.user?.role === 'admin' ? 'success' : 'neutral'">{{ auth.user?.role }}</Badge>
              </div>
              <div class="border-t border-neutral-200 my-1"></div>
              <RouterLink v-for="item in profileTabs" :key="item.tab" role="menuitem" tabindex="-1" @click="close()"
                :to="item.tab === 'account' ? '/profile' : { path: '/profile', query: { tab: item.tab } }"
                class="flex items-center gap-2.5 px-3 py-2 text-neutral-700 hover:bg-neutral-50 focus:bg-neutral-50 focus:outline-none">
                <svg class="w-4 h-4 shrink-0 text-neutral-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
                </svg>
                <span class="flex-1">{{ item.label }}</span>
                <Badge v-if="item.badge" tone="success">{{ item.badge }}</Badge>
              </RouterLink>
              <div class="border-t border-neutral-200 my-1"></div>
              <button v-if="canLockSession" type="button" role="menuitem" tabindex="-1" @click="close(); sessionSecurity.lock()"
                class="cursor-pointer w-full flex items-center gap-2.5 px-3 py-2 text-left text-neutral-700 hover:bg-neutral-50 focus:bg-neutral-50 focus:outline-none">
                <svg class="w-4 h-4 shrink-0 text-neutral-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="LOCK_ICON" />
                </svg>
                {{ t('session_lock.lock_now') }}
              </button>
              <button type="button" role="menuitem" tabindex="-1" :disabled="logoutBusy" @click="close(); logout()"
                class="cursor-pointer w-full flex items-center gap-2.5 px-3 py-2 text-left text-danger-500 hover:bg-danger-50 focus:bg-danger-50 focus:outline-none disabled:opacity-60">
                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="LOGOUT_ICON" />
                </svg>
                {{ t('nav.logout') }}
              </button>
            </template>
          </TopbarMenu>

          <!-- Hamburger (mobile, < lg) -->
          <button
            type="button" @click="mobileOpen = !mobileOpen"
            :aria-expanded="mobileOpen" aria-label="Menu"
            class="lg:hidden inline-flex items-center justify-center w-9 h-9 shrink-0 rounded-md text-neutral-700 hover:bg-neutral-100"
          >
            <svg v-if="!mobileOpen" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
            <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>

    </header>

    <!-- ═════════════════════ TĚLO: SIDEBAR + OBSAH ═════════════════════ -->
    <div class="flex flex-1 min-h-0">

      <!-- Mobile backdrop -->
      <div
        v-if="mobileOpen" @click="mobileOpen = false"
        class="lg:hidden fixed inset-0 bg-backdrop z-20"
        aria-hidden="true"
      ></div>

      <!-- ── SIDEBAR ── -->
      <aside
        :class="[
          'fixed lg:sticky top-14 z-30 lg:z-auto',
          'h-[calc(100dvh-3.5rem)] w-60 shrink-0',
          collapsed ? 'lg:w-14' : '',
          'bg-surface border-r border-neutral-200',
          'flex flex-col print:hidden',
          'transition-transform duration-200 ease-in-out',
          mobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
        ]"
      >
        <!-- Čí data zobrazuji — na telefonu se do horní lišty nevejde -->
        <div v-if="owner.received.value.length" class="sm:hidden px-2.5 pt-3">
          <select :value="owner.ownerId.value ?? ''" :aria-label="t('sharing.context_label')"
                  class="w-full h-9 px-2 border border-field rounded-md bg-surface text-sm"
                  @change="owner.switchTo(($event.target as HTMLSelectElement).value === '' ? null : Number(($event.target as HTMLSelectElement).value))">
            <option value="">{{ t('sharing.my_data') }}</option>
            <option v-for="s in owner.received.value" :key="s.id" :value="s.owner_user_id">{{ s.owner_name }}</option>
          </select>
        </div>
        <nav class="flex-1 overflow-y-auto scrollbar-slim px-2.5 py-3">
          <template v-for="(section, si) in navSections" :key="si">
            <!-- Section title — soft pill background v barvě sekce -->
            <div v-if="section.title && collapsed" class="hidden lg:block mx-1 my-2 border-t border-neutral-200" aria-hidden="true"></div>
            <div v-if="section.title" :class="[si === 0 ? 'pt-1 pb-1.5' : 'pt-4 pb-1.5', collapsed ? 'lg:hidden' : '']">
              <button v-if="section.key" type="button" class="cursor-pointer group w-full flex items-center justify-between gap-2 rounded-md"
                      :aria-expanded="groupOpen[section.key]" :aria-controls="`nav-group-${section.key}`" @click="toggleGroup(section.key)">
                <span
                  class="inline-flex items-center px-2.5 py-1 rounded-md text-[11px] font-bold uppercase tracking-wider"
                  :class="section.accent ? ACCENT_CLASSES[section.accent] : 'bg-neutral-100 text-neutral-600'"
                >{{ section.title }}</span>
                <svg class="w-3.5 h-3.5 mr-1.5 shrink-0 text-neutral-400 group-hover:text-neutral-700 transition-transform" :class="{ '-rotate-90': !groupOpen[section.key] }"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                </svg>
              </button>
              <div v-else
                class="inline-flex items-center px-2.5 py-1 rounded-md text-[11px] font-bold uppercase tracking-wider"
                :class="section.accent ? ACCENT_CLASSES[section.accent] : 'bg-neutral-100 text-neutral-600'"
              >{{ section.title }}</div>
            </div>

            <div v-show="sectionOpen(section)" :id="section.key ? `nav-group-${section.key}` : undefined">
            <RouterLink
              v-for="item in section.items" :key="item.to"
              :to="item.to"
              active-class=""
              exact-active-class=""
              :title="collapsed ? item.label : undefined"
              class="flex items-center gap-2.5 px-2.5 py-[7px] rounded-md text-sm transition-colors leading-tight"
              :class="isActive(item)
                ? 'bg-primary-50 text-primary-700 font-medium'
                : 'text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100'"
            >
              <svg class="w-[15px] h-[15px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
              </svg>
              <span :class="collapsed ? 'lg:sr-only' : ''">{{ item.label }}</span>
            </RouterLink>
            </div>
          </template>
        </nav>

        <!-- Sbalit / rozbalit menu (desktop) -->
        <button type="button" class="cursor-pointer hidden lg:flex items-center gap-2 px-4 py-2 border-t border-neutral-100 text-xs text-neutral-500 hover:text-neutral-900 hover:bg-neutral-50"
                :aria-expanded="!collapsed" :title="collapsed ? t('nav.expand_menu') : undefined" @click="toggleCollapsed">
          <svg class="w-4 h-4 shrink-0 transition-transform" :class="{ 'rotate-180': collapsed }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M11 19l-7-7 7-7m8 14l-7-7 7-7" />
          </svg>
          <span :class="collapsed ? 'sr-only' : ''">{{ collapsed ? t('nav.expand_menu') : t('nav.collapse_menu') }}</span>
        </button>

        <!-- Verze (dole) -->
        <div v-if="version" class="px-4 py-2.5 border-t border-neutral-100 text-xs text-neutral-500" :class="collapsed ? 'lg:hidden' : ''">
          MyPortfolio v{{ version }}
        </div>

      </aside>

      <!-- ── HLAVNÍ OBSAH ── -->
      <div class="flex-1 min-w-0 flex flex-col">
        <main id="main" ref="mainEl" tabindex="-1" class="flex-1 px-5 sm:px-8 py-6 w-full outline-none">
          <div v-if="owner.share.value" class="mb-4 rounded-md bg-primary-50 border border-primary-500/30 px-3 py-2 text-sm text-primary-700 flex flex-wrap items-center justify-between gap-2">
            <span>{{ t('sharing.viewing', { name: owner.share.value.owner_name }) }} · {{ t(`sharing.permissions.${owner.share.value.permission}`) }}<template v-if="owner.share.value.scope === 'portfolios'"> · {{ owner.share.value.portfolios.map(p => p.name).join(', ') }}</template></span>
            <button type="button" class="cursor-pointer text-xs font-medium hover:underline" @click="owner.switchTo(null)">{{ t('sharing.back_to_mine') }}</button>
          </div>
          <RouterView />
        </main>

      </div>
    </div>

  </div>
</template>
