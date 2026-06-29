<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { useAuthStore } from '../stores/auth'
import AppShell from '../components/AppShell.vue'
import api from '../services/api'

const auth = useAuthStore()

// Helper: get today in YYYY-MM-DD
function today() { return new Date().toISOString().substring(0, 10) }
// Helper: N days ago
function daysAgo(n: number) {
  const d = new Date(); d.setDate(d.getDate() - n); return d.toISOString().substring(0, 10)
}

// Filters — admin defaults to last 30 days; cashier defaults to today
const fromDate = ref('')
const toDate = ref('')
const typeFilter = ref('')
const cashierFilter = ref('')
const storeFilter = ref('')
const sessionFilter = ref('')

// Data lists
const mutations = ref<any[]>([])
const cashiers = ref<any[]>([])
const stores = ref<any[]>([])
const sessions = ref<any[]>([])
const loading = ref(true)
const error = ref('')

// Info banner
const sessionInfoMsg = ref('')

const filteredCashiers = computed(() => {
  const activeStoreId = auth.isOwner
    ? (storeFilter.value ? Number(storeFilter.value) : null)
    : auth.user?.store_id
  return cashiers.value.filter((u: any) => !activeStoreId || u.store_id === activeStoreId || u.role === 'owner')
})

const money = (n: number) =>
  new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Math.round(n))

const formatDateTime = (dateStr: string) => {
  const d = new Date(dateStr)
  return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' }) + ' ' + d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
}

// Fetch daily cashier cash mutations
async function fetchMutations() {
  loading.value = true
  error.value = ''
  try {
    const params: any = {
      from: fromDate.value,
      to: toDate.value,
      type: typeFilter.value || undefined,
      user_id: cashierFilter.value || undefined,
      store_id: storeFilter.value || undefined,
      cashier_session_id: sessionFilter.value || undefined,
      per_page: 200,
    }

    const { data } = await api.get('/cashier-mutations', { params })
    mutations.value = data.data || []
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal memuat laporan mutasi kas.'
  } finally {
    loading.value = false
  }
}

// Load filters data (stores, users) if user has role
async function loadFilters() {
  if (auth.isAdminOrOwner) {
    try {
      const usersRes = await api.get('/users', { params: { per_page: 200 } })
      cashiers.value = (usersRes.data.data || usersRes.data || []).filter((u: any) => u.role === 'cashier' || u.role === 'admin')
      
      if (auth.isOwner) {
        const storesRes = await api.get('/tenant/stores')
        stores.value = storesRes.data || []
      }
    } catch (e) {
      console.warn('Gagal memuat opsi filter', e)
    }
  }
}

// Summary Aggregates
const totalTambah = computed(() => {
  return mutations.value
    .filter(m => m.type === 'tambah')
    .reduce((sum, m) => sum + Number(m.amount), 0)
})

const totalKurang = computed(() => {
  return mutations.value
    .filter(m => m.type === 'kurang')
    .reduce((sum, m) => sum + Number(m.amount), 0)
})

const totalPengeluaran = computed(() => {
  return mutations.value
    .filter(m => m.type === 'pengeluaran')
    .reduce((sum, m) => sum + Number(m.amount), 0)
})

const totalKoreksiIn = computed(() => {
  return mutations.value
    .filter(m => m.type === 'koreksi' && m.direction === 'in')
    .reduce((sum, m) => sum + Number(m.amount), 0)
})

const totalKoreksiOut = computed(() => {
  return mutations.value
    .filter(m => m.type === 'koreksi' && m.direction === 'out')
    .reduce((sum, m) => sum + Number(m.amount), 0)
})

const totalModalAwal = computed(() => {
  return mutations.value
    .filter(m => m.type === 'modal_awal')
    .reduce((sum, m) => sum + Number(m.amount), 0)
})

const totalPenjualanTunai = computed(() => {
  return mutations.value
    .filter(m => m.type === 'penjualan_tunai')
    .reduce((sum, m) => sum + Number(m.amount), 0)
})

const totalSetorKas = computed(() => {
  return mutations.value
    .filter(m => m.type === 'setor_kas')
    .reduce((sum, m) => sum + Number(m.amount), 0)
})

