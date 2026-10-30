<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import RowActionsMenu, { type RowAction } from '@/components/ui/RowActionsMenu.vue'
import TransactionFormModal from '@/components/portfolio/TransactionFormModal.vue'
import TransferFormModal from '@/components/portfolio/TransferFormModal.vue'
import CashBalanceModal from '@/components/portfolio/CashBalanceModal.vue'
import { portfolioApi, type Position } from '@/api/portfolio'
import { apiErrorMessage } from '@/api/errors'
import { useOwnerContext } from '@/composables/useOwnerContext'
import { useToast } from '@/composables/useToast'

/**
 * Menu „⋯“ u pozice v seznamech (Přehled, Pozice): Detail, Nová transakce, Převést, Nastavit zůstatek.
 * Pozici (a pro převod seznam pozic) načte až při volbě akce; po uložení vyšle `changed`.
 */
const props = defineProps<{ positionId: number; name: string; market: boolean; cash: boolean }>()
const emit = defineEmits<{ changed: [] }>()
const { t } = useI18n()
const toast = useToast()
const { canWrite } = useOwnerContext()

const position = ref<Position | null>(null)
const positions = ref<Position[]>([])
const modal = ref<'tx' | 'transfer' | 'cash' | null>(null)

async function openModal(kind: 'tx' | 'transfer' | 'cash') {
  try {
    position.value = await portfolioApi.position(props.positionId)
    if (kind === 'transfer') positions.value = await portfolioApi.positions()
    modal.value = kind
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}
function saved() {
  modal.value = null
  emit('changed')
}

const items = computed<RowAction[]>(() => [
  { key: 'detail', label: t('common.detail'), to: `/positions/${props.positionId}` },
  ...(canWrite.value && !props.cash ? [{ key: 'tx', label: t('tx.new'), run: () => openModal('tx') }] : []),
  ...(canWrite.value && props.market ? [{ key: 'transfer', label: t('tx.transfer'), run: () => openModal('transfer') }] : []),
  ...(canWrite.value && props.cash ? [{ key: 'cash', label: t('cash.set'), run: () => openModal('cash') }] : []),
])
</script>

<template>
  <RowActionsMenu :items="items" :label="t('positions.actions_for', { name })" />
  <TransactionFormModal v-if="modal === 'tx' && position" :position="position" @close="modal = null" @saved="saved" />
  <TransferFormModal v-if="modal === 'transfer' && position" :from="position" :positions="positions" @close="modal = null" @saved="saved" />
  <CashBalanceModal v-if="modal === 'cash' && position" :position="position" @close="modal = null" @saved="saved" />
</template>
