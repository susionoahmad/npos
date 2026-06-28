<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import AppShell from '../components/AppShell.vue'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()

type PaymentMethodItem = {
  method: string
  amount: number
  count: number
}

type TopProductItem = {
  product_id: number
  product_name: string
  qty_sold: string | number
  total_revenue: string | number
}

type RecentTransactionItem = {
  id: number
  invoice_number: string
  total: string | number
  created_at: string
  payment_method: string
  user?: {
    name: string
  }
}

type Summary = {
  total_sales: number
  total_transactions: number
  average_order_value: number
  total_discount: number
  total_tax: number
  payment_methods: PaymentMethodItem[]
  top_products: TopProductItem[]
  recent_transactions: RecentTransactionItem[]
}

const summary = ref<Summary>({
  total_sales: 0,
  total_transactions: 0,
  average_order_value: 0,
  total_discount: 0,
  total_tax: 0,
  payment_methods: [],
  top_products: [],
  recent_transactions: [],
})

const loading = ref(true)
const loadError = ref('')

const money = (n: number) =>
  new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Math.round(n))

const formatTime = (dateStr: string) => {
  return new Date(dateStr).toLocaleTimeString('id-ID', {
    hour: '2-digit',
    minute: '2-digit'
  })
}

const getPaymentLabel = (method: string) => {
  switch (method) {
    case 'cash': return 'Tunai'
    case 'qris': return 'QRIS'
    case 'transfer': return 'Transfer'
    case 'card': return 'Kartu'
    default: return method
  }
}

const getPaymentBadgeClass = (method: string) => {
  switch (method) {
    case 'cash': return 'bg-emerald-50 text-emerald-700 border-emerald-200'
    case 'qris': return 'bg-purple-50 text-purple-700 border-purple-200'
    case 'transfer': return 'bg-amber-50 text-amber-700 border-amber-200'
    case 'card': return 'bg-blue-50 text-blue-700 border-blue-200'
    default: return 'bg-slate-50 text-slate-700 border-slate-200'
  }
}

// Calculate percentages for payment breakdown visual
const maxPaymentVolume = computed(() => {
  if (!summary.value.payment_methods.length) return 1
  return Math.max(...summary.value.payment_methods.map(p => p.amount), 1)
})

// Calculate relative percentage for top products sold volume
const maxProductQtySold = computed(() => {
  if (!summary.value.top_products.length) return 1
  return Math.max(...summary.value.top_products.map(p => Number(p.qty_sold)), 1)
})

const activeSession = ref<any>(null)
const sessionSummary = ref<any>(null)

// Saldo laci terkini: mutasi masuk - mutasi keluar (sudah mencakup modal awal & penjualan tunai)
const drawerBalance = computed(() => {
  if (!sessionSummary.value) return activeSession.value?.start_balance ?? 0
  const s = sessionSummary.value
  return (s.mutations_in ?? 0) - (s.mutations_out ?? 0)
})

async function loadDashboard() {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get('/reports/daily-summary')
    summary.value = data
    
    // Fetch active session
    if (auth.user?.store_id) {
      const sessRes = await api.get('/cashier-sessions/active')
      activeSession.value = sessRes.data && Object.keys(sessRes.data).length > 0 ? sessRes.data : null

      // Fetch session summary untuk saldo laci terkini
      if (activeSession.value) {
        try {
          const sumRes = await api.get('/cashier-sessions/active/summary')
          sessionSummary.value = sumRes.data
        } catch {
          sessionSummary.value = null
        }
      } else {
        sessionSummary.value = null
      }
    }
  } catch (e: any) {
    loadError.value = 'Gagal memuat laporan ringkasan dashboard.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadDashboard()
})
</script>