const netMutation = computed(() => {
  // net = IN - OUT
  const totalIn = mutations.value
    .filter(m => m.direction === 'in')
    .reduce((sum, m) => sum + Number(m.amount), 0)
  const totalOut = mutations.value
    .filter(m => m.direction === 'out')
    .reduce((sum, m) => sum + Number(m.amount), 0)
  return totalIn - totalOut
})

const getTypeLabel = (t: string, dir: string) => {
  switch (t) {
    case 'tambah': return 'Tambah Kas'
    case 'kurang': return 'Kurang Kas'
    case 'koreksi': return `Koreksi (${dir === 'in' ? 'Tambah' : 'Kurang'})`
    case 'pengeluaran': return 'Operasional'
    case 'penjualan_tunai': return 'Penjualan Tunai'
    case 'modal_awal': return 'Modal Awal'
    case 'setor_kas': return 'Setoran ke Kas Besar'
    default: return t
  }
}

const getTypeBadgeClass = (t: string, dir: string) => {
  switch (t) {
    case 'tambah': return 'bg-emerald-50 text-emerald-700 border-emerald-200'
    case 'kurang': return 'bg-rose-50 text-rose-700 border-rose-200'
    case 'koreksi': 
      return dir === 'in' 
        ? 'bg-blue-50 text-blue-700 border-blue-200'
        : 'bg-amber-50 text-amber-700 border-amber-200'
    case 'pengeluaran': return 'bg-slate-100 text-slate-700 border-slate-300'
    case 'penjualan_tunai': return 'bg-cyan-50 text-cyan-700 border-cyan-200'
    case 'modal_awal': return 'bg-indigo-50 text-indigo-700 border-indigo-200'
    case 'setor_kas': return 'bg-purple-50 text-purple-700 border-purple-200'
    default: return 'bg-slate-50 text-slate-600 border-slate-200'
  }
}

async function fetchSessions() {
  try {
    const params: any = {}
    if (fromDate.value) params.from = fromDate.value
    if (toDate.value)   params.to   = toDate.value
    if (cashierFilter.value) params.user_id   = cashierFilter.value
    if (storeFilter.value)   params.store_id  = storeFilter.value
    const { data } = await api.get('/cashier-sessions', { params })
    sessions.value = data || []
  } catch (e) {
    console.warn('Gagal memuat sesi kasir', e)
  }
}

async function onMainFiltersChange() {
  sessionFilter.value = ''
  sessionInfoMsg.value = ''

  // Reset cashier filter if it does not belong to the selected store anymore
  if (cashierFilter.value) {
    const isMatched = filteredCashiers.value.some(c => c.id === Number(cashierFilter.value))
    if (!isMatched) {
      cashierFilter.value = ''
    }
  }

  await fetchSessions()
  await fetchMutations()
}

// Called when admin changes the cashier filter
async function onCashierChange() {
  sessionFilter.value = ''
  sessionInfoMsg.value = ''
  await fetchSessions()

  if (cashierFilter.value && sessions.value.length > 0) {
    // Auto-select the AKTIF session for this cashier if one exists
    const activeSession = sessions.value.find((s: any) => s.status === 'AKTIF')
    if (activeSession) {
      sessionFilter.value = activeSession.id
      sessionInfoMsg.value = ''
    } else {
      // No active session — aggregating all closed sessions in range
      const count = sessions.value.length
      sessionInfoMsg.value = `ℹ️ Tidak ada sesi aktif. Menampilkan data gabungan dari ${count} sesi (${sessions.value.map((s:any) => s.session_number).join(', ')}).`
    }
  } else if (!cashierFilter.value) {
    // All cashiers — no auto-select
    const count = sessions.value.length
    if (count > 0) {
      sessionInfoMsg.value = `ℹ️ Menampilkan data gabungan ${count} sesi dari semua kasir. Pilih kasir atau sesi tertentu untuk detail akurat.`
    }
  }

  await fetchMutations()
}

function printReport() {
  window.print()
}

