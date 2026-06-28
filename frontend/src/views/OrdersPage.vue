<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import AppShell from '../components/AppShell.vue'
import api from '../services/api'

const orders = ref<any[]>([])
const loading = ref(false)
const search = ref('')
const fromDate = ref('')
const toDate = ref('')
const currentPage = ref(1)
const lastPage = ref(1)
const totalOrders = ref(0)
const selectedOrder = ref<any>(null)
const showMobileReceipt = ref(false)

const getImageUrl = (path: string | null) => {
  if (!path) return ''
  if (path.startsWith('http')) return path
  const apiBase = api.defaults.baseURL || 'http://127.0.0.1:8000/api'
  const host = apiBase.replace(/\/api$/, '')
  return `${host}/storage/${path}`
}

const money = (n: number) =>
  new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Math.round(n))

const formatDate = (dateStr: string) => {
  if (!dateStr) return '-'
  return new Date(dateStr).toLocaleString('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short'
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
    case 'cash':
      return 'bg-emerald-50 text-emerald-700 border-emerald-200'
    case 'qris':
      return 'bg-purple-50 text-purple-700 border-purple-200'
    case 'transfer':
      return 'bg-amber-50 text-amber-700 border-amber-200'
    case 'card':
      return 'bg-blue-50 text-blue-700 border-blue-200'
    default:
      return 'bg-slate-50 text-slate-700 border-slate-200'
  }
}

const fetchOrders = async (page = 1) => {
  loading.value = true
  try {
    const params: any = {
      page,
      search: search.value || undefined,
      from: fromDate.value || undefined,
      to: toDate.value || undefined,
    }
    const { data } = await api.get('/transactions', { params })
    orders.value = data.data || []
    currentPage.value = data.current_page || 1
    lastPage.value = data.last_page || 1
    totalOrders.value = data.total || 0
    
    // Auto select first order if on desktop and nothing selected yet
    if (orders.value.length > 0) {
      const stillExists = orders.value.find(o => o.id === selectedOrder.value?.id)
      if (stillExists) {
        selectedOrder.value = stillExists
      } else {
        selectedOrder.value = orders.value[0]
      }
    } else {
      selectedOrder.value = null
    }
  } catch (e) {
    console.error('Gagal mengambil data transaksi:', e)
  } finally {
    loading.value = false
  }
}

const selectOrder = (order: any) => {
  selectedOrder.value = order
  showMobileReceipt.value = true
}

