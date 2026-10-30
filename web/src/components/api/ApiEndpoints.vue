<script setup lang="ts">
/**
 * Seznam endpointů dostupných API tokenem (katalog z GET /api/api-catalog — stejný zdroj jako oprávnění tokenu
 * a OpenAPI). U každého parametry, příklad curl a „Vyzkoušet" (volá endpoint přihlášením v prohlížeči, ne tokenem).
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '@/api/client'
import { apiDocs, type ApiCatalog, type ApiEndpoint, type ApiParam } from '@/api/apiDocs'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const toast = useToast()

const catalog = ref<ApiCatalog | null>(null)
const loading = ref(true)
const error = ref('')
const search = ref('')
const group = ref('')
const open = ref<string | null>(null)

onMounted(async () => {
  try {
    catalog.value = await apiDocs.catalog()
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    loading.value = false
  }
})

const grouped = computed(() => {
  const q = search.value.trim().toLowerCase()
  const out: { key: string; label: string; endpoints: ApiEndpoint[] }[] = []
  for (const g of catalog.value?.groups ?? []) {
    if (group.value && g.key !== group.value) continue
    const endpoints = (catalog.value?.endpoints ?? []).filter(e => e.group === g.key
      && (!q || `${e.method} ${e.path} ${e.summary}`.toLowerCase().includes(q)))
    if (endpoints.length) out.push({ key: g.key, label: g.label, endpoints })
  }
  return out
})

const METHOD_BADGES: Record<string, string> = {
  GET: 'bg-success-50 text-success-600',
  POST: 'bg-primary-50 text-primary-700',
  PUT: 'bg-warning-50 text-warning-600',
  DELETE: 'bg-danger-50 text-danger-500',
}

function exampleValue(p: ApiParam): unknown {
  if (p.example !== null && p.example !== undefined) return p.example
  if (p.enum?.length) return p.enum[0]
  if (p.default !== null && p.default !== undefined) return p.default
  return { integer: 1, boolean: true, date: '2026-01-31', datetime: '2026-01-31 10:00', decimal: '100.50', currency: 'CZK', object: {}, file: '@export.csv' }[p.type as string] ?? 'text'
}

/** Příklad curl s adresou instance a parametry z příkladů (povinné vždy, volitelné jen s příkladem). */
function curl(e: ApiEndpoint): string {
  const base = catalog.value?.base_url ?? ''
  let path = e.path
  const query: string[] = []
  const body: Record<string, unknown> = {}
  const form: string[] = []
  for (const p of e.params) {
    if (!p.required && (p.example === null || p.example === undefined)) continue
    const v = exampleValue(p)
    if (p.in === 'path') path = path.replace(`{${p.name}}`, String(v))
    else if (p.in === 'query') query.push(`${p.name}=${encodeURIComponent(String(v))}`)
    else if (p.in === 'form') form.push(`  -F '${p.name}=${p.type === 'file' ? '@export.csv' : String(v)}'`)
    else body[p.name] = v
  }
  const lines = [`curl${e.method === 'GET' ? '' : ` -X ${e.method}`} '${base}${path}${query.length ? `?${query.join('&')}` : ''}'`,
    `  -H 'Authorization: Bearer <token>'`]
  if (Object.keys(body).length) {
    lines.push(`  -H 'Content-Type: application/json'`, `  -d '${JSON.stringify(body)}'`)
  }
  lines.push(...form)
  return lines.join(' \\\n')
}

async function copy(text: string) {
  try {
    await navigator.clipboard.writeText(text)
    toast.success(t('api_tokens.copied'))
  } catch {
    toast.error(t('common.error'))
  }
}

// ── Vyzkoušet ───────────────────────────────────────────────────────────
const tryValues = reactive<Record<string, Record<string, unknown>>>({})
const tryFiles = reactive<Record<string, File | null>>({})
const tryResult = reactive<Record<string, { status: number; ms: number; body: string } | null>>({})
const trying = ref<string | null>(null)

function toggle(e: ApiEndpoint) {
  open.value = open.value === e.id ? null : e.id
  if (!tryValues[e.id]) {
    tryValues[e.id] = Object.fromEntries(e.params.filter(p => p.type !== 'file').map(p => [p.name,
      p.type === 'boolean' ? (p.default ?? false) : p.type === 'object' ? '' : (p.required && p.in === 'path' ? '' : (p.default ?? ''))]))
  }
}

