<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import AppShell from '../components/AppShell.vue'
import api from '../services/api'
import { pickErrorMessage, pickFieldErrors } from '../utils/laravelErrors'
import { useAuthStore } from '../stores/auth'

type Supplier = { id: number; name: string }
type Product = { id: number; name: string; buying_price: string; stock: number }

type PurchaseItemInput = {
  product_id: number | ''
  quantity: number
  buying_price: number
}

type PurchaseItem = {
  id: number
  product_name: string
  quantity: number
  buying_price: string
  line_total: string
  product?: { name: string } | null
}

type Purchase = {
  id: number
  purchase_number: string
  purchase_date: string
  sub_total: string
  discount_amount: string
  tax_amount: string
  total: string
  paid_amount: string
  payment_status: string
  payment_method: string
  notes: string | null
  supplier: Supplier | null
  user: { name: string } | null
  items: PurchaseItem[]
}

const loading = ref(false)
const saving = ref(false)
const authStore = useAuthStore()
const error = ref<string | null>(null)
const success = ref<string | null>(null)

// History filters
const purchases = ref<Purchase[]>([])
const meta = ref<any>(null)
const currentPage = ref(1)

const search = ref('')
const filterSupplierId = ref('')
const filterPaymentMethod = ref('')
const filterFrom = ref('')
const filterTo = ref('')

// Dropdowns data
const suppliers = ref<Supplier[]>([])
const products = ref<Product[]>([])

// Create Modal State
const createModalOpen = ref(false)
const detailModalOpen = ref(false)
const payModalOpen = ref(false)
const selectedPurchase = ref<Purchase | null>(null)

const payForm = ref({
  amount: 0,
  purchase_id: 0,
  purchase_number: '',
  remaining_debt: 0
})

const fieldErrors = ref<Record<string, string[]>>({})

// Form State
const form = ref({
  supplier_id: '' as number | '',
  purchase_date: new Date().toISOString().split('T')[0],
  payment_method: 'cash' as 'cash' | 'transfer' | 'debt',
  discount_amount: 0,
  tax_amount: 0,
  notes: '',
  items: [] as PurchaseItemInput[],
})

const money = (n: number | string) =>
  new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Math.round(Number(n)))

async function fetchSuppliers() {
  try {
    const { data } = await api.get('/suppliers')
    suppliers.value = data || []
  } catch (e) {
    console.error('Gagal memuat supplier:', e)
  }
}

async function fetchProducts() {
  try {
    const { data } = await api.get('/products', { params: { per_page: 500 } })
    products.value = data.data || []
  } catch (e) {
    console.error('Gagal memuat produk:', e)
  }
}

async function fetchPurchases(page = 1) {
  loading.value = true
  error.value = null
  currentPage.value = page
  try {
    const { data } = await api.get('/purchases', {
      params: {
        page,
        search: search.value.trim() || undefined,
        supplier_id: filterSupplierId.value || undefined,
        payment_method: filterPaymentMethod.value || undefined,
        from: filterFrom.value || undefined,
        to: filterTo.value || undefined,
      },
    })
    purchases.value = data.data || []
    meta.value = data
  } catch (e: any) {
    error.value = e?.response?.data?.message || e?.message || 'Gagal memuat data pembelian'
  } finally {
    loading.value = false
  }
}

const computedSubTotal = computed(() => {
  return form.value.items.reduce((sum, item) => {
    return sum + (item.quantity * item.buying_price)
  }, 0)
})

const computedTotal = computed(() => {
  return Math.max(0, computedSubTotal.value - form.value.discount_amount + form.value.tax_amount)
})

function openCreate() {
  if (!authStore.user?.store_id) {
    alert('Silakan pilih toko tertentu di menu sidebar terlebih dahulu untuk menambah data.')
    return
  }
  error.value = null
  success.value = null
  fieldErrors.value = {}
  form.value = {
    supplier_id: '',
    purchase_date: new Date().toISOString().split('T')[0],
    payment_method: 'cash',
    discount_amount: 0,
    tax_amount: 0,
    notes: '',
    items: [{ product_id: '', quantity: 1, buying_price: 0 }],
  }
  createModalOpen.value = true
}

