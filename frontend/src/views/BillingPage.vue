<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AppShell from '../components/AppShell.vue'
import api from '../services/api'

const loading = ref(true)
const checkoutLoading = ref(false)
const error = ref('')
const success = ref('')

const tenant = ref<any>(null)
const slots = ref<any[]>([])
const currentStores = ref(0)
const currentUsers = ref(0)
const pricing = ref({
  base_fee: 100000,
  store_addon_fee: 50000,
  free_stores_limit: 1,
})

const money = (n: number) =>
  new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n)

const formatDate = (d: string | null) => {
  if (!d) return '-'
  return new Date(d).toLocaleDateString('id-ID', { year: 'numeric', month: 'long', day: 'numeric' })
}

const fetchBilling = async () => {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/billing/overview')
    tenant.value = data.tenant
    slots.value = data.slots ?? []
    currentStores.value = data.current_stores ?? 0
    currentUsers.value = data.current_users ?? 0
    if (data.pricing_config) {
      pricing.value = data.pricing_config
    }
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal memuat data tagihan.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchBilling()
})

// Dynamic script loader for Midtrans Snap SDK
const loadMidtransSnap = (): Promise<boolean> => {
  return new Promise((resolve) => {
    if ((window as any).snap) {
      resolve(true)
      return
    }
    const isProduction = import.meta.env.VITE_MIDTRANS_IS_PRODUCTION === 'true'
    const clientKey = import.meta.env.VITE_MIDTRANS_CLIENT_KEY || 'SB-Mid-client-y_aIuP3k0F3rW-Uq'
    
    const script = document.createElement('script')
    script.src = isProduction
      ? 'https://app.midtrans.com/snap/snap.js'
      : 'https://app.sandbox.midtrans.com/snap/snap.js'
    
    script.setAttribute('data-client-key', clientKey)
    script.onload = () => resolve(true)
    script.onerror = () => resolve(false)
    document.head.appendChild(script)
  })
};

const handleCheckout = async (action: 'renew' | 'add_addon', slotId?: number) => {
  checkoutLoading.value = true
  error.value = ''
  success.value = ''

  try {
    // 1. Load Snap SDK
    const sdkLoaded = await loadMidtransSnap()
    if (!sdkLoaded) {
      throw new Error('Gagal memuat sistem pembayaran Midtrans. Coba matikan AdBlocker Anda.')
    }

    // 2. Request snap token from backend
    const { data } = await api.post('/billing/checkout', {
      action,
      slot_id: slotId,
    })

    if (!data.token) {
      throw new Error('Gagal mendapatkan kode pembayaran.')
    }

    // 3. Open Midtrans Snap Payment modal popup
    (window as any).snap.pay(data.token, {
      onSuccess: function () {
        success.value = 'Pembayaran berhasil! Masa berlangganan Anda telah diperpanjang.'
        fetchBilling()
      },
      onPending: function () {
        success.value = 'Pembayaran tertunda. Silakan selesaikan pembayaran di aplikasi e-wallet / bank Anda.'
      },
      onError: function () {
        error.value = 'Terjadi kesalahan pembayaran. Silakan coba lagi.'
      },
      onClose: function () {
        checkoutLoading.value = false
      }
    })
  } catch (e: any) {
    error.value = e.message || 'Terjadi kesalahan saat memproses checkout.'
    checkoutLoading.value = false
  }
}
</script>

