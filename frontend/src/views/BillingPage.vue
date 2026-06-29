<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import AppShell from '../components/AppShell.vue'
import api from '../services/api'

const loading = ref(true)
const checkoutLoading = ref(false)
const error = ref('')
const success = ref('')

const tenant = ref<any>(null)
const currentStores = ref(0)
const currentUsers = ref(0)
const pricing = ref({
  base_fee: 100000,
  store_addon_fee: 50000,
  free_stores_limit: 1,
})

// Calculator selection
const selectedStores = ref(1)

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
    currentStores.value = data.current_stores ?? 0
    currentUsers.value = data.current_users ?? 0
    if (data.pricing_config) {
      pricing.value = data.pricing_config
    }
    // Set default selected stores to current stores or max_stores
    selectedStores.value = Math.max(1, tenant.value?.max_stores || currentStores.value)
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal memuat data tagihan.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchBilling()
})

// Calculate price based on selected stores
const calculatedPrice = computed(() => {
  const base = pricing.value.base_fee
  const addon = pricing.value.store_addon_fee
  const freeLimit = pricing.value.free_stores_limit
  const extra = Math.max(0, selectedStores.value - freeLimit)
  return base + (extra * addon)
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

const handleCheckout = async () => {
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
      total_stores: selectedStores.value,
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

      <div v-else class="grid gap-6 md:grid-cols-3 items-start">
        <!-- Left: Status & Current Usage -->
        <div class="md:col-span-2 space-y-6">
          <!-- Subscription Info Card -->
          <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6 space-y-4">
            <h3 class="font-extrabold text-slate-900 text-base">Status Langganan Saat Ini</h3>
            
            <div class="flex items-center gap-3">
              <span
                class="inline-flex items-center rounded-full px-3 py-1 text-xs font-black uppercase tracking-wider"
                :class="{
                  'bg-emerald-100 text-emerald-800 border border-emerald-200': tenant?.subscription_status === 'active',
                  'bg-blue-100 text-blue-800 border border-blue-200': tenant?.subscription_status === 'trial',
                  'bg-rose-100 text-rose-800 border border-rose-200': tenant?.subscription_status === 'expired',
                  'bg-slate-100 text-slate-700': tenant?.subscription_status === 'cancelled',
                }"
              >
                {{ tenant?.subscription_status }}
              </span>
              <span v-if="tenant?.subscription_status === 'trial'" class="text-xs text-slate-500 font-semibold">
                Masa uji coba gratis berakhir pada: <strong class="text-slate-800 font-bold">{{ formatDate(tenant.trial_ends_at) }}</strong>
              </span>
              <span v-else-if="tenant?.subscription_status === 'active'" class="text-xs text-slate-500 font-semibold">
                Masa aktif berlangganan berakhir pada: <strong class="text-slate-800 font-bold">{{ formatDate(tenant.subscription_ends_at) }}</strong>
              </span>
            </div>

            <!-- Current Limit Progress -->
            <div class="grid gap-4 sm:grid-cols-2 pt-2">
              <div class="border border-slate-100 rounded-xl p-4 space-y-1">
                <div class="text-xs font-bold text-slate-400 uppercase tracking-wider">Cabang Toko</div>
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

          <!-- Checkout Calculator Card -->
          <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6 space-y-6">
            <h3 class="font-extrabold text-slate-900 text-base">Perpanjang / Perbarui Cabang</h3>
            <div class="space-y-4">
              <div class="space-y-1">
                <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500 mb-1">
                  Pilih Jumlah Total Toko yang Diinginkan
                </label>
                <div class="flex items-center gap-4">
                  <input
                    v-model.number="selectedStores"
                    type="range"
                    min="1"
                    max="10"
                    class="w-full h-2 bg-slate-100 rounded-lg appearance-none cursor-pointer accent-slate-900"
                  />
                  <span class="text-xl font-black text-slate-900 shrink-0 w-12 text-center">
                    {{ selectedStores }} Toko
                  </span>
                </div>
              </div>

              <!-- Calculation Details -->
              <div class="bg-slate-50 rounded-xl p-4 text-xs text-slate-600 space-y-2 font-medium">
                <div class="flex justify-between">
                  <span>Biaya Dasar Bulanan ({{ pricing.free_stores_limit }} Toko)</span>
                  <span class="font-bold text-slate-900">{{ money(pricing.base_fee) }}</span>
                </div>
                <div class="flex justify-between" v-if="selectedStores > pricing.free_stores_limit">
                  <span>Toko Tambahan ({{ selectedStores - pricing.free_stores_limit }} Cabang x {{ money(pricing.store_addon_fee) }})</span>
                  <span class="font-bold text-slate-900">{{ money((selectedStores - pricing.free_stores_limit) * pricing.store_addon_fee) }}</span>
                </div>
                <div class="border-t border-slate-200 pt-2 flex justify-between text-sm font-black text-slate-900">
                  <span>Total Tagihan Bulanan</span>
                  <span>{{ money(calculatedPrice) }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Right: Checkout CTA -->
        <div class="bg-slate-900 text-white rounded-2xl shadow-md p-6 space-y-6 flex flex-col justify-between h-full relative overflow-hidden">
          <div class="space-y-4 z-10">
            <h3 class="font-black text-lg">Perbarui Sekarang</h3>
            <p class="text-xs text-slate-300 leading-relaxed">
              Masa berlangganan Anda akan diperpanjang selama **30 hari** ke depan dengan jumlah limit cabang yang disesuaikan.
            </p>
            <div class="border-t border-slate-800 pt-4 space-y-1">
              <span class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400">Total Pembayaran</span>
              <div class="text-2xl font-black text-white font-mono">{{ money(calculatedPrice) }}</div>
            </div>
          </div>
          <button
            type="button"
            @click="handleCheckout"
            :disabled="checkoutLoading"
            class="w-full rounded-xl bg-white py-3 text-sm font-extrabold text-slate-950 hover:bg-slate-50 transition-colors shadow-lg disabled:opacity-50 mt-6 z-10"
          >
            {{ checkoutLoading ? 'Menghubungkan Midtrans...' : '💳 Bayar Sekarang' }}
          </button>
        </div>
      </div>
    </div>
  </AppShell>
</template>
