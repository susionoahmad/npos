<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import AppShell from '../components/AppShell.vue'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()

// ── Utility ──────────────────────────────────────────────────────────
const money = (n: number | string) =>
  new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Math.round(Number(n) || 0))

const formatDate = (d: string) =>
  new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })

const paymentLabel: Record<string, string> = {
  cash: 'Tunai', qris: 'QRIS', transfer: 'Transfer', card: 'Kartu'
}
const paymentColor: Record<string, string> = {
  cash: 'bg-emerald-100 text-emerald-700', qris: 'bg-violet-100 text-violet-700',
  transfer: 'bg-blue-100 text-blue-700', card: 'bg-orange-100 text-orange-700'
}

// ── Tab navigation ────────────────────────────────────────────────────
type TabId = 'operational' | 'barang' | 'pembelian' | 'keuangan'
type SubTabOps = 'harian' | 'per_kasir' | 'rekap_bayar'
type SubTabBarang = 'terlaris' | 'tidak_laku' | 'stok' | 'mutasi'
type SubTabPembelian = 'periode' | 'supplier' | 'hutang'

const activeTab       = ref<TabId>('operational')
const subTabOps       = ref<SubTabOps>('harian')
const subTabBarang    = ref<SubTabBarang>('terlaris')
const subTabPembelian = ref<SubTabPembelian>('periode')

const TABS: { id: TabId; label: string; icon: string }[] = [
  { id: 'operational', label: 'Operasional',  icon: '📊' },
  { id: 'barang',      label: 'Barang & Stok', icon: '📦' },
  { id: 'pembelian',   label: 'Pembelian',     icon: '🛒' },
  { id: 'keuangan',    label: 'Keuangan',      icon: '💰' },
]

// ── Filters ───────────────────────────────────────────────────────────
const today        = new Date().toISOString().slice(0, 10)
const firstOfMonth = today.slice(0, 8) + '01'
const filterFrom   = ref(firstOfMonth)
const filterTo     = ref(today)

// Cashier filter
type Cashier = { id: number; name: string; store_id?: number; role?: string }
const cashiers      = ref<Cashier[]>([])
const filterCashier = ref<number | ''>('') // '' = semua kasir

const filteredCashiers = computed(() => {
  const activeStoreId = auth.user?.store_id
  if (!activeStoreId) return cashiers.value
  return cashiers.value.filter((u: any) => !u.store_id || u.store_id === activeStoreId || u.role === 'owner')
})

async function loadCashiers() {
  try {
    const { data } = await api.get('/users', {
      params: { store_id: auth.user?.store_id }
    })
    // Show cashiers + admins that may operate POS
    cashiers.value = (data?.data ?? data ?? [])
      .filter((u: any) => ['cashier', 'admin', 'owner'].includes(u.role))
      .map((u: any) => ({ id: u.id, name: u.name, store_id: u.store_id, role: u.role }))
  } catch {}
}

// ── Loading / error ───────────────────────────────────────────────────
const loading = ref(false)
const error   = ref('')

// ── Data stores ───────────────────────────────────────────────────────
const salesData    = ref<any>({})
const paymentData  = ref<any>({})
const topData      = ref<any>({})
const slowData     = ref<any>({})
const stockData    = ref<any>({})
const plData       = ref<any>({})
const purchaseData = ref<any>({})
const mutationData = ref<any>({})
const filterProduct = ref<number | ''>('')

const stockSearch = ref('')

// ── Fetch ─────────────────────────────────────────────────────────────
function buildParams(extra?: Record<string, any>) {
  const p: Record<string, any> = { from: filterFrom.value, to: filterTo.value }
  if (filterCashier.value !== '') p.user_id = filterCashier.value
  return { ...p, ...extra }
}

async function fetchAll() {
  loading.value = true
  error.value   = ''
  const params = buildParams()
  try {
    const [s, p, top, slow, pl, pr] = await Promise.all([
      api.get('/reports/sales-by-cashier', { params }),
      api.get('/reports/payment-recap',    { params }),
      api.get('/reports/top-products',     { params }),
      api.get('/reports/slow-products',    { params }),
      api.get('/reports/profit-loss',      { params }),
      api.get('/reports/purchase-summary', { params }),
    ])
    salesData.value    = s.data
    paymentData.value  = p.data
    topData.value      = top.data
    slowData.value     = slow.data
    plData.value       = pl.data
    purchaseData.value = pr.data
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal memuat laporan.'
  } finally {
    loading.value = false
  }
}

async function fetchStock() {
  try {
    const { data } = await api.get('/reports/stock-current', {
      params: { search: stockSearch.value || undefined }
    })
    stockData.value = data
  } catch {}
}

async function fetchMutations() {
  try {
    const params = buildParams({ product_id: filterProduct.value || undefined })
    const { data } = await api.get('/reports/stock-mutations', { params })
    mutationData.value = data
  } catch {}
}

onMounted(() => {
  loadCashiers()
  fetchAll()
  fetchStock()
  fetchMutations()
})

watch([filterFrom, filterTo, filterCashier], () => {
  fetchAll()
  fetchMutations()
})
watch(stockSearch, fetchStock)
watch(filterProduct, fetchMutations)

// ── Computed ──────────────────────────────────────────────────────────
const dailyRows   = computed(() => salesData.value?.by_date    ?? [])
const cashierRows = computed(() => salesData.value?.by_cashier ?? [])
const paymentRows = computed(() => paymentData.value?.rows     ?? [])
const topRows     = computed(() => topData.value?.rows         ?? [])
const slowRows    = computed(() => slowData.value?.rows        ?? [])
const stockRows   = computed(() => stockData.value?.rows       ?? [])
const stockStats  = computed(() => stockData.value?.stats      ?? {})
const mutationRows = computed(() => mutationData.value?.rows    ?? [])

const plSummary = computed(() => plData.value?.summary ?? {})
const plDaily   = computed(() => plData.value?.daily   ?? [])

const purchaseSummary      = computed(() => purchaseData.value?.summary ?? {})
const purchasePeriodRows   = computed(() => purchaseData.value?.by_period ?? [])
const purchaseSupplierRows = computed(() => purchaseData.value?.by_supplier ?? [])
const supplierDebtRows     = computed(() => purchaseData.value?.supplier_debt ?? [])

// Selected cashier label
const selectedCashierName = computed(() => {
  if (filterCashier.value === '') return 'Semua Kasir'
  return filteredCashiers.value.find(c => c.id === filterCashier.value)?.name ?? 'Kasir'
})