const printThermal = (trx: any) => {
  if (!trx) return

  const store = trx.store || {}
  const invoiceNumber = trx.invoice_number
  const formattedDate = new Date(trx.created_at).toLocaleString('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short'
  })
  const cashierName = trx.user?.name || '-'
  const paymentMethodLabel = getPaymentLabel(trx.payment_method)
  
  const subTotal = Number(trx.sub_total)
  const discountAmount = Number(trx.discount_amount)
  const taxAmount = Number(trx.tax_amount)
  const total = Number(trx.total)
  const paidAmount = Number(trx.paid_amount)
  const changeAmount = Number(trx.change_amount)

  // Check if store requires RawBT Android Print Mode
  if (store.print_method === 'rawbt') {
    const line = '--------------------------------'
    let text = ''
    text += `${(store.name || 'TOKO').toUpperCase()}\n`
    if (store.address) text += `${store.address}\n`
    if (store.phone) text += `Telp: ${store.phone}\n`
    text += `${line}\n`
    text += `No   : ${invoiceNumber}\n`
    text += `Tgl  : ${formattedDate}\n`
    text += `Kasir: ${cashierName}\n`
    text += `Bayar: ${paymentMethodLabel}\n`
    text += `${line}\n`

    trx.items.forEach((it: any) => {
      const name = it.product_name || (it.product?.name ?? '')
      const qty = it.quantity
      const price = Number(it.price)
      const lineTotal = Number(it.line_total)
      
      text += `${name}\n`
      const qtyPriceStr = `  ${qty} x Rp ${money(price)}`
      const totalStr = `Rp ${money(lineTotal)}`
      const spacesCount = Math.max(1, 32 - qtyPriceStr.length - totalStr.length)
      text += qtyPriceStr + ' '.repeat(spacesCount) + totalStr + '\n'
    })

    text += `${line}\n`
    
    const addRow = (label: string, val: number) => {
      const valStr = `Rp ${money(val)}`
      const spaces = Math.max(1, 32 - label.length - valStr.length)
      return label + ' '.repeat(spaces) + valStr + '\n'
    }

    text += addRow('Subtotal', subTotal)
    if (discountAmount > 0) text += addRow('Diskon', -discountAmount)
    if (taxAmount > 0) text += addRow('Pajak', taxAmount)
    text += `${line}\n`
    text += addRow('TOTAL', total)
    text += addRow('Bayar', paidAmount)
    text += addRow('Kembali', changeAmount)
    text += `${line}\n`
    text += `${store.receipt_footer || 'Terima kasih atas kunjungan Anda'}\n\n\n`

    const intentUrl = `intent:#Intent;action=ru.a402d.rawbtprinter.action.PRINT;category=android.intent.category.DEFAULT;type=text/plain;S.text=${encodeURIComponent(text)};end;`
    window.location.href = intentUrl
    return
  }
  
  // Format items list for IFrame browser printing
  const itemsHtml = trx.items.map((it: any) => {
    const name = it.product_name || (it.product?.name ?? '')
    const qty = it.quantity
    const price = Number(it.price)
    const lineTotal = Number(it.line_total)
    return `
      <div style="margin-bottom: 4px; font-size: 11px;">
        <div style="word-break: break-all;">${name}</div>
        <div style="display: flex; justify-content: space-between;">
          <span>${qty} x Rp ${money(price)}</span>
          <span>Rp ${money(lineTotal)}</span>
        </div>
      </div>
    `
  }).join('')
  
  const discountRow = discountAmount > 0 
    ? `<div style="display: flex; justify-content: space-between;"><span>Diskon</span><span>-Rp ${money(discountAmount)}</span></div>` 
    : ''
  const taxRow = taxAmount > 0 
    ? `<div style="display: flex; justify-content: space-between;"><span>Pajak</span><span>Rp ${money(taxAmount)}</span></div>` 
    : ''

  const receiptHtml = `
    <div style="text-align: center; font-weight: bold; font-size: 14px; margin-bottom: 2px; text-transform: uppercase;">
      ${store.name || 'TOKO'}
    </div>
    ${store.address ? `<div style="text-align: center; font-size: 10px; margin-bottom: 2px; line-height: 1.2;">${store.address}</div>` : ''}
    ${store.phone ? `<div style="text-align: center; font-size: 10px; margin-bottom: 4px;">Telp: ${store.phone}</div>` : ''}
    <div style="border-top: 1px dashed #000; margin: 6px 0;"></div>
    <div style="font-size: 10px; line-height: 1.2;">
      <div style="display: flex; justify-content: space-between;"><span>No: ${invoiceNumber}</span></div>
      <div style="display: flex; justify-content: space-between;"><span>Tgl: ${formattedDate}</span></div>
      <div style="display: flex; justify-content: space-between;"><span>Kasir: ${cashierName}</span></div>
      <div style="display: flex; justify-content: space-between;"><span>Metode: ${paymentMethodLabel}</span></div>
    </div>
    <div style="border-top: 1px dashed #000; margin: 6px 0;"></div>
    <div>
      ${itemsHtml}
    </div>
    <div style="border-top: 1px dashed #000; margin: 6px 0;"></div>
    <div style="font-size: 10px; line-height: 1.2;">
      <div style="display: flex; justify-content: space-between;"><span>Subtotal</span><span>Rp ${money(subTotal)}</span></div>
      ${discountRow}
      ${taxRow}
      <div style="border-top: 1px dashed #000; margin: 4px 0;"></div>
      <div style="display: flex; justify-content: space-between; font-weight: bold; font-size: 11px;"><span>TOTAL</span><span>Rp ${money(total)}</span></div>
      <div style="display: flex; justify-content: space-between;"><span>Dibayar</span><span>Rp ${money(paidAmount)}</span></div>
      <div style="display: flex; justify-content: space-between; font-weight: bold;"><span>Kembalian</span><span>Rp ${money(changeAmount)}</span></div>
    </div>
    <div style="border-top: 1px dashed #000; margin: 6px 0;"></div>
    <div style="text-align: center; font-size: 10px; white-space: pre-wrap; margin-top: 6px; line-height: 1.3;">
      ${store.receipt_footer || 'Terima kasih atas kunjungan Anda'}
    </div>
  `

  // Create temporary iframe for printing
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
        <title>Invoice ${invoiceNumber}</title>
        <style>
          @page {
            size: auto;
            margin: 0mm;
          }
          body {
            font-family: 'Courier New', Courier, monospace;
            width: 74mm;
            margin: 0;
            padding: 8px;
            box-sizing: border-box;
            background-color: #ffffff;
            color: #000000;
          }
          * {
            box-sizing: border-box;
          }
        </style>
      </head>
      <body>
        ${receiptHtml}
      </body>
    </html>
  `)
  doc.close()

  // Wait for the iframe content to load, then print and clean up
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

const printReceipt = () => {
  if (!selectedOrder.value) return
  printThermal(selectedOrder.value)
}

const clearFilters = () => {
  search.value = ''
  fromDate.value = ''
  toDate.value = ''
  fetchOrders(1)
}

watch([fromDate, toDate], () => {
  fetchOrders(1)
})

onMounted(() => {
  fetchOrders(1)
})
</script>

<template>
  <AppShell>
    <div class="space-y-4">
      <!-- Title & Header -->
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="text-2xl font-bold tracking-tight text-slate-900">Riwayat Transaksi</h2>
          <p class="text-xs text-slate-500 font-medium">Cari, filter, dan cetak ulang struk penjualan toko</p>
        </div>
      </div>

      <!-- Advanced Filter Panel -->
      <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm space-y-3">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
          <!-- Text Search -->
          <div class="relative">
            <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
              </svg>
            </span>
            <input
              v-model="search"
              type="text"
              class="w-full rounded-xl border border-slate-300 bg-white pl-9 pr-3 py-2.5 text-sm placeholder-slate-400 focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all font-medium"
              placeholder="Cari Invoice, Produk, Kasir..."
              @keyup.enter="fetchOrders(1)"
            />
          </div>

          <!-- Date From -->
          <div class="flex items-center gap-2">
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider shrink-0">Dari</span>
            <input
              v-model="fromDate"
              type="date"
              class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all font-medium"
            />
          </div>

          <!-- Date To -->
          <div class="flex items-center gap-2">
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider shrink-0">Sampai</span>
            <input
              v-model="toDate"
              type="date"
              class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all font-medium"
            />
          </div>

          <!-- Filter Actions -->
          <div class="flex gap-2">
            <button
              type="button"
              class="flex-1 rounded-xl bg-slate-900 text-white text-sm font-bold py-2.5 hover:bg-slate-800 transition-colors"
              @click="fetchOrders(1)"
            >
              Terapkan
            </button>
            <button
              type="button"
              class="rounded-xl border border-slate-200 text-slate-700 bg-slate-50 text-sm font-bold px-3 py-2.5 hover:bg-slate-100 transition-colors"
              title="Reset Filter"
              @click="clearFilters"
            >
              Reset
            </button>
          </div>
        </div>
      </div>

      <!-- Main Columns Grid -->
      <div class="grid gap-4 lg:grid-cols-[420px_1fr] items-start">
        
        <!-- Left: Transaction Cards List -->
        <div class="space-y-3">
          <!-- Card Header Info -->
          <div class="flex items-center justify-between text-xs text-slate-500 font-semibold px-1">
            <span>Daftar Transaksi ({{ totalOrders }})</span>
            <span v-if="orders.length">Hal. {{ currentPage }} / {{ lastPage }}</span>
          </div>

          <!-- Loading skeletons -->
          <div v-if="loading && !orders.length" class="space-y-3">
            <div v-for="i in 5" :key="i" class="animate-pulse bg-white p-4 rounded-2xl border border-slate-200/80 flex items-center justify-between">
              <div class="space-y-2 flex-1">
                <div class="h-4 bg-slate-200 rounded w-1/2"></div>
                <div class="h-3 bg-slate-200 rounded w-1/3"></div>
              </div>
              <div class="h-6 bg-slate-200 rounded w-20"></div>
            </div>
          </div>

          <!-- Empty State -->
          <div v-else-if="!orders.length" class="bg-white rounded-2xl border border-slate-200 p-12 text-center shadow-sm">
            <div class="h-12 w-12 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-3 text-slate-400">
              📁
            </div>
            <p class="font-bold text-slate-800 text-sm">Tidak ada transaksi</p>
            <p class="text-slate-400 text-xs mt-1">Coba sesuaikan kata kunci atau filter tanggal Anda.</p>
          </div>

          <!-- Transaction list -->
          <div v-else class="space-y-2.5 max-h-[calc(100vh-290px)] overflow-y-auto pr-1">
            <div
              v-for="o in orders"
              :key="o.id"
              class="bg-white p-3.5 rounded-2xl border transition-all cursor-pointer select-none active:scale-[0.99] flex items-center justify-between gap-4 shadow-sm"
              :class="selectedOrder?.id === o.id ? 'border-slate-900 ring-2 ring-slate-900/10 bg-slate-50/50' : 'border-slate-200 hover:border-slate-300'"
              @click="selectOrder(o)"
            >
              <div class="space-y-1.5 min-w-0">
                <div class="flex items-center gap-2">
                  <span class="font-bold text-slate-900 text-sm truncate font-mono">{{ o.invoice_number }}</span>
                </div>
                <div class="flex items-center gap-2 text-xs font-semibold text-slate-500">
                  <span>{{ formatDate(o.created_at) }}</span>
                  <span>•</span>
                  <span class="truncate">Kasir: {{ o.user?.name || '-' }}</span>
                </div>
                <div class="flex items-center gap-1.5 flex-wrap">
                  <span class="px-2 py-0.5 rounded-full border text-[10px] font-extrabold uppercase tracking-wider" :class="getPaymentBadgeClass(o.payment_method)">
                    {{ getPaymentLabel(o.payment_method) }}
                  </span>
                  <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 text-[10px] font-bold">
                    {{ o.items?.length || 0 }} item
                  </span>
                </div>
              </div>

              <!-- Price Info -->
              <div class="text-right shrink-0">
                <span class="block font-extrabold text-slate-900 text-base font-mono">Rp {{ money(Number(o.total)) }}</span>
              </div>
            </div>
          </div>

          <!-- Pagination Bar -->
          <div v-if="orders.length && lastPage > 1" class="bg-white p-3 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
            <button
              type="button"
              class="px-3.5 py-1.5 rounded-xl border border-slate-200 text-slate-700 bg-white hover:bg-slate-50 disabled:opacity-40 text-xs font-bold transition-colors"
              :disabled="currentPage === 1"
              @click="fetchOrders(currentPage - 1)"
            >
              Sebelumnya
            </button>
            <span class="text-xs font-semibold text-slate-500 font-mono">
              {{ currentPage }} / {{ lastPage }}
            </span>
            <button
              type="button"
              class="px-3.5 py-1.5 rounded-xl border border-slate-200 text-slate-700 bg-white hover:bg-slate-50 disabled:opacity-40 text-xs font-bold transition-colors"
              :disabled="currentPage === lastPage"
              @click="fetchOrders(currentPage + 1)"
            >
              Selanjutnya
            </button>
          </div>
        </div>

        <!-- Right: Desktop Receipt Details View -->
        <div class="hidden lg:block">
          <!-- Empty View -->
          <div v-if="!selectedOrder" class="bg-white rounded-2xl border border-slate-200 p-16 text-center shadow-sm sticky top-0">
            <div class="text-5xl mb-4">🧾</div>
            <p class="font-extrabold text-slate-800">Detail Struk Transaksi</p>
            <p class="text-slate-400 text-xs mt-1">Pilih transaksi dari daftar di sebelah kiri untuk melihat struk belanja detail.</p>
          </div>

          <!-- Details View -->
          <div v-else class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm sticky top-0 max-h-[calc(100vh-200px)] flex flex-col overflow-hidden">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4 mb-4">
              <div>
                <h3 class="font-extrabold text-slate-800 text-lg">Struk Transaksi</h3>
                <span class="text-xs text-slate-400 font-medium">Rincian invoice penjualan</span>
              </div>
              <button
                type="button"
                class="rounded-xl bg-slate-900 px-4 py-2 text-xs font-bold text-white hover:bg-slate-800 flex items-center gap-1.5 shadow-sm transition-all"
                @click="printReceipt"
              >
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                </svg>
                Cetak Struk
              </button>
            </div>

            <!-- Receipt layout scrolling wrapper -->
            <div class="flex-1 overflow-y-auto space-y-4 pr-1 font-sans">
              <!-- Virtual Thermal Receipt Card -->
              <div class="border border-slate-200 bg-slate-50/50 rounded-2xl p-5 shadow-inner">
                <!-- Receipt Header -->
                <div class="text-center space-y-1">
                  <h4 class="font-extrabold text-slate-950 text-base leading-tight uppercase">{{ selectedOrder.store?.name || 'Toko Nessa POS' }}</h4>
                  <p v-if="selectedOrder.store?.address" class="text-xs text-slate-600 max-w-[280px] mx-auto leading-relaxed">{{ selectedOrder.store.address }}</p>
                  <p v-if="selectedOrder.store?.phone" class="text-[11px] text-slate-500">Telp: {{ selectedOrder.store.phone }}</p>
                </div>

                <div class="border-t border-dashed border-slate-300 my-4"></div>

                <!-- Transaction Meta -->
                <div class="grid grid-cols-2 gap-y-2 text-xs text-slate-600 font-medium">
                  <div>No Invoice:</div>
                  <div class="text-right font-bold text-slate-800 font-mono">{{ selectedOrder.invoice_number }}</div>

                  <div>Waktu:</div>
                  <div class="text-right text-slate-800">{{ formatDate(selectedOrder.created_at) }}</div>

                  <div>Kasir:</div>
                  <div class="text-right text-slate-800">{{ selectedOrder.user?.name || '-' }}</div>

                  <div>Pembayaran:</div>
                  <div class="text-right text-slate-800 font-bold uppercase">{{ getPaymentLabel(selectedOrder.payment_method) }}</div>
                </div>

                <div class="border-t border-dashed border-slate-300 my-4"></div>

                <!-- Items List -->
                <div class="space-y-3">
                  <div v-for="it in selectedOrder.items" :key="it.id" class="flex gap-3 items-start">
                    <!-- Product Image Thumbnail -->
                    <div class="h-9 w-9 bg-white border border-slate-200 rounded-lg overflow-hidden flex items-center justify-center shrink-0">
                      <img
                        v-if="it.product?.image"
                        :src="getImageUrl(it.product.image)"
                        class="h-full w-full object-cover"
                        alt="Product Image"
                      />
                      <span v-else class="text-base">🍕</span>
                    </div>

                    <!-- Item info -->
                    <div class="flex-1 min-w-0 text-xs">
                      <div class="font-bold text-slate-900 leading-tight truncate">{{ it.product_name }}</div>
                      <div class="text-slate-500 font-medium mt-0.5 font-mono">
                        {{ it.quantity }} x Rp {{ money(Number(it.price)) }}
                      </div>
                    </div>

                    <!-- Line Total -->
                    <span class="text-xs font-bold text-slate-800 font-mono mt-0.5">
                      Rp {{ money(Number(it.line_total)) }}
                    </span>
                  </div>
                </div>

                <div class="border-t border-dashed border-slate-300 my-4"></div>

                <!-- Summary Breakdown -->
                <div class="space-y-2 text-xs text-slate-600 font-medium">
                  <div class="flex justify-between">
                    <span>Subtotal</span>
                    <span class="font-mono text-slate-800">Rp {{ money(Number(selectedOrder.sub_total)) }}</span>
                  </div>
                  <div v-if="Number(selectedOrder.discount_amount) > 0" class="flex justify-between text-red-600">
                    <span>Diskon</span>
                    <span class="font-mono font-bold">-Rp {{ money(Number(selectedOrder.discount_amount)) }}</span>
                  </div>
                  <div class="flex justify-between">
                    <span>Pajak</span>
                    <span class="font-mono text-slate-800">Rp {{ money(Number(selectedOrder.tax_amount)) }}</span>
                  </div>
                  
                  <div class="border-t border-slate-200/50 my-1"></div>

                  <div class="flex justify-between text-sm font-extrabold text-slate-900 py-1">
                    <span>Total Transaksi</span>
                    <span class="font-mono text-blue-600">Rp {{ money(Number(selectedOrder.total)) }}</span>
                  </div>
                  <div class="flex justify-between">
                    <span>Dibayar</span>
                    <span class="font-mono text-slate-800">Rp {{ money(Number(selectedOrder.paid_amount)) }}</span>
                  </div>
                  <div class="flex justify-between font-bold text-emerald-700">
                    <span>Kembalian</span>
                    <span class="font-mono">Rp {{ money(Number(selectedOrder.change_amount)) }}</span>
                  </div>
                </div>

                <div class="border-t border-dashed border-slate-300 my-4"></div>
                
                <p class="text-center text-[10px] text-slate-400 font-semibold whitespace-pre-wrap uppercase">{{ selectedOrder.store?.receipt_footer || 'Terima kasih atas kunjungan Anda' }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Mobile Slide Up Detail Receipt Sheet -->
    <Teleport to="body">
      <div
        v-if="showMobileReceipt && selectedOrder"
        class="lg:hidden fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-0 sm:p-4"
        @click.self="showMobileReceipt = false"
      >
        <div class="w-full max-w-md bg-white rounded-t-2xl sm:rounded-2xl shadow-xl flex flex-col max-h-[90vh] overflow-hidden animate-slide-up">
          <!-- Modal Header -->
          <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between shrink-0">
            <div>
              <h3 class="font-bold text-slate-800 text-sm">Detail Struk Transaksi</h3>
              <p class="text-[11px] text-slate-400 font-medium font-mono">{{ selectedOrder.invoice_number }}</p>
            </div>
            <button
              type="button"
              class="text-slate-400 hover:text-slate-600 text-lg p-1"
              @click="showMobileReceipt = false"
            >
              ✕
            </button>
          </div>

          <!-- Modal Scrollable Content -->
          <div class="flex-1 overflow-y-auto p-5 space-y-4">
            <!-- Virtual thermal receipt representation on mobile -->
            <div class="border border-slate-200 bg-slate-50/50 rounded-2xl p-4 shadow-inner">
              <div class="text-center space-y-1">
                <h4 class="font-extrabold text-slate-950 text-sm uppercase">{{ selectedOrder.store?.name || 'Toko Nessa POS' }}</h4>
                <p v-if="selectedOrder.store?.address" class="text-xs text-slate-600 leading-relaxed">{{ selectedOrder.store.address }}</p>
                <p v-if="selectedOrder.store?.phone" class="text-[11px] text-slate-500">Telp: {{ selectedOrder.store.phone }}</p>
              </div>

              <div class="border-t border-dashed border-slate-300 my-4"></div>

              <div class="grid grid-cols-2 gap-y-2 text-xs text-slate-600 font-medium">
                <div>No Invoice:</div>
                <div class="text-right font-bold text-slate-800 font-mono">{{ selectedOrder.invoice_number }}</div>

                <div>Waktu:</div>
                <div class="text-right text-slate-800">{{ formatDate(selectedOrder.created_at) }}</div>

                <div>Kasir:</div>
                <div class="text-right text-slate-800">{{ selectedOrder.user?.name || '-' }}</div>

                <div>Pembayaran:</div>
                <div class="text-right text-slate-800 font-bold uppercase">{{ getPaymentLabel(selectedOrder.payment_method) }}</div>
              </div>

              <div class="border-t border-dashed border-slate-300 my-4"></div>

              <!-- Items list -->
              <div class="space-y-3">
                <div v-for="it in selectedOrder.items" :key="'mob-'+it.id" class="flex gap-2.5 items-start">
                  <div class="h-8 w-8 bg-white border border-slate-200 rounded-lg overflow-hidden flex items-center justify-center shrink-0">
                    <img
                      v-if="it.product?.image"
                      :src="getImageUrl(it.product.image)"
                      class="h-full w-full object-cover"
                      alt="Product"
                    />
                    <span v-else class="text-sm">🍕</span>
                  </div>
                  <div class="flex-1 min-w-0 text-xs">
                    <div class="font-bold text-slate-900 truncate leading-tight">{{ it.product_name }}</div>
                    <div class="text-slate-500 font-mono mt-0.5">{{ it.quantity }} x Rp {{ money(Number(it.price)) }}</div>
                  </div>
                  <span class="text-xs font-bold text-slate-800 font-mono">Rp {{ money(Number(it.line_total)) }}</span>
                </div>
              </div>

              <div class="border-t border-dashed border-slate-300 my-4"></div>

              <!-- Calculation details -->
              <div class="space-y-2 text-xs text-slate-600 font-medium">
                <div class="flex justify-between">
                  <span>Subtotal</span>
                  <span class="font-mono text-slate-800">Rp {{ money(Number(selectedOrder.sub_total)) }}</span>
                </div>
                <div v-if="Number(selectedOrder.discount_amount) > 0" class="flex justify-between text-red-600">
                  <span>Diskon</span>
                  <span class="font-mono font-bold">-Rp {{ money(Number(selectedOrder.discount_amount)) }}</span>
                </div>
                <div class="flex justify-between">
                  <span>Pajak</span>
                  <span class="font-mono text-slate-800">Rp {{ money(Number(selectedOrder.tax_amount)) }}</span>
                </div>
                
                <div class="border-t border-slate-200/50 my-1"></div>

                <div class="flex justify-between text-sm font-extrabold text-slate-900 py-0.5">
                  <span>Total</span>
                  <span class="font-mono text-blue-600">Rp {{ money(Number(selectedOrder.total)) }}</span>
                </div>
                <div class="flex justify-between">
                  <span>Dibayar</span>
                  <span class="font-mono text-slate-800">Rp {{ money(Number(selectedOrder.paid_amount)) }}</span>
                </div>
                <div class="flex justify-between font-bold text-emerald-700">
                  <span>Kembalian</span>
                  <span class="font-mono">Rp {{ money(Number(selectedOrder.change_amount)) }}</span>
                </div>
              </div>

              <div class="border-t border-dashed border-slate-300 my-4"></div>
              
              <p class="text-center text-[9px] text-slate-400 font-bold whitespace-pre-wrap uppercase">{{ selectedOrder.store?.receipt_footer || 'Terima kasih atas kunjungan Anda' }}</p>
            </div>
          </div>

          <!-- Modal Footer with action buttons -->
          <div class="px-5 py-4 border-t border-slate-100 bg-slate-50 flex gap-3 shrink-0">
            <button
              type="button"
              class="flex-1 rounded-xl bg-slate-900 py-3 text-sm font-extrabold text-white hover:bg-slate-800 shadow-sm transition-all flex items-center justify-center gap-1.5"
              @click="printReceipt"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
              </svg>
              Cetak Struk
            </button>
            <button
              type="button"
              class="px-5 py-3 rounded-xl border border-slate-200 text-sm font-bold bg-white text-slate-700 hover:bg-slate-50 transition-colors"
              @click="showMobileReceipt = false"
            >
              Tutup
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Hidden Printer thermal style template -->
    <div
      v-if="selectedOrder"
      class="hidden print:block"
    >
      <div id="orders-receipt-print" class="p-4 text-xs font-mono text-black">
        <p class="text-center font-bold text-base leading-tight uppercase">{{ selectedOrder.store?.name || 'Toko Nessa POS' }}</p>
        <p v-if="selectedOrder.store?.address" class="text-center text-[10px] leading-relaxed mt-0.5">{{ selectedOrder.store.address }}</p>
        <p v-if="selectedOrder.store?.phone" class="text-center text-[10px] mt-0.5">Telp: {{ selectedOrder.store.phone }}</p>
        <hr class="my-2 border-dashed border-black" />
        <p class="text-[10px]">No: {{ selectedOrder.invoice_number }}</p>
        <p class="text-[10px]">Tgl: {{ formatDate(selectedOrder.created_at) }}</p>
        <p class="text-[10px]">Kasir: {{ selectedOrder.user?.name || '-' }}</p>
        <p class="text-[10px]">Metode: {{ getPaymentLabel(selectedOrder.payment_method) }}</p>
        <hr class="my-2 border-dashed border-black" />
        <div v-for="it in selectedOrder.items" :key="'print-'+it.id" class="flex justify-between text-[10px] my-1">
          <div class="max-w-[70%]">
            <div>{{ it.product_name }}</div>
            <div>{{ it.quantity }} x Rp {{ money(Number(it.price)) }}</div>
          </div>
          <span class="shrink-0">Rp {{ money(Number(it.line_total)) }}</span>
        </div>
        <hr class="my-2 border-dashed border-black" />
        <div class="flex justify-between text-[10px]"><span>Subtotal</span><span>Rp {{ money(Number(selectedOrder.sub_total)) }}</span></div>
        <div v-if="Number(selectedOrder.discount_amount) > 0" class="flex justify-between text-[10px]"><span>Diskon</span><span>-Rp {{ money(Number(selectedOrder.discount_amount)) }}</span></div>
        <div class="flex justify-between text-[10px]"><span>Pajak</span><span>Rp {{ money(Number(selectedOrder.tax_amount)) }}</span></div>
        <hr class="my-1 border-dashed border-black" />
        <div class="flex justify-between font-bold text-sm"><span>Total</span><span>Rp {{ money(Number(selectedOrder.total)) }}</span></div>
        <div class="flex justify-between text-[10px]"><span>Bayar</span><span>Rp {{ money(Number(selectedOrder.paid_amount)) }}</span></div>
        <div class="flex justify-between font-bold text-[10px]"><span>Kembalian</span><span>Rp {{ money(Number(selectedOrder.change_amount)) }}</span></div>
        <hr class="my-2 border-dashed border-black" />
        <p class="mt-4 text-center text-[10px] whitespace-pre-wrap">{{ selectedOrder.store?.receipt_footer || 'Terima kasih atas kunjungan Anda' }}</p>
      </div>
    </div>
  </AppShell>
</template>

<style scoped>
@media print {
  body * {
    visibility: hidden !important;
  }
  #orders-receipt-print,
  #orders-receipt-print * {
    visibility: visible !important;
  }
  #orders-receipt-print {
    position: absolute;
    left: 0;
    top: 0;
    width: 80mm;
    max-width: 100%;
    background-color: white !important;
    color: black !important;
  }
}

@keyframes slideUp {
  from {
    transform: translateY(100%);
  }
  to {
    transform: translateY(0);
  }
}
.animate-slide-up {
  animation: slideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
</style>
