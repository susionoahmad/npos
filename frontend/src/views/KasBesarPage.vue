<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import AppShell from '../components/AppShell.vue'
import api from '../services/api'

// ─── State ────────────────────────────────────────────────────────────────────
const mutations      = ref<any[]>([])
const loading        = ref(false)
const submitting     = ref(false)
const errorMsg       = ref('')
const successMsg     = ref('')
const currentPage    = ref(1)
const lastPage       = ref(1)
const balance        = ref(0)
const totalIn        = ref(0)
const totalOut       = ref(0)
const showForm       = ref(false)

// Helper functions for date defaults
function getFirstDayOfMonth() {
  const d = new Date()
  return new Date(d.getFullYear(), d.getMonth(), 1).toISOString().substring(0, 10)
}

function getToday() {
  return new Date().toISOString().substring(0, 10)
}

// Filters
const filterFrom     = ref(getFirstDayOfMonth())
const filterTo       = ref(getToday())
const filterType     = ref('')
const filterDir      = ref('')

// Form
const form = ref({
  type: '',
  direction: 'in',
  amount: '',
  source: '',
  destination: '',
  notes: '',
})

// ─── Constants ────────────────────────────────────────────────────────────────
const TYPE_OPTIONS = [
  { value: 'modal_awal_toko',         label: 'Modal Awal Toko',           dir: 'in'  },
  { value: 'tambah_modal',            label: 'Tambah Modal Toko',         dir: 'in'  },
  { value: 'modal_awal_kasir',        label: 'Penyediaan Modal Kasir',    dir: 'out' },
  { value: 'setoran_kasir',           label: 'Setoran Kasir',             dir: 'in'  },
  { value: 'pengeluaran_operasional', label: 'Pengeluaran Operasional',   dir: 'out' },
  { value: 'pembelian_barang',        label: 'Pembelian Barang',          dir: 'out' },
  { value: 'bayar_supplier',          label: 'Bayar Supplier',            dir: 'out' },
  { value: 'transfer_bank',           label: 'Transfer Bank',             dir: 'out' },
  { value: 'penyetoran_bank',         label: 'Penyetoran Bank',           dir: 'out' },
  { value: 'penarikan_operasional',   label: 'Penarikan Operasional',     dir: 'out' },
  { value: 'koreksi',                 label: 'Koreksi Saldo',             dir: 'in'  },
]

const TYPE_LABEL: Record<string, string> = Object.fromEntries(TYPE_OPTIONS.map(t => [t.value, t.label]))

// ─── Computed ─────────────────────────────────────────────────────────────────
const directionAuto = computed(() => {
  const found = TYPE_OPTIONS.find(t => t.value === form.value.type)
  return found ? found.dir : form.value.direction
})

// ─── Methods ──────────────────────────────────────────────────────────────────
async function loadMutations(page = 1) {
  loading.value = true
  errorMsg.value = ''
  try {
    const params: any = { page }
    if (filterFrom.value) params.from = filterFrom.value
    if (filterTo.value)   params.to   = filterTo.value
    if (filterType.value) params.type = filterType.value
    if (filterDir.value)  params.direction = filterDir.value

    const { data } = await api.get('/kas-besar', { params })
    mutations.value = data.mutations?.data ?? []
    currentPage.value = data.mutations?.current_page ?? 1
    lastPage.value    = data.mutations?.last_page ?? 1
    balance.value     = data.balance ?? 0
    totalIn.value     = data.total_in ?? 0
    totalOut.value    = data.total_out ?? 0
  } catch (e: any) {
    errorMsg.value = e?.response?.data?.message ?? 'Gagal memuat data Kas Besar.'
  } finally {
    loading.value = false
  }
}

function onTypeChange() {
  const found = TYPE_OPTIONS.find(t => t.value === form.value.type)
  if (found) form.value.direction = found.dir
}

function resetFilters() {
  filterFrom.value = getFirstDayOfMonth()
  filterTo.value = getToday()
  filterType.value = ''
  filterDir.value = ''
  loadMutations(1)
}

function resetForm() {
  form.value = { type: '', direction: 'in', amount: '', source: '', destination: '', notes: '' }
  errorMsg.value = ''
  successMsg.value = ''
}

function openForm() {
  resetForm()
  showForm.value = true
}