function addProductRow() {
  form.value.items.push({ product_id: '', quantity: 1, buying_price: 0 })
}

function removeProductRow(index: number) {
  form.value.items.splice(index, 1)
  if (form.value.items.length === 0) {
    addProductRow()
  }
}

function handleProductChange(index: number) {
  const itemId = form.value.items[index].product_id
  if (itemId) {
    const prod = products.value.find((p) => p.id === itemId)
    if (prod) {
      form.value.items[index].buying_price = Number(prod.buying_price) || 0
    }
  }
}

async function savePurchase() {
  error.value = null
  fieldErrors.value = {}

  // Validasi sederhana
  const hasInvalidItems = form.value.items.some(it => !it.product_id || it.quantity <= 0)
  if (hasInvalidItems) {
    error.value = 'Harap lengkapi semua baris produk dan jumlah dengan benar.'
    return
  }

  saving.value = true
  const payload = {
    supplier_id: form.value.supplier_id || null,
    purchase_date: form.value.purchase_date,
    payment_method: form.value.payment_method,
    discount_amount: form.value.discount_amount,
    tax_amount: form.value.tax_amount,
    notes: form.value.notes.trim() || null,
    items: form.value.items.map(it => ({
      product_id: Number(it.product_id),
      quantity: Number(it.quantity),
      buying_price: Number(it.buying_price),
    })),
  }

  try {
    await api.post('/purchases', payload)
    createModalOpen.value = false
    success.value = 'Pembelian barang berhasil dicatat dan stok telah diperbarui!'
    fetchPurchases(1)
  } catch (e: any) {
    fieldErrors.value = pickFieldErrors(e)
    error.value = pickErrorMessage(e, 'Gagal menyimpan transaksi pembelian')
  } finally {
    saving.value = false
  }
}

function showDetail(purchase: Purchase) {
  selectedPurchase.value = purchase
  detailModalOpen.value = true
}

async function cancelPurchase(purchase: Purchase) {
  if (!confirm(`Batalkan transaksi ${purchase.purchase_number}? Aksi ini akan mengurangi stok barang kembali!`)) return
  error.value = null
  success.value = null
  try {
    const { data } = await api.delete(`/purchases/${purchase.id}`)
    success.value = data.message || 'Pembelian berhasil dibatalkan.'
    fetchPurchases(currentPage.value)
  } catch (e: any) {
    error.value = e?.response?.data?.message || e?.message || 'Gagal membatalkan pembelian'
  }
}

function openPayModal(p: Purchase) {
  error.value = null
  success.value = null
  const remaining = Number(p.total) - Number(p.paid_amount || 0)
  payForm.value = {
    amount: remaining,
    purchase_id: p.id,
    purchase_number: p.purchase_number,
    remaining_debt: remaining
  }
  payModalOpen.value = true
}

async function submitPayment() {
  if (payForm.value.amount <= 0 || payForm.value.amount > payForm.value.remaining_debt) {
    error.value = 'Nominal pembayaran tidak valid.'
    return
  }
  saving.value = true
  error.value = null
  success.value = null
  try {
    const { data } = await api.post(`/purchases/${payForm.value.purchase_id}/pay`, {
      amount: payForm.value.amount
    })
    success.value = data.message || 'Pembayaran cicilan berhasil dicatat.'
    payModalOpen.value = false
    fetchPurchases(currentPage.value)
  } catch (e: any) {
    error.value = e?.response?.data?.message || e?.message || 'Gagal mencatat pembayaran hutang.'
  } finally {
    saving.value = false
  }
}

watch([search, filterSupplierId, filterPaymentMethod, filterFrom, filterTo], () => {
  fetchPurchases(1)
})