async function tryIt(e: ApiEndpoint) {
  if (e.method !== 'GET' && !confirm(t('api.try_confirm', { method: e.method, path: e.path }))) return
  const values = tryValues[e.id]
  let url = e.path.replace(/^\/api/, '')
  const params: Record<string, unknown> = {}
  const body: Record<string, unknown> = {}
  const form = new FormData()
  try {
    for (const p of e.params) {
      const v = values[p.name]
      if (p.type === 'file') {
        if (tryFiles[e.id]) form.append(p.name, tryFiles[e.id] as File)
        continue
      }
      if (v === '' || v === null || v === undefined) continue
      const typed = p.type === 'integer' ? Number(v) : p.type === 'object' ? JSON.parse(String(v)) : v
      if (p.in === 'path') url = url.replace(`{${p.name}}`, encodeURIComponent(String(v)))
      else if (p.in === 'query') params[p.name] = v
      else if (p.in === 'form') form.append(p.name, String(v))
      else body[p.name] = typed
    }
  } catch {
    toast.error(t('api.invalid_json'))
    return
  }
  trying.value = e.id
  const started = performance.now()
  try {
    const r = await api.request({
      method: e.method, url, params,
      data: e.content_type === 'multipart/form-data' ? form : (e.method === 'GET' || e.method === 'DELETE' ? undefined : body),
      headers: e.content_type === 'multipart/form-data' ? { 'Content-Type': 'multipart/form-data' } : undefined,
      responseType: 'text', transformResponse: [(d: unknown) => d], validateStatus: () => true,
    })
    tryResult[e.id] = { status: r.status, ms: Math.round(performance.now() - started), body: pretty(String(r.data ?? '')) }
  } catch (err) {
    tryResult[e.id] = { status: 0, ms: Math.round(performance.now() - started), body: apiErrorMessage(err, t('common.error')) }
  } finally {
    trying.value = null
  }
}

function pretty(text: string): string {
  try {
    return JSON.stringify(JSON.parse(text), null, 2)
  } catch {
    return text
  }
}

const IN_ORDER: Record<string, number> = { path: 0, query: 1, form: 2, body: 3 }
const sortedParams = (e: ApiEndpoint) => [...e.params].sort((a, b) => IN_ORDER[a.in] - IN_ORDER[b.in])
const inputClass = 'w-full h-8 px-2 border border-field rounded-md bg-surface text-sm'
</script>

