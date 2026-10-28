<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AppShell from '../components/AppShell.vue'
import api from '../services/api'

interface Plan {
  id?: number
  slug: string
  name: string
  tagline: string | null
  price_monthly: number
  price_yearly: number
  max_stores: number
  max_users: number
  features: string[]
  is_active: boolean
  sort_order: number
  subscribers_count?: number
  active_subscribers_count?: number
}

const loading = ref(true)
const plans = ref<Plan[]>([])
const error = ref('')
const success = ref('')

// Global trial settings
const trialSettings = ref({
  subscription_trial_days: 14,
  subscription_trial_stores_limit: 1,
  subscription_trial_users_limit: 3,
})
const savingTrial = ref(false)

// Modal Plan State
const showModal = ref(false)
const modalMode = ref<'create' | 'edit'>('create')
const modalLoading = ref(false)
const featuresText = ref('')

const planForm = ref<Plan>({
  slug: '',
  name: '',
  tagline: '',
  price_monthly: 99000,
  price_yearly: 990000,
  max_stores: 1,
  max_users: 3,
  features: [],
  is_active: true,
  sort_order: 0,
})

const money = (val: number) => {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(val || 0)
}

const fetchData = async () => {
  loading.value = true
  error.value = ''
  try {
    const [plansRes, settingsRes] = await Promise.all([
      api.get('/superadmin/plans'),
      api.get('/superadmin/settings'),
    ])

    plans.value = plansRes.data?.data || []
    if (settingsRes.data) {
      trialSettings.value = {
        subscription_trial_days: settingsRes.data.subscription_trial_days ?? 14,
        subscription_trial_stores_limit: settingsRes.data.subscription_trial_stores_limit ?? 1,
        subscription_trial_users_limit: settingsRes.data.subscription_trial_users_limit ?? 3,
      }
    }
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal memuat data paket langganan.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchData()
})

const openCreateModal = () => {
  modalMode.value = 'create'
  planForm.value = {
    slug: '',
    name: '',
    tagline: '',
    price_monthly: 99000,
    price_yearly: 990000,
    max_stores: 1,
    max_users: 3,
    features: [],
    is_active: true,
    sort_order: plans.value.length,
  }
  featuresText.value = "1 Cabang Toko\n3 Pengguna Sistem\nModul POS Kasir & Transaksi\nManajemen Inventori & Produk\nLaporan Keuangan & Penjualan"
  showModal.value = true
}

const openEditModal = (plan: Plan) => {
  modalMode.value = 'edit'
  planForm.value = {
    ...plan,
    features: Array.isArray(plan.features) ? [...plan.features] : [],
  }
  featuresText.value = (planForm.value.features || []).join('\n')
  showModal.value = true
}

const submitPlan = async () => {
  modalLoading.value = true
  error.value = ''
  success.value = ''

  try {
    // Parse features from newline separated text
    const parsedFeatures = featuresText.value
      .split('\n')
      .map((f) => f.trim())
      .filter((f) => f.length > 0)

    const payload = {
      ...planForm.value,
      features: parsedFeatures,
    }

    if (modalMode.value === 'create') {
      await api.post('/superadmin/plans', payload)
      success.value = `Paket '${payload.name}' berhasil dibuat.`
    } else {
      await api.put(`/superadmin/plans/${planForm.value.id}`, payload)
      success.value = `Paket '${payload.name}' berhasil diperbarui.`
    }

    showModal.value = false
    await fetchData()
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal menyimpan data paket langganan.'
  } finally {
    modalLoading.value = false
  }
}

const togglePlanStatus = async (plan: Plan) => {
  error.value = ''
  success.value = ''
  try {
    const updatedStatus = !plan.is_active
    await api.put(`/superadmin/plans/${plan.id}`, {
      ...plan,
      is_active: updatedStatus,
    })
    plan.is_active = updatedStatus
    success.value = `Status paket '${plan.name}' berhasil diubah menjadi ${updatedStatus ? 'Aktif' : 'Non-aktif'}.`
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal mengubah status paket.'
  }
}

