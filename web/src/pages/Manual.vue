<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { manualChapters, renderManual, searchManual } from '@/utils/manual'
import { useFieldId } from '@/composables/useFieldId'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const chapters = computed(() => manualChapters.filter(c => !c.adminOnly || auth.user?.role === 'admin'))
const current = computed(() => chapters.value.find(c => c.slug === route.params.chapter) ?? chapters.value[0])
// Hledání napříč kapitolami (jen kapitoly, které uživatel vidí)
const fid = useFieldId()
const query = ref('')
const hits = computed(() => searchManual(chapters.value, query.value))
function openHit(slug: string, id: string) {
  query.value = ''
  router.push({ name: 'manual', params: { chapter: slug }, hash: id ? `#${id}` : '' })
}
const html = computed(() => (current.value ? renderManual(current.value.source) : ''))
/** Odkaz do kapitoly jen pro správce (instalace, administrace) */
const adminOnly = computed(() => !chapters.value.some(c => c.slug === route.params.chapter) && manualChapters.some(c => c.slug === route.params.chapter))

// Kotva z URL (#55-import…) po vykreslení kapitoly; jinak nahoru
watch(() => [current.value?.slug, route.hash], async () => {
  await nextTick()
  const id = decodeURIComponent(route.hash.replace(/^#/, ''))
  const target = id ? document.getElementById(id) : null
  if (target) target.scrollIntoView({ block: 'start' })
  else window.scrollTo({ top: 0 })
}, { immediate: true })

// Odkazy v textu manuálu: kapitoly přes router (bez znovunačtení stránky)
function onClick(e: MouseEvent) {
  const a = (e.target as HTMLElement).closest('a')
  const href = a?.getAttribute('href') ?? ''
  if (!a || e.defaultPrevented || e.metaKey || e.ctrlKey || a.target === '_blank') return
  if (href.startsWith('/manual/') || href.startsWith('#')) {
    e.preventDefault()
    router.push(href.startsWith('#') ? { hash: href } : href)
  }
}

function go(slug: string) {
  router.push({ name: 'manual', params: { chapter: slug } })
}
</script>

<template>
  <div>
    <div class="mb-4">
      <!-- Jediná H1 stránky je název kapitoly v obsahu. -->
      <p class="text-2xl font-semibold">{{ t('manual.title') }}</p>
      <p v-if="locale !== 'cs'" class="text-sm text-warning-600 mt-1">{{ t('manual.cs_only') }}</p>
      <div class="mt-3 max-w-md">
        <label :for="fid('q')" class="block text-xs font-medium text-neutral-600 mb-1">{{ t('manual.search') }}</label>
        <input :id="fid('q')" v-model="query" type="search" :placeholder="t('manual.search_placeholder')"
               class="w-full h-10 px-3 border border-field rounded-md bg-surface text-sm" />
      </div>
    </div>

    <section v-if="query.trim().length >= 2" class="mb-4 bg-surface border border-neutral-200 rounded-lg shadow-sm" aria-live="polite">
      <p class="px-4 py-2.5 border-b border-neutral-200 text-xs text-neutral-500">{{ t('manual.search_results', { n: hits.length }) }}</p>
      <ul class="divide-y divide-neutral-100">
        <li v-for="h in hits" :key="`${h.slug}#${h.id}`">
          <button type="button" class="cursor-pointer block w-full text-left px-4 py-2.5 hover:bg-primary-50" @click="openHit(h.slug, h.id)">
            <span class="block text-sm font-medium text-primary-700">{{ h.section }}</span>
            <span class="block text-xs text-neutral-500">{{ h.chapter }}</span>
            <span class="block text-xs text-neutral-600 mt-0.5">{{ h.snippet }}</span>
          </button>
        </li>
      </ul>
    </section>

    <!-- Mobil: výběr kapitoly -->
    <select class="lg:hidden w-full h-10 px-3 mb-4 border border-field rounded-md bg-surface text-sm"
            :value="current?.slug" :aria-label="t('manual.chapters')" @change="go(($event.target as HTMLSelectElement).value)">
      <option v-for="c in chapters" :key="c.slug" :value="c.slug">{{ c.title }}</option>
    </select>

    <div class="flex gap-6 items-start">
      <!-- Svislé záložky kapitol, u aktivní obsah sekcí -->
      <nav class="hidden lg:block w-64 shrink-0 sticky top-20 max-h-[calc(100vh-6rem)] overflow-y-auto scrollbar-slim" :aria-label="t('manual.chapters')">
        <ul class="space-y-0.5">
          <li v-for="c in chapters" :key="c.slug">
            <RouterLink :to="{ name: 'manual', params: { chapter: c.slug } }"
              class="block px-3 py-1.5 rounded-md text-sm border-l-2"
              :class="c.slug === current?.slug ? 'bg-primary-50 text-primary-700 font-medium border-primary-600' : 'text-neutral-600 hover:bg-neutral-100 border-transparent'">
              {{ c.title }}
            </RouterLink>
            <ul v-if="c.slug === current?.slug && c.sections.length" class="mt-0.5 mb-1.5 ml-4 space-y-0.5">
              <li v-for="s in c.sections" :key="s.id">
                <RouterLink :to="{ name: 'manual', params: { chapter: c.slug }, hash: `#${s.id}` }"
                  class="block px-2 py-0.5 text-xs text-neutral-500 hover:text-primary-700 rounded"
                  :class="{ 'text-primary-700 font-medium': route.hash === `#${s.id}` }">
                  {{ s.title }}
                </RouterLink>
              </li>
            </ul>
          </li>
        </ul>
      </nav>

      <article class="flex-1 min-w-0 bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 sm:p-8">
        <p v-if="adminOnly" class="mb-4 rounded-md bg-warning-50 text-warning-600 px-3 py-2 text-sm">{{ t('manual.admin_only') }}</p>
        <!-- Obsah je markdown z repozitáře (manual/*.md), HTML v něm vypnuté -->
        <div class="manual-prose max-w-3xl" @click="onClick" v-html="html" />
      </article>
    </div>
  </div>
</template>
