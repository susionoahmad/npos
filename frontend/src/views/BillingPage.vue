<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import AppShell from '../components/AppShell.vue'
import api from '../services/api'

interface Plan {
  id: number
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
}

interface TenantStore {
  id: number
  name: string
  address: string | null
  phone: string | null
  is_license_activated: boolean
  license_activated_at: string | null
  users_count: number
}

interface TenantUser {
  id: number
  name: string
  email: string
  role: string
  store_id: number | null
  is_active: boolean
  store?: {
    id: number
    name: string
  }
}

const loading = ref(true)
const checkoutLoading = ref(false)
const error = ref('')
const success = ref('')

const tenant = ref<any>(null)
const currentPlan = ref<Plan | null>(null)
const plans = ref<Plan[]>([])
const stores = ref<TenantStore[]>([])
const users = ref<TenantUser[]>([])

const currentStores = ref(0)
const activeStores = ref(0)
const currentUsers = ref(0)
const activeUsers = ref(0)
const maxStoresData = ref(1)
const maxUsersData = ref(3)
const isOverQuotaStores = ref(false)
const isOverQuotaUsers = ref(false)
const daysRemaining = ref(0)
const isActive = ref(true)

// Billing Cycle Switcher: monthly vs yearly (hemat 2 bulan)
const billingCycle = ref<'monthly' | 'yearly'>('yearly')

// Modals for Owner Allocation
const showStoreAllocationModal = ref(false)
const showUserAllocationModal = ref(false)
const savingAllocation = ref(false)
const selectedStoreIds = ref<number[]>([])
const selectedUserIds = ref<number[]>([])

const effectiveMaxStores = computed(() => {
  return currentPlan.value?.max_stores || maxStoresData.value || tenant.value?.max_stores || 1
})

const effectiveMaxUsers = computed(() => {
  return currentPlan.value?.max_users || maxUsersData.value || tenant.value?.max_users || 3
})

const money = (val: number) => {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(val || 0)
}

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
    currentPlan.value = data.current_plan
    plans.value = data.plans || []
    stores.value = data.stores || []
    users.value = data.users || []

    currentStores.value = data.current_stores || 0
    activeStores.value = data.active_stores || 0
    currentUsers.value = data.current_users || 0
    activeUsers.value = data.active_users || 0

    maxStoresData.value = data.max_stores || data.current_plan?.max_stores || 1
    maxUsersData.value = data.max_users || data.current_plan?.max_users || 3
    isOverQuotaStores.value = !!data.is_over_quota_stores
    isOverQuotaUsers.value = !!data.is_over_quota_users

    daysRemaining.value = data.days_remaining || 0
    isActive.value = data.is_active

    // Set default cycle to tenant's current billing cycle if available
    if (data.tenant?.billing_cycle === 'monthly') {
      billingCycle.value = 'monthly'
    } else {
      billingCycle.value = 'yearly'
    }
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal memuat informasi tagihan & langganan.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchBilling()
})

// Open Store Allocation Modal
const openStoreAllocation = () => {
  // Pre-fill with currently active store IDs, or pick up to maxStores
  const currentActiveIds = stores.value
    .filter((s) => s.is_license_activated)
    .map((s) => s.id)

  if (currentActiveIds.length > 0 && currentActiveIds.length <= effectiveMaxStores.value) {
    selectedStoreIds.value = [...currentActiveIds]
  } else {
    // Take first N stores up to limit
    selectedStoreIds.value = stores.value.slice(0, effectiveMaxStores.value).map((s) => s.id)
  }
  showStoreAllocationModal.value = true
}

const toggleStoreSelection = (storeId: number) => {
  const index = selectedStoreIds.value.indexOf(storeId)
  if (index > -1) {
    selectedStoreIds.value.splice(index, 1)
  } else {
    if (selectedStoreIds.value.length < effectiveMaxStores.value) {
      selectedStoreIds.value.push(storeId)
    }
  }
}

const submitStoreAllocation = async () => {
  savingAllocation.value = true
  error.value = ''
  success.value = ''

  try {
    const { data } = await api.post('/billing/assign-active-stores', {
      store_ids: selectedStoreIds.value,
    })
    success.value = data?.message || 'Alokasi cabang toko aktif berhasil disimpan.'
    showStoreAllocationModal.value = false
    await fetchBilling()
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal menyimpan alokasi cabang toko.'
  } finally {
    savingAllocation.value = false
  }
}

