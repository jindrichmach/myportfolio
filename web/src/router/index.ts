import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    component: () => import('@/components/layout/AppLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      { path: '',                       name: 'home',           component: () => import('@/pages/Dashboard.vue') },
      // Portfolio
      { path: 'positions',              name: 'positions',       component: () => import('@/pages/portfolio/Positions.vue') },
      { path: 'positions/:id(\\d+)',    name: 'position-detail', component: () => import('@/pages/portfolio/PositionDetail.vue') },
      { path: 'transactions',           name: 'transactions',    component: () => import('@/pages/portfolio/Transactions.vue') },
      { path: 'household',              name: 'household',       component: () => import('@/pages/portfolio/Household.vue') },
      { path: 'net-worth',              name: 'net-worth',       component: () => import('@/pages/portfolio/NetWorth.vue') },
      { path: 'allocation',             name: 'allocation',      component: () => import('@/pages/portfolio/Allocation.vue') },
      { path: 'transactions/import',    name: 'import',          component: () => import('@/pages/portfolio/Import.vue') },
      { path: 'summary',                name: 'summary',         component: () => import('@/pages/portfolio/Summary.vue') },
      { path: 'income',                 name: 'income',          component: () => import('@/pages/portfolio/Income.vue') },
      { path: 'tax-report',             name: 'tax-report',      component: () => import('@/pages/portfolio/TaxReport.vue') },
      { path: 'accounts',               name: 'accounts',        component: () => import('@/pages/portfolio/Accounts.vue') },
      { path: 'portfolios',             name: 'portfolios',      component: () => import('@/pages/portfolio/Portfolios.vue') },
      // Katalog (sdílený; úpravy jen admin)
      { path: 'catalog/instruments',    name: 'instruments',     component: () => import('@/pages/catalog/Instruments.vue') },
      { path: 'catalog/institutions',   name: 'institutions',    component: () => import('@/pages/catalog/Institutions.vue') },
      // Admin
      { path: 'admin/activity-log',     name: 'activity-log',   component: () => import('@/pages/admin/ActivityLog.vue'), meta: { adminOnly: true } },
      { path: 'admin/market',           name: 'admin-market',   component: () => import('@/pages/admin/Market.vue'),      meta: { adminOnly: true } },
      { path: 'admin/cron-jobs',        name: 'cron-jobs',      component: () => import('@/pages/admin/CronJobs.vue'),    meta: { adminOnly: true } },
      { path: 'admin/update',           name: 'admin-update',   component: () => import('@/pages/admin/Update.vue'),      meta: { adminOnly: true } },
      { path: 'admin/users',            name: 'admin-users',    component: () => import('@/pages/admin/Users.vue'),       meta: { adminOnly: true } },
      // Nastavení instance po záložkách (?tab=smtp)
      { path: 'admin/settings',         name: 'admin-settings', component: () => import('@/pages/admin/Settings.vue'),    meta: { adminOnly: true } },
      // Profil — záložky na /profile/password; staré URL přesměrují na příslušnou záložku.
      { path: 'profile',                name: 'profile',               component: () => import('@/pages/Profile.vue') },
      { path: 'profile/totp',           name: 'profile-totp',          redirect: (to) => ({ path: '/profile/password', query: { ...to.query, tab: 'totp' } }) },
      { path: 'profile/password',       name: 'profile-password',      component: () => import('@/pages/PasswordChange.vue') },
      { path: 'profile/api',            name: 'profile-api',           component: () => import('@/pages/Api.vue') },
      { path: 'profile/api-tokens',     name: 'profile-api-tokens',    redirect: (to) => ({ path: '/profile/api', query: to.query }) },
      { path: 'manual/:chapter?',       name: 'manual',          component: () => import('@/pages/Manual.vue') },
      { path: 'profile/sharing',        name: 'profile-sharing',       component: () => import('@/pages/Sharing.vue') },
      { path: 'profile/exchanges',      name: 'profile-exchanges',     component: () => import('@/pages/Exchanges.vue') },
      { path: 'profile/bank-notices',   name: 'profile-bank-notices',  component: () => import('@/pages/BankNotices.vue') },
      { path: 'profile/passkeys',       name: 'profile-passkeys',      redirect: (to) => ({ path: '/profile/password', query: { ...to.query, tab: 'passkeys' } }) },
      { path: 'profile/session-lock',   name: 'profile-session-lock',  redirect: (to) => ({ path: '/profile/password', query: { ...to.query, tab: 'session-lock' } }) },
    ],
  },
  { path: '/login',  name: 'login',  component: () => import('@/pages/Login.vue'),          meta: { public: true } },
  { path: '/setup',  name: 'setup',  component: () => import('@/pages/Setup.vue'),          meta: { public: true } },
  { path: '/setup-mfa', name: 'setup-mfa', component: () => import('@/pages/ForcedMfaSetup.vue'), meta: { requiresAuth: true, mfaSetupOnly: true } },
  { path: '/setup-totp', name: 'setup-totp', redirect: { path: '/setup-mfa', query: { method: 'totp' } } },
  { path: '/forgot', name: 'forgot', component: () => import('@/pages/ForgotPassword.vue'), meta: { public: true } },
  { path: '/reset',  name: 'reset',  component: () => import('@/pages/ResetPassword.vue'),  meta: { public: true } },
  { path: '/invite/:token([0-9a-f]{64})', name: 'invite', component: () => import('@/pages/Invite.vue'), meta: { public: true } },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/pages/NotFound.vue'),
  },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
  // Scroll-to-top při navigaci sidebar linky; respektuj #hash a back/forward
  scrollBehavior(_to, _from, savedPosition) {
    if (savedPosition) return savedPosition
    if (_to.hash) return { el: _to.hash, behavior: 'smooth' }
    return { top: 0, left: 0 }
  },
})