async function submitForm() {
  if (!form.value.type || !form.value.amount || !form.value.notes) {
    errorMsg.value = 'Jenis transaksi, nominal, dan keterangan wajib diisi.'
    return
  }
  submitting.value = true
  errorMsg.value   = ''
  successMsg.value = ''
  try {
    await api.post('/kas-besar', {
      type:        form.value.type,
      direction:   directionAuto.value,
      amount:      Number(form.value.amount),
      source:      form.value.source || null,
      destination: form.value.destination || null,
      notes:       form.value.notes,
    })
    successMsg.value = 'Transaksi Kas Besar berhasil dicatat.'
    showForm.value   = false
    await loadMutations()
  } catch (e: any) {
    const errs = e?.response?.data?.errors
    if (errs) {
      errorMsg.value = Object.values(errs).flat().join(', ')
    } else {
      errorMsg.value = e?.response?.data?.message ?? 'Gagal menyimpan transaksi.'
    }
  } finally {
    submitting.value = false
  }
}

function formatCurrency(val: number) {
  return 'Rp ' + Number(val ?? 0).toLocaleString('id-ID')
}

function formatDate(val: string) {
  if (!val) return '-'
  return new Date(val).toLocaleString('id-ID', {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}

function getTypeLabel(type: string) {
  return TYPE_LABEL[type] ?? type
}

function printReceipt(m: any) {
  const win = window.open('', '_blank', 'width=350,height=500')
  if (!win) return
  win.document.write(`
    <html><head><title>Bukti Kas Besar</title>
    <style>body{font-family:monospace;font-size:12px;margin:16px;}h2{text-align:center;font-size:14px;}hr{border:1px dashed #000;}table{width:100%;}td{padding:2px 4px;vertical-align:top;}td:last-child{text-align:right;}@media print{button{display:none;}}</style>
    </head><body>
    <h2>BUKTI KAS BESAR</h2><hr/>
    <table>
      <tr><td>No. Bukti</td><td>${m.reference_number}</td></tr>
      <tr><td>Tanggal</td><td>${formatDate(m.created_at)}</td></tr>
      <tr><td>Jenis</td><td>${getTypeLabel(m.type)}</td></tr>
      <tr><td>Arah</td><td>${m.direction === 'in' ? '⬆ Masuk' : '⬇ Keluar'}</td></tr>
      <tr><td>Nominal</td><td><b>${formatCurrency(m.amount)}</b></td></tr>
      <tr><td>Sumber</td><td>${m.source ?? '-'}</td></tr>
      <tr><td>Tujuan</td><td>${m.destination ?? '-'}</td></tr>
      <tr><td>Keterangan</td><td>${m.notes ?? '-'}</td></tr>
      <tr><td>Dicatat oleh</td><td>${m.user?.name ?? '-'}</td></tr>
    </table>
    <hr/><p style="text-align:center;font-size:10px;">Dicetak: ${new Date().toLocaleString('id-ID')}</p>
    <button onclick="window.print()">🖨 Cetak</button>
    </body></html>
  `)
  win.document.close()
}

onMounted(() => loadMutations())
</script>

<template>
  <AppShell>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-slate-800">Kas Besar</h1>
        <p class="text-sm text-slate-500 mt-0.5">Manajemen kas utama toko &amp; arus dana</p>
      </div>
      <button
        @click="openForm"
        class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow hover:bg-emerald-700 transition-colors"
      >
        <span class="text-lg leading-none">＋</span> Catat Transaksi
      </button>
    </div>

    <!-- Balance Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <!-- Saldo Kas Besar -->
      <div class="rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 p-5 text-white shadow-lg">
        <p class="text-xs font-semibold uppercase tracking-widest opacity-75">Saldo Kas Besar</p>
        <p class="mt-2 text-3xl font-extrabold">{{ formatCurrency(balance) }}</p>
        <p class="text-xs opacity-60 mt-1">Total akumulatif</p>
      </div>
      <!-- Total Masuk -->
      <div class="rounded-2xl bg-white border border-slate-200 p-5 shadow-sm">
        <div class="flex items-center justify-between">
          <p class="text-sm font-medium text-slate-500">Total Masuk</p>
          <span class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-emerald-50 text-emerald-600 text-lg">⬆</span>
        </div>
        <p class="mt-1 text-2xl font-bold text-emerald-600">{{ formatCurrency(totalIn) }}</p>
      </div>
      <!-- Total Keluar -->
      <div class="rounded-2xl bg-white border border-slate-200 p-5 shadow-sm">
        <div class="flex items-center justify-between">
          <p class="text-sm font-medium text-slate-500">Total Keluar</p>
          <span class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-red-50 text-red-500 text-lg">⬇</span>
        </div>
        <p class="mt-1 text-2xl font-bold text-red-500">{{ formatCurrency(totalOut) }}</p>
      </div>
    </div>

    <!-- Filter Bar -->
    <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div>
          <label class="block text-xs font-medium text-slate-500 mb-1">Dari Tanggal</label>
          <input type="date" v-model="filterFrom" @change="loadMutations(1)" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500" />
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-500 mb-1">Sampai</label>
          <input type="date" v-model="filterTo" @change="loadMutations(1)" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500" />
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-500 mb-1">Jenis</label>
          <select v-model="filterType" @change="loadMutations(1)" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
            <option value="">Semua Jenis</option>
            <option v-for="t in TYPE_OPTIONS" :key="t.value" :value="t.value">{{ t.label }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-500 mb-1">Arah</label>
          <select v-model="filterDir" @change="loadMutations(1)" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
            <option value="">Semua</option>
            <option value="in">Masuk</option>
            <option value="out">Keluar</option>
          </select>
        </div>
      </div>
      <div class="mt-3 flex gap-2">
        <button @click="loadMutations(1)" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 transition-colors">
          Filter
        </button>
        <button @click="resetFilters" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50 transition-colors">
          Reset
        </button>
      </div>
    </div>

    <!-- Feedback -->
    <div v-if="successMsg" class="flex items-center gap-2 rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">
      <span>✅</span> {{ successMsg }}
    </div>
    <div v-if="errorMsg && !showForm" class="flex items-center gap-2 rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
      <span>⚠️</span> {{ errorMsg }}
    </div>

    <!-- Transaction Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
      <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
        <h2 class="font-semibold text-slate-700">Riwayat Transaksi Kas Besar</h2>
        <span v-if="loading" class="text-xs text-slate-400 animate-pulse">Memuat...</span>
      </div>

      <div v-if="loading && mutations.length === 0" class="py-12 text-center text-slate-400">
        <div class="text-4xl mb-3">⏳</div>
        <p class="text-sm">Memuat data...</p>
      </div>
      <div v-else-if="mutations.length === 0" class="py-12 text-center text-slate-400">
        <div class="text-4xl mb-3">🏦</div>
        <p class="text-sm font-medium">Belum ada transaksi Kas Besar</p>
        <p class="text-xs mt-1">Klik "Catat Transaksi" untuk memulai</p>
      </div>
      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-xs uppercase text-slate-500 tracking-wide">
            <tr>
              <th class="px-4 py-3 text-left">Tanggal</th>
              <th class="px-4 py-3 text-left">No. Bukti</th>
              <th class="px-4 py-3 text-left">Jenis</th>
              <th class="px-4 py-3 text-left">Sumber / Tujuan</th>
              <th class="px-4 py-3 text-right">Nominal</th>
              <th class="px-4 py-3 text-center">Arah</th>
              <th class="px-4 py-3 text-left">Keterangan</th>
              <th class="px-4 py-3 text-left">Dicatat</th>
              <th class="px-4 py-3 text-center">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr
              v-for="m in mutations"
              :key="m.id"
              class="hover:bg-slate-50 transition-colors"
            >
              <td class="px-4 py-3 text-slate-600 whitespace-nowrap text-xs">{{ formatDate(m.created_at) }}</td>
              <td class="px-4 py-3 font-mono text-xs text-slate-700 whitespace-nowrap">{{ m.reference_number }}</td>
              <td class="px-4 py-3">
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium"
                  :class="m.direction === 'in'
                    ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200'
                    : 'bg-orange-50 text-orange-700 ring-1 ring-orange-200'">
                  {{ getTypeLabel(m.type) }}
                </span>
              </td>
              <td class="px-4 py-3 text-xs text-slate-600">
                <span v-if="m.source">{{ m.source }}</span>
                <span v-if="m.source && m.destination" class="text-slate-400 mx-1">→</span>
                <span v-if="m.destination">{{ m.destination }}</span>
                <span v-if="!m.source && !m.destination" class="text-slate-400">-</span>
              </td>
              <td class="px-4 py-3 text-right font-semibold whitespace-nowrap"
                :class="m.direction === 'in' ? 'text-emerald-600' : 'text-red-500'">
                {{ m.direction === 'in' ? '+' : '-' }}{{ formatCurrency(m.amount) }}
              </td>
              <td class="px-4 py-3 text-center">
                <span :class="m.direction === 'in' ? 'text-emerald-500' : 'text-red-400'" class="text-base">
                  {{ m.direction === 'in' ? '⬆' : '⬇' }}
                </span>
              </td>
              <td class="px-4 py-3 text-xs text-slate-600 max-w-[160px] truncate" :title="m.notes">{{ m.notes }}</td>
              <td class="px-4 py-3 text-xs text-slate-500">{{ m.user?.name ?? '-' }}</td>
              <td class="px-4 py-3 text-center">
                <button @click="printReceipt(m)" class="text-slate-400 hover:text-slate-700 text-base" title="Cetak Bukti">🖨</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="lastPage > 1" class="flex items-center justify-between px-5 py-3 border-t border-slate-100 text-sm">
        <button
          :disabled="currentPage <= 1"
          @click="loadMutations(currentPage - 1)"
          class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
        >← Sebelumnya</button>
        <span class="text-xs text-slate-500">Halaman {{ currentPage }} / {{ lastPage }}</span>
        <button
          :disabled="currentPage >= lastPage"
          @click="loadMutations(currentPage + 1)"
          class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
        >Berikutnya →</button>
      </div>
    </div>

    <!-- ── Modal Form ───────────────────────────────────────────────────────── -->
    <Teleport to="body">
      <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="showForm = false" />

        <!-- Modal Card -->
        <div class="relative w-full max-w-lg rounded-2xl bg-white shadow-2xl overflow-hidden">
          <!-- Modal Header -->
          <div class="bg-gradient-to-r from-emerald-600 to-teal-600 px-6 py-5 text-white">
            <h3 class="text-lg font-bold">Catat Transaksi Kas Besar</h3>
            <p class="text-xs text-emerald-100 mt-0.5">Semua transaksi akan masuk ke buku besar kas</p>
          </div>

          <!-- Modal Body -->
          <form @submit.prevent="submitForm" class="p-6 space-y-4">
            <!-- Error -->
            <div v-if="errorMsg" class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
              ⚠️ {{ errorMsg }}
            </div>

            <!-- Jenis Transaksi -->
            <div>
              <label class="block text-sm font-semibold text-slate-700 mb-1.5">Jenis Transaksi <span class="text-red-500">*</span></label>
              <select v-model="form.type" @change="onTypeChange" required
                class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
                <option value="" disabled>— Pilih jenis transaksi —</option>
                <optgroup label="Penerimaan (Masuk)">
                  <option v-for="t in TYPE_OPTIONS.filter(x => x.dir === 'in')" :key="t.value" :value="t.value">{{ t.label }}</option>
                </optgroup>
                <optgroup label="Pengeluaran (Keluar)">
                  <option v-for="t in TYPE_OPTIONS.filter(x => x.dir === 'out')" :key="t.value" :value="t.value">{{ t.label }}</option>
                </optgroup>
              </select>
              <p v-if="form.type" class="mt-1 text-xs text-slate-400">
                Arah: <span :class="directionAuto === 'in' ? 'text-emerald-600 font-semibold' : 'text-red-500 font-semibold'">{{ directionAuto === 'in' ? '⬆ Masuk' : '⬇ Keluar' }}</span>
              </p>
            </div>

            <!-- Nominal -->
            <div>
              <label class="block text-sm font-semibold text-slate-700 mb-1.5">Nominal <span class="text-red-500">*</span></label>
              <div class="relative">
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 font-semibold text-sm">Rp</span>
                <input
                  v-model="form.amount"
                  type="number"
                  min="1"
                  step="any"
                  required
                  placeholder="0"
                  class="w-full rounded-xl border border-slate-300 pl-10 pr-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500"
                />
              </div>
            </div>

            <!-- Sumber & Tujuan -->
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1.5">Sumber Dana</label>
                <input v-model="form.source" type="text" placeholder="e.g. Kasir, Rekening BCA"
                  class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500" />
              </div>
              <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1.5">Tujuan Dana</label>
                <input v-model="form.destination" type="text" placeholder="e.g. Kas Besar, Supplier"
                  class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500" />
              </div>
            </div>

            <!-- Keterangan -->
            <div>
              <label class="block text-sm font-semibold text-slate-700 mb-1.5">Keterangan <span class="text-red-500">*</span></label>
              <textarea v-model="form.notes" rows="2" required placeholder="Tulis keterangan transaksi..."
                class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-emerald-500" />
            </div>

            <!-- Actions -->
            <div class="flex gap-3 pt-2">
              <button type="button" @click="showForm = false"
                class="flex-1 rounded-xl border border-slate-300 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50 transition-colors">
                Batal
              </button>
              <button type="submit" :disabled="submitting"
                class="flex-1 rounded-xl bg-emerald-600 py-2.5 text-sm font-bold text-white hover:bg-emerald-700 disabled:opacity-60 disabled:cursor-not-allowed transition-colors">
                {{ submitting ? 'Menyimpan...' : 'Simpan Transaksi' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </Teleport>
  </div>
  </AppShell>
</template>