// Open User Allocation Modal
const openUserAllocation = () => {
  // Pre-fill with currently active user IDs
  const activeIds = users.value.filter((u) => u.is_active).map((u) => u.id)
  const ownerUser = users.value.find((u) => u.role === 'owner')

  if (activeIds.length > 0 && activeIds.length <= effectiveMaxUsers.value) {
    selectedUserIds.value = [...activeIds]
  } else {
    // Make sure owner is included, then take next users up to limit
    const list: number[] = []
    if (ownerUser) list.push(ownerUser.id)
    for (const u of users.value) {
      if (list.length >= effectiveMaxUsers.value) break
      if (!list.includes(u.id)) list.push(u.id)
    }
    selectedUserIds.value = list
  }
  showUserAllocationModal.value = true
}

const toggleUserSelection = (userId: number, role: string) => {
  if (role === 'owner') return // Owner cannot be deselected

  const index = selectedUserIds.value.indexOf(userId)
  if (index > -1) {
    selectedUserIds.value.splice(index, 1)
  } else {
    if (selectedUserIds.value.length < effectiveMaxUsers.value) {
      selectedUserIds.value.push(userId)
    }
  }
}

const submitUserAllocation = async () => {
  savingAllocation.value = true
  error.value = ''
  success.value = ''

  try {
    const { data } = await api.post('/billing/assign-active-users', {
      user_ids: selectedUserIds.value,
    })
    success.value = data?.message || 'Alokasi staf pengguna aktif berhasil disimpan.'
    showUserAllocationModal.value = false
    await fetchBilling()
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal menyimpan alokasi staf pengguna.'
  } finally {
    savingAllocation.value = false
  }
}

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
}

const handleCheckout = async (plan: Plan) => {
  checkoutLoading.value = true
  error.value = ''
  success.value = ''

  try {
    // 1. Load Snap SDK
    const sdkLoaded = await loadMidtransSnap()
    if (!sdkLoaded) {
      throw new Error('Gagal memuat modul pembayaran Midtrans. Pastikan koneksi internet stabil atau nonaktifkan ad-blocker.')
    }

    // 2. Request snap token from backend
    const { data } = await api.post('/billing/checkout', {
      plan: plan.slug,
      billing_cycle: billingCycle.value,
    })

    if (!data.token) {
      throw new Error('Gagal mendapatkan sesi pembayaran Midtrans.')
    }

    // 3. Open Midtrans Snap Payment modal popup
    (window as any).snap.pay(data.token, {
      onSuccess: function () {
        success.value = `Pembayaran berhasil! Masa berlangganan paket '${plan.name}' telah aktif.`
        fetchBilling()
      },
      onPending: function () {
        success.value = 'Instruksi pembayaran telah dibuat. Silakan selesaikan pembayaran di aplikasi e-wallet / mobile banking Anda.'
      },
      onError: function () {
        error.value = 'Terjadi kesalahan saat memproses pembayaran Midtrans. Silakan coba kembali.'
      },
      onClose: function () {
        // User closed snap modal
      },
    })
  } catch (e: any) {
    error.value = e?.response?.data?.message || e?.message || 'Gagal memulai checkout langganan.'
  } finally {
    checkoutLoading.value = false
  }
}

const isCurrentPlan = (plan: Plan) => {
  return tenant.value?.plan === plan.slug
}
</script>