onMounted(() => {
  fetchPurchases()
  fetchSuppliers()
  fetchProducts()
})

const getPaymentLabel = (method: string) => {
  switch (method) {
    case 'cash': return 'Tunai'
    case 'transfer': return 'Transfer'
    case 'debt': return 'Hutang / Tempo'
    default: return method
  }
}
</script>

<template>
  <AppShell>
    <div class="space-y-4">
      <!-- Header Section -->
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="text-2xl font-bold tracking-tight text-slate-900">Pembelian Barang (Restock)</h2>
          <p class="text-xs text-slate-500 font-medium">Catat pasokan barang masuk dari supplier & perbarui stok langsung</p>
        </div>
        <button
          type="button"
          class="rounded-xl bg-slate-900 px-4 py-2.5 text-xs font-bold text-white shadow-sm hover:bg-slate-800 flex items-center gap-1.5 transition-all"
          @click="openCreate"
        >
          ➕ Catat Pembelian Baru
        </button>
      </div>

      <!-- Success & Error Alerts -->
      <div v-if="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-xs font-semibold text-red-800 shadow-sm">
        {{ error }}
      </div>
      <div v-if="success" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs font-semibold text-emerald-800 shadow-sm">
        {{ success }}
      </div>

      <!-- Filters Panel -->
      <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-4">
        <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-5">
          <!-- Search -->
          <div class="md:col-span-1">
            <label class="mb-1 block text-[10px] font-bold text-slate-500 uppercase tracking-wide">Cari No / Barang</label>
            <input
              v-model="search"
              type="text"
              class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900 transition-all"
              placeholder="Nomor transaksi..."
            />
          </div>

          <!-- Supplier Filter -->
          <div>
            <label class="mb-1 block text-[10px] font-bold text-slate-500 uppercase tracking-wide">Filter Supplier</label>
            <select
              v-model="filterSupplierId"
              class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900 transition-all"
            >
              <option value="">Semua Supplier</option>
              <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
          </div>

          <!-- Payment Method Filter -->
          <div>
            <label class="mb-1 block text-[10px] font-bold text-slate-500 uppercase tracking-wide">Metode Bayar</label>
            <select
              v-model="filterPaymentMethod"
              class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900 transition-all"
            >
              <option value="">Semua Metode</option>
              <option value="cash">Tunai</option>
              <option value="transfer">Transfer</option>
              <option value="debt">Hutang / Tempo</option>
            </select>
          </div>

          <!-- From Date -->
          <div>
            <label class="mb-1 block text-[10px] font-bold text-slate-500 uppercase tracking-wide">Dari Tanggal</label>
            <input
              v-model="filterFrom"
              type="date"
              class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900 transition-all"
            />
          </div>

          <!-- To Date -->
          <div>
            <label class="mb-1 block text-[10px] font-bold text-slate-500 uppercase tracking-wide">Sampai Tanggal</label>
            <input
              v-model="filterTo"
              type="date"
              class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900 transition-all"
            />
          </div>
        </div>
      </div>

      <!-- History Table -->
      <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
        <div v-if="loading" class="py-12 text-center text-slate-400 text-xs font-medium">
          <div class="h-6 w-6 animate-spin rounded-full border-2 border-slate-900 border-t-transparent mx-auto mb-2"></div>
          Memuat data riwayat pembelian...
        </div>
        <div v-else-if="purchases.length === 0" class="py-12 text-center text-slate-400 text-xs font-medium">
          Tidak ada riwayat transaksi pembelian.
        </div>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-left border-collapse">
            <thead>
              <tr class="bg-slate-50 text-[10px] font-bold text-slate-500 uppercase border-b border-slate-100">
                <th class="px-5 py-3">Tanggal</th>
                <th class="px-5 py-3">No Pembelian</th>
                <th class="px-5 py-3">Supplier</th>
                <th class="px-5 py-3">Metode Bayar</th>
                <th class="px-5 py-3 text-right">Total</th>
                <th class="px-5 py-3 text-right">Sisa Hutang</th>
                <th class="px-5 py-3 text-center">Status</th>
                <th class="px-5 py-3 text-center">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-xs font-semibold text-slate-700">
              <tr v-for="p in purchases" :key="p.id" class="hover:bg-slate-50/50 transition-colors">
                <td class="px-5 py-3.5">{{ new Date(p.purchase_date).toLocaleDateString('id-ID', { dateStyle: 'medium' }) }}</td>
                <td class="px-5 py-3.5 font-mono text-slate-900">{{ p.purchase_number }}</td>
                <td class="px-5 py-3.5">{{ p.supplier?.name || '(Umum / Tanpa Supplier)' }}</td>
                <td class="px-5 py-3.5">{{ getPaymentLabel(p.payment_method) }}</td>
                <td class="px-5 py-3.5 text-right font-bold font-mono text-slate-900">Rp {{ money(p.total) }}</td>
                <td class="px-5 py-3.5 text-right font-bold font-mono text-slate-700">
                  <span v-if="p.payment_method === 'debt' && p.payment_status !== 'PAID'" class="text-red-600">
                    Rp {{ money(Number(p.total) - Number(p.paid_amount || 0)) }}
                  </span>
                  <span v-else class="text-slate-400">-</span>
                </td>
                <td class="px-5 py-3.5 text-center">
                  <span
                    class="inline-flex rounded-full px-2 py-0.5 text-[9px] font-extrabold uppercase border"
                    :class="p.payment_status === 'PAID' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : p.payment_status === 'PARTIAL' ? 'bg-blue-50 text-blue-700 border-blue-200' : 'bg-amber-50 text-amber-700 border-amber-200'"
                  >
                    {{ p.payment_status === 'PAID' ? 'Lunas' : p.payment_status === 'PARTIAL' ? 'Cicilan' : 'Belum Lunas' }}
                  </span>
                </td>
                <td class="px-5 py-3.5 text-center flex items-center justify-center gap-1.5">
                  <button
                    type="button"
                    class="text-blue-600 hover:text-blue-800 font-bold hover:underline"
                    @click="showDetail(p)"
                  >
                    Detail
                  </button>
                  <template v-if="p.payment_method === 'debt' && p.payment_status !== 'PAID'">
                    <span class="text-slate-300">|</span>
                    <button
                      type="button"
                      class="text-emerald-600 hover:text-emerald-800 font-bold hover:underline"
                      @click="openPayModal(p)"
                    >
                      Bayar
                    </button>
                  </template>
                  <span class="text-slate-300">|</span>
                  <button
                    type="button"
                    class="text-red-600 hover:text-red-800 font-bold hover:underline"
                    @click="cancelPurchase(p)"
                  >
                    Batalkan
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div v-if="meta && meta.last_page > 1" class="border-t border-slate-100 px-5 py-3 flex items-center justify-between bg-slate-50/50">
          <span class="text-[11px] text-slate-500 font-medium">Halaman {{ meta.current_page }} dari {{ meta.last_page }}</span>
          <div class="flex gap-1.5">
            <button
              :disabled="meta.current_page === 1"
              class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
              @click="fetchPurchases(meta.current_page - 1)"
            >
              Sebelumnya
            </button>
            <button
              :disabled="meta.current_page === meta.last_page"
              class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
              @click="fetchPurchases(meta.current_page + 1)"
            >
              Selanjutnya
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- CREATE MODAL -->
    <div
      v-if="createModalOpen"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      @click.self="createModalOpen = false"
    >
      <div class="bg-white rounded-2xl w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden shadow-2xl">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between shrink-0 bg-slate-50">
          <div>
            <h3 class="text-base font-bold text-slate-900">Catat Pembelian Produk (Restock)</h3>
            <p class="text-[10px] text-slate-500 font-medium">Isi detail pembelian barang dari supplier untuk memperbarui stok toko</p>
          </div>
          <button type="button" class="text-slate-400 hover:text-slate-600 text-lg" @click="createModalOpen = false">✕</button>
        </div>

        <div class="flex-1 overflow-y-auto p-6 space-y-4">
          <!-- Main Meta Fields -->
          <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-4">
            <div>
              <label class="mb-1 block text-[10px] font-bold text-slate-600 uppercase tracking-wide">Supplier / Pemasok</label>
              <select
                v-model="form.supplier_id"
                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold focus:border-slate-900 focus:outline-none"
              >
                <option value="">(Umum / Tanpa Supplier)</option>
                <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
              </select>
            </div>
            <div>
              <label class="mb-1 block text-[10px] font-bold text-slate-600 uppercase tracking-wide">Tanggal Pembelian</label>
              <input
                v-model="form.purchase_date"
                type="date"
                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold focus:border-slate-900 focus:outline-none"
              />
            </div>
            <div>
              <label class="mb-1 block text-[10px] font-bold text-slate-600 uppercase tracking-wide">Metode Pembayaran</label>
              <select
                v-model="form.payment_method"
                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold focus:border-slate-900 focus:outline-none"
              >
                <option value="cash">Tunai (Kas Besar Toko)</option>
                <option value="transfer">Transfer (Kas Besar Toko)</option>
                <option value="debt">Hutang / Tempo</option>
              </select>
            </div>
            <div>
              <label class="mb-1 block text-[10px] font-bold text-slate-600 uppercase tracking-wide">Keterangan / Notes</label>
              <input
                v-model="form.notes"
                type="text"
                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold focus:border-slate-900 focus:outline-none"
                placeholder="Catatan tambahan..."
              />
            </div>
          </div>

          <!-- Items Lines Title -->
          <div class="border-t border-slate-100 pt-4 flex items-center justify-between">
            <h4 class="text-xs font-bold text-slate-800 uppercase tracking-wider">Daftar Item Produk Barang</h4>
            <button
              type="button"
              class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-bold text-slate-700 hover:bg-slate-50 flex items-center gap-1 shadow-sm transition-colors"
              @click="addProductRow"
            >
              ➕ Tambah Baris Produk
            </button>
          </div>

          <!-- Items input grid -->
          <div class="space-y-2">
            <div
              v-for="(item, index) in form.items"
              :key="'row-' + index"
              class="grid gap-2 items-center grid-cols-[1fr_80px_130px_100px_40px]"
            >
              <!-- Product Dropdown -->
              <div>
                <select
                  v-model="item.product_id"
                  class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold focus:border-slate-900 focus:outline-none"
                  @change="handleProductChange(index)"
                >
                  <option value="" disabled>-- Pilih Produk Barang --</option>
                  <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }} (Stok: {{ p.stock }})</option>
                </select>
              </div>

              <!-- Quantity -->
              <div>
                <input
                  v-model.number="item.quantity"
                  type="number"
                  min="1"
                  class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold focus:border-slate-900 focus:outline-none text-right font-mono"
                  placeholder="Qty"
                />
              </div>

              <!-- Buying Price -->
              <div>
                <div class="relative">
                  <span class="absolute left-3 top-2 text-[10px] font-bold text-slate-400">Rp</span>
                  <input
                    v-model.number="item.buying_price"
                    type="number"
                    min="0"
                    class="w-full rounded-xl border border-slate-300 bg-white pl-8 pr-3 py-2 text-xs font-semibold focus:border-slate-900 focus:outline-none text-right font-mono"
                    placeholder="Harga Beli"
                  />
                </div>
              </div>

              <!-- Subtotal Line -->
              <div class="text-right font-bold text-slate-800 font-mono text-xs pr-2">
                Rp {{ money(item.quantity * item.buying_price) }}
              </div>

              <!-- Action Delete -->
              <button
                type="button"
                class="h-8 w-8 rounded-lg border border-red-200 text-red-600 hover:bg-red-50 flex items-center justify-center font-bold text-sm"
                @click="removeProductRow(index)"
              >
                ✕
              </button>
            </div>
          </div>

          <!-- Summary Calculations -->
          <div class="border-t border-slate-100 pt-4 flex flex-col md:flex-row gap-4 justify-between items-start">
            <div class="text-[11px] text-amber-800 bg-amber-50 px-4 py-2 rounded-xl border border-amber-100 max-w-md font-semibold">
              ℹ️ Mencatat pembelian akan langsung menambah stok produk di sistem dan mencatat pengeluaran di Kas Besar Toko secara otomatis.
            </div>

            <div class="w-full md:w-80 space-y-2 text-xs">
              <div class="flex justify-between font-semibold text-slate-600">
                <span>Subtotal</span>
                <span class="font-mono text-slate-800">Rp {{ money(computedSubTotal) }}</span>
              </div>
              <div class="flex justify-between items-center font-semibold text-slate-600 gap-3">
                <span>Diskon Pembelian</span>
                <input
                  v-model.number="form.discount_amount"
                  type="number"
                  min="0"
                  class="w-28 rounded border border-slate-300 px-2 py-0.5 text-right font-mono text-xs"
                />
              </div>
              <div class="flex justify-between items-center font-semibold text-slate-600 gap-3">
                <span>Pajak (PPN/Lainnya)</span>
                <input
                  v-model.number="form.tax_amount"
                  type="number"
                  min="0"
                  class="w-28 rounded border border-slate-300 px-2 py-0.5 text-right font-mono text-xs"
                />
              </div>
              <div class="border-t border-slate-200 my-2"></div>
              <div class="flex justify-between font-extrabold text-sm text-slate-900">
                <span>TOTAL HARGA</span>
                <span class="font-mono text-blue-600">Rp {{ money(computedTotal) }}</span>
              </div>
            </div>
          </div>
        </div>

        <div class="px-6 py-4 border-t border-slate-100 bg-slate-50 flex gap-2 justify-end shrink-0">
          <button
            type="button"
            class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50"
            @click="createModalOpen = false"
          >
            Batal
          </button>
          <button
            type="button"
            class="rounded-xl bg-slate-900 px-5 py-2.5 text-xs font-bold text-white hover:bg-slate-800 disabled:opacity-50"
            :disabled="saving"
            @click="savePurchase"
          >
            {{ saving ? 'Menyimpan...' : 'Simpan Pembelian' }}
          </button>
        </div>
      </div>
    </div>

    <!-- DETAIL MODAL -->
    <div
      v-if="detailModalOpen && selectedPurchase"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      @click.self="detailModalOpen = false"
    >
      <div class="bg-white rounded-2xl w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden shadow-2xl">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between shrink-0 bg-slate-50">
          <div>
            <h3 class="text-base font-bold text-slate-900">Detail Transaksi Pembelian</h3>
            <p class="text-[10px] text-slate-500 font-medium font-mono">{{ selectedPurchase.purchase_number }}</p>
          </div>
          <button type="button" class="text-slate-400 hover:text-slate-600 text-lg" @click="detailModalOpen = false">✕</button>
        </div>

        <div class="flex-1 overflow-y-auto p-6 space-y-4 font-sans">
          <!-- Metadata grid -->
          <div class="grid grid-cols-2 gap-4 text-xs font-semibold text-slate-600">
            <div>
              <p class="text-[10px] text-slate-400 uppercase tracking-wide">Nomor Transaksi</p>
              <p class="text-slate-900 font-mono font-bold">{{ selectedPurchase.purchase_number }}</p>
            </div>
            <div>
              <p class="text-[10px] text-slate-400 uppercase tracking-wide">Tanggal Transaksi</p>
              <p class="text-slate-900">{{ new Date(selectedPurchase.purchase_date).toLocaleDateString('id-ID', { dateStyle: 'long' }) }}</p>
            </div>
            <div>
              <p class="text-[10px] text-slate-400 uppercase tracking-wide">Supplier / Pemasok</p>
              <p class="text-slate-900">{{ selectedPurchase.supplier?.name || '(Umum / Tanpa Supplier)' }}</p>
            </div>
            <div>
              <p class="text-[10px] text-slate-400 uppercase tracking-wide">Operator / Cashier</p>
              <p class="text-slate-900">{{ selectedPurchase.user?.name || '-' }}</p>
            </div>
            <div>
              <p class="text-[10px] text-slate-400 uppercase tracking-wide">Metode Pembayaran</p>
              <p class="text-slate-900 font-bold uppercase">{{ getPaymentLabel(selectedPurchase.payment_method) }}</p>
            </div>
            <div>
              <p class="text-[10px] text-slate-400 uppercase tracking-wide">Status Pembayaran</p>
              <span
                class="inline-flex rounded-full px-2 py-0.5 text-[9px] font-extrabold uppercase border"
                :class="selectedPurchase.payment_status === 'PAID' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : selectedPurchase.payment_status === 'PARTIAL' ? 'bg-blue-50 text-blue-700 border-blue-200' : 'bg-amber-50 text-amber-700 border-amber-200'"
              >
                {{ selectedPurchase.payment_status === 'PAID' ? 'Lunas' : selectedPurchase.payment_status === 'PARTIAL' ? 'Cicilan' : 'Belum Lunas' }}
              </span>
            </div>
            <div v-if="selectedPurchase.payment_method === 'debt'">
              <p class="text-[10px] text-slate-400 uppercase tracking-wide">Jumlah Terbayar</p>
              <p class="text-emerald-600 font-bold font-mono">Rp {{ money(selectedPurchase.paid_amount || 0) }}</p>
            </div>
            <div v-if="selectedPurchase.payment_method === 'debt'">
              <p class="text-[10px] text-slate-400 uppercase tracking-wide">Sisa Hutang</p>
              <p class="text-red-600 font-bold font-mono">Rp {{ money(Number(selectedPurchase.total) - Number(selectedPurchase.paid_amount || 0)) }}</p>
            </div>
          </div>

          <div class="border-t border-slate-100 pt-4">
            <h4 class="text-xs font-bold text-slate-800 uppercase tracking-wider mb-2">Item Produk Dibeli</h4>
            <div class="border border-slate-200 bg-slate-50/50 rounded-2xl p-4 space-y-3 shadow-inner">
              <div v-for="it in selectedPurchase.items" :key="it.id" class="flex justify-between items-start text-xs font-semibold">
                <div class="flex-1 min-w-0">
                  <div class="text-slate-900 truncate">{{ it.product_name }}</div>
                  <div class="text-slate-400 font-mono text-[10px] mt-0.5">{{ it.quantity }} x Rp {{ money(it.buying_price) }}</div>
                </div>
                <span class="font-mono text-slate-800 pr-1">Rp {{ money(it.line_total) }}</span>
              </div>

              <div class="border-t border-dashed border-slate-300 my-3"></div>

              <div class="space-y-1.5 text-xs font-semibold text-slate-500">
                <div class="flex justify-between">
                  <span>Subtotal</span>
                  <span class="font-mono text-slate-800">Rp {{ money(selectedPurchase.sub_total) }}</span>
                </div>
                <div v-if="Number(selectedPurchase.discount_amount) > 0" class="flex justify-between text-red-600">
                  <span>Diskon</span>
                  <span class="font-mono">-Rp {{ money(selectedPurchase.discount_amount) }}</span>
                </div>
                <div v-if="Number(selectedPurchase.tax_amount) > 0" class="flex justify-between">
                  <span>Pajak</span>
                  <span class="font-mono text-slate-800">Rp {{ money(selectedPurchase.tax_amount) }}</span>
                </div>
                <div class="border-t border-slate-200/50 my-1"></div>
                <div class="flex justify-between text-sm font-extrabold text-slate-900">
                  <span>Grand Total</span>
                  <span class="font-mono text-blue-600">Rp {{ money(selectedPurchase.total) }}</span>
                </div>
              </div>
            </div>
          </div>

          <div v-if="selectedPurchase.notes" class="text-xs">
            <p class="text-[10px] text-slate-400 uppercase tracking-wide mb-0.5">Catatan Tambahan</p>
            <p class="text-slate-700 bg-slate-50 rounded-xl p-3 border border-slate-200 font-medium">{{ selectedPurchase.notes }}</p>
          </div>
        </div>

        <div class="px-6 py-4 border-t border-slate-100 bg-slate-50 flex justify-end shrink-0">
          <button
            type="button"
            class="rounded-xl bg-slate-900 px-5 py-2.5 text-xs font-bold text-white hover:bg-slate-800"
            @click="detailModalOpen = false"
          >
            Tutup
          </button>
        </div>
      </div>
    </div>

    <!-- PAY MODAL (PELUNASAN HUTANG) -->
    <div
      v-if="payModalOpen"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      @click.self="payModalOpen = false"
    >
      <div class="bg-white rounded-2xl w-full max-w-md overflow-hidden shadow-2xl flex flex-col">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50 shrink-0">
          <div>
            <h3 class="text-base font-bold text-slate-900">Pelunasan Hutang Pembelian</h3>
            <p class="text-[10px] text-slate-500 font-mono mt-0.5">{{ payForm.purchase_number }}</p>
          </div>
          <button type="button" class="text-slate-400 hover:text-slate-600 text-lg" @click="payModalOpen = false">✕</button>
        </div>

        <div class="p-6 space-y-4 text-xs font-semibold text-slate-600">
          <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-3.5 flex flex-col gap-1 shadow-sm">
            <span class="text-[10px] text-amber-600 font-bold uppercase tracking-wider">Sisa Hutang Saat Ini</span>
            <span class="text-xl font-black font-mono">Rp {{ money(payForm.remaining_debt) }}</span>
          </div>

          <div class="space-y-1.5">
            <label class="block text-slate-700 font-bold">Nominal Pembayaran (Rp)</label>
            <div class="relative flex items-center bg-white border border-slate-300 rounded-xl px-3 py-2.5 focus-within:ring-2 focus-within:ring-emerald-500 focus-within:border-emerald-500 transition-all shadow-sm">
              <span class="text-slate-400 font-bold pr-2 border-r border-slate-200">Rp</span>
              <input
                v-model.number="payForm.amount"
                type="number"
                min="1"
                :max="payForm.remaining_debt"
                class="flex-1 outline-none border-0 text-slate-800 font-bold font-mono pl-2 text-sm bg-transparent"
                placeholder="Masukkan jumlah bayar..."
              />
              <button
                type="button"
                class="ml-2 px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-[10px] font-black rounded-lg transition-colors shrink-0 uppercase"
                @click="payForm.amount = payForm.remaining_debt"
              >
                Lunas
              </button>
            </div>
            <p class="text-[10px] text-slate-400">Pembayaran akan langsung memotong saldo Kas Besar Toko.</p>
          </div>
        </div>

        <div class="px-6 py-4 border-t border-slate-100 bg-slate-50 flex gap-2 justify-end shrink-0">
          <button
            type="button"
            class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 transition-colors"
            @click="payModalOpen = false"
          >
            Batal
          </button>
          <button
            type="button"
            class="rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white px-5 py-2.5 text-xs font-bold shadow-sm transition-colors disabled:opacity-50"
            :disabled="saving || payForm.amount <= 0 || payForm.amount > payForm.remaining_debt"
            @click="submitPayment"
          >
            {{ saving ? 'Menyimpan...' : 'Simpan Pembayaran' }}
          </button>
        </div>
      </div>
    </div>
  </AppShell>
</template>