function exportToCSV(filename: string, headers: string[], rows: any[][]) {
  const csvContent = "\uFEFF" + [
    headers.join(","),
    ...rows.map(row => row.map(val => {
      const cell = val === null || val === undefined ? "" : String(val)
      return `"${cell.replace(/"/g, '""')}"`
    }).join(","))
  ].join("\r\n")

  const blob = new Blob([csvContent], { type: "text/csv;charset=utf-8;" })
  const link = document.createElement("a")
  link.href = URL.createObjectURL(blob)
  link.setAttribute("download", filename + ".csv")
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

function printToPDF(title: string, periodText: string, cashierText: string, headers: string[], rows: any[][]) {
  const store = auth.user?.store || {}
  
  // Format table rows HTML
  const tableHeadersHtml = headers.map(h => `<th style="border: 1px solid #ddd; padding: 8px; background-color: #f2f2f2; text-align: left; font-size: 11px;">${h}</th>`).join('')
  const tableRowsHtml = rows.map(row => {
    return `<tr>${row.map(cell => `<td style="border: 1px solid #ddd; padding: 8px; font-size: 11px;">${cell}</td>`).join('')}</tr>`
  }).join('')

  const printContentHtml = `
    <div style="font-family: Arial, sans-serif; padding: 20px; color: #333;">
      <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 10px;">
        <div>
          <h1 style="margin: 0; font-size: 20px; text-transform: uppercase;">${store.name || 'Toko Nessa POS'}</h1>
          <p style="margin: 4px 0 0 0; font-size: 11px; color: #666;">${store.address || '-'}</p>
        </div>
        <div style="text-align: right;">
          <h2 style="margin: 0; font-size: 16px; color: #059669;">${title}</h2>
          <p style="margin: 4px 0 0 0; font-size: 11px; font-weight: bold; color: #475569;">${periodText}</p>
          <p style="margin: 2px 0 0 0; font-size: 10px; color: #64748b;">${cashierText}</p>
        </div>
      </div>
      
      <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
        <thead>
          <tr>${tableHeadersHtml}</tr>
        </thead>
        <tbody>
          ${tableRowsHtml}
        </tbody>
      </table>
      
      <div style="margin-top: 40px; font-size: 10px; color: #94a3b8; text-align: right;">
        Dicetak pada: ${new Date().toLocaleString('id-ID')}
      </div>
    </div>
  `

  const iframe = document.createElement('iframe')
  iframe.style.position = 'fixed'
  iframe.style.width = '0px'
  iframe.style.height = '0px'
  iframe.style.border = 'none'
  iframe.style.opacity = '0'
  document.body.appendChild(iframe)

  const doc = iframe.contentWindow?.document
  if (!doc) return

  doc.write(`
    <!DOCTYPE html>
    <html>
      <head>
        <title>${title}</title>
        <style>
          @page {
            size: A4 portrait;
            margin: 10mm;
          }
          body {
            margin: 0;
            padding: 0;
            background-color: #ffffff;
          }
        </style>
      </head>
      <body>
        ${printContentHtml}
      </body>
    </html>
  `)
  doc.close()

  setTimeout(() => {
    if (iframe.contentWindow) {
      iframe.contentWindow.focus()
      iframe.contentWindow.print()
      setTimeout(() => {
        document.body.removeChild(iframe)
      }, 500)
    }
  }, 300)
}

function handleExport(format: 'excel' | 'pdf') {
  let title = ''
  let headers: string[] = []
  let rows: any[][] = []
  
  // Format filter period info
  const periodText = `Periode: ${formatDate(filterFrom.value)} - ${formatDate(filterTo.value)}`
  const cashierText = filterCashier.value ? `Kasir: ${selectedCashierName.value}` : 'Kasir: Semua Kasir'

  if (activeTab.value === 'operational') {
    if (subTabOps.value === 'harian') {
      title = 'Laporan Penjualan Harian'
      headers = ['Tanggal', 'Jumlah Transaksi', 'Rata-rata/Trx', 'Total Omzet']
      rows = dailyRows.value.map((r: any) => [
        formatDate(r.date),
        r.total_transactions,
        `Rp ${money(r.total_sales / Math.max(r.total_transactions, 1))}`,
        `Rp ${money(r.total_sales)}`
      ])
    } else if (subTabOps.value === 'per_kasir') {
      title = 'Laporan Penjualan Per Kasir'
      headers = ['Nama Kasir', 'Peran', 'Transaksi', 'Rata-rata/Trx', 'Total Omzet']
      rows = cashierRows.value.map((r: any) => {
        const found = filteredCashiers.value.find(c => c.id === r.user_id)
        const roleLabel = found?.role === 'owner' ? 'Owner' : (found?.role === 'admin' ? 'Admin' : 'Kasir')
        return [
          r.cashier_name,
          roleLabel,
          r.total_transactions,
          `Rp ${money(r.total_sales / Math.max(r.total_transactions, 1))}`,
          `Rp ${money(r.total_sales)}`
        ]
      })
    } else if (subTabOps.value === 'rekap_bayar') {
      title = 'Laporan Rekap Metode Pembayaran'
      headers = ['Metode Pembayaran', 'Jumlah Transaksi', 'Total Penerimaan']
      rows = paymentRows.value.map((r: any) => [
        paymentLabel[r.method] || r.method,
        r.total_transactions,
        `Rp ${money(r.total_amount)}`
      ])
    }
  } else if (activeTab.value === 'barang') {
    if (subTabBarang.value === 'terlaris') {
      title = 'Laporan Barang Terlaris'
      headers = ['Rank', 'Nama Produk', 'Jumlah Terjual', 'Harga Rata-rata', 'Total Omzet']
      rows = topRows.value.map((r: any, i: number) => [
        i + 1,
        r.product_name,
        `${r.qty_sold} pcs`,
        `Rp ${money(r.avg_price)}`,
        `Rp ${money(r.total_revenue)}`
      ])
    } else if (subTabBarang.value === 'tidak_laku') {
      title = 'Laporan Barang Kurang Laku'
      headers = ['Nama Produk', 'Barcode', 'Stok', 'Harga', 'Terjual']
      rows = slowRows.value.map((r: any) => [
        r.product_name,
        r.barcode || '-',
        r.stock,
        `Rp ${money(r.price)}`,
        `${r.qty_sold} pcs`
      ])
    } else if (subTabBarang.value === 'stok') {
      title = 'Laporan Stok Barang Saat Ini'
      headers = ['Nama Produk', 'Kategori', 'Barcode', 'Harga Beli', 'Harga Jual', 'Stok', 'Nilai Aset']
      rows = stockRows.value.map((r: any) => [
        r.product_name,
        r.category || '-',
        r.barcode || '-',
        `Rp ${money(r.buying_price)}`,
        `Rp ${money(r.price)}`,
        r.stock,
        `Rp ${money(r.stock * r.buying_price)}`
      ])
    } else if (subTabBarang.value === 'mutasi') {
      title = 'Laporan Mutasi Stok Barang'
      headers = ['Tanggal', 'Nama Produk', 'Tipe', 'Kuantitas', 'Referensi', 'User', 'Catatan']
      rows = mutationRows.value.map((r: any) => [
        r.date,
        r.product_name,
        r.type === 'in' ? 'Masuk' : 'Keluar',
        r.quantity,
        r.reference,
        r.user_name,
        r.notes || '-'
      ])
    }
  } else if (activeTab.value === 'pembelian') {
    if (subTabPembelian.value === 'periode') {
      title = 'Laporan Pembelian Harian'
      headers = ['Tanggal', 'Jumlah Transaksi', 'Total Pembelian']
      rows = purchasePeriodRows.value.map((r: any) => [
        formatDate(r.date),
        r.count,
        `Rp ${money(r.amount)}`
      ])
    } else if (subTabPembelian.value === 'supplier') {
      title = 'Laporan Pembelian Per Supplier'
      headers = ['Nama Supplier', 'Jumlah Transaksi', 'Total Belanja']
      rows = purchaseSupplierRows.value.map((r: any) => [
        r.supplier_name || 'Tanpa Supplier',
        r.count,
        `Rp ${money(r.amount)}`
      ])
    } else if (subTabPembelian.value === 'hutang') {
      title = 'Laporan Hutang Supplier'
      headers = ['Nama Supplier', 'No. Telepon', 'Invoice Pending', 'Total Hutang']
      rows = supplierDebtRows.value.map((r: any) => [
        r.supplier_name,
        r.supplier_phone || '-',
        `${r.pending_invoices_count} invoice`,
        `Rp ${money(r.total_debt)}`
      ])
    }
  } else if (activeTab.value === 'keuangan') {
    title = 'Laporan Laba Rugi'
    headers = ['Keterangan / Tanggal', 'Omzet Penjualan', 'HPP / Biaya', 'Laba Kotor / Bersih']
    rows = [
      ['[RINGKASAN KEUANGAN]', '', '', ''],
      ['Total Pendapatan Penjualan', `Rp ${money(plSummary.value.revenue ?? 0)}`, '-', '-'],
      ['Total Harga Pokok Penjualan (HPP)', '-', `Rp ${money(plSummary.value.cost ?? 0)}`, '-'],
      ['Total Laba Kotor', '-', '-', `Rp ${money(plSummary.value.gross_profit ?? 0)}`],
      ['Total Pengeluaran Toko', '-', `Rp ${money(plSummary.value.expenses ?? 0)}`, '-'],
      ['Laba Bersih', '-', '-', `Rp ${money(plSummary.value.net_profit ?? 0)}`],
      ['', '', '', ''],
      ['[RINCIAN HARIAN]', '', '', '']
    ]

    plDaily.value.forEach((r: any) => {
      rows.push([
        formatDate(r.date),
        `Rp ${money(r.sales)}`,
        `Rp ${money(r.cost)}`,
        `Rp ${money(r.profit)}`
      ])
    })
  }

  if (format === 'excel') {
    const filename = `${title.toLowerCase().replace(/ /g, '_')}_${filterFrom.value}_to_${filterTo.value}`
    const cleanedHeaders = [...headers]
    const cleanedRows = rows.map(r => r.map(c => typeof c === 'string' ? c.replace(/Rp\s?/g, '').trim() : c))
    exportToCSV(filename, cleanedHeaders, cleanedRows)
  } else if (format === 'pdf') {
    printToPDF(title, periodText, cashierText, headers, rows)
  }
}
</script>

<template>
  <AppShell>
    <div class="max-w-7xl mx-auto space-y-5">

      <!-- ── Page Header ── -->
      <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
        <div>
          <h1 class="text-2xl font-black text-slate-900 tracking-tight">Keuangan &amp; Laporan</h1>
          <p class="text-sm text-slate-500 mt-0.5">Analisis performa toko secara lengkap</p>
        </div>

        <!-- ── Global Filters ── -->
        <div class="flex flex-wrap items-center gap-2">

          <!-- Cashier filter -->
          <div class="flex items-center gap-2 bg-white border border-slate-200 rounded-xl px-3 py-2 shadow-sm min-w-[180px]">
            <span class="text-base shrink-0">👤</span>
            <select
              v-model="filterCashier"
              class="text-xs font-semibold text-slate-700 bg-transparent border-0 outline-none flex-1 cursor-pointer"
            >
              <option value="">Semua Kasir</option>
              <option v-for="c in filteredCashiers" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>

          <!-- Date range -->
          <div class="flex items-center gap-2 bg-white border border-slate-200 rounded-xl px-3 py-2 shadow-sm">
            <span class="text-xs font-semibold text-slate-500 shrink-0">📅</span>
            <input type="date" v-model="filterFrom" class="text-xs border-0 outline-none text-slate-700 bg-transparent font-medium" />
            <span class="text-slate-300">→</span>
            <input type="date" v-model="filterTo"   class="text-xs border-0 outline-none text-slate-700 bg-transparent font-medium" />
          </div>

          <!-- Excel Export -->
          <button
            type="button"
            @click="handleExport('excel')"
            class="px-3.5 py-2.5 bg-white border border-slate-200 hover:border-emerald-500 text-slate-700 hover:text-emerald-700 text-xs font-extrabold rounded-xl shadow-sm flex items-center gap-1.5 transition-all"
            title="Ekspor Laporan ke Excel (.csv)"
          >
            <svg class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            Excel
          </button>

          <!-- PDF Export -->
          <button
            type="button"
            @click="handleExport('pdf')"
            class="px-3.5 py-2.5 bg-white border border-slate-200 hover:border-rose-500 text-slate-700 hover:text-rose-700 text-xs font-extrabold rounded-xl shadow-sm flex items-center gap-1.5 transition-all"
            title="Ekspor Laporan ke PDF / Cetak"
          >
            <svg class="h-4 w-4 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
            </svg>
            PDF
          </button>
        </div>
      </div>

      <!-- Active filter badge -->
      <div v-if="filterCashier !== ''" class="flex items-center gap-2">
        <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-bold rounded-full">
          👤 Filter aktif: {{ selectedCashierName }}
          <button @click="filterCashier = ''" class="ml-1 hover:text-red-500 transition-colors">✕</button>
        </span>
        <span class="text-xs text-slate-400">Semua angka hanya untuk kasir ini</span>
      </div>

      <!-- Error -->
      <div v-if="error" class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl px-4 py-3">⚠️ {{ error }}</div>

      <!-- ── Main Tabs ── -->
      <div class="flex gap-1 bg-slate-100 rounded-2xl p-1.5">
        <button
          v-for="tab in TABS" :key="tab.id"
          @click="activeTab = tab.id"
          class="flex-1 flex items-center justify-center gap-2 py-2.5 px-3 rounded-xl text-sm font-semibold transition-all"
          :class="activeTab === tab.id
            ? 'bg-white text-slate-900 shadow-md'
            : 'text-slate-500 hover:text-slate-700 hover:bg-white/60'"
        >
          <span>{{ tab.icon }}</span>
          <span class="hidden sm:inline">{{ tab.label }}</span>
        </button>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-20">
        <div class="text-center">
          <div class="w-10 h-10 rounded-full border-4 border-emerald-500 border-t-transparent animate-spin mx-auto mb-3"></div>
          <p class="text-sm text-slate-500">Memuat laporan...</p>
        </div>
      </div>

      <template v-else>

        <!-- ══════════════════════════════════════════════════
             OPERASIONAL HARIAN
        ══════════════════════════════════════════════════════ -->
        <div v-if="activeTab === 'operational'" class="space-y-5">

          <!-- Sub-tabs -->
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="(lbl, key) in { harian: '📅 Penjualan Harian', per_kasir: '👤 Per Kasir', rekap_bayar: '💳 Rekap Bayar' }"
              :key="key"
              @click="subTabOps = key as SubTabOps"
              class="px-4 py-1.5 rounded-full text-xs font-bold transition-all border"
              :class="subTabOps === key
                ? 'bg-emerald-500 text-white border-emerald-500'
                : 'bg-white text-slate-600 border-slate-200 hover:border-emerald-300'"
            >{{ lbl }}</button>
          </div>

          <!-- ── Penjualan Harian ── -->
          <div v-if="subTabOps === 'harian'" class="space-y-4">
            <!-- Summary cards -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
                <p class="text-xs text-slate-500 font-semibold mb-1">Total Penjualan</p>
                <p class="text-xl font-black text-emerald-600">Rp {{ money(salesData.grand_total ?? 0) }}</p>
                <p v-if="filterCashier" class="text-[10px] text-emerald-500 mt-0.5 font-semibold">{{ selectedCashierName }}</p>
              </div>
              <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
                <p class="text-xs text-slate-500 font-semibold mb-1">Total Transaksi</p>
                <p class="text-xl font-black text-slate-800">{{ (salesData.grand_trx ?? 0).toLocaleString('id-ID') }}</p>
              </div>
              <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
                <p class="text-xs text-slate-500 font-semibold mb-1">Rata-rata / Trx</p>
                <p class="text-xl font-black text-blue-600">
                  Rp {{ money((salesData.grand_total ?? 0) / Math.max(salesData.grand_trx ?? 1, 1)) }}
                </p>
              </div>
              <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
                <p class="text-xs text-slate-500 font-semibold mb-1">Hari Aktif</p>
                <p class="text-xl font-black text-slate-800">{{ dailyRows.length }}</p>
              </div>
            </div>

            <!-- Daily table -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
              <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                <h3 class="font-bold text-slate-800 text-sm">
                  Rincian Harian
                  <span v-if="filterCashier" class="ml-2 text-xs font-semibold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full">{{ selectedCashierName }}</span>
                </h3>
                <span class="text-xs text-slate-400">{{ dailyRows.length }} hari</span>
              </div>
              <div class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead>
                    <tr class="bg-slate-50 text-xs text-slate-500 font-bold uppercase tracking-wide">
                      <th class="px-5 py-3 text-left">Tanggal</th>
                      <th class="px-5 py-3 text-right">Transaksi</th>
                      <th class="px-5 py-3 text-right">Total Penjualan</th>
                      <th class="px-5 py-3 text-right">Rata-rata / Trx</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100">
                    <tr v-if="dailyRows.length === 0">
                      <td colspan="4" class="px-5 py-10 text-center text-slate-400 text-sm">Tidak ada data dalam periode ini</td>
                    </tr>
                    <tr v-for="row in dailyRows" :key="row.date" class="hover:bg-slate-50 transition-colors">
                      <td class="px-5 py-3 font-semibold text-slate-700">{{ formatDate(row.date) }}</td>
                      <td class="px-5 py-3 text-right text-slate-600">{{ row.total_transactions }}</td>
                      <td class="px-5 py-3 text-right font-bold text-emerald-600">Rp {{ money(row.total_sales) }}</td>
                      <td class="px-5 py-3 text-right text-slate-500">Rp {{ money(row.total_sales / Math.max(row.total_transactions, 1)) }}</td>
                    </tr>
                  </tbody>
                  <tfoot v-if="dailyRows.length > 0">
                    <tr class="bg-emerald-50 font-bold text-sm">
                      <td class="px-5 py-3 text-emerald-800">Total</td>
                      <td class="px-5 py-3 text-right text-emerald-700">{{ salesData.grand_trx ?? 0 }}</td>
                      <td class="px-5 py-3 text-right text-emerald-700">Rp {{ money(salesData.grand_total ?? 0) }}</td>
                      <td class="px-5 py-3 text-right text-emerald-700">
                        Rp {{ money((salesData.grand_total ?? 0) / Math.max(salesData.grand_trx ?? 1, 1)) }}
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </div>
          </div>

          <!-- ── Per Kasir ── -->
          <div v-if="subTabOps === 'per_kasir'" class="space-y-4">
            <!-- Info if filtered -->
            <div v-if="filterCashier" class="bg-amber-50 border border-amber-200 text-amber-700 text-xs rounded-xl px-4 py-2.5">
              ℹ️ Anda sedang memfilter ke <strong>{{ selectedCashierName }}</strong>. Tabel hanya menampilkan data kasir ini.
              <button @click="filterCashier = ''" class="underline ml-2 font-bold">Tampilkan semua</button>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
              <div class="px-5 py-3 border-b border-slate-100">
                <h3 class="font-bold text-slate-800 text-sm">Penjualan per Kasir</h3>
              </div>
              <div class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead>
                    <tr class="bg-slate-50 text-xs text-slate-500 font-bold uppercase tracking-wide">
                      <th class="px-5 py-3 text-left">#</th>
                      <th class="px-5 py-3 text-left">Kasir</th>
                      <th class="px-5 py-3 text-right">Transaksi</th>
                      <th class="px-5 py-3 text-right">Diskon</th>
                      <th class="px-5 py-3 text-right">Pajak</th>
                      <th class="px-5 py-3 text-right">Total Penjualan</th>
                      <th class="px-5 py-3 text-right">Rata-rata</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100">
                    <tr v-if="cashierRows.length === 0">
                      <td colspan="7" class="px-5 py-10 text-center text-slate-400">Tidak ada data</td>
                    </tr>
                    <tr
                      v-for="(row, i) in cashierRows" :key="row.user_id"
                      class="hover:bg-slate-50 transition-colors"
                      :class="{ 'bg-emerald-50/30 ring-1 ring-inset ring-emerald-200': filterCashier && row.user_id === Number(filterCashier) }"
                    >
                      <td class="px-5 py-3 text-slate-400 text-xs">{{ Number(i) + 1 }}</td>
                      <td class="px-5 py-3 font-semibold text-slate-800">
                        <span class="inline-flex items-center gap-2">
                          <span class="w-7 h-7 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center text-xs font-black shrink-0">
                            {{ (row.cashier_name ?? '?')[0].toUpperCase() }}
                          </span>
                          {{ row.cashier_name }}
                        </span>
                      </td>
                      <td class="px-5 py-3 text-right text-slate-600">{{ row.total_transactions }}</td>
                      <td class="px-5 py-3 text-right text-red-500 text-xs">Rp {{ money(row.total_discount) }}</td>
                      <td class="px-5 py-3 text-right text-blue-400 text-xs">Rp {{ money(row.total_tax) }}</td>
                      <td class="px-5 py-3 text-right font-bold text-emerald-600">Rp {{ money(row.total_sales) }}</td>
                      <td class="px-5 py-3 text-right text-slate-500">
                        Rp {{ money(row.total_sales / Math.max(row.total_transactions, 1)) }}
                      </td>
                    </tr>
                  </tbody>
                  <tfoot v-if="cashierRows.length > 0">
                    <tr class="bg-slate-50 font-bold text-sm border-t-2 border-slate-200">
                      <td class="px-5 py-3" colspan="2">Total</td>
                      <td class="px-5 py-3 text-right text-slate-700">{{ salesData.grand_trx ?? 0 }}</td>
                      <td class="px-5 py-3 text-right text-red-500 text-xs">
                        Rp {{ money(cashierRows.reduce((s: number, r: any) => s + (r.total_discount || 0), 0)) }}
                      </td>
                      <td class="px-5 py-3 text-right text-blue-400 text-xs">
                        Rp {{ money(cashierRows.reduce((s: number, r: any) => s + (r.total_tax || 0), 0)) }}
                      </td>
                      <td class="px-5 py-3 text-right text-emerald-700">Rp {{ money(salesData.grand_total ?? 0) }}</td>
                      <td class="px-5 py-3 text-right text-slate-500">
                        Rp {{ money((salesData.grand_total ?? 0) / Math.max(salesData.grand_trx ?? 1, 1)) }}
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </div>
          </div>

          <!-- ── Rekap Bayar ── -->
          <div v-if="subTabOps === 'rekap_bayar'" class="space-y-4">
            <!-- Info if filtered -->
            <div v-if="filterCashier" class="bg-amber-50 border border-amber-200 text-amber-700 text-xs rounded-xl px-4 py-2.5">
              ℹ️ Rekap pembayaran untuk kasir: <strong>{{ selectedCashierName }}</strong>
            </div>
            <!-- Method cards -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <div v-for="row in paymentRows" :key="row.method" class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-bold mb-2"
                  :class="paymentColor[row.method] || 'bg-slate-100 text-slate-600'">
                  {{ paymentLabel[row.method] || row.method }}
                </span>
                <p class="text-xl font-black text-slate-800">Rp {{ money(row.total_amount) }}</p>
                <p class="text-xs text-slate-400 mt-1">{{ row.total_transactions }} transaksi</p>
              </div>
            </div>
            <!-- Table -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
              <div class="px-5 py-3 border-b border-slate-100 flex justify-between items-center">
                <h3 class="font-bold text-slate-800 text-sm">Detail Rekap Pembayaran</h3>
                <span class="font-bold text-emerald-600 text-sm">Total: Rp {{ money(paymentData.grand_total ?? 0) }}</span>
              </div>
              <div class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead>
                    <tr class="bg-slate-50 text-xs text-slate-500 font-bold uppercase tracking-wide">
                      <th class="px-5 py-3 text-left">Metode</th>
                      <th class="px-5 py-3 text-right">Transaksi</th>
                      <th class="px-5 py-3 text-right">Rata-rata</th>
                      <th class="px-5 py-3 text-right">Total</th>
                      <th class="px-5 py-3 text-right">%</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100">
                    <tr v-if="paymentRows.length === 0">
                      <td colspan="5" class="px-5 py-10 text-center text-slate-400">Tidak ada data</td>
                    </tr>
                    <tr v-for="row in paymentRows" :key="row.method" class="hover:bg-slate-50">
                      <td class="px-5 py-3">
                        <span class="px-2.5 py-1 rounded-full text-xs font-bold"
                          :class="paymentColor[row.method] || 'bg-slate-100 text-slate-600'">
                          {{ paymentLabel[row.method] || row.method }}
                        </span>
                      </td>
                      <td class="px-5 py-3 text-right text-slate-600">{{ row.total_transactions }}</td>
                      <td class="px-5 py-3 text-right text-slate-500">Rp {{ money(row.avg_transaction) }}</td>
                      <td class="px-5 py-3 text-right font-bold text-slate-800">Rp {{ money(row.total_amount) }}</td>
                      <td class="px-5 py-3 text-right text-slate-400 text-xs">
                        {{ ((row.total_amount / Math.max(paymentData.grand_total ?? 1, 1)) * 100).toFixed(1) }}%
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             BARANG & STOK
        ══════════════════════════════════════════════════════ -->
        <div v-if="activeTab === 'barang'" class="space-y-5">
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="(lbl, key) in { terlaris: '🏆 Terlaris', tidak_laku: '😴 Tidak Laku', stok: '📊 Stok Saat Ini', mutasi: '🔄 Mutasi Stok' }"
              :key="key"
              @click="subTabBarang = key as SubTabBarang"
              class="px-4 py-1.5 rounded-full text-xs font-bold transition-all border"
              :class="subTabBarang === key
                ? 'bg-emerald-500 text-white border-emerald-500'
                : 'bg-white text-slate-600 border-slate-200 hover:border-emerald-300'"
            >{{ lbl }}</button>
          </div>

          <!-- Terlaris -->
          <div v-if="subTabBarang === 'terlaris'" class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
              <h3 class="font-bold text-slate-800 text-sm">🏆 Barang Terlaris</h3>
              <span class="text-xs text-slate-400">{{ topRows.length }} produk</span>
            </div>
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead>
                  <tr class="bg-slate-50 text-xs text-slate-500 font-bold uppercase tracking-wide">
                    <th class="px-5 py-3 text-left">Rank</th>
                    <th class="px-5 py-3 text-left">Produk</th>
                    <th class="px-5 py-3 text-right">Qty Terjual</th>
                    <th class="px-5 py-3 text-right">Harga Rata-rata</th>
                    <th class="px-5 py-3 text-right">Total Omzet</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-if="topRows.length === 0">
                    <td colspan="5" class="px-5 py-10 text-center text-slate-400">Tidak ada data</td>
                  </tr>
                  <tr v-for="(row, i) in topRows" :key="row.product_id" class="hover:bg-slate-50">
                    <td class="px-5 py-3">
                      <span class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-black"
                        :class="Number(i)===0?'bg-yellow-100 text-yellow-700':Number(i)===1?'bg-slate-100 text-slate-600':Number(i)===2?'bg-orange-100 text-orange-600':'bg-slate-50 text-slate-400'">
                        {{ Number(i) + 1 }}
                      </span>
                    </td>
                    <td class="px-5 py-3 font-semibold text-slate-800">{{ row.product_name }}</td>
                    <td class="px-5 py-3 text-right">
                      <span class="px-2.5 py-1 bg-emerald-50 text-emerald-700 rounded-full text-xs font-bold">{{ row.qty_sold }} pcs</span>
                    </td>
                    <td class="px-5 py-3 text-right text-slate-500">Rp {{ money(row.avg_price) }}</td>
                    <td class="px-5 py-3 text-right font-bold text-emerald-600">Rp {{ money(row.total_revenue) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Tidak Laku -->
          <div v-if="subTabBarang === 'tidak_laku'" class="space-y-4">
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
              <div class="bg-white rounded-2xl border border-red-100 p-4 shadow-sm">
                <p class="text-xs text-slate-500 font-semibold mb-1">Tidak Terjual</p>
                <p class="text-2xl font-black text-red-500">{{ slowRows.filter((r:any) => r.qty_sold === 0).length }}</p>
              </div>
              <div class="bg-white rounded-2xl border border-orange-100 p-4 shadow-sm">
                <p class="text-xs text-slate-500 font-semibold mb-1">Terjual &lt; 5 pcs</p>
                <p class="text-2xl font-black text-orange-500">{{ slowRows.filter((r:any) => r.qty_sold < 5).length }}</p>
              </div>
              <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
                <p class="text-xs text-slate-500 font-semibold mb-1">Total Produk</p>
                <p class="text-2xl font-black text-slate-700">{{ slowRows.length }}</p>
              </div>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
              <div class="px-5 py-3 border-b border-slate-100">
                <h3 class="font-bold text-slate-800 text-sm">😴 Produk Kurang Laku</h3>
              </div>
              <div class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead>
                    <tr class="bg-slate-50 text-xs text-slate-500 font-bold uppercase tracking-wide">
                      <th class="px-5 py-3 text-left">Produk</th>
                      <th class="px-5 py-3 text-left">Barcode</th>
                      <th class="px-5 py-3 text-right">Stok</th>
                      <th class="px-5 py-3 text-right">Harga</th>
                      <th class="px-5 py-3 text-right">Terjual (Periode)</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100">
                    <tr v-if="slowRows.length === 0">
                      <td colspan="5" class="px-5 py-10 text-center text-slate-400">Tidak ada data</td>
                    </tr>
                    <tr v-for="row in slowRows" :key="row.product_id" class="hover:bg-slate-50">
                      <td class="px-5 py-3 font-semibold text-slate-800">{{ row.product_name }}</td>
                      <td class="px-5 py-3 text-slate-400 font-mono text-xs">{{ row.barcode || '-' }}</td>
                      <td class="px-5 py-3 text-right">
                        <span class="px-2 py-0.5 rounded-full text-xs font-bold"
                          :class="row.stock === 0 ? 'bg-red-100 text-red-600' : 'bg-slate-100 text-slate-600'">
                          {{ row.stock }}
                        </span>
                      </td>
                      <td class="px-5 py-3 text-right text-slate-500">Rp {{ money(row.price) }}</td>
                      <td class="px-5 py-3 text-right">
                        <span class="px-2 py-0.5 rounded-full text-xs font-bold"
                          :class="row.qty_sold === 0 ? 'bg-red-50 text-red-500' : 'bg-orange-50 text-orange-600'">
                          {{ row.qty_sold }} pcs
                        </span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Stok Saat Ini -->
          <div v-if="subTabBarang === 'stok'" class="space-y-4">
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
              <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
                <p class="text-xs text-slate-500 font-semibold mb-1">Total Produk</p>
                <p class="text-2xl font-black text-slate-700">{{ stockStats.total_products ?? 0 }}</p>
              </div>
              <div class="bg-white rounded-2xl border border-orange-100 p-4 shadow-sm">
                <p class="text-xs text-slate-500 font-semibold mb-1">Stok Rendah (≤5)</p>
                <p class="text-2xl font-black text-orange-500">{{ stockStats.low_stock_count ?? 0 }}</p>
              </div>
              <div class="bg-white rounded-2xl border border-red-100 p-4 shadow-sm">
                <p class="text-xs text-slate-500 font-semibold mb-1">Habis (Stok 0)</p>
                <p class="text-2xl font-black text-red-500">{{ stockStats.out_of_stock ?? 0 }}</p>
              </div>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 px-4 py-2.5 flex items-center gap-2 shadow-sm">
              <span class="text-slate-400">🔍</span>
              <input v-model="stockSearch" placeholder="Cari nama atau barcode produk..."
                class="flex-1 text-sm outline-none bg-transparent text-slate-700 placeholder-slate-400" />
              <button v-if="stockSearch" @click="stockSearch = ''" class="text-slate-400 hover:text-red-400 text-xs">✕</button>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
              <div class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead>
                    <tr class="bg-slate-50 text-xs text-slate-500 font-bold uppercase tracking-wide">
                      <th class="px-5 py-3 text-left">Produk</th>
                      <th class="px-5 py-3 text-left">Kategori</th>
                      <th class="px-5 py-3 text-left">Barcode</th>
                      <th class="px-5 py-3 text-right">Stok</th>
                      <th class="px-5 py-3 text-right">Harga</th>
                      <th class="px-5 py-3 text-center">Status</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100">
                    <tr v-if="stockRows.length === 0">
                      <td colspan="6" class="px-5 py-10 text-center text-slate-400">Tidak ada produk</td>
                    </tr>
                    <tr v-for="row in stockRows" :key="row.product_id" class="hover:bg-slate-50">
                      <td class="px-5 py-3 font-semibold text-slate-800">{{ row.product_name }}</td>
                      <td class="px-5 py-3 text-slate-500 text-xs">{{ row.category }}</td>
                      <td class="px-5 py-3 font-mono text-xs text-slate-400">{{ row.barcode || '-' }}</td>
                      <td class="px-5 py-3 text-right font-bold"
                        :class="row.stock === 0 ? 'text-red-500' : row.is_low_stock ? 'text-orange-500' : 'text-slate-700'">
                        {{ row.stock }}
                      </td>
                      <td class="px-5 py-3 text-right text-slate-500">Rp {{ money(row.price) }}</td>
                      <td class="px-5 py-3 text-center">
                        <span class="px-2 py-0.5 rounded-full text-xs font-bold"
                          :class="row.stock === 0 ? 'bg-red-100 text-red-600' : row.is_low_stock ? 'bg-orange-100 text-orange-600' : 'bg-emerald-100 text-emerald-700'">
                          {{ row.stock === 0 ? 'Habis' : row.is_low_stock ? 'Rendah' : 'OK' }}
                        </span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Mutasi Stok -->
          <div v-if="subTabBarang === 'mutasi'" class="space-y-4">
            <!-- Product Filter for Mutations -->
            <div class="flex items-center gap-2 bg-white border border-slate-200 rounded-xl px-3 py-2 shadow-sm max-w-xs">
              <span class="text-base shrink-0">📦</span>
              <select
                v-model="filterProduct"
                class="text-xs font-semibold text-slate-700 bg-transparent border-0 outline-none flex-1 cursor-pointer"
              >
                <option value="">Semua Produk</option>
                <option v-for="p in stockRows" :key="p.product_id" :value="p.product_id">{{ p.product_name }}</option>
              </select>
            </div>

            <!-- Table -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
              <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                <h3 class="font-bold text-slate-800 text-sm">🔄 Riwayat Mutasi Stok (In &amp; Out)</h3>
                <span class="text-xs text-slate-400">{{ mutationRows.length }} mutasi</span>
              </div>
              <div class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead>
                    <tr class="bg-slate-50 text-xs text-slate-500 font-bold uppercase tracking-wide">
                      <th class="px-5 py-3 text-left">Tanggal</th>
                      <th class="px-5 py-3 text-left">Produk</th>
                      <th class="px-5 py-3 text-center">Tipe</th>
                      <th class="px-5 py-3 text-right">Qty</th>
                      <th class="px-5 py-3 text-left">Referensi</th>
                      <th class="px-5 py-3 text-left">Operator</th>
                      <th class="px-5 py-3 text-left">Keterangan</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100">
                    <tr v-if="mutationRows.length === 0">
                      <td colspan="7" class="px-5 py-10 text-center text-slate-400">Tidak ada riwayat mutasi stok</td>
                    </tr>
                    <tr v-for="(row, idx) in mutationRows" :key="idx" class="hover:bg-slate-50 transition-colors">
                      <td class="px-5 py-3 text-slate-600 font-medium">{{ formatDate(row.date) }} {{ row.date.substring(11, 16) }}</td>
                      <td class="px-5 py-3 font-semibold text-slate-800">{{ row.product_name }}</td>
                      <td class="px-5 py-3 text-center">
                        <span class="px-2 py-0.5 rounded-full text-xs font-bold"
                          :class="row.type === 'in' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'">
                          {{ row.type === 'in' ? 'Barang Masuk' : 'Barang Keluar' }}
                        </span>
                      </td>
                      <td class="px-5 py-3 text-right font-mono font-bold"
                        :class="row.type === 'in' ? 'text-emerald-600' : 'text-red-500'">
                        {{ row.type === 'in' ? '+' : '-' }}{{ row.quantity }}
                      </td>
                      <td class="px-5 py-3 text-slate-700 font-semibold">{{ row.reference }}</td>
                      <td class="px-5 py-3 text-slate-600">{{ row.user_name }}</td>
                      <td class="px-5 py-3 text-slate-500 text-xs">{{ row.notes }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             PEMBELIAN
        ══════════════════════════════════════════════════════ -->
        <div v-if="activeTab === 'pembelian'" class="space-y-5">

          <!-- Sub-tabs -->
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="(lbl, key) in { periode: '📅 Pembelian Harian', supplier: '🏢 Per Supplier', hutang: '💸 Hutang Supplier' }"
              :key="key"
              @click="subTabPembelian = key as SubTabPembelian"
              class="px-4 py-1.5 rounded-full text-xs font-bold transition-all border"
              :class="subTabPembelian === key
                ? 'bg-emerald-500 text-white border-emerald-500'
                : 'bg-white text-slate-600 border-slate-200 hover:border-emerald-300'"
            >{{ lbl }}</button>
          </div>

          <!-- Summary cards -->
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
              <p class="text-xs text-slate-500 font-semibold mb-1">Total Pembelian</p>
              <p class="text-xl font-black text-emerald-600">Rp {{ money(purchaseSummary.total_purchases_amount ?? 0) }}</p>
              <p class="text-[10px] text-slate-400 mt-0.5">Periode terpilih</p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
              <p class="text-xs text-slate-500 font-semibold mb-1">Total Transaksi</p>
              <p class="text-xl font-black text-slate-800">{{ (purchaseSummary.total_purchases_count ?? 0).toLocaleString('id-ID') }}</p>
              <p class="text-[10px] text-slate-400 mt-0.5">Transaksi sukses</p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
              <p class="text-xs text-slate-500 font-semibold mb-1">Rata-rata / Pembelian</p>
              <p class="text-xl font-black text-blue-600">
                Rp {{ money((purchaseSummary.total_purchases_amount ?? 0) / Math.max(purchaseSummary.total_purchases_count ?? 1, 1)) }}
              </p>
              <p class="text-[10px] text-slate-400 mt-0.5">Nilai rata-rata trx</p>
            </div>
            <div class="bg-white rounded-2xl border border-red-100 p-4 shadow-sm">
              <p class="text-xs text-red-500 font-semibold mb-1">Total Hutang Pending</p>
              <p class="text-xl font-black text-red-600">Rp {{ money(purchaseSummary.total_debt_amount ?? 0) }}</p>
              <p class="text-[10px] text-red-400 mt-0.5 font-semibold">Semua waktu (Belum lunas)</p>
            </div>
          </div>

          <!-- ── Pembelian Harian / Periode ── -->
          <div v-if="subTabPembelian === 'periode'" class="space-y-4">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
              <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                <h3 class="font-bold text-slate-800 text-sm">Rincian Pembelian Harian</h3>
                <router-link to="/purchases" class="text-xs font-bold text-emerald-600 hover:text-emerald-700 transition-colors">
                  Lihat Riwayat Pembelian ↗
                </router-link>
              </div>
              <div class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead>
                    <tr class="bg-slate-50 text-xs text-slate-500 font-bold uppercase tracking-wide">
                      <th class="px-5 py-3 text-left">Tanggal</th>
                      <th class="px-5 py-3 text-right">Transaksi</th>
                      <th class="px-5 py-3 text-right">Total Pembelian</th>
                      <th class="px-5 py-3 text-right">Rata-rata</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100">
                    <tr v-if="purchasePeriodRows.length === 0">
                      <td colspan="4" class="px-5 py-10 text-center text-slate-400">Tidak ada transaksi pembelian pada periode ini</td>
                    </tr>
                    <tr v-for="row in purchasePeriodRows" :key="row.date" class="hover:bg-slate-50 transition-colors">
                      <td class="px-5 py-3 font-semibold text-slate-700">{{ formatDate(row.date) }}</td>
                      <td class="px-5 py-3 text-right text-slate-600">{{ row.count }}</td>
                      <td class="px-5 py-3 text-right font-bold text-emerald-600">Rp {{ money(row.amount) }}</td>
                      <td class="px-5 py-3 text-right text-slate-500">Rp {{ money(row.amount / Math.max(row.count, 1)) }}</td>
                    </tr>
                  </tbody>
                  <tfoot v-if="purchasePeriodRows.length > 0">
                    <tr class="bg-emerald-50 font-bold text-sm">
                      <td class="px-5 py-3 text-emerald-800">Total</td>
                      <td class="px-5 py-3 text-right text-emerald-700">{{ purchaseSummary.total_purchases_count ?? 0 }}</td>
                      <td class="px-5 py-3 text-right text-emerald-700">Rp {{ money(purchaseSummary.total_purchases_amount ?? 0) }}</td>
                      <td class="px-5 py-3 text-right text-emerald-700">
                        Rp {{ money((purchaseSummary.total_purchases_amount ?? 0) / Math.max(purchaseSummary.total_purchases_count ?? 1, 1)) }}
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </div>
          </div>

          <!-- ── Per Supplier ── -->
          <div v-if="subTabPembelian === 'supplier'" class="space-y-4">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
              <div class="px-5 py-3 border-b border-slate-100">
                <h3 class="font-bold text-slate-800 text-sm">Pembelian per Supplier</h3>
              </div>
              <div class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead>
                    <tr class="bg-slate-50 text-xs text-slate-500 font-bold uppercase tracking-wide">
                      <th class="px-5 py-3 text-left">Nama Supplier</th>
                      <th class="px-5 py-3 text-right">Transaksi</th>
                      <th class="px-5 py-3 text-right">Total Belanja</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100">
                    <tr v-if="purchaseSupplierRows.length === 0">
                      <td colspan="3" class="px-5 py-10 text-center text-slate-400">Tidak ada transaksi supplier pada periode ini</td>
                    </tr>
                    <tr v-for="row in purchaseSupplierRows" :key="row.supplier_name" class="hover:bg-slate-50 transition-colors">
                      <td class="px-5 py-3 font-semibold text-slate-800">{{ row.supplier_name }}</td>
                      <td class="px-5 py-3 text-right text-slate-600">{{ row.count }}</td>
                      <td class="px-5 py-3 text-right font-bold text-emerald-600">Rp {{ money(row.amount) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- ── Hutang Supplier ── -->
          <div v-if="subTabPembelian === 'hutang'" class="space-y-4">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
              <div class="px-5 py-3 border-b border-slate-100">
                <h3 class="font-bold text-slate-800 text-sm">Daftar Hutang Supplier (Pending)</h3>
              </div>
              <div class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead>
                    <tr class="bg-slate-50 text-xs text-slate-500 font-bold uppercase tracking-wide">
                      <th class="px-5 py-3 text-left">Nama Supplier</th>
                      <th class="px-5 py-3 text-left">No. Telepon</th>
                      <th class="px-5 py-3 text-right">Invoice Pending</th>
                      <th class="px-5 py-3 text-right">Total Hutang</th>
                      <th class="px-5 py-3 text-center">Aksi</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100">
                    <tr v-if="supplierDebtRows.length === 0">
                      <td colspan="5" class="px-5 py-10 text-center text-slate-400">Tidak ada hutang pending</td>
                    </tr>
                    <tr v-for="row in supplierDebtRows" :key="row.supplier_name" class="hover:bg-slate-50 transition-colors">
                      <td class="px-5 py-3 font-semibold text-slate-800">{{ row.supplier_name }}</td>
                      <td class="px-5 py-3 text-slate-600 font-mono text-xs">{{ row.supplier_phone || '-' }}</td>
                      <td class="px-5 py-3 text-right">
                        <span class="px-2.5 py-1 bg-red-50 text-red-600 rounded-full text-xs font-bold">{{ row.pending_invoices_count }} invoice</span>
                      </td>
                      <td class="px-5 py-3 text-right font-bold text-red-600">Rp {{ money(row.total_debt) }}</td>
                      <td class="px-5 py-3 text-center">
                        <a v-if="row.supplier_phone"
                           :href="'https://wa.me/' + row.supplier_phone.replace(/\D/g, '')"
                           target="_blank"
                           class="inline-flex items-center gap-1 px-3 py-1 bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg text-xs font-semibold shadow-sm transition-colors"
                        >
                          💬 Hubungi WA
                        </a>
                        <span v-else class="text-xs text-slate-400">-</span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             KEUANGAN
        ══════════════════════════════════════════════════════ -->
        <div v-if="activeTab === 'keuangan'" class="space-y-4">
          <div v-if="filterCashier" class="bg-amber-50 border border-amber-200 text-amber-700 text-xs rounded-xl px-4 py-2.5">
            ℹ️ Laba rugi difilter untuk kasir: <strong>{{ selectedCashierName }}</strong> (hanya pendapatan dari kasir ini)
          </div>
          <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
            <div class="bg-emerald-50 rounded-2xl border border-emerald-200 p-4 shadow-sm">
              <p class="text-xs text-emerald-600 font-semibold mb-1">Pendapatan Bersih</p>
              <p class="text-lg font-black text-emerald-700">Rp {{ money(plSummary.net_revenue ?? 0) }}</p>
              <p class="text-[10px] text-slate-400 mt-0.5">Kotor: Rp {{ money(plSummary.revenue ?? 0) }}</p>
            </div>
            <div class="bg-amber-50 rounded-2xl border border-amber-200 p-4 shadow-sm">
              <p class="text-xs text-amber-600 font-semibold mb-1">Total HPP</p>
              <p class="text-lg font-black text-amber-700">Rp {{ money(plSummary.total_hpp ?? 0) }}</p>
              <p class="text-[10px] text-slate-400 mt-0.5">Biaya barang terjual</p>
            </div>
            <div class="bg-blue-50 rounded-2xl border border-blue-200 p-4 shadow-sm">
              <p class="text-xs text-blue-600 font-semibold mb-1">Laba Kotor</p>
              <p class="text-lg font-black text-blue-700">Rp {{ money(plSummary.gross_profit ?? 0) }}</p>
              <p class="text-[10px] text-slate-400 mt-0.5">Pendapatan - HPP</p>
            </div>
            <div class="bg-orange-50 rounded-2xl border border-orange-200 p-4 shadow-sm">
              <p class="text-xs text-orange-600 font-semibold mb-1">Beban Operasional</p>
              <p class="text-lg font-black text-orange-600">Rp {{ money(plSummary.opex ?? 0) }}</p>
              <p class="text-[10px] text-slate-400 mt-0.5">Kas Besar &amp; Kasir</p>
            </div>
            <div class="rounded-2xl border p-4 shadow-sm"
              :class="(plSummary.net_profit ?? 0) >= 0 ? 'bg-indigo-50 border-indigo-200' : 'bg-red-50 border-red-200'">
              <p class="text-xs font-semibold mb-1" :class="(plSummary.net_profit ?? 0) >= 0 ? 'text-indigo-600' : 'text-red-600'">
                Laba Bersih
              </p>
              <p class="text-lg font-black" :class="(plSummary.net_profit ?? 0) >= 0 ? 'text-indigo-700' : 'text-red-700'">
                Rp {{ money(Math.abs(plSummary.net_profit ?? 0)) }}
              </p>
              <p class="text-xs mt-0.5 font-bold" :class="(plSummary.net_profit ?? 0) >= 0 ? 'text-indigo-500' : 'text-red-500'">
                Margin: {{ (plSummary.net_margin_pct ?? 0).toFixed(1) }}%
              </p>
            </div>
          </div>
          <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-3">
            <h3 class="font-bold text-slate-800 text-sm">📊 Rincian Laba Rugi</h3>
            <div class="space-y-1.5">
              <div class="flex justify-between py-2 border-b border-slate-100">
                <span class="text-sm text-slate-600">Pendapatan Penjualan (Kotor)</span>
                <span class="font-bold text-slate-700">Rp {{ money(plSummary.revenue ?? 0) }}</span>
              </div>
              <div class="flex justify-between py-2 border-b border-slate-100">
                <span class="text-sm text-slate-600">Dikurangi: Diskon Penjualan</span>
                <span class="font-semibold text-red-500">- Rp {{ money(plSummary.discount ?? 0) }}</span>
              </div>
              <div class="flex justify-between py-2 border-b border-slate-100 bg-emerald-50/50 rounded px-2">
                <span class="text-sm font-semibold text-emerald-800">Pendapatan Penjualan Bersih</span>
                <span class="font-bold text-emerald-700">Rp {{ money(plSummary.net_revenue ?? 0) }}</span>
              </div>
              <div class="flex justify-between py-2 border-b border-slate-100">
                <span class="text-sm text-slate-600">Dikurangi: Harga Pokok Penjualan (HPP)</span>
                <span class="font-semibold text-red-500">- Rp {{ money(plSummary.total_hpp ?? 0) }}</span>
              </div>
              <div class="flex justify-between py-2 border-b border-slate-100 bg-blue-50/50 rounded px-2">
                <span class="text-sm font-semibold text-blue-800">Laba Kotor (Gross Profit)</span>
                <span class="font-bold text-blue-700">Rp {{ money(plSummary.gross_profit ?? 0) }}</span>
              </div>
              <div class="flex justify-between py-2 border-b border-slate-100">
                <span class="text-sm text-slate-600">Dikurangi: Beban Operasional (Kas Besar)</span>
                <span class="font-semibold text-red-500">- Rp {{ money(plSummary.opex_kas_besar ?? 0) }}</span>
              </div>
              <div class="flex justify-between py-2 border-b border-slate-100">
                <span class="text-sm text-slate-600">Dikurangi: Pengeluaran Kasir</span>
                <span class="font-semibold text-red-500">- Rp {{ money(plSummary.opex_kasir ?? 0) }}</span>
              </div>
              <div class="flex justify-between py-3 rounded-xl px-3 mt-2"
                :class="(plSummary.net_profit ?? 0) >= 0 ? 'bg-indigo-50' : 'bg-red-50'">
                <span class="text-base font-black text-slate-800">LABA BERSIH SEBENARNYA</span>
                <span class="text-lg font-black" :class="(plSummary.net_profit ?? 0) >= 0 ? 'text-indigo-700' : 'text-red-700'">
                  Rp {{ money(Math.abs(plSummary.net_profit ?? 0)) }}
                  <span class="text-xs font-semibold ml-1">({{ (plSummary.net_margin_pct ?? 0).toFixed(1) }}%)</span>
                </span>
              </div>
            </div>
            <p class="text-xs text-slate-400">ℹ️ Perhitungan laba rugi di atas dihitung secara akurat menggunakan Harga Beli (HPP) per produk.</p>
          </div>
          <!-- Daily -->
          <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100">
              <h3 class="font-bold text-slate-800 text-sm">Rincian Harian</h3>
            </div>
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead>
                  <tr class="bg-slate-50 text-xs text-slate-500 font-bold uppercase tracking-wide">
                    <th class="px-5 py-3 text-left">Tanggal</th>
                    <th class="px-5 py-3 text-right">Transaksi</th>
                    <th class="px-5 py-3 text-right">Diskon</th>
                    <th class="px-5 py-3 text-right">Pajak</th>
                    <th class="px-5 py-3 text-right">Pendapatan</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-if="plDaily.length === 0">
                    <td colspan="5" class="px-5 py-10 text-center text-slate-400">Tidak ada data</td>
                  </tr>
                  <tr v-for="row in plDaily" :key="row.date" class="hover:bg-slate-50">
                    <td class="px-5 py-3 font-semibold text-slate-700">{{ formatDate(row.date) }}</td>
                    <td class="px-5 py-3 text-right text-slate-500">{{ row.transactions }}</td>
                    <td class="px-5 py-3 text-right text-red-400">Rp {{ money(row.discount) }}</td>
                    <td class="px-5 py-3 text-right text-blue-400">Rp {{ money(row.tax) }}</td>
                    <td class="px-5 py-3 text-right font-bold text-emerald-600">Rp {{ money(row.revenue) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

      </template>
    </div>
  </AppShell>
</template>