const deletePlan = async (plan: Plan) => {
  if (!confirm(`Yakin ingin menghapus/menonaktifkan paket '${plan.name}'?`)) return

  error.value = ''
  success.value = ''
  try {
    const { data } = await api.delete(`/superadmin/plans/${plan.id}`)
    success.value = data?.message || `Paket '${plan.name}' berhasil diproses.`
    await fetchData()
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal menghapus paket.'
  }
}

const saveTrialSettings = async () => {
  savingTrial.value = true
  error.value = ''
  success.value = ''
  try {
    await api.post('/superadmin/settings', {
      subscription_base_fee: 100000,
      subscription_store_addon_fee: 50000,
      subscription_free_stores_limit: 1,
      ...trialSettings.value,
    })
    success.value = 'Pengaturan masa uji coba (trial) berhasil disimpan.'
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal menyimpan pengaturan trial.'
  } finally {
    savingTrial.value = false
  }
}
</script>

<template>
  <AppShell>
    <div class="space-y-6 max-w-6xl pb-12">
      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 class="text-2xl font-black tracking-tight text-slate-900">Administrasi Paket Langganan SaaS</h2>
          <p class="text-sm text-slate-500">Kelola paket berjenjang bulanan & tahunan (Starter, Pro, Enterprise) dan batas platform.</p>
        </div>
        <button
          type="button"
          @click="openCreateModal"
          class="inline-flex items-center justify-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-xs font-bold text-white hover:bg-slate-800 transition-colors shadow-sm"
        >
          <span>+</span>
          <span>Buat Paket Baru</span>
        </button>
      </div>

      <!-- Messages -->
      <div v-if="error" class="bg-red-50 text-red-800 p-4 rounded-xl text-sm font-medium border border-red-200">
        {{ error }}
      </div>
      <div v-if="success" class="bg-emerald-50 text-emerald-800 p-4 rounded-xl text-sm font-medium border border-emerald-200">
        {{ success }}
      </div>

      <!-- Loading State -->
      <div v-if="loading" class="bg-white rounded-2xl border border-slate-100 p-12 flex justify-center items-center shadow-sm">
        <div class="h-8 w-8 animate-spin rounded-full border-4 border-slate-900 border-t-transparent"></div>
      </div>

      <div v-else class="space-y-8">
        <!-- Plans Grid -->
        <div class="grid gap-6 md:grid-cols-3">
          <div
            v-for="plan in plans"
            :key="plan.id"
            class="bg-white rounded-2xl border p-6 flex flex-col justify-between space-y-6 shadow-sm transition-all hover:shadow-md"
            :class="plan.is_active ? 'border-slate-200' : 'border-slate-200 bg-slate-50/60 opacity-80'"
          >
            <!-- Card Header -->
            <div class="space-y-3">
              <div class="flex items-center justify-between">
                <span class="text-xs font-mono font-bold text-slate-400 uppercase tracking-wider">#{{ plan.slug }}</span>
                <span
                  class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider"
                  :class="plan.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700'"
                >
                  {{ plan.is_active ? 'Aktif' : 'Non-aktif' }}
                </span>
              </div>
              <div>
                <h3 class="text-lg font-black text-slate-900">{{ plan.name }}</h3>
                <p class="text-xs text-slate-500 mt-1 min-h-[32px]">{{ plan.tagline || '-' }}</p>
              </div>

              <!-- Pricing Display -->
              <div class="pt-2 border-t border-slate-100 space-y-1">
                <div class="flex items-baseline gap-1">
                  <span class="text-2xl font-black text-slate-900">{{ money(plan.price_monthly) }}</span>
                  <span class="text-xs text-slate-400 font-semibold">/ bln</span>
                </div>
                <div class="text-xs text-slate-500">
                  Tahunan: <strong class="text-slate-800 font-bold">{{ money(plan.price_yearly) }}</strong> / thn
                </div>
              </div>

              <!-- Quotas -->
              <div class="grid grid-cols-2 gap-2 pt-2 text-xs">
                <div class="bg-slate-50 rounded-lg p-2.5 border border-slate-100">
                  <span class="block text-slate-400 font-bold uppercase text-[9px]">Maks Toko</span>
                  <span class="text-sm font-black text-slate-900">{{ plan.max_stores }} Cabang</span>
                </div>
                <div class="bg-slate-50 rounded-lg p-2.5 border border-slate-100">
                  <span class="block text-slate-400 font-bold uppercase text-[9px]">Maks User</span>
                  <span class="text-sm font-black text-slate-900">{{ plan.max_users }} User</span>
                </div>
              </div>

              <!-- Features list -->
              <div class="pt-2 space-y-1.5">
                <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Fitur Termasuk:</div>
                <ul class="text-xs text-slate-600 space-y-1">
                  <li v-for="(feat, idx) in plan.features" :key="idx" class="flex items-start gap-1.5">
                    <span class="text-emerald-500 font-bold shrink-0">✓</span>
                    <span>{{ feat }}</span>
                  </li>
                  <li v-if="!plan.features || plan.features.length === 0" class="text-slate-400 italic">
                    Belum ada catatan fitur spesifik.
                  </li>
                </ul>
              </div>
            </div>

            <!-- Card Footer & Actions -->
            <div class="pt-4 border-t border-slate-100 space-y-3">
              <div class="flex items-center justify-between text-xs text-slate-500">
                <span>Pengguna Aktif:</span>
                <span class="font-bold text-slate-900">{{ plan.active_subscribers_count || 0 }} Tenant</span>
              </div>
              <div class="flex items-center gap-2">
                <button
                  type="button"
                  @click="openEditModal(plan)"
                  class="flex-1 rounded-xl bg-slate-100 px-3 py-2 text-xs font-bold text-slate-800 hover:bg-slate-200 transition-colors"
                >
                  Edit Paket
                </button>
                <button
                  type="button"
                  @click="togglePlanStatus(plan)"
                  class="rounded-xl px-3 py-2 text-xs font-bold transition-colors"
                  :class="plan.is_active ? 'bg-amber-50 text-amber-800 hover:bg-amber-100' : 'bg-emerald-50 text-emerald-800 hover:bg-emerald-100'"
                >
                  {{ plan.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                </button>
                <button
                  type="button"
                  @click="deletePlan(plan)"
                  class="rounded-xl bg-rose-50 px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-100 transition-colors"
                  title="Hapus / Nonaktifkan"
                >
                  Hapus
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Global Trial Settings Section -->
        <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6 space-y-6">
          <div>
            <h3 class="text-lg font-black text-slate-900">Pengaturan Uji Coba Gratis (Trial Platform)</h3>
            <p class="text-xs text-slate-500 mt-0.5">Konfigurasi otomatis yang diberikan kepada akun tenant baru saat pertama kali registrasi.</p>
          </div>

          <form @submit.prevent="saveTrialSettings" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-3">
              <div class="space-y-1">
                <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Masa Trial (Hari)</label>
                <input
                  v-model.number="trialSettings.subscription_trial_days"
                  type="number"
                  min="1"
                  required
                  class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
                />
              </div>
              <div class="space-y-1">
                <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Maks Cabang Toko Trial</label>
                <input
                  v-model.number="trialSettings.subscription_trial_stores_limit"
                  type="number"
                  min="1"
                  required
                  class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
                />
              </div>
              <div class="space-y-1">
                <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Maks Pengguna Trial</label>
                <input
                  v-model.number="trialSettings.subscription_trial_users_limit"
                  type="number"
                  min="1"
                  required
                  class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
                />
              </div>
            </div>

            <div class="flex justify-end pt-2">
              <button
                type="submit"
                :disabled="savingTrial"
                class="rounded-xl bg-slate-900 px-5 py-2 text-xs font-bold text-white hover:bg-slate-800 disabled:opacity-50 transition-colors shadow-sm"
              >
                {{ savingTrial ? 'Menyimpan...' : 'Simpan Pengaturan Trial' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- MODAL: ADD / EDIT PLAN -->
    <div v-if="showModal" class="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-full max-w-lg p-6 space-y-4 shadow-xl border border-slate-100 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center">
          <h3 class="text-lg font-black text-slate-900">
            {{ modalMode === 'create' ? 'Buat Paket Langganan Baru' : 'Edit Paket Langganan' }}
          </h3>
          <button type="button" @click="showModal = false" class="text-slate-400 hover:text-slate-600 font-bold text-lg">✕</button>
        </div>

        <form @submit.prevent="submitPlan" class="space-y-4">
          <div class="grid grid-cols-2 gap-3">
            <div class="space-y-1">
              <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Nama Paket</label>
              <input
                v-model="planForm.name"
                type="text"
                required
                placeholder="Contoh: Pro POS"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              />
            </div>
            <div class="space-y-1">
              <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Slug ID (Unik)</label>
              <input
                v-model="planForm.slug"
                type="text"
                required
                :disabled="modalMode === 'edit'"
                placeholder="contoh: pro"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none disabled:bg-slate-100 font-mono"
              />
            </div>
          </div>

          <div class="space-y-1">
            <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Tagline / Deskripsi Singkat</label>
            <input
              v-model="planForm.tagline"
              type="text"
              placeholder="Contoh: Cocok untuk bisnis berkembang dengan multi-cabang"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
            />
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div class="space-y-1">
              <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Harga Bulanan (Rp)</label>
              <input
                v-model.number="planForm.price_monthly"
                type="number"
                min="0"
                required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              />
            </div>
            <div class="space-y-1">
              <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Harga Tahunan (Rp)</label>
              <input
                v-model.number="planForm.price_yearly"
                type="number"
                min="0"
                required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              />
              <p class="text-[10px] text-slate-400">Rekomendasi: 10x harga bulanan (hemat 2 bulan).</p>
            </div>
          </div>

          <div class="grid grid-cols-3 gap-3">
            <div class="space-y-1">
              <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Maks Toko</label>
              <input
                v-model.number="planForm.max_stores"
                type="number"
                min="1"
                required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              />
            </div>
            <div class="space-y-1">
              <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Maks User</label>
              <input
                v-model.number="planForm.max_users"
                type="number"
                min="1"
                required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              />
            </div>
            <div class="space-y-1">
              <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Urutan (Sort)</label>
              <input
                v-model.number="planForm.sort_order"
                type="number"
                required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              />
            </div>
          </div>

          <div class="space-y-1">
            <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Fitur-Fitur (Satu baris per fitur)</label>
            <textarea
              v-model="featuresText"
              rows="4"
              placeholder="Contoh:&#10;Hingga 3 Cabang Toko&#10;15 Pengguna Kasir & Admin&#10;Laporan Lengkap & Realtime"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs focus:border-slate-900 focus:outline-none font-sans"
            ></textarea>
          </div>

          <div class="flex items-center gap-2 pt-1">
            <input
              id="is_active"
              v-model="planForm.is_active"
              type="checkbox"
              class="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-900"
            />
            <label for="is_active" class="text-xs font-bold text-slate-700">Aktifkan paket untuk dipesan pelanggan</label>
          </div>

          <div class="flex justify-end gap-2 pt-4 border-t border-slate-100">
            <button
              type="button"
              @click="showModal = false"
              class="rounded-xl border border-slate-200 px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50"
            >
              Batal
            </button>
            <button
              type="submit"
              :disabled="modalLoading"
              class="rounded-xl bg-slate-900 px-5 py-2 text-xs font-bold text-white hover:bg-slate-800 disabled:opacity-50 shadow-sm"
            >
              {{ modalLoading ? 'Menyimpan...' : 'Simpan Paket' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </AppShell>
</template>