/**
 * Bezpečný fallback při zamítnutí. Vrací `true` (pusť dál), když už cílíme na
 * `home` — jinak by vznikla NEKONEČNÁ smyčka, kdyby dashboard sám propadl
 * některým z gatů níž. Radši pustit dál a nechat stránku zobrazit prázdný stav
 * nebo chybu z API, než točit prohlížeč donekonečna.
 */
function denyFallback(toName: unknown) {
  return toName === 'home' ? true : { name: 'home' }
}

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (auth.setupStatus === null) {
    try {
      await auth.fetchSetupStatus()
    } catch {
      // ignore
    }
  }

  if (auth.needsSetup && to.name !== 'setup') {
    return { name: 'setup' }
  }
  if (!auth.needsSetup && to.name === 'setup') {
    return { name: 'login' }
  }

  const requiresAuth = to.matched.some((r) => r.meta.requiresAuth)
  if (requiresAuth && !auth.isAuthenticated) {
    // Rozhoduje stav storu, ne návratová hodnota: refresh() při síťovém výpadku
    // vrací false, ale známou identitu si záměrně drží.
    await auth.refresh()
    if (!auth.isAuthenticated) return { name: 'login' }
  }
  if (requiresAuth && auth.lockedSession) {
    useSessionSecurityStore().apply(auth.lockedSession)
    return true
  }
  if (requiresAuth) {
    const sessionSecurity = useSessionSecurityStore()
    if (sessionSecurity.state === null) {
      await sessionSecurity.refresh()
    }
  }

  // Setup session nemá přístup k business routám, dokud uživatel nedokončí MFA.
  const mustSetupMfa = auth.mustSetupMfa || auth.mustSetupTotp
  if (auth.isAuthenticated && mustSetupMfa && to.name !== 'setup-mfa' && requiresAuth) {
    return { name: 'setup-mfa' }
  }
  if (auth.isAuthenticated && !mustSetupMfa && to.name === 'setup-mfa') {
    return { name: 'home' }
  }

  // Admin-only stránky
  const adminOnly = to.matched.some((r) => r.meta.adminOnly)
  if (adminOnly && auth.user?.role !== 'admin') {
    return denyFallback(to.name)
  }

  return true
})
