<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { RouterLink, RouterView, useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { systemApi } from '@/api/system'
import ThemeToggle from './ThemeToggle.vue'
import AppLogo from './AppLogo.vue'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'
import { useToast } from '@/composables/useToast'
import { loadReceivedShares, useOwnerContext } from '@/composables/useOwnerContext'

const { t, locale } = useI18n()
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

const navSections = computed<NavSection[]>(() => {
  const sections: NavSection[] = [
    { items: [{ to: '/', label: t('nav.dashboard'), icon: ICONS.dashboard }] },
    {
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
        { to: '/accounts',     label: t('nav.accounts'),     icon: ICONS.accounts },
        { to: '/portfolios',   label: t('nav.portfolios'),   icon: ICONS.portfolios },
      ],
    },
    {
      title: t('nav.section_catalog'),
      accent: 'warning',
      items: [
        { to: '/catalog/instruments',  label: t('nav.instruments'),  icon: ICONS.instruments },
        { to: '/catalog/institutions', label: t('nav.institutions'), icon: ICONS.institutions },
      ],
    },
    {
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

// Zavři mobile drawer po navigaci
watch(() => route.path, () => { mobileOpen.value = false })

const version = ref<string | null>(null)
onMounted(loadReceivedShares)
onMounted(async () => {
  try { version.value = (await systemApi.version()).current } catch {}
})
</script>

<template>
  <div class="min-h-screen flex flex-col bg-neutral-50">

    <!-- ═════════════════════ TOPBAR ═════════════════════ -->
    <header class="sticky top-0 z-30 bg-surface border-b border-neutral-200 print:hidden">
      <div class="h-14 px-4 flex items-center justify-between gap-3">
        <!-- Logo -->
        <RouterLink to="/" class="flex items-center gap-2.5 shrink-0" @click="mobileOpen = false">
          <AppLogo class="w-8 h-8" />
          <span class="text-sm font-semibold leading-tight select-none">
            My<span class="text-primary-700">Portfolio</span>
          </span>
        </RouterLink>

        <!-- Pravá strana topbaru -->
        <div class="flex items-center gap-2 text-sm">
          <!-- Čí data zobrazuji (sdílení pro domácnost) -->
          <select v-if="owner.received.value.length" :value="owner.ownerId.value ?? ''" :aria-label="t('sharing.context_label')"
                  class="h-8 px-2 border border-field rounded-md bg-surface text-sm max-w-44"
                  @change="owner.switchTo(($event.target as HTMLSelectElement).value === '' ? null : Number(($event.target as HTMLSelectElement).value))">
            <option value="">{{ t('sharing.my_data') }}</option>
            <option v-for="s in owner.received.value" :key="s.id" :value="s.owner_user_id">{{ s.owner_name }}</option>
          </select>
          <!-- Jméno uživatele (desktop) — link na profil (heslo + 2FA v záložkách). -->
          <RouterLink
            to="/profile/password"
            class="hidden lg:inline text-sm text-neutral-600 hover:text-primary-700 hover:underline"
            :title="t('auth.profile_title')"
          >{{ auth.user?.name }}</RouterLink>

          <!-- Locale switcher (CZ / EN s SVG vlajkami) -->
          <div class="hidden sm:inline-flex items-center border border-neutral-200 rounded-md overflow-hidden">
            <button
              @click="setLocale('cs')" title="Čeština" aria-label="Čeština"
              class="cursor-pointer h-8 px-2 inline-flex items-center"
              :class="locale === 'cs' ? 'bg-primary-50' : 'hover:bg-neutral-50 grayscale opacity-60 hover:grayscale-0 hover:opacity-100'"
            >
              <svg width="22" height="15" viewBox="0 0 6 4" xmlns="http://www.w3.org/2000/svg">
                <rect width="6" height="2" fill="#ffffff"/>
                <rect y="2" width="6" height="2" fill="#d7141a"/>
                <polygon points="0,0 3,2 0,4" fill="#11457e"/>
              </svg>
            </button>
            <button
              @click="setLocale('en')" title="English" aria-label="English"
              class="cursor-pointer h-8 px-2 inline-flex items-center border-l border-neutral-200"
              :class="locale === 'en' ? 'bg-primary-50' : 'hover:bg-neutral-50 grayscale opacity-60 hover:grayscale-0 hover:opacity-100'"
            >
              <svg width="22" height="15" viewBox="0 0 60 30" xmlns="http://www.w3.org/2000/svg">
                <clipPath id="uk-flag-tb"><path d="M30,15 h30 v15 z v15 h-30 z h-30 v-15 z v-15 h30 z"/></clipPath>
                <path d="M0,0 v30 h60 v-30 z" fill="#012169"/>
                <path d="M0,0 L60,30 M60,0 L0,30" stroke="#fff" stroke-width="6"/>
                <path d="M0,0 L60,30 M60,0 L0,30" clip-path="url(#uk-flag-tb)" stroke="#C8102E" stroke-width="4"/>
                <path d="M30,0 v30 M0,15 h60" stroke="#fff" stroke-width="10"/>
                <path d="M30,0 v30 M0,15 h60" stroke="#C8102E" stroke-width="6"/>
              </svg>
            </button>
          </div>

          <!-- Přepínač motivu (System / Light / Dark) — na mobilu je v drawer patičce -->
          <div class="hidden sm:inline-flex">
            <ThemeToggle />
          </div>

          <!-- Odhlásit (desktop) -->
          <button
            v-if="canLockSession"
            @click="sessionSecurity.lock"
            class="cursor-pointer hidden sm:inline-flex px-3 h-8 items-center text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50"
          >{{ t('session_lock.lock_now') }}</button>
          <button
            @click="logout"
            :disabled="logoutBusy"
            class="cursor-pointer hidden sm:inline-flex px-3 h-8 items-center text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50 disabled:opacity-60"
          >{{ t('nav.logout') }}</button>

          <!-- Hamburger (mobile, < lg) -->
          <button
            type="button" @click="mobileOpen = !mobileOpen"
            :aria-expanded="mobileOpen" aria-label="Menu"
            class="lg:hidden inline-flex items-center justify-center w-9 h-9 rounded-md text-neutral-700 hover:bg-neutral-100"
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
          'h-[calc(100vh-3.5rem)] w-60 shrink-0',
          'bg-surface border-r border-neutral-200',
          'flex flex-col print:hidden',
          'transition-transform duration-200 ease-in-out',
          mobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
        ]"
      >
        <nav class="flex-1 overflow-y-auto scrollbar-slim px-2.5 py-3">
          <template v-for="(section, si) in navSections" :key="si">
            <!-- Section title — soft pill background v barvě sekce -->
            <div v-if="section.title" :class="si === 0 ? 'pt-1 pb-1.5' : 'pt-4 pb-1.5'">
              <div
                class="inline-flex items-center px-2.5 py-1 rounded-md text-[11px] font-bold uppercase tracking-wider"
                :class="section.accent ? ACCENT_CLASSES[section.accent] : 'bg-neutral-100 text-neutral-600'"
              >{{ section.title }}</div>
            </div>

            <RouterLink
              v-for="item in section.items" :key="item.to"
              :to="item.to"
              active-class=""
              exact-active-class=""
              class="flex items-center gap-2.5 px-2.5 py-[7px] rounded-md text-sm transition-colors leading-tight"
              :class="isActive(item)
                ? 'bg-primary-50 text-primary-700 font-medium'
                : 'text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100'"
            >
              <svg class="w-[15px] h-[15px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
              </svg>
              {{ item.label }}
            </RouterLink>
          </template>
        </nav>

        <!-- Verze (dole) -->
        <div v-if="version" class="px-4 py-2.5 border-t border-neutral-100 text-xs text-neutral-500">
          MyPortfolio v{{ version }}
        </div>

        <!-- Mobile only: profil + ovládání relace (na dně sidebaru) -->
        <div class="lg:hidden border-t border-neutral-200 px-4 py-3 bg-neutral-50 space-y-3">
          <div class="flex items-center justify-between">
            <RouterLink
              to="/profile/password"
              @click="mobileOpen = false"
              class="group min-w-0 flex-1 rounded-md -ml-2 px-2 py-1.5 text-sm hover:bg-surface"
              :title="t('auth.profile_title')"
            >
              <div class="truncate font-medium text-neutral-900 group-hover:text-primary-700 group-hover:underline">
                {{ auth.user?.name }}
              </div>
              <div class="truncate text-xs text-neutral-500">{{ auth.user?.email }} · {{ auth.user?.role }}</div>
            </RouterLink>
          </div>
          <div class="flex items-center justify-between gap-2">
            <!-- Přepínač motivu (System / Light / Dark) — mobilní varianta -->
            <ThemeToggle />
            <div class="inline-flex items-center border border-neutral-200 bg-surface rounded-md overflow-hidden">
              <button
                @click="setLocale('cs')" title="Čeština"
                class="cursor-pointer h-9 px-3 inline-flex items-center"
                :class="locale === 'cs' ? 'bg-primary-50' : 'hover:bg-neutral-50 grayscale opacity-60'"
              >
                <svg width="22" height="15" viewBox="0 0 6 4" xmlns="http://www.w3.org/2000/svg">
                  <rect width="6" height="2" fill="#ffffff"/>
                  <rect y="2" width="6" height="2" fill="#d7141a"/>
                  <polygon points="0,0 3,2 0,4" fill="#11457e"/>
                </svg>
              </button>
              <button
                @click="setLocale('en')" title="English"
                class="cursor-pointer h-9 px-3 inline-flex items-center border-l border-neutral-200"
                :class="locale === 'en' ? 'bg-primary-50' : 'hover:bg-neutral-50 grayscale opacity-60'"
              >
                <svg width="22" height="15" viewBox="0 0 60 30" xmlns="http://www.w3.org/2000/svg">
                  <clipPath id="uk-flag-mob"><path d="M30,15 h30 v15 z v15 h-30 z h-30 v-15 z v-15 h30 z"/></clipPath>
                  <path d="M0,0 v30 h60 v-30 z" fill="#012169"/>
                  <path d="M0,0 L60,30 M60,0 L0,30" stroke="#fff" stroke-width="6"/>
                  <path d="M0,0 L60,30 M60,0 L0,30" clip-path="url(#uk-flag-mob)" stroke="#C8102E" stroke-width="4"/>
                  <path d="M30,0 v30 M0,15 h60" stroke="#fff" stroke-width="10"/>
                  <path d="M30,0 v30 M0,15 h60" stroke="#C8102E" stroke-width="6"/>
                </svg>
              </button>
            </div>
          </div>
          <div class="grid gap-2" :class="canLockSession ? 'grid-cols-2' : 'grid-cols-1'">
            <button
              v-if="canLockSession"
              @click="sessionSecurity.lock"
              class="cursor-pointer w-full px-2 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-surface"
            >{{ t('session_lock.lock_now') }}</button>
            <button
              @click="logout"
              :disabled="logoutBusy"
              class="cursor-pointer w-full px-2 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-surface disabled:opacity-60"
            >{{ t('nav.logout') }}</button>
          </div>
        </div>
      </aside>

      <!-- ── HLAVNÍ OBSAH ── -->
      <div class="flex-1 min-w-0 flex flex-col">
        <main class="flex-1 px-5 sm:px-8 py-6 w-full">
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
