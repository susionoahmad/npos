<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AppShell from '../components/AppShell.vue'
import api from '../services/api'

const loading = ref(true)
const saving = ref(false)
const error = ref('')
const success = ref('')

const form = ref({
  subscription_base_fee: 100000,
  subscription_store_addon_fee: 50000,
  subscription_free_stores_limit: 1,
  subscription_trial_days: 14,
  subscription_trial_stores_limit: 1,
  subscription_trial_users_limit: 3,
})

const fetchSettings = async () => {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/superadmin/settings')
    form.value = {
      subscription_base_fee: data.subscription_base_fee ?? 100000,
      subscription_store_addon_fee: data.subscription_store_addon_fee ?? 50000,
      subscription_free_stores_limit: data.subscription_free_stores_limit ?? 1,
      subscription_trial_days: data.subscription_trial_days ?? 14,
      subscription_trial_stores_limit: data.subscription_trial_stores_limit ?? 1,
      subscription_trial_users_limit: data.subscription_trial_users_limit ?? 3,
    }
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal memuat konfigurasi SaaS.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchSettings()
})

const submitSave = async () => {
  saving.value = true
  error.value = ''
  success.value = ''
  try {
    await api.post('/superadmin/settings', form.value)
    success.value = 'Pengaturan sistem SaaS berhasil diperbarui.'
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal memperbarui pengaturan.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <AppShell>
    <div class="space-y-6 max-w-2xl">
      <!-- Header -->
      <div>
        <h2 class="text-2xl font-black tracking-tight text-slate-900">Pengaturan Langganan SaaS</h2>
        <p class="text-sm text-slate-500">Konfigurasi dinamis untuk tarif berlangganan bulanan dan batas uji coba gratis.</p>
      </div>

      <!-- Messages -->
      <div v-if="error" class="bg-red-50 text-red-800 p-3 rounded-lg text-sm font-medium">
        {{ error }}
      </div>
      <div v-if="success" class="bg-emerald-50 text-emerald-800 p-3 rounded-lg text-sm font-medium">
        {{ success }}
      </div>

      <!-- Loading State -->
      <div v-if="loading" class="bg-white rounded-2xl border border-slate-100 p-10 flex justify-center items-center shadow-sm">
        <div class="h-8 w-8 animate-spin rounded-full border-4 border-slate-900 border-t-transparent"></div>
      </div>

      <!-- Settings Form -->
      <form v-else @submit.prevent="submitSave" class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6 space-y-6">
        <!-- Section 1: Pricing -->
        <div class="space-y-4">
          <h3 class="font-extrabold text-slate-900 text-base border-b pb-2">Skema & Tarif Berlangganan</h3>
          <div class="grid gap-4 md:grid-cols-2">
            <div class="space-y-1">
              <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Biaya Dasar Bulanan (Rp)</label>
              <input
                v-model.number="form.subscription_base_fee"
                type="number"
                min="0"
                required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              />
              <p class="text-[10px] text-slate-400">Tarif dasar berlangganan per bulan.</p>
            </div>
            <div class="space-y-1">
              <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Biaya Per Toko Tambahan (Rp)</label>
              <input
                v-model.number="form.subscription_store_addon_fee"
                type="number"
                min="0"
                required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              />
              <p class="text-[10px] text-slate-400">Biaya bulanan untuk setiap toko tambahan melebihi batas gratis.</p>
            </div>
          </div>
          <div class="space-y-1 max-w-xs">
            <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Batas Toko Gratis (Biaya Dasar)</label>
            <input
              v-model.number="form.subscription_free_stores_limit"
              type="number"
              min="0"
              required
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
            />
            <p class="text-[10px] text-slate-400">Jumlah toko yang sudah tercakup di dalam biaya dasar (umumnya 1 toko).</p>
          </div>
        </div>

        <!-- Section 2: Trial Limits -->
        <div class="space-y-4">
          <h3 class="font-extrabold text-slate-900 text-base border-b pb-2">Masa Uji Coba Gratis (Trial)</h3>
          <div class="grid gap-4 md:grid-cols-3">
            <div class="space-y-1">
              <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Masa Trial (Hari)</label>
              <input
                v-model.number="form.subscription_trial_days"
                type="number"
                min="1"
                required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              />
            </div>
            <div class="space-y-1">
              <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Maks. Toko Trial</label>
              <input
                v-model.number="form.subscription_trial_stores_limit"
                type="number"
                min="1"
                required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              />
            </div>
            <div class="space-y-1">
              <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Maks. User Trial</label>
              <input
                v-model.number="form.subscription_trial_users_limit"
                type="number"
                min="1"
                required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              />
            </div>
          </div>
          <p class="text-[10px] text-slate-400">
            Batasan yang otomatis diterapkan pada tenant saat pendaftaran pertama kali di platform.
          </p>
        </div>

        <!-- Save Button -->
        <div class="flex justify-end pt-4 border-t">
          <button
            type="submit"
            :disabled="saving"
            class="rounded-xl bg-slate-900 px-6 py-2.5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-50 transition-colors shadow-sm"
          >
            {{ saving ? 'Menyimpan...' : 'Simpan Pengaturan' }}
          </button>
        </div>
      </form>
    </div>
  </AppShell>
</template>