<template>
  <AppShell>
    <div class="space-y-6">
      
      <!-- Top Header Row -->
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="text-2xl font-bold tracking-tight text-slate-900">Dashboard</h2>
          <p class="text-xs text-slate-500 font-medium">Ringkasan aktivitas penjualan toko hari ini</p>
        </div>
        <button
          type="button"
          class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 flex items-center gap-1.5 shadow-sm transition-colors"
          :disabled="loading"
          @click="loadDashboard"
        >
          <svg class="h-3.5 w-3.5" :class="loading ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 1121.253 8H18" />
          </svg>
          Segarkan
        </button>
      </div>

      <!-- Warning Alert -->
      <p v-if="loadError" class="rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-xs font-semibold text-red-800 shadow-sm">{{ loadError }}</p>

      <!-- Superadmin / Empty store ID specific banner -->
      <div v-if="auth.isSuperAdmin && !auth.user?.store_id" class="bg-white rounded-2xl border border-slate-200 p-6 flex flex-col md:flex-row items-center gap-4 shadow-sm">
        <span class="text-4xl">👑</span>
        <div class="space-y-1 text-center md:text-left">
          <h3 class="font-extrabold text-slate-900 text-sm">Mode SaaS Superadmin</h3>
          <p class="text-xs text-slate-500 font-medium max-w-[580px] leading-relaxed">
            Anda login sebagai Superadmin global. Anda tidak memiliki konteks cabang toko aktif saat ini. Kunjungi menu 
            <RouterLink to="/settings" class="text-blue-600 font-bold hover:underline">Pengaturan</RouterLink> 
            untuk mendaftarkan tenant baru atau mengatur lisensi toko.
          </p>
        </div>
      </div>

      <!-- Cashier Session Banner -->
      <div v-else-if="activeSession" class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-4">
        <div class="flex items-center gap-3">
          <span class="text-2xl">⏰</span>
          <div>
            <h3 class="font-extrabold text-slate-900 text-sm">Sesi Shift Kasir Aktif</h3>
            <div class="text-xs text-slate-500 font-semibold flex flex-wrap items-center gap-1.5 mt-0.5">
              <span class="inline-flex rounded-full bg-slate-900 text-white px-2 py-0.5 text-[9px] font-extrabold uppercase font-mono">
                {{ activeSession.session_number }}
              </span>
              <span>Shift {{ activeSession.shift }}</span>
              <span>•</span>
              <span class="font-mono">Modal Awal: Rp {{ money(activeSession.start_balance) }}</span>
              <span>•</span>
              <!-- Saldo Laci Live -->
              <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200 px-2.5 py-0.5 font-mono font-extrabold text-[10px]">
                🏧 Saldo Laci: Rp {{ money(drawerBalance) }}
              </span>
              <span>•</span>
              <span>Dibuka sejak {{ new Date(activeSession.opened_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) }} WIB</span>
            </div>
          </div>
        </div>
        <RouterLink to="/pos" class="w-full sm:w-auto text-center rounded-xl bg-slate-900 px-4 py-2 text-xs font-bold text-white hover:bg-slate-800 transition-all">
          Masuk Kasir POS
        </RouterLink>
      </div>

      <div v-else-if="auth.user?.store_id" class="bg-amber-50 border border-amber-200 rounded-2xl p-4 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-4">
        <div class="flex items-center gap-3">
          <span class="text-2xl">⚠️</span>
          <div>
            <h3 class="font-extrabold text-amber-800 text-sm">Sesi Kasir Belum Dibuka</h3>
            <p class="text-xs text-amber-600 font-semibold mt-0.5">Mulai shift baru Anda dengan mendaftarkan uang laci modal awal terlebih dahulu.</p>
          </div>
        </div>
        <RouterLink to="/session/open" class="w-full sm:w-auto text-center rounded-xl bg-amber-600 px-4 py-2 text-xs font-bold text-white hover:bg-amber-700 transition-all shadow-sm">
          Buka Sesi Kasir
        </RouterLink>
      </div>

      <div v-else-if="loading" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div v-for="i in 4" :key="i" class="animate-pulse bg-white border border-slate-200/80 rounded-2xl p-5 space-y-3">
          <div class="h-8 w-8 bg-slate-200 rounded-full"></div>
          <div class="h-4 bg-slate-200 rounded w-1/2"></div>
          <div class="h-6 bg-slate-200 rounded w-3/4"></div>
        </div>
      </div>

      <!-- 4 Financial Stats Cards Grid -->
      <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- 1. Total Sales -->
        <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm flex items-center gap-4">
          <div class="h-12 w-12 rounded-2xl bg-emerald-50 border border-emerald-100 flex items-center justify-center text-emerald-600 text-2xl shrink-0">
            💵
          </div>
          <div class="min-w-0">
            <span class="block text-[11px] font-bold uppercase tracking-wider text-slate-400">Total Penjualan</span>
            <span class="block text-xl font-extrabold text-slate-950 font-mono mt-0.5">Rp {{ money(summary.total_sales) }}</span>
            <span class="block text-[10px] font-semibold text-slate-400 mt-0.5">Hari ini</span>
          </div>
        </div>

        <!-- 2. Transactions Today -->
        <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm flex items-center gap-4">
          <div class="h-12 w-12 rounded-2xl bg-blue-50 border border-blue-100 flex items-center justify-center text-blue-600 text-2xl shrink-0">
            🛒
          </div>
          <div class="min-w-0">
            <span class="block text-[11px] font-bold uppercase tracking-wider text-slate-400">Total Transaksi</span>
            <span class="block text-xl font-extrabold text-slate-950 font-mono mt-0.5">{{ summary.total_transactions }}</span>
            <span class="block text-[10px] font-semibold text-slate-400 mt-0.5">Struk terbit hari ini</span>
          </div>
        </div>

        <!-- 3. Average Value -->
        <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm flex items-center gap-4">
          <div class="h-12 w-12 rounded-2xl bg-indigo-50 border border-indigo-100 flex items-center justify-center text-indigo-600 text-2xl shrink-0">
            📊
          </div>
          <div class="min-w-0">
            <span class="block text-[11px] font-bold uppercase tracking-wider text-slate-400">Rata-Rata Belanja</span>
            <span class="block text-xl font-extrabold text-slate-950 font-mono mt-0.5">Rp {{ money(summary.average_order_value) }}</span>
            <span class="block text-[10px] font-semibold text-slate-400 mt-0.5">Nilai per transaksi</span>
          </div>
        </div>

        <!-- 4. Discounts & Taxes -->
        <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm flex items-center gap-4">
          <div class="h-12 w-12 rounded-2xl bg-amber-50 border border-amber-100 flex items-center justify-center text-amber-600 text-2xl shrink-0">
            🏷️
          </div>
          <div class="min-w-0">
            <span class="block text-[11px] font-bold uppercase tracking-wider text-slate-400">Pajak & Diskon</span>
            <div class="flex items-center gap-2 mt-0.5">
              <span class="text-sm font-extrabold text-slate-800 font-mono" title="Pajak">P: {{ money(summary.total_tax) }}</span>
              <span class="text-xs text-slate-300">|</span>
              <span class="text-sm font-extrabold text-red-600 font-mono" title="Diskon">D: {{ money(summary.total_discount) }}</span>
            </div>
            <span class="block text-[10px] font-semibold text-slate-400 mt-0.5">Pajak (P) & Diskon (D)</span>
          </div>
        </div>
      </div>

      <!-- Main Columns Grid -->
      <div v-if="!loading" class="grid gap-6 lg:grid-cols-3 items-start">
        
        <!-- Column 1 & 2: Top Products & Recent Transactions -->
        <div class="lg:col-span-2 space-y-6">
          
          <!-- Top Products Today card -->
          <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-4">
            <div>
              <h3 class="font-extrabold text-slate-900 text-sm">Produk Terlaris Hari Ini</h3>
              <p class="text-[11px] text-slate-500 font-medium">Berdasarkan volume penjualan kuantitas produk</p>
            </div>

            <div class="space-y-4">
              <div v-for="(p, idx) in summary.top_products" :key="p.product_id" class="space-y-1.5">
                <div class="flex items-center justify-between text-xs font-semibold">
                  <div class="flex items-center gap-2">
                    <span class="h-5 w-5 bg-slate-100 rounded-full flex items-center justify-center text-[10px] font-extrabold text-slate-500 shrink-0">
                      {{ idx + 1 }}
                    </span>
                    <span class="text-slate-900 font-bold truncate max-w-[280px] sm:max-w-[420px]">{{ p.product_name }}</span>
                  </div>
                  <div class="text-right shrink-0">
                    <span class="font-bold text-slate-900 font-mono">{{ p.qty_sold }} terjual</span>
                    <span class="text-slate-400 font-medium font-mono text-[10px] ml-2">Rp {{ money(Number(p.total_revenue)) }}</span>
                  </div>
                </div>
                
                <!-- Progress visual bar -->
                <div class="w-full h-2 bg-slate-100 rounded-full overflow-hidden">
                  <div 
                    class="h-full rounded-full transition-all duration-500" 
                    :class="idx === 0 ? 'bg-blue-600' : idx === 1 ? 'bg-indigo-500' : 'bg-slate-400'"
                    :style="{ width: `${(Number(p.qty_sold) / maxProductQtySold) * 100}%` }"
                  ></div>
                </div>
              </div>

              <!-- Empty top products today -->
              <div v-if="!summary.top_products.length" class="text-center py-6 text-slate-400 text-xs font-medium">
                Belum ada data penjualan produk hari ini.
              </div>
            </div>
          </div>

          <!-- Recent Transactions list card -->
          <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-4">
            <div class="flex items-center justify-between">
              <div>
                <h3 class="font-extrabold text-slate-900 text-sm">Transaksi Terakhir</h3>
                <p class="text-[11px] text-slate-500 font-medium">Lima invoice transaksi penjualan terbaru</p>
              </div>
              <RouterLink to="/orders" class="text-xs font-bold text-blue-600 hover:underline">Lihat Semua</RouterLink>
            </div>

            <!-- List items -->
            <div class="space-y-3">
              <div
                v-for="trx in summary.recent_transactions"
                :key="trx.id"
                class="flex items-center justify-between gap-4 border-b border-slate-100 pb-3 last:border-0 last:pb-0"
              >
                <div class="space-y-1 min-w-0">
                  <span class="block font-bold text-slate-900 text-xs font-mono truncate">{{ trx.invoice_number }}</span>
                  <div class="flex items-center gap-1.5 text-[10px] text-slate-400 font-semibold">
                    <span>Kasir: {{ trx.user?.name || '-' }}</span>
                    <span>•</span>
                    <span>Waktu: {{ formatTime(trx.created_at) }}</span>
                  </div>
                </div>

                <div class="flex items-center gap-3 shrink-0">
                  <span class="px-2 py-0.5 rounded-full border text-[9px] font-extrabold uppercase" :class="getPaymentBadgeClass(trx.payment_method)">
                    {{ getPaymentLabel(trx.payment_method) }}
                  </span>
                  <span class="font-extrabold text-slate-900 text-xs font-mono">Rp {{ money(Number(trx.total)) }}</span>
                </div>
              </div>

              <!-- Empty recent transactions -->
              <div v-if="!summary.recent_transactions.length" class="text-center py-6 text-slate-400 text-xs font-medium">
                Belum ada transaksi terekam saat ini.
              </div>
            </div>
          </div>

        </div>

        <!-- Column 3: Payment Breakdown -->
        <div class="space-y-6">
          <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-4">
            <div>
              <h3 class="font-extrabold text-slate-900 text-sm">Metode Pembayaran Hari Ini</h3>
              <p class="text-[11px] text-slate-500 font-medium">Breakdown omzet penjualan per metode bayar</p>
            </div>

            <div class="space-y-4">
              <div v-for="pm in summary.payment_methods" :key="pm.method" class="space-y-1.5">
                <div class="flex items-center justify-between text-xs font-semibold">
                  <span class="px-2 py-0.5 rounded-full border text-[9px] font-extrabold uppercase" :class="getPaymentBadgeClass(pm.method)">
                    {{ getPaymentLabel(pm.method) }}
                  </span>
                  <div class="text-right shrink-0">
                    <span class="font-bold text-slate-900 font-mono">Rp {{ money(pm.amount) }}</span>
                    <span class="text-slate-400 font-medium font-mono text-[10px] ml-2">({{ pm.count }}x)</span>
                  </div>
                </div>

                <!-- Progress visual bar -->
                <div class="w-full h-2 bg-slate-100 rounded-full overflow-hidden">
                  <div 
                    class="h-full rounded-full transition-all duration-500" 
                    :class="pm.method === 'cash' ? 'bg-emerald-500' : pm.method === 'qris' ? 'bg-purple-500' : pm.method === 'card' ? 'bg-blue-500' : 'bg-amber-500'"
                    :style="{ width: `${(pm.amount / maxPaymentVolume) * 100}%` }"
                  ></div>
                </div>
              </div>

              <!-- Empty payments -->
              <div v-if="!summary.payment_methods.length" class="text-center py-6 text-slate-400 text-xs font-medium">
                Belum ada data pembayaran masuk hari ini.
              </div>
            </div>
          </div>
        </div>

      </div>

    </div>
  </AppShell>
</template>

<style scoped>
.scrollbar-none::-webkit-scrollbar {
  display: none;
}
.scrollbar-none {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
</style>