<template>
  <AppShell>
    <div class="space-y-8 max-w-5xl pb-16">
      <!-- Title -->
      <div>
        <h2 class="text-2xl font-black tracking-tight text-slate-900">Paket & Tagihan Langganan</h2>
        <p class="text-sm text-slate-500">Pilih paket terbaik untuk bisnis ritel Anda. Satu paket mencakup seluruh cabang toko tanpa repot mengingat masa aktif terpisah.</p>
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
        <!-- Current Subscription Status Card -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-5">
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-100 pb-4">
            <div>
              <span class="text-[11px] font-extrabold uppercase tracking-wider text-slate-400">Status Langganan Saat Ini</span>
              <div class="flex items-center gap-2 mt-1">
                <h3 class="text-xl font-black text-slate-900">
                  {{ currentPlan?.name || (tenant?.plan ? tenant.plan.toUpperCase() : 'Starter POS') }}
                </h3>
                <span
                  class="rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider"
                  :class="tenant?.billing_cycle === 'yearly' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700'"
                >
                  {{ tenant?.billing_cycle === 'yearly' ? 'Tahunan' : 'Bulanan' }}
                </span>
                <span
                  class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider"
                  :class="{
                    'bg-emerald-100 text-emerald-800 border border-emerald-200': tenant?.subscription_status === 'active',
                    'bg-blue-100 text-blue-800 border border-blue-200': tenant?.subscription_status === 'trial',
                    'bg-rose-100 text-rose-800 border border-rose-200': tenant?.subscription_status === 'expired',
                  }"
                >
                  {{ tenant?.subscription_status === 'trial' ? 'Masa Trial' : (tenant?.subscription_status === 'active' ? 'Aktif' : 'Kedaluwarsa') }}
                </span>
              </div>
            </div>

            <!-- Expiration Notice -->
            <div class="sm:text-right">
              <div class="text-xs text-slate-500">
                <span v-if="tenant?.subscription_status === 'trial'">
                  Masa trial berakhir: <strong class="text-slate-800">{{ formatDate(tenant?.trial_ends_at) }}</strong>
                </span>
                <span v-else-if="tenant?.subscription_status === 'active'">
                  Masa aktif berakhir: <strong class="text-slate-800">{{ formatDate(tenant?.subscription_ends_at) }}</strong>
                </span>
                <span v-else class="text-rose-600 font-bold">
                  Masa aktif telah berakhir. Harap perpanjang paket Anda.
                </span>
              </div>
              <div v-if="isActive && daysRemaining > 0" class="text-xs font-bold text-emerald-700 mt-0.5">
                Sisa {{ daysRemaining }} hari masa aktif
              </div>
            </div>
          </div>

          <!-- Usage progress & Allocation triggers -->
          <div class="grid gap-5 sm:grid-cols-2 pt-1">
            <!-- Branch Store Usage -->
            <div class="border border-slate-100 bg-slate-50/50 rounded-xl p-4 space-y-2.5">
              <div class="flex justify-between items-center text-xs font-bold text-slate-500">
                <span>Cabang Toko Terdaftar</span>
                <span class="text-slate-900 font-black">{{ currentStores }} / {{ effectiveMaxStores }} Cabang</span>
              </div>
              <div class="w-full bg-slate-200 h-2 rounded-full overflow-hidden">
                <div
                  class="h-full rounded-full transition-all duration-300"
                  :class="currentStores > effectiveMaxStores ? 'bg-amber-500' : 'bg-slate-900'"
                  :style="{ width: Math.min(100, (currentStores / effectiveMaxStores) * 100) + '%' }"
                ></div>
              </div>
              <div class="space-y-2">
                <p class="text-[11px]" :class="isActive ? 'text-slate-500 font-medium' : 'text-rose-600 font-semibold'">
                  {{ isActive ? `Lisensi cabang aktif: ${activeStores} toko` : 'Semua cabang dinonaktifkan sementara karena masa langganan kedaluwarsa' }}
                </p>

                <!-- Downgrade / Over quota advice -->
                <div v-if="currentStores > effectiveMaxStores" class="text-[11px] text-amber-800 bg-amber-50 rounded-lg p-2.5 border border-amber-200/70 space-y-1.5">
                  <p class="font-bold">
                    ⚠️ Paket ini hanya mengizinkan {{ effectiveMaxStores }} cabang aktif (Anda memiliki {{ currentStores }} cabang).
                  </p>
                  <p class="text-[10px] text-amber-700 leading-normal">
                    Anda dapat menentukan cabang mana yang aktif beroperasi, atau upgrade ke Paket Pro untuk mengaktifkan seluruh cabang.
                  </p>
                </div>

                <!-- Store Allocation Button for Owner -->
                <button
                  type="button"
                  @click="openStoreAllocation"
                  class="w-full rounded-lg bg-white border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-100 hover:text-slate-900 transition-colors shadow-xs flex items-center justify-center gap-1.5"
                >
                  <span>⚙️</span>
                  <span>Atur Alokasi Cabang Aktif (Maks. {{ effectiveMaxStores }})</span>
                </button>
              </div>
            </div>

            <!-- User Staff Usage -->
            <div class="border border-slate-100 bg-slate-50/50 rounded-xl p-4 space-y-2.5">
              <div class="flex justify-between items-center text-xs font-bold text-slate-500">
                <span>Pengguna Staf Terdaftar</span>
                <span class="text-slate-900 font-black">{{ currentUsers }} / {{ effectiveMaxUsers }} User</span>
              </div>
              <div class="w-full bg-slate-200 h-2 rounded-full overflow-hidden">
                <div
                  class="h-full rounded-full transition-all duration-300"
                  :class="currentUsers > effectiveMaxUsers ? 'bg-amber-500' : 'bg-slate-900'"
                  :style="{ width: Math.min(100, (currentUsers / effectiveMaxUsers) * 100) + '%' }"
                ></div>
              </div>
              <div class="space-y-2">
                <p class="text-[11px] text-slate-500 font-medium">
                  Pengguna aktif saat ini: <strong>{{ activeUsers }}</strong> user (kasir, admin, owner)
                </p>

                <!-- Downgrade / Over quota advice -->
                <div v-if="currentUsers > effectiveMaxUsers" class="text-[11px] text-amber-800 bg-amber-50 rounded-lg p-2.5 border border-amber-200/70 space-y-1.5">
                  <p class="font-bold">
                    ⚠️ Paket ini membatasi {{ effectiveMaxUsers }} staf aktif (Anda memiliki {{ currentUsers }} user terdaftar).
                  </p>
                  <p class="text-[10px] text-amber-700 leading-normal">
                    Tentukan staf/kasir yang aktif bertugas agar sesuai batas paket langganan Anda.
                  </p>
                </div>

                <!-- User Allocation Button for Owner -->
                <button
                  type="button"
                  @click="openUserAllocation"
                  class="w-full rounded-lg bg-white border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-100 hover:text-slate-900 transition-colors shadow-xs flex items-center justify-center gap-1.5"
                >
                  <span>👥</span>
                  <span>Atur Alokasi Pengguna Aktif (Maks. {{ effectiveMaxUsers }})</span>
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Billing Cycle Switcher -->
        <div class="flex flex-col items-center justify-center space-y-3 pt-4">
          <div class="text-xs font-extrabold uppercase tracking-wider text-slate-400">Pilih Siklus Pembayaran</div>
          <div class="inline-flex items-center bg-slate-100 p-1 rounded-2xl border border-slate-200 shadow-inner">
            <button
              type="button"
              @click="billingCycle = 'monthly'"
              class="px-5 py-2 text-xs font-bold rounded-xl transition-all"
              :class="billingCycle === 'monthly' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'"
            >
              Bulanan
            </button>
            <button
              type="button"
              @click="billingCycle = 'yearly'"
              class="px-5 py-2 text-xs font-bold rounded-xl transition-all flex items-center gap-1.5"
              :class="billingCycle === 'yearly' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900'"
            >
              <span>Tahunan</span>
              <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase tracking-wider" :class="billingCycle === 'yearly' ? 'bg-amber-400 text-slate-950' : 'bg-amber-200 text-amber-900'">
                Hemat 2 Bln 🔥
              </span>
            </button>
          </div>
        </div>

        <!-- Plans Grid -->
        <div class="grid gap-6 md:grid-cols-3 items-stretch">
          <div
            v-for="plan in plans"
            :key="plan.id"
            class="bg-white rounded-2xl border flex flex-col justify-between p-6 space-y-6 shadow-sm transition-all relative"
            :class="[
              plan.slug === 'pro' ? 'border-slate-900 ring-2 ring-slate-900 shadow-md' : 'border-slate-200 hover:shadow-md',
              isCurrentPlan(plan) ? 'bg-slate-50/40' : ''
            ]"
          >
            <!-- Badge Most Popular or Current Plan -->
            <div v-if="plan.slug === 'pro'" class="absolute -top-3 left-1/2 -translate-x-1/2">
              <span class="bg-slate-900 text-white text-[10px] font-black uppercase tracking-widest px-3 py-1 rounded-full shadow-sm">
                Paling Populer
              </span>
            </div>

            <!-- Card Top -->
            <div class="space-y-4">
              <div class="flex items-center justify-between">
                <h4 class="text-xl font-black text-slate-900">{{ plan.name }}</h4>
                <span
                  v-if="isCurrentPlan(plan)"
                  class="rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200 px-2.5 py-0.5 text-[9px] font-black uppercase tracking-wider"
                >
                  Paket Anda
                </span>
              </div>

              <p class="text-xs text-slate-500 min-h-[34px] leading-relaxed">
                {{ plan.tagline || 'Pilihan tepat untuk mendukung kelancaran operasional ritel toko Anda.' }}
              </p>

              <!-- Price Box -->
              <div class="pt-3 border-t border-slate-100 space-y-1">
                <div class="flex items-baseline gap-1">
                  <span class="text-3xl font-black text-slate-900">
                    {{ money(billingCycle === 'yearly' ? plan.price_yearly : plan.price_monthly) }}
                  </span>
                  <span class="text-xs text-slate-400 font-semibold">
                    / {{ billingCycle === 'yearly' ? 'tahun' : 'bulan' }}
                  </span>
                </div>
                <div v-if="billingCycle === 'yearly'" class="text-[11px] text-amber-600 font-bold">
                  Setara {{ money(plan.price_yearly / 12) }} per bulan
                </div>
                <div v-else class="text-[11px] text-slate-400">
                  Tagihan dibayar setiap bulan
                </div>
              </div>

              <!-- Quotas -->
              <div class="space-y-2 pt-2">
                <div class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                  <span class="flex h-5 w-5 items-center justify-center rounded-full bg-slate-100 text-slate-900 font-bold text-[10px]">🏢</span>
                  <span>Hingga <strong class="text-slate-950 font-black">{{ plan.max_stores }} Cabang Toko</strong></span>
                </div>
                <div class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                  <span class="flex h-5 w-5 items-center justify-center rounded-full bg-slate-100 text-slate-900 font-bold text-[10px]">👥</span>
                  <span>Hingga <strong class="text-slate-950 font-black">{{ plan.max_users }} Pengguna Sistem</strong></span>
                </div>
              </div>

              <!-- Features checklist -->
              <div class="space-y-2 pt-3 border-t border-slate-100">
                <div class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400">Semua Fitur Termasuk:</div>
                <ul class="text-xs text-slate-600 space-y-2">
                  <li v-for="(feat, idx) in plan.features" :key="idx" class="flex items-start gap-2">
                    <span class="text-emerald-500 font-bold shrink-0">✓</span>
                    <span>{{ feat }}</span>
                  </li>
                  <li class="flex items-start gap-2">
                    <span class="text-emerald-500 font-bold shrink-0">✓</span>
                    <span>Integrasi Midtrans Snap QRIS & Virtual Account</span>
                  </li>
                  <li class="flex items-start gap-2">
                    <span class="text-emerald-500 font-bold shrink-0">✓</span>
                    <span>Satu Tanggal Aktif Terpadu Semua Cabang</span>
                  </li>
                </ul>
              </div>
            </div>

            <!-- Action Button -->
            <div class="pt-4 border-t border-slate-100">
              <button
                type="button"
                @click="handleCheckout(plan)"
                :disabled="checkoutLoading"
                class="w-full rounded-xl py-3 text-xs font-black transition-all shadow-sm hover:shadow disabled:opacity-50"
                :class="[
                  plan.slug === 'pro' 
                    ? 'bg-slate-900 text-white hover:bg-slate-800' 
                    : 'bg-slate-100 text-slate-900 hover:bg-slate-200',
                  isCurrentPlan(plan) && isActive
                    ? 'border border-emerald-500 text-emerald-800 bg-emerald-50/70 hover:bg-emerald-100'
                    : ''
                ]"
              >
                <span v-if="checkoutLoading">Memproses Midtrans...</span>
                <span v-else-if="isCurrentPlan(plan) && isActive">Perpanjang Paket Ini ({{ billingCycle === 'yearly' ? '1 Tahun' : '1 Bulan' }})</span>
                <span v-else>Pilih & Aktifkan Paket</span>
              </button>
            </div>
          </div>
        </div>

        <!-- FAQ / Help Note -->
        <div class="bg-slate-50 border border-slate-200/80 rounded-2xl p-6 text-xs text-slate-600 space-y-2">
          <h4 class="font-bold text-slate-900 text-sm">Informasi & Bantuan Berlangganan</h4>
          <p>
            • Jika Anda beralih paket (downgrade), seluruh data historis transaksi dan inventori cabang tetap aman tersimpan tanpa terhapus.
          </p>
          <p>
            • Owner memiliki wewenang penuh untuk memilih cabang mana dan staf mana yang aktif sesuai batas maksimal paket.
          </p>
          <p>
            • Pembayaran didukung penuh melalui Midtrans QRIS, GoPay, OVO, ShopeePay, dan Transfer Virtual Account BCA/Mandiri/BRI/BNI.
          </p>
        </div>
      </div>
    </div>

    <!-- MODAL: ATUR ALOKASI CABANG TOKO AKTIF -->
    <div v-if="showStoreAllocationModal" class="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-full max-w-lg p-6 space-y-5 shadow-xl border border-slate-100 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-start border-b pb-3">
          <div>
            <h3 class="text-lg font-black text-slate-900">Pilih Cabang Toko Aktif</h3>
            <p class="text-xs text-slate-500 mt-0.5">
              Kuota Paket: Maksimal <strong class="text-slate-900">{{ effectiveMaxStores }} Cabang</strong>
            </p>
          </div>
          <button type="button" @click="showStoreAllocationModal = false" class="text-slate-400 hover:text-slate-600 font-bold text-lg">✕</button>
        </div>

        <!-- Progress Counter -->
        <div class="flex items-center justify-between text-xs font-bold p-3 rounded-xl border" :class="selectedStoreIds.length === effectiveMaxStores ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-slate-50 border-slate-200 text-slate-700'">
          <span>Cabang Terpilih:</span>
          <span>{{ selectedStoreIds.length }} / {{ effectiveMaxStores }} Cabang</span>
        </div>

        <!-- List of Stores -->
        <div class="space-y-2">
          <div
            v-for="store in stores"
            :key="store.id"
            @click="toggleStoreSelection(store.id)"
            class="p-3.5 rounded-xl border transition-all cursor-pointer flex items-center justify-between gap-3"
            :class="selectedStoreIds.includes(store.id) ? 'border-slate-900 bg-slate-50/80 shadow-xs' : 'border-slate-200 bg-white hover:bg-slate-50/40'"
          >
            <div class="space-y-0.5">
              <div class="flex items-center gap-2">
                <span class="font-bold text-slate-900 text-sm">{{ store.name }}</span>
                <span
                  v-if="selectedStoreIds.includes(store.id)"
                  class="rounded-full bg-emerald-100 text-emerald-800 text-[9px] font-black uppercase px-2 py-0.5"
                >
                  Aktif
                </span>
                <span
                  v-else
                  class="rounded-full bg-slate-100 text-slate-500 text-[9px] font-black uppercase px-2 py-0.5"
                >
                  Nonaktif
                </span>
              </div>
              <p class="text-xs text-slate-500">{{ store.address || 'Alamat belum diatur' }}</p>
              <p class="text-[10px] text-slate-400">{{ store.users_count || 0 }} staf pengguna di cabang ini</p>
            </div>

            <!-- Checkbox UI -->
            <input
              type="checkbox"
              :checked="selectedStoreIds.includes(store.id)"
              :disabled="!selectedStoreIds.includes(store.id) && selectedStoreIds.length >= effectiveMaxStores"
              class="h-5 w-5 rounded border-slate-300 text-slate-900 focus:ring-slate-900 cursor-pointer pointer-events-none"
            />
          </div>
        </div>

        <p class="text-[11px] text-slate-400 italic">
          * Cabang yang dinonaktifkan tidak dapat memproses transaksi kasir, namun data riwayat dan stoknya tetap aman tersimpan.
        </p>

        <div class="flex justify-end gap-2 pt-3 border-t">
          <button
            type="button"
            @click="showStoreAllocationModal = false"
            class="rounded-xl border border-slate-200 px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50"
          >
            Batal
          </button>
          <button
            type="button"
            @click="submitStoreAllocation"
            :disabled="savingAllocation || selectedStoreIds.length === 0"
            class="rounded-xl bg-slate-900 px-5 py-2 text-xs font-bold text-white hover:bg-slate-800 disabled:opacity-50"
          >
            {{ savingAllocation ? 'Menyimpan...' : 'Simpan Alokasi Cabang' }}
          </button>
        </div>
      </div>
    </div>

    <!-- MODAL: ATUR ALOKASI PENGGUNA STAF AKTIF -->
    <div v-if="showUserAllocationModal" class="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-full max-w-lg p-6 space-y-5 shadow-xl border border-slate-100 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-start border-b pb-3">
          <div>
            <h3 class="text-lg font-black text-slate-900">Pilih Staf Pengguna Aktif</h3>
            <p class="text-xs text-slate-500 mt-0.5">
              Kuota Paket: Maksimal <strong class="text-slate-900">{{ effectiveMaxUsers }} Pengguna</strong>
            </p>
          </div>
          <button type="button" @click="showUserAllocationModal = false" class="text-slate-400 hover:text-slate-600 font-bold text-lg">✕</button>
        </div>

        <!-- Progress Counter -->
        <div class="flex items-center justify-between text-xs font-bold p-3 rounded-xl border" :class="selectedUserIds.length === effectiveMaxUsers ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-slate-50 border-slate-200 text-slate-700'">
          <span>Pengguna Terpilih:</span>
          <span>{{ selectedUserIds.length }} / {{ effectiveMaxUsers }} User</span>
        </div>

        <!-- List of Users -->
        <div class="space-y-2">
          <div
            v-for="u in users"
            :key="u.id"
            @click="toggleUserSelection(u.id, u.role)"
            class="p-3.5 rounded-xl border transition-all flex items-center justify-between gap-3"
            :class="[
              selectedUserIds.includes(u.id) ? 'border-slate-900 bg-slate-50/80 shadow-xs' : 'border-slate-200 bg-white hover:bg-slate-50/40',
              u.role === 'owner' ? 'cursor-default' : 'cursor-pointer'
            ]"
          >
            <div class="space-y-0.5">
              <div class="flex items-center gap-2">
                <span class="font-bold text-slate-900 text-sm">{{ u.name }}</span>
                <span
                  class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase"
                  :class="u.role === 'owner' ? 'bg-purple-100 text-purple-800' : (u.role === 'admin' ? 'bg-blue-100 text-blue-800' : 'bg-slate-100 text-slate-700')"
                >
                  {{ u.role }}
                </span>
                <span
                  v-if="selectedUserIds.includes(u.id)"
                  class="rounded-full bg-emerald-100 text-emerald-800 text-[9px] font-black uppercase px-2 py-0.5"
                >
                  Aktif
                </span>
              </div>
              <p class="text-xs text-slate-500">{{ u.email }}</p>
              <p class="text-[10px] text-slate-400">Cabang: {{ u.store?.name || 'Semua Cabang / Owner' }}</p>
            </div>

            <!-- Checkbox UI -->
            <input
              type="checkbox"
              :checked="selectedUserIds.includes(u.id)"
              :disabled="u.role === 'owner' || (!selectedUserIds.includes(u.id) && selectedUserIds.length >= effectiveMaxUsers)"
              class="h-5 w-5 rounded border-slate-300 text-slate-900 focus:ring-slate-900 cursor-pointer pointer-events-none"
            />
          </div>
        </div>

        <p class="text-[11px] text-slate-400 italic">
          * Akun Owner otomatis selalu aktif. Staf yang dinonaktifkan tidak dapat login ke aplikasi kasir hingga kuota ditambah atau diaktifkan kembali.
        </p>

        <div class="flex justify-end gap-2 pt-3 border-t">
          <button
            type="button"
            @click="showUserAllocationModal = false"
            class="rounded-xl border border-slate-200 px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50"
          >
            Batal
          </button>
          <button
            type="button"
            @click="submitUserAllocation"
            :disabled="savingAllocation || selectedUserIds.length === 0"
            class="rounded-xl bg-slate-900 px-5 py-2 text-xs font-bold text-white hover:bg-slate-800 disabled:opacity-50"
          >
            {{ savingAllocation ? 'Menyimpan...' : 'Simpan Alokasi Pengguna' }}
          </button>
        </div>
      </div>
    </div>
  </AppShell>
</template>