<template>
  <AppShell>
    <div class="space-y-6 max-w-4xl">
      <!-- Title -->
      <div>
        <h2 class="text-2xl font-black tracking-tight text-slate-900">Tagihan & Langganan</h2>
        <p class="text-sm text-slate-500">Kelola lisensi cabang toko Anda dan perbarui masa aktif berlangganan.</p>
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

      <div v-else class="space-y-6">
        <!-- Overview Banner -->
        <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6 space-y-4">
          <h3 class="font-extrabold text-slate-900 text-base">Status Langganan Utama</h3>
          
          <div class="flex flex-wrap items-center gap-3">
            <span
              class="inline-flex items-center rounded-full px-3 py-1 text-xs font-black uppercase tracking-wider"
              :class="{
                'bg-emerald-100 text-emerald-800 border border-emerald-200': tenant?.subscription_status === 'active',
                'bg-blue-100 text-blue-800 border border-blue-200': tenant?.subscription_status === 'trial',
                'bg-rose-100 text-rose-800 border border-rose-200': tenant?.subscription_status === 'expired',
              }"
            >
              {{ tenant?.subscription_status === 'trial' ? 'Masa Uji Coba (Trial)' : 'Berlangganan Aktif' }}
            </span>
            <span v-if="tenant?.subscription_status === 'trial'" class="text-xs text-slate-500 font-semibold">
              Masa uji coba gratis berakhir pada: <strong class="text-slate-800 font-bold">{{ formatDate(tenant.trial_ends_at) }}</strong>
            </span>
            <span v-else class="text-xs text-slate-500 font-semibold">
              Status Utama: <strong class="text-slate-800 font-bold">Aktif</strong>
            </span>
          </div>

          <!-- Usage progress -->
          <div class="grid gap-4 sm:grid-cols-2 pt-2">
            <div class="border border-slate-100 rounded-xl p-4 space-y-1">
              <div class="text-xs font-bold text-slate-400 uppercase tracking-wider">Cabang Toko Terpakai</div>
              <div class="text-2xl font-black text-slate-900">{{ currentStores }} / {{ tenant?.max_stores }}</div>
              <div class="w-full bg-slate-100 h-1.5 rounded-full overflow-hidden">
                <div
                  class="bg-slate-900 h-full rounded-full"
                  :style="{ width: Math.min(100, (currentStores / (tenant?.max_stores || 1)) * 100) + '%' }"
                ></div>
              </div>
            </div>
            <div class="border border-slate-100 rounded-xl p-4 space-y-1">
              <div class="text-xs font-bold text-slate-400 uppercase tracking-wider">Pengguna Sistem</div>
              <div class="text-2xl font-black text-slate-900">{{ currentUsers }} / {{ tenant?.max_users }}</div>
              <div class="w-full bg-slate-100 h-1.5 rounded-full overflow-hidden">
                <div
                  class="bg-slate-900 h-full rounded-full"
                  :style="{ width: Math.min(100, (currentUsers / (tenant?.max_users || 1)) * 100) + '%' }"
                ></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Slots List Section -->
        <div>
          <div class="flex items-center justify-between mb-4">
            <h3 class="font-extrabold text-slate-900 text-base">Detail Lisensi Slot Cabang</h3>
            <button
              v-if="tenant?.subscription_status !== 'trial'"
              type="button"
              @click="handleCheckout('add_addon')"
              :disabled="checkoutLoading"
              class="rounded-lg bg-slate-900 px-4 py-2 text-xs font-bold text-white hover:bg-slate-800 transition-colors shadow-sm disabled:opacity-50"
            >
              ➕ Tambah Slot Cabang Baru ({{ money(pricing.store_addon_fee) }} / 30 Hari)
            </button>
          </div>

          <!-- Card Grid of Slots -->
          <div class="grid gap-4 sm:grid-cols-2">
            <!-- Slot Card -->
            <div
              v-for="slot in slots"
              :key="slot.id"
              class="bg-white rounded-2xl border p-5 flex flex-col justify-between space-y-4 shadow-sm"
              :class="slot.status === 'active' ? 'border-slate-100' : 'border-rose-200 bg-rose-50/20'"
            >
              <div class="space-y-2">
                <div class="flex items-center justify-between">
                  <span class="text-lg">{{ slot.slot_type === 'base' ? '🏪' : '🏬' }}</span>
                  <span
                    class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-wider"
                    :class="slot.status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'"
                  >
                    {{ slot.status === 'active' ? 'Aktif' : 'Expired' }}
                  </span>
                </div>
                <div>
                  <h4 class="font-extrabold text-slate-900 text-sm">{{ slot.label }}</h4>
                  <p class="text-xs text-slate-500 mt-0.5">
                    <span v-if="slot.status === 'active'">
                      Berakhir pada: <strong class="text-slate-800">{{ formatDate(slot.expires_at) }}</strong>
                      <span class="block text-[10px] text-emerald-700 font-bold mt-0.5">Sisa {{ slot.days_remaining }} hari lagi</span>
                    </span>
                    <span v-else class="text-rose-600 font-semibold">
                      Lisensi telah berakhir. Cabang terkait dinonaktifkan.
                    </span>
                  </p>
                </div>
              </div>

              <button
                type="button"
                @click="handleCheckout('renew', slot.id)"
                :disabled="checkoutLoading"
                class="w-full rounded-xl py-2 text-xs font-bold transition-all"
                :class="slot.status === 'active' 
                  ? 'bg-slate-100 text-slate-800 hover:bg-slate-200' 
                  : 'bg-rose-600 text-white hover:bg-rose-700'"
              >
                {{ checkoutLoading ? 'Memproses...' : `Perpanjang (${money(slot.renew_fee)})` }}
              </button>
            </div>

            <!-- Empty slots prompt for Trial Mode -->
            <div 
              v-if="tenant?.subscription_status === 'trial'"
              class="sm:col-span-2 bg-gradient-to-br from-blue-50 to-indigo-50/50 border border-blue-100 rounded-2xl p-6 flex flex-col items-center justify-center text-center space-y-4"
            >
              <div class="text-3xl">🚀</div>
              <div class="space-y-1 max-w-md">
                <h4 class="font-extrabold text-blue-900 text-sm">Aktifkan Langganan Penuh</h4>
                <p class="text-xs text-blue-700 leading-relaxed">
                  Aktifkan lisensi paket dasar Anda sekarang untuk membuka akses penuh 100 pengguna sistem dan mendaftarkan cabang-cabang tambahan.
                </p>
              </div>
              <button
                type="button"
                @click="handleCheckout('add_addon')"
                :disabled="checkoutLoading"
                class="rounded-xl bg-blue-600 px-6 py-2.5 text-xs font-bold text-white hover:bg-blue-700 transition-all shadow-md hover:shadow-lg disabled:opacity-50"
              >
                {{ checkoutLoading ? 'Menghubungkan Midtrans...' : `Aktifkan Paket Dasar (${money(pricing.base_fee)} / 30 Hari)` }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppShell>
</template>