<template>
  <div>
    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <div role="alert" v-else-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</div>
    <template v-else-if="catalog">
      <!-- Úvod -->
      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4 text-sm space-y-2">
        <p>{{ t('api.intro') }}</p>
        <pre class="bg-neutral-50 border border-neutral-200 rounded-md px-3 py-2 text-xs overflow-x-auto">curl '{{ catalog.base_url }}/api/auth/api-me' \
  -H 'Authorization: Bearer &lt;token&gt;'</pre>
        <ul class="list-disc pl-5 text-neutral-600 space-y-0.5">
          <li>{{ t('api.rule_scope') }}</li>
          <li>{{ t('api.rule_numbers') }}</li>
          <li>{{ t('api.rule_errors') }}</li>
          <li>{{ t('api.rule_limit', { n: catalog.rate_limit }) }}</li>
        </ul>
        <div class="flex flex-wrap gap-2 pt-1">
          <a :href="apiDocs.openApiUrl" download="myportfolio-openapi.json" class="inline-flex items-center h-9 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('api.openapi_download') }}</a>
          <a :href="apiDocs.openApiUrl" target="_blank" rel="noopener" class="inline-flex items-center h-9 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('api.openapi_open') }}</a>
        </div>
      </section>

      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4 p-3 flex flex-wrap items-center gap-2">
        <input v-model="search" type="search" :placeholder="t('api.search')" class="h-9 px-3 border border-field rounded-md bg-surface text-sm flex-1 min-w-48" />
        <select v-model="group" class="h-9 px-3 border border-field rounded-md bg-surface text-sm">
          <option value="">{{ t('api.all_groups') }}</option>
          <option v-for="g in catalog.groups" :key="g.key" :value="g.key">{{ g.label }}</option>
        </select>
        <span class="text-xs text-neutral-500">{{ t('api.count', { n: catalog.endpoints.length }) }}</span>
      </div>

      <p v-if="!grouped.length" class="text-sm text-neutral-500 text-center py-6">{{ t('api.no_results') }}</p>

      <section v-for="g in grouped" :key="g.key" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-4">
        <h2 class="px-4 py-2.5 text-sm font-semibold border-b border-neutral-200 bg-neutral-50">{{ g.label }}</h2>
        <div class="divide-y divide-neutral-100">
          <div v-for="e in g.endpoints" :key="e.id">
            <button type="button" @click="toggle(e)" class="cursor-pointer w-full text-left px-4 py-2.5 flex flex-wrap items-center gap-x-3 gap-y-1 hover:bg-neutral-50">
              <span class="w-16 text-center text-xs font-semibold px-2 py-0.5 rounded" :class="METHOD_BADGES[e.method]">{{ e.method }}</span>
              <code class="text-sm break-all">{{ e.path }}</code>
              <span class="text-sm text-neutral-600 flex-1 min-w-40">{{ e.summary }}</span>
              <span class="text-xs px-2 py-0.5 rounded font-medium" :class="e.scope === 'read' ? 'bg-neutral-100 text-neutral-600' : 'bg-warning-50 text-warning-600'">{{ e.scope }}</span>
            </button>

            <div v-if="open === e.id" class="px-4 pb-4 pt-1 space-y-3 text-sm">
              <!-- Parametry -->
              <div v-if="e.params.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead class="text-xs text-neutral-500 uppercase tracking-wide">
                    <tr>
                      <th class="px-2 py-1.5 text-left font-medium">{{ t('api.param') }}</th>
                      <th class="px-2 py-1.5 text-left font-medium">{{ t('api.where') }}</th>
                      <th class="px-2 py-1.5 text-left font-medium">{{ t('api.type') }}</th>
                      <th class="px-2 py-1.5 text-left font-medium">{{ t('api.description') }}</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-neutral-100">
                    <tr v-for="p in sortedParams(e)" :key="p.name">
                      <td class="px-2 py-1.5 whitespace-nowrap align-top">
                        <code>{{ p.name }}</code><span v-if="p.required" class="text-danger-500" :title="t('api.required')"> *</span>
                      </td>
                      <td class="px-2 py-1.5 text-neutral-500 align-top">{{ t(`api.in.${p.in}`) }}</td>
                      <td class="px-2 py-1.5 text-neutral-500 align-top whitespace-nowrap">{{ t(`api.types.${p.type}`) }}</td>
                      <td class="px-2 py-1.5 align-top">
                        {{ p.description }}
                        <span v-if="p.enum" class="block text-xs text-neutral-500">{{ t('api.values') }}: <code>{{ p.enum.join(' | ') }}</code></span>
                        <span v-if="p.default !== null" class="block text-xs text-neutral-500">{{ t('api.default') }}: <code>{{ p.default }}</code></span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <p v-else class="text-neutral-500">{{ t('api.no_params') }}</p>

              <!-- Odpověď -->
              <div>
                <p><span class="font-medium">{{ t('api.response') }} {{ e.response.status }}</span> — {{ e.response.description }}</p>
                <pre v-if="e.response.example !== null" class="mt-1 bg-neutral-50 border border-neutral-200 rounded-md px-3 py-2 text-xs overflow-x-auto max-h-64">{{ JSON.stringify(e.response.example, null, 2) }}</pre>
              </div>

              <!-- curl -->
              <div>
                <div class="flex items-center justify-between">
                  <span class="font-medium">{{ t('api.example') }}</span>
                  <button type="button" @click="copy(curl(e))" class="cursor-pointer text-xs text-primary-700 hover:underline">{{ t('api_tokens.copy') }}</button>
                </div>
                <pre class="mt-1 bg-neutral-50 border border-neutral-200 rounded-md px-3 py-2 text-xs overflow-x-auto">{{ curl(e) }}</pre>
              </div>

              <!-- Vyzkoušet -->
              <div class="border-t border-neutral-200 pt-3">
                <p class="font-medium">{{ t('api.try') }}</p>
                <p class="text-xs text-neutral-500 mb-2">{{ t('api.try_hint') }}</p>
                <div v-if="e.params.length" class="grid gap-2 sm:grid-cols-2 mb-2">
                  <label v-for="p in sortedParams(e)" :key="p.name" class="text-xs">
                    <span class="block text-neutral-600 mb-0.5"><code>{{ p.name }}</code><span v-if="p.required" class="text-danger-500"> *</span></span>
                    <input v-if="p.type === 'file'" type="file" class="block w-full text-sm" @change="ev => (tryFiles[e.id] = (ev.target as HTMLInputElement).files?.[0] ?? null)" />
                    <input v-else-if="p.type === 'boolean'" v-model="tryValues[e.id][p.name]" type="checkbox" class="rounded border-field text-primary-700" />
                    <select v-else-if="p.enum" v-model="tryValues[e.id][p.name]" :class="inputClass">
                      <option value="">—</option>
                      <option v-for="v in p.enum" :key="String(v)" :value="v">{{ v }}</option>
                    </select>
                    <textarea v-else-if="p.type === 'object'" v-model="tryValues[e.id][p.name] as string" rows="2" placeholder="{ }" class="w-full px-2 py-1 border border-field rounded-md bg-surface text-sm font-mono"></textarea>
                    <input v-else v-model="tryValues[e.id][p.name]" :type="p.type === 'date' ? 'date' : 'text'" :placeholder="p.example !== null ? String(p.example) : ''" :class="inputClass" />
                  </label>
                </div>
                <button type="button" :disabled="trying !== null" @click="tryIt(e)"
                  class="cursor-pointer h-9 px-4 text-sm font-medium rounded-md disabled:bg-neutral-300 disabled:text-neutral-500"
                  :class="e.method === 'GET' ? 'bg-action hover:bg-action-hover text-on-action' : 'border border-warning-500/60 bg-warning-50 text-warning-600 hover:border-warning-500'">
                  {{ trying === e.id ? '…' : t('api.send', { method: e.method }) }}
                </button>
                <div v-if="tryResult[e.id]" class="mt-2">
                  <p class="text-xs" :class="tryResult[e.id]!.status >= 200 && tryResult[e.id]!.status < 300 ? 'text-success-600' : 'text-danger-500'">
                    HTTP {{ tryResult[e.id]!.status }} · {{ tryResult[e.id]!.ms }} ms
                  </p>
                  <pre class="mt-1 bg-neutral-50 border border-neutral-200 rounded-md px-3 py-2 text-xs overflow-auto max-h-80">{{ tryResult[e.id]!.body || '—' }}</pre>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>