function exportToExcel() {
  let html = `<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">`;
  html += `<head><meta charset="utf-8"/><style>table { border-collapse: collapse; } th, td { border: 1px solid #ccc; padding: 6px; font-family: sans-serif; font-size: 12px; } th { background-color: #f1f5f9; font-weight: bold; }</style></head><body>`;
  html += `<h2>LAPORAN MUTASI KAS LACI KASIR</h2>`;
  html += `<p>Periode: ${fromDate.value} s/d ${toDate.value}</p>`;
  html += `<p>Jumlah Data: ${mutations.value.length}</p>`;
  html += `<table border="1">`;
  html += `<thead><tr>`;
  html += `<th>Tanggal & Jam</th><th>No Mutasi</th><th>No Ref</th><th>Tipe</th><th>Nominal</th><th>Keterangan</th><th>Kasir</th>`;
  html += `</tr></thead><tbody>`;
  
  mutations.value.forEach(m => {
    const typeLabel = getTypeLabel(m.type, m.direction);
    const amountVal = (m.direction === 'in' ? '' : '-') + m.amount;
    const formattedDate = new Date(m.created_at).toLocaleString('id-ID');
    html += `<tr>`;
    html += `<td>${formattedDate}</td>`;
    html += `<td>${m.mutation_number}</td>`;
    html += `<td>${m.reference_number || '-'}</td>`;
    html += `<td>${typeLabel}</td>`;
    html += `<td style="text-align: right;">${amountVal}</td>`;
    html += `<td>${m.notes || ''}</td>`;
    html += `<td>${m.user?.name || '-'}</td>`;
    html += `</tr>`;
  });
  
  html += `</tbody></table></body></html>`;

  const blob = new Blob([html], { type: 'application/vnd.ms-excel' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `laporan_mutasi_kas_laci_${fromDate.value}_to_${toDate.value}.xls`;
  link.click();
  URL.revokeObjectURL(url);
}

onMounted(async () => {
  // For cashier: auto-select active session so their report is pre-filtered
  // For admin/owner: skip – they should see all cashiers' sessions
  if (auth.isCashier) {
    try {
      const activeRes = await api.get('/cashier-sessions/active')
      if (activeRes.data && activeRes.data.id) {
        sessionFilter.value = activeRes.data.id
        fromDate.value = today()
        toDate.value   = today()
      } else {
        fromDate.value = today()
        toDate.value   = today()
      }
    } catch (e) {
      fromDate.value = today()
      toDate.value   = today()
      console.warn('Gagal memuat sesi aktif', e)
    }
  } else {
    // Admin/owner: default to last 30 days to show all cashier history
    fromDate.value = daysAgo(30)
    toDate.value   = today()
  }
  await fetchSessions()
  // Admin with no cashier selected — show info about session count
  if (auth.isAdminOrOwner && !cashierFilter.value && sessions.value.length > 0) {
    sessionInfoMsg.value = `ℹ️ Menampilkan data gabungan ${sessions.value.length} sesi dari semua kasir. Pilih kasir tertentu untuk detail per sesi.`
  }
  await fetchMutations()
  loadFilters()
})
</script>

<template>
  <AppShell>
    <div class="space-y-6">
      
      <!-- Top Header Row -->
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="text-2xl font-bold tracking-tight text-slate-900">Laporan Mutasi Kas Laci</h2>
          <p class="text-xs text-slate-500 font-medium">Rekap log mutasi kas masuk, keluar, penyesuaian, dan operasional harian</p>
        </div>
        
        <div class="flex gap-2 print:hidden">
          <button
            type="button"
            class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 flex items-center gap-1.5 shadow-sm transition-colors"
            @click="exportToExcel"
          >
            📊 Export Excel
          </button>
          <button
            type="button"
            class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 flex items-center gap-1.5 shadow-sm transition-colors"
            @click="printReport"
          >
            🖨️ Cetak Laporan
          </button>
        </div>
      </div>

      <!-- Filters Panel (Hidden when printing) -->
      <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-4 print:hidden">
        <h3 class="font-extrabold text-slate-800 text-xs uppercase tracking-wider">Filter Laporan</h3>
        
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
          <!-- Date range -->
          <div class="space-y-1">
            <label class="block text-[10px] font-bold text-slate-500 uppercase">Dari Tanggal</label>
            <input
              v-model="fromDate"
              type="date"
              class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-800 focus:outline-none"
              @change="onMainFiltersChange"
            />
          </div>
          <div class="space-y-1">
            <label class="block text-[10px] font-bold text-slate-500 uppercase">Hingga Tanggal</label>
            <input
              v-model="toDate"
              type="date"
              class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-800 focus:outline-none"
              @change="onMainFiltersChange"
            />
          </div>

          <!-- Type filter -->
          <div class="space-y-1">
            <label class="block text-[10px] font-bold text-slate-500 uppercase">Tipe Mutasi</label>
            <select
              v-model="typeFilter"
              class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-800 focus:outline-none bg-white"
              @change="fetchMutations"
            >
              <option value="">Semua Tipe</option>
              <option value="tambah">Tambah Kas</option>
              <option value="kurang">Kurang Kas</option>
              <option value="koreksi">Koreksi Kas</option>
              <option value="pengeluaran">Pengeluaran Operasional</option>
              <option value="penjualan_tunai">Penjualan Tunai</option>
              <option value="modal_awal">Modal Awal</option>
              <option value="setor_kas">Setoran ke Kas Besar</option>
            </select>
          </div>

          <!-- Sesi Kasir filter -->
          <div class="space-y-1">
            <label class="block text-[10px] font-bold text-slate-500 uppercase">Sesi Kasir</label>
            <select
              v-model="sessionFilter"
              class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-800 focus:outline-none bg-white"
              @change="fetchMutations"
            >
              <option value="">Semua Sesi</option>
              <option v-for="s in sessions" :key="s.id" :value="s.id">
                {{ s.session_number }} — {{ s.user?.name || '?' }} ({{ s.shift }}) {{ s.status === 'AKTIF' ? '🟢' : '⚫' }}
              </option>
            </select>
          </div>

          <!-- Cashier filter (Only shown to admin/owners) -->
          <div v-if="auth.isAdminOrOwner" class="space-y-1">
            <label class="block text-[10px] font-bold text-slate-500 uppercase">Kasir</label>
            <select
              v-model="cashierFilter"
              class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-800 focus:outline-none bg-white"
              @change="onCashierChange"
            >
              <option value="">Semua Kasir</option>
              <option v-for="u in filteredCashiers" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
          </div>

          <!-- Store filter (Only shown to owners with multiple stores) -->
          <div v-if="auth.isOwner && stores.length > 1" class="space-y-1">
            <label class="block text-[10px] font-bold text-slate-500 uppercase">Cabang Toko</label>
            <select
              v-model="storeFilter"
              class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-800 focus:outline-none bg-white"
              @change="onMainFiltersChange"
            >
              <option value="">Semua Toko</option>
              <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Info banner: warn when aggregating multiple sessions -->
      <div
        v-if="sessionInfoMsg && auth.isAdminOrOwner"
        class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs font-medium text-amber-800 print:hidden"
      >
        <span class="text-base shrink-0">⚠️</span>
        <span>{{ sessionInfoMsg }}</span>
        <button
          v-if="sessions.length > 0 && !sessionFilter"
          @click="sessionFilter = sessions[0]?.id; fetchMutations()"
          class="ml-auto shrink-0 rounded-lg bg-amber-600 px-2.5 py-1 text-white text-[10px] font-bold hover:bg-amber-700 transition-colors"
        >
          Tampilkan Sesi Terbaru
        </button>
      </div>

      <!-- Financial Mutations Summary Grid -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <!-- 1. Total Modal Awal -->
        <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
          <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Total Modal Awal</span>
          <span class="block text-sm font-extrabold text-indigo-600 font-mono mt-1">Rp {{ money(totalModalAwal) }}</span>
        </div>
        <!-- 2. Total Penjualan Tunai -->
        <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
          <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Total Penjualan Tunai</span>
          <span class="block text-sm font-extrabold text-cyan-600 font-mono mt-1">Rp {{ money(totalPenjualanTunai) }}</span>
        </div>
        <!-- 3. Total Tambah -->
        <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
          <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Total Tambah Kas</span>
          <span class="block text-sm font-extrabold text-emerald-600 font-mono mt-1">Rp {{ money(totalTambah) }}</span>
        </div>
        <!-- 4. Total Kurang -->
        <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
          <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Total Kurang Kas</span>
          <span class="block text-sm font-extrabold text-rose-600 font-mono mt-1">Rp {{ money(totalKurang) }}</span>
        </div>
        <!-- 5. Total Operasional -->
        <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
          <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Total Operasional</span>
          <span class="block text-sm font-extrabold text-slate-700 font-mono mt-1">Rp {{ money(totalPengeluaran) }}</span>
        </div>
        <!-- 6. Total Setoran -->
        <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
          <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Total Setoran Kas</span>
          <span class="block text-sm font-extrabold text-purple-600 font-mono mt-1">Rp {{ money(totalSetorKas) }}</span>
        </div>
        <!-- 7. Total Koreksi (Net) -->
        <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
          <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Total Koreksi Kas</span>
          <div class="flex items-center gap-1.5 mt-1 text-[11px] font-mono font-bold">
            <span class="text-blue-600">+{{ money(totalKoreksiIn) }}</span>
            <span class="text-slate-300">|</span>
            <span class="text-amber-600">-{{ money(totalKoreksiOut) }}</span>
          </div>
        </div>
        <!-- 8. Net Mutation -->
        <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
          <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Net Arus Kas (Mutasi)</span>
          <span class="block text-sm font-extrabold font-mono mt-1" :class="netMutation >= 0 ? 'text-blue-600' : 'text-rose-600'">
            {{ netMutation >= 0 ? '+' : '' }}Rp {{ money(netMutation) }}
          </span>
        </div>
      </div>

      <!-- Mutations List Table Card -->
      <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-4">
        <div class="flex items-center justify-between">
          <h3 class="font-extrabold text-slate-900 text-sm">Daftar Log Arus Kas</h3>
          <span class="text-[11px] font-mono text-slate-400 font-semibold">{{ mutations.length }} data terekam</span>
        </div>

        <div v-if="loading" class="flex items-center justify-center py-20">
          <div class="h-8 w-8 animate-spin rounded-full border-4 border-slate-900 border-t-transparent"></div>
        </div>

        <div v-else-if="!mutations.length" class="text-center py-16 text-slate-400 text-xs font-medium">
          Tidak ada data mutasi kas laci kasir untuk kriteria filter yang dipilih.
        </div>

        <div v-else class="overflow-x-auto">
          <table class="w-full text-left text-xs border-collapse">
            <thead>
              <tr class="border-b border-slate-200 text-slate-400 font-bold uppercase tracking-wide">
                <th class="py-2.5 px-3">Tanggal & Jam</th>
                <th class="py-2.5 px-3">No Mutasi</th>
                <th class="py-2.5 px-3">No Ref</th>
                <th class="py-2.5 px-3">Tipe</th>
                <th class="py-2.5 px-3 text-right">Nominal</th>
                <th class="py-2.5 px-3">Keterangan</th>
                <th class="py-2.5 px-3">Kasir</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
              <tr v-for="mut in mutations" :key="mut.id" class="hover:bg-slate-50/50">
                <td class="py-3 px-3 whitespace-nowrap text-slate-500 font-semibold">
                  {{ formatDateTime(mut.created_at) }}
                </td>
                <td class="py-3 px-3 font-mono font-bold text-slate-900 whitespace-nowrap">
                  {{ mut.mutation_number }}
                </td>
                <td class="py-3 px-3 font-mono text-slate-400 whitespace-nowrap">
                  {{ mut.reference_number || '-' }}
                </td>
                <td class="py-3 px-3 whitespace-nowrap">
                  <span class="px-2 py-0.5 rounded-full border text-[9px] font-extrabold uppercase" :class="getTypeBadgeClass(mut.type, mut.direction)">
                    {{ getTypeLabel(mut.type, mut.direction) }}
                  </span>
                </td>
                <td class="py-3 px-3 text-right font-mono font-bold whitespace-nowrap" :class="mut.direction === 'in' ? 'text-emerald-600' : 'text-red-600'">
                  {{ mut.direction === 'in' ? '+' : '-' }} Rp {{ money(mut.amount) }}
                </td>
                <td class="py-3 px-3 text-slate-600 max-w-[280px] break-words">
                  {{ mut.notes }}
                </td>
                <td class="py-3 px-3 text-slate-900 font-bold whitespace-nowrap">
                  👤 {{ mut.user?.name || '-' }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Print-only Report Layout -->
    <Teleport to="body">
      <div id="print-report-container-laci" class="font-sans text-xs text-black p-6">
        <div class="text-center space-y-1 mb-4">
          <h2 class="text-lg font-bold uppercase">LAPORAN MUTASI KAS LACI KASIR</h2>
          <p class="text-[10px]">Periode: {{ fromDate }} s/d {{ toDate }}</p>
          <p class="text-[10px] italic">Dicetak oleh: {{ auth.user?.name || '-' }} pada {{ new Date().toLocaleString('id-ID') }} WIB</p>
        </div>

        <hr class="border-black my-3" />

        <!-- Summary -->
        <div class="print-grid-4 mb-4 font-mono text-[9px]">
          <div>Modal Awal: <br/><strong>Rp {{ money(totalModalAwal) }}</strong></div>
          <div>Penjualan Tunai: <br/><strong>Rp {{ money(totalPenjualanTunai) }}</strong></div>
          <div>Tambah Kas: <br/><strong>Rp {{ money(totalTambah) }}</strong></div>
          <div>Kurang Kas: <br/><strong>Rp {{ money(totalKurang) }}</strong></div>
          <div>Operasional: <br/><strong>Rp {{ money(totalPengeluaran) }}</strong></div>
          <div>Setoran Kas: <br/><strong>Rp {{ money(totalSetorKas) }}</strong></div>
          <div>Koreksi: <br/><strong>+{{ money(totalKoreksiIn) }}/-{{ money(totalKoreksiOut) }}</strong></div>
          <div>Net Arus Kas: <br/><strong>Rp {{ money(netMutation) }}</strong></div>
        </div>

        <!-- Table -->
        <table class="w-full text-[10px] border-collapse">
          <thead>
            <tr class="border-b border-black text-left font-bold uppercase">
              <th class="py-1">Tanggal & Jam</th>
              <th class="py-1">No Mutasi</th>
              <th class="py-1">Tipe</th>
              <th class="py-1 text-right">Nominal</th>
              <th class="py-1">Keterangan</th>
              <th class="py-1">Kasir</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-300">
            <tr v-for="mut in mutations" :key="'print-' + mut.id">
              <td class="py-1.5 whitespace-nowrap">{{ formatDateTime(mut.created_at) }}</td>
              <td class="py-1.5 font-mono whitespace-nowrap">{{ mut.mutation_number }}</td>
              <td class="py-1.5 uppercase">{{ getTypeLabel(mut.type, mut.direction) }}</td>
              <td class="py-1.5 text-right font-mono font-bold">{{ mut.direction === 'in' ? '+' : '-' }} Rp {{ money(mut.amount) }}</td>
              <td class="py-1.5">{{ mut.notes }}</td>
              <td class="py-1.5 font-bold">{{ mut.user?.name || '-' }}</td>
            </tr>
          </tbody>
        </table>

        <hr class="border-black my-6" />
        <div class="print-grid-2 text-center text-[10px] pt-4">
          <div>
            <p>Dibuat Oleh,</p>
            <br/><br/><br/>
            <p>( __________________ )</p>
            <p class="font-bold">{{ auth.user?.name || '-' }}</p>
          </div>
          <div>
            <p>Diverifikasi Oleh,</p>
            <br/><br/><br/>
            <p>( __________________ )</p>
            <p class="font-bold">Supervisor / Owner</p>
          </div>
        </div>
      </div>
    </Teleport>
  </AppShell>
</template>

<style scoped>
/* Scoped styles can go here if needed */
</style>

<style>
/* Default screen display for print block: hidden */
#print-report-container-laci {
  display: none;
}

@media print {
  /* Hide the entire App shell layout */
  #app {
    display: none !important;
  }
  
  /* Show only our print layout container */
  body {
    background-color: white !important;
    color: black !important;
    font-family: monospace !important;
  }
  
  #print-report-container-laci {
    display: block !important;
    visibility: visible !important;
    position: absolute !important;
    left: 0 !important;
    top: 0 !important;
    width: 100% !important;
  }
  
  #print-report-container-laci * {
    visibility: visible !important;
  }
  
  /* Simple grid simulation for print since Tailwind grid might get hidden */
  .print-grid-4 {
    display: flex !important;
    flex-wrap: wrap !important;
    border: 1px solid black !important;
    padding: 10px !important;
  }
  .print-grid-4 > div {
    width: 25% !important;
    min-width: 120px !important;
    margin-bottom: 8px !important;
  }
  .print-grid-2 {
    display: flex !important;
    justify-content: space-between !important;
    margin-top: 30px !important;
  }
  .print-grid-2 > div {
    width: 45% !important;
    text-align: center !important;
  }
  
  /* Table formatting */
  table {
    width: 100% !important;
    border-collapse: collapse !important;
    margin-top: 15px !important;
  }
  th, td {
    border-bottom: 1px solid #000 !important;
    padding: 6px 4px !important;
    text-align: left !important;
  }
  th {
    font-weight: bold !important;
    text-transform: uppercase !important;
  }
  .text-right {
    text-align: right !important;
  }
}
</style>
