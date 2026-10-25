<script setup lang="ts">
/** Logo v barvách schématu (tokeny --color-logo*); `colors` = pevné barvy pro náhled schématu ve výběru. */
defineProps<{ colors?: readonly [string, string] }>()
</script>

<template>
  <svg viewBox="0 0 64 64" role="img" aria-label="MyPortfolio">
    <rect x="2" y="2" width="60" height="60" rx="14" :style="{ fill: colors?.[0] ?? 'var(--color-logo)' }" />
    <g fill="none" stroke-width="9" transform="rotate(-90 32 32)">
      <circle cx="32" cy="32" r="17" stroke="#fff" stroke-dasharray="58 106.8" />
      <circle cx="32" cy="32" r="17" :style="{ stroke: colors?.[1] ?? 'var(--color-logo-accent)' }" stroke-dasharray="0 62 28 106.8" />
      <circle cx="32" cy="32" r="17" stroke="#fff" stroke-opacity=".55" stroke-dasharray="0 94 9 106.8" />
    </g>
  </svg>
</template>
