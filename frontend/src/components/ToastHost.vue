<script setup lang="ts">
import { computed } from 'vue'
import { useToastStore } from '../stores/toast'

const toast = useToastStore()
const isVisible = computed(() => !!toast.message)
</script>

<template>
  <div v-if="isVisible" class="pointer-events-none fixed inset-0 z-50 flex items-start justify-center px-4 pt-6 sm:items-start">
    <div
      class="pointer-events-auto w-full max-w-sm overflow-hidden rounded-xl border px-3 py-2 shadow-lg"
      :class="toast.variant === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800'"
    >
      <div class="flex items-start gap-2">
        <div class="mt-0.5 text-lg">
          <span v-if="toast.variant === 'error'">⚠️</span>
          <span v-else>✅</span>
        </div>
        <div class="flex-1 text-sm">
          <div class="font-medium">
            {{ toast.title || (toast.variant === 'error' ? 'Gagal' : 'Berhasil') }}
          </div>
          <div v-if="toast.message" class="mt-0.5">
            {{ toast.message }}
          </div>
        </div>
        <button
          type="button"
          class="rounded px-2 py-1 text-xs text-slate-500 hover:bg-slate-100"
          @click="toast.clear()"
        >
          Tutup
        </button>
      </div>
    </div>
  </div>
</template>

