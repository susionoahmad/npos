<script setup lang="ts">
import { computed, nextTick, onMounted, ref, useTemplateRef, watch } from 'vue'
import { useRouter } from 'vue-router'
import AppShell from '../components/AppShell.vue'
import api from '../services/api'

type CartLine = {
  product_id: number
  name: string
  price: number
  quantity: number
  maxStock: number
}

const searchInput = useTemplateRef('searchInput')

const search = ref('')
const products = ref<any[]>([])
const categories = ref<any[]>([])
const selectedCategoryId = ref<number | null>(null)
const cart = ref<CartLine[]>([])
const loading = ref(false)
const checkoutLoading = ref(false)
const error = ref('')
const successMsg = ref('')

const discountType = ref<'percent' | 'fixed'>('percent')
const discountValue = ref(0)
const taxPercent = ref(11)
const paidAmount = ref<number | null>(null)
const paymentMethod = ref<'cash' | 'transfer' | 'qris' | 'card'>('cash')

const lastTransaction = ref<any>(null)
const showReceipt = ref(false)
const showMobileCart = ref(false)

const money = (n: number) =>
  new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Math.round(n))

const getImageUrl = (path: string | null) => {
  if (!path) return ''
  if (path.startsWith('http')) return path
  const apiBase = api.defaults.baseURL || 'http://127.0.0.1:8000/api'
  const host = apiBase.replace(/\/api$/, '')
  return `${host}/storage/${path}`
}

function apiErrorMessage(e: any): string {
  const d = e.response?.data
  if (d?.message) return d.message
  if (d?.errors) {
    return Object.values(d.errors)
      .flat()
      .filter(Boolean)
      .join(' ')
  }
  return 'Terjadi kesalahan. Coba lagi.'
}

const stockFor = (productId: number) => {
  const p = products.value.find((x) => x.id === productId)
  return p != null ? Number(p.stock) : null
}

const fetchCategories = async () => {
  try {
    const { data } = await api.get('/categories', { params: { per_page: 200 } })
    categories.value = data.data ?? data ?? []
  } catch (e) {
    console.error('Gagal memuat kategori', e)
  }
}

const fetchProducts = async () => {
  loading.value = true
  error.value = ''
  try {
    const params: any = {
      search: search.value || undefined,
      category_id: selectedCategoryId.value || undefined,
      per_page: 200,
    }
    const { data } = await api.get('/products', { params })
    products.value = data.data ?? []
    syncCartMaxStock()
  } catch (e: any) {
    error.value = apiErrorMessage(e)
    products.value = []
  } finally {
    loading.value = false
  }
}

function syncCartMaxStock() {
  for (const row of cart.value) {
    const s = stockFor(row.product_id)
    if (s != null) row.maxStock = s
    if (row.quantity > row.maxStock) row.quantity = Math.max(0, row.maxStock)
  }
  cart.value = cart.value.filter((r) => r.quantity > 0)
}

const selectCategory = (id: number | null) => {
  selectedCategoryId.value = id
  fetchProducts()
}

const onSearchSubmit = async () => {
  await fetchProducts()
  if (products.value.length === 1) {
    const p = products.value[0]
    if (Number(p.stock) > 0) {
      addToCart(p)
      search.value = ''
      await nextTick()
      searchInput.value?.focus()
    }
  }
}

const addToCart = (p: any) => {
  const max = Number(p.stock)
  if (max <= 0) return
  const row = cart.value.find((x) => x.product_id === p.id)
  if (row) {
    if (row.quantity >= max) return
    row.quantity += 1
  } else {
    cart.value.push({
      product_id: p.id,
      name: p.name,
      price: Number(p.price),
      quantity: 1,
      maxStock: max,
    })
  }
}

const incQty = (row: CartLine) => {
  const live = stockFor(row.product_id)
  const cap = live != null ? live : row.maxStock
  if (row.quantity < cap) row.quantity += 1
}

const decQty = (row: CartLine) => {
  row.quantity -= 1
  if (row.quantity <= 0) removeLine(row.product_id)
}

const removeLine = (productId: number) => {
  cart.value = cart.value.filter((r) => r.product_id !== productId)
}

const qtyInCart = (productId: number) => {
  const found = cart.value.find((x) => x.product_id === productId)
  return found ? found.quantity : 0
}

const subTotal = computed(() => cart.value.reduce((s, i) => s + i.price * i.quantity, 0))
const discount = computed(() =>
  discountType.value === 'percent'
    ? subTotal.value * (Number(discountValue.value) / 100)
    : Number(discountValue.value),
)
const discountCapped = computed(() => Math.min(discount.value, subTotal.value))
const tax = computed(
  () => Math.max(0, subTotal.value - discountCapped.value) * (Number(taxPercent.value) / 100),
)
const total = computed(() => {
  const val = Math.max(0, subTotal.value - discountCapped.value + tax.value)
  if (paymentMethod.value === 'cash') {
    return Math.round(val / 100) * 100
  }
  return val
})

const change = computed(() => {
  const paid = paidAmount.value
  if (paid == null || Number.isNaN(paid)) return 0
  return Math.max(0, paid - total.value)
})

watch([total, paymentMethod], () => {
  if (paymentMethod.value !== 'cash') {
    paidAmount.value = Math.round(total.value)
  }
})

const canCheckout = computed(() => {
  if (!cart.value.length || checkoutLoading.value) return false
  const paid = paidAmount.value
  if (paid == null || paid < total.value) return false
  for (const row of cart.value) {
    const cap = stockFor(row.product_id) ?? row.maxStock
    if (row.quantity > cap) return false
  }
  return true
})

function setExactPay() {
  paidAmount.value = Math.round(total.value)
}

// Generate touch-friendly quick cash options based on total
const quickCashOptions = computed(() => {
  const t = Math.round(total.value)
  if (t <= 0) return []
  
  const options = new Set<number>()
  options.add(t) // Exact amount

  const standardBills = [5000, 10000, 20000, 50000, 100000, 200000]
  for (const bill of standardBills) {
    if (bill > t) {
      options.add(bill)
    }
  }

  // Also common rounded targets (e.g. next 10k or 50k)
  const next5k = Math.ceil(t / 5000) * 5000
  const next10k = Math.ceil(t / 10000) * 10000
  const next50k = Math.ceil(t / 50000) * 50000
  if (next5k > t) options.add(next5k)
  if (next10k > t) options.add(next10k)
  if (next50k > t) options.add(next50k)

  return Array.from(options).sort((a, b) => a - b).slice(0, 5)
})

const printThermal = (trx: any) => {
  if (!trx) return

  const store = trx.store || {}
  const invoiceNumber = trx.invoice_number
  const formattedDate = new Date(trx.created_at).toLocaleString('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short'
  })
  const cashierName = trx.user?.name || '-'
  
  const getPaymentLabel = (method: string) => {
    switch (method) {
      case 'cash': return 'Tunai'
      case 'qris': return 'QRIS'
      case 'transfer': return 'Transfer'
      case 'card': return 'Kartu'
      default: return method
    }
  }
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

const checkout = async () => {
  if (!canCheckout.value) {
    if (cart.value.some((r) => (stockFor(r.product_id) ?? r.maxStock) < r.quantity)) {
      error.value = 'Stok tidak cukup untuk salah satu item. Perbarui keranjang.'
    } else if (paidAmount.value == null || paidAmount.value < total.value) {
      error.value = 'Jumlah bayar harus sama atau lebih besar dari total.'
    }
    return
  }

  checkoutLoading.value = true
  error.value = ''
  successMsg.value = ''
  try {
    const { data } = await api.post('/transactions', {
      items: cart.value.map((x) => ({ product_id: x.product_id, quantity: x.quantity })),
      discount_type: discountType.value,
      discount_value: discountValue.value,
      tax_percent: taxPercent.value,
      paid_amount: paidAmount.value,
      payment_method: paymentMethod.value,
    })

    lastTransaction.value = data.transaction
    successMsg.value = data.message || 'Transaksi berhasil.'
    showReceipt.value = true
    showMobileCart.value = false

    cart.value = []
    paidAmount.value = null
    discountValue.value = 0
    await fetchProducts()
    // Refresh saldo laci setelah transaksi
    try {
      const sumRes = await api.get('/cashier-sessions/active/summary')
      sessionSummary.value = sumRes.data
    } catch { /* ignore */ }
    await nextTick()
    
    // Auto-trigger printing
    printReceipt()

    searchInput.value?.focus()
  } catch (e: any) {
    error.value = apiErrorMessage(e)
  } finally {
    checkoutLoading.value = false
  }
}

const closeReceipt = () => {
  showReceipt.value = false
  lastTransaction.value = null
}

const printReceipt = () => {
  if (!lastTransaction.value) return
  printThermal(lastTransaction.value)
}

const router = useRouter()
const activeSession = ref<any>(null)
const sessionSummary = ref<any>(null)

// Saldo laci terkini = mutasi masuk - mutasi keluar (sudah mencakup modal awal & penjualan tunai)
const drawerBalance = computed(() => {
  if (!sessionSummary.value) return activeSession.value?.start_balance ?? 0
  const s = sessionSummary.value
  return (s.mutations_in ?? 0) - (s.mutations_out ?? 0)
})

const checkSession = async () => {
  try {
    const { data } = await api.get('/cashier-sessions/active')
    if (!data || Object.keys(data).length === 0) {
      router.push('/session/open')
      return
    }
    activeSession.value = data
    // Fetch summary untuk saldo laci terkini
    try {
      const sumRes = await api.get('/cashier-sessions/active/summary')
      sessionSummary.value = sumRes.data
    } catch {
      sessionSummary.value = null
    }
  } catch (e) {
    console.error('Gagal memverifikasi sesi kasir:', e)
  }
}

onMounted(() => {
  checkSession()
  fetchCategories()
  fetchProducts()
  nextTick(() => searchInput.value?.focus())
})
</script>

<template>
  <AppShell>
    <!-- Content Grid containing Left Column and Right Column -->
    <div class="grid gap-4 lg:grid-cols-[1fr_380px] pb-36 lg:pb-0 items-start">
      
      <!-- Left Column: Header, Search, Categories, Products -->
      <div class="space-y-4">
        <!-- Header -->
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 class="text-2xl font-bold tracking-tight">Kasir POS</h2>
            <div v-if="activeSession" class="text-xs text-slate-500 font-semibold flex items-center gap-1.5 mt-0.5 flex-wrap">
              <span class="inline-flex rounded-full bg-slate-900 text-white px-2 py-0.5 text-[9px] font-extrabold uppercase font-mono">
                {{ activeSession.session_number }}
              </span>
              <span>Shift {{ activeSession.shift }}</span>
              <span>•</span>
              <!-- Saldo laci live -->
              <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200 px-2.5 py-0.5 font-mono font-extrabold text-[10px]">
                🏧 Laci: Rp {{ money(drawerBalance) }}
              </span>
              <span>•</span>
              <RouterLink to="/session/close" class="text-red-600 hover:text-red-800 font-bold hover:underline transition-all">
                🔴 Tutup Sesi
              </RouterLink>
            </div>
          </div>
          <div class="w-full sm:w-[320px] flex gap-2">
            <input
              ref="searchInput"
              v-model="search"
              type="text"
              autocomplete="off"
              class="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20"
              placeholder="Cari nama / barcode (Enter)"
              @keyup.enter="onSearchSubmit"
            />
            <button
              type="button"
              class="shrink-0 rounded-lg bg-slate-900 px-3 py-2 text-sm text-white hover:bg-slate-800"
              :disabled="loading"
              @click="fetchProducts"
            >
              Cari
            </button>
          </div>
        </div>

        <!-- Category Pills -->
        <div class="overflow-x-auto scrollbar-none flex gap-2 pb-2">
          <button
            type="button"
            class="shrink-0 px-4 py-2 rounded-full text-xs font-semibold tracking-wide transition-colors"
            :class="selectedCategoryId === null ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
            @click="selectCategory(null)"
          >
            Semua
          </button>
          <button
            v-for="c in categories"
            :key="c.id"
            type="button"
            class="shrink-0 px-4 py-2 rounded-full text-xs font-semibold tracking-wide transition-colors"
            :class="selectedCategoryId === c.id ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
            @click="selectCategory(c.id)"
          >
            {{ c.name }}
          </button>
        </div>

        <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800 font-medium">{{ error }}</p>
        <p v-if="successMsg && !showReceipt" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 font-medium">
          {{ successMsg }}
        </p>

        <!-- Left: Touch-friendly Product Grid -->
        <div class="space-y-4">
          <div v-if="loading" class="flex items-center justify-center py-20">
            <div class="h-8 w-8 animate-spin rounded-full border-4 border-slate-900 border-t-transparent"></div>
          </div>
          <div v-else-if="!products.length" class="bg-white rounded-xl p-10 text-center border border-slate-100 shadow-sm">
            <p class="text-slate-400 text-sm">Tidak ada produk ditemukan.</p>
          </div>
          <div v-else class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-3">
            <div
              v-for="p in products"
              :key="p.id"
              class="relative flex flex-col bg-white rounded-xl border border-slate-100 shadow-sm overflow-hidden select-none cursor-pointer transform transition-all active:scale-[0.98]"
              :class="Number(p.stock) <= 0 ? 'opacity-50 cursor-not-allowed' : 'hover:border-slate-200'"
              @click="Number(p.stock) > 0 && addToCart(p)"
            >
              <!-- Product Image / Fallback -->
              <div class="aspect-square bg-slate-50 relative flex items-center justify-center overflow-hidden border-b border-slate-100">
                <img
                  v-if="p.image"
                  :src="getImageUrl(p.image)"
                  class="h-full w-full object-cover"
                  alt="Product"
                />
                <div v-else class="flex flex-col items-center justify-center gap-1 text-slate-300">
                  <span class="text-4xl">🍕</span>
                </div>
                
                <!-- Quantity in Cart Badge -->
                <div
                  v-if="qtyInCart(p.id) > 0"
                  class="absolute top-2 right-2 bg-emerald-600 text-white h-6 min-w-6 px-1.5 rounded-full flex items-center justify-center text-xs font-bold shadow-md"
                >
                  {{ qtyInCart(p.id) }}
                </div>
              </div>

              <!-- Product Details -->
              <div class="p-3 flex-1 flex flex-col justify-between">
                <div class="font-bold text-slate-800 text-sm leading-snug line-clamp-2 mb-1">{{ p.name }}</div>
                <div class="flex items-end justify-between">
                  <div class="text-xs text-slate-500 font-medium">Stok: {{ p.stock }}</div>
                  <div class="font-extrabold text-slate-900 text-sm">Rp {{ money(Number(p.price)) }}</div>
                </div>
              </div>
              
              <!-- Out of Stock Overlay -->
              <div v-if="Number(p.stock) <= 0" class="absolute inset-0 bg-white/70 flex items-center justify-center z-10">
                <span class="bg-red-600 text-white px-2.5 py-1 rounded-md text-xs font-bold uppercase tracking-wider">Habis</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Right: Cart & Checkout (Desktop view only, hidden on mobile/tablet) -->
      <div class="hidden lg:block">
        <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200 sticky top-0 max-h-[calc(100vh-48px)] flex flex-col">
          <h3 class="font-bold text-slate-800 text-lg mb-3 flex items-center gap-2">
            <span>🛒</span> Keranjang Belanja
          </h3>

          <!-- Cart list -->
          <div class="flex-1 overflow-y-auto min-h-0 mb-4 divide-y divide-slate-100 pr-1">
            <div v-if="!cart.length" class="text-center py-10 text-slate-400 text-sm">
              Keranjang kosong. Tap produk untuk menambahkan.
            </div>
            <div v-else v-for="c in cart" :key="c.product_id" class="py-2.5 flex flex-col gap-1.5">
              <div class="flex justify-between items-start">
                <span class="font-bold text-sm text-slate-800 leading-snug">{{ c.name }}</span>
                <button type="button" class="text-red-500 text-xs hover:underline ml-2" @click="removeLine(c.product_id)">Hapus</button>
              </div>
              <div class="flex justify-between items-center text-xs">
                <span class="text-slate-500 font-mono">@ Rp {{ money(c.price) }}</span>
                <span class="font-bold text-slate-800 font-mono">Rp {{ money(c.price * c.quantity) }}</span>
              </div>
              <!-- Quantity adjustment keys -->
              <div class="flex items-center gap-2">
                <button
                  type="button"
                  class="h-7 w-7 rounded-lg border border-slate-200 text-slate-600 font-bold bg-slate-50 flex items-center justify-center transition-colors hover:bg-slate-100"
                  @click="decQty(c)"
                >
                  −
                </button>
                <span class="w-8 text-center font-bold text-sm font-mono">{{ c.quantity }}</span>
                <button
                  type="button"
                  class="h-7 w-7 rounded-lg border border-slate-200 text-slate-600 font-bold bg-slate-50 flex items-center justify-center transition-colors hover:bg-slate-100 disabled:opacity-40"
                  :disabled="c.quantity >= (stockFor(c.product_id) ?? c.maxStock)"
                  @click="incQty(c)"
                >
                  +
                </button>
              </div>
            </div>
          </div>

          <!-- Checkout summary and payment -->
          <div class="border-t border-slate-100 pt-3 space-y-3 shrink-0 text-sm">
            <div class="flex justify-between">
              <span class="text-slate-600">Subtotal</span>
              <span class="font-bold text-slate-800 font-mono">Rp {{ money(subTotal) }}</span>
            </div>
            
            <div class="flex items-center justify-between gap-2">
              <span class="text-slate-600">Diskon</span>
              <div class="flex items-center gap-1.5">
                <select v-model="discountType" class="rounded border border-slate-300 px-1 py-0.5 text-xs bg-slate-50">
                  <option value="percent">%</option>
                  <option value="fixed">Nominal</option>
                </select>
                <input
                  v-model.number="discountValue"
                  type="number"
                  min="0"
                  class="w-20 rounded border border-slate-300 px-1.5 py-0.5 font-mono text-xs text-right"
                />
              </div>
            </div>
            
            <div class="flex justify-between text-xs text-slate-500">
              <span>Potongan diskon</span>
              <span class="font-mono">- Rp {{ money(discountCapped) }}</span>
            </div>
            
            <div class="flex items-center justify-between gap-2">
              <span class="text-slate-600">Pajak %</span>
              <input
                v-model.number="taxPercent"
                type="number"
                min="0"
                step="0.1"
                class="w-14 rounded border border-slate-300 px-1.5 py-0.5 font-mono text-xs text-right"
              />
            </div>
            
            <div class="flex justify-between text-xs text-slate-500 border-b border-slate-100 pb-2">
              <span>Pajak total</span>
              <span class="font-mono">Rp {{ money(tax) }}</span>
            </div>
            
            <div class="flex justify-between text-base font-extrabold text-slate-900">
              <span>Total</span>
              <span class="font-mono text-lg text-blue-600">Rp {{ money(total) }}</span>
            </div>

            <div class="space-y-2">
              <div class="grid grid-cols-2 gap-1.5">
                <button
                  v-for="method in (['cash', 'qris', 'transfer', 'card'] as const)"
                  :key="method"
                  type="button"
                  class="py-1.5 rounded-lg border text-xs font-bold uppercase transition-colors"
                  :class="paymentMethod === method ? 'bg-slate-900 border-slate-900 text-white' : 'border-slate-200 text-slate-600 bg-white hover:bg-slate-50'"
                  @click="paymentMethod = method"
                >
                  {{ method === 'cash' ? 'Tunai' : method }}
                </button>
              </div>

              <!-- Paid field and touchscreen cash keys -->
              <div v-if="paymentMethod === 'cash'" class="space-y-1.5">
                <div class="flex gap-1.5">
                  <input
                    v-model.number="paidAmount"
                    type="number"
                    min="0"
                    placeholder="Uang Diterima"
                    class="flex-1 rounded-lg border border-slate-300 px-2.5 py-1.5 font-mono text-sm focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 text-right font-bold"
                  />
                  <button type="button" @click="setExactPay" class="px-3 py-1.5 rounded-lg border border-slate-300 text-xs font-bold hover:bg-slate-50">Pas</button>
                </div>
                
                <!-- Quick touch keys for cashier -->
                <div class="flex flex-wrap gap-1">
                  <button
                    v-for="cash in quickCashOptions"
                    :key="cash"
                    type="button"
                    class="px-2 py-1 rounded bg-slate-100 hover:bg-slate-200 text-[10px] font-extrabold text-slate-700 font-mono"
                    @click="paidAmount = cash"
                  >
                    {{ money(cash) }}
                  </button>
                </div>
                
                <div class="flex justify-between items-center bg-slate-50 p-2 rounded-lg border border-slate-100">
                  <span class="text-xs text-slate-600">Kembalian</span>
                  <span class="font-extrabold text-emerald-600 font-mono">Rp {{ money(change) }}</span>
                </div>
              </div>
            </div>

            <button
              type="button"
              class="w-full rounded-xl bg-emerald-600 py-3 text-sm font-extrabold text-white shadow hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed transition-all"
              :disabled="!canCheckout"
              @click="checkout"
            >
              {{ checkoutLoading ? 'Menyimpan…' : 'BAYAR SEKARANG' }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Floating bottom Cart Bar for Mobile/Tablet -->
    <div
      v-if="cart.length"
      class="lg:hidden fixed bottom-[calc(56px+env(safe-area-inset-bottom,0px))] left-0 right-0 bg-slate-900 text-white p-4 shadow-lg z-30 flex items-center justify-between border-t border-slate-800"
    >
      <div class="flex flex-col">
        <span class="text-[10px] text-slate-400 font-semibold uppercase">Total Belanja</span>
        <span class="text-lg font-extrabold font-mono text-emerald-400">Rp {{ money(total) }}</span>
      </div>
      <button
        @click="showMobileCart = true"
        class="bg-blue-600 active:bg-blue-700 px-5 py-2.5 rounded-xl font-extrabold text-sm flex items-center gap-2 shadow-md"
      >
        <span>Lihat Keranjang ({{ cart.reduce((sum, item) => sum + item.quantity, 0) }})</span>
        <span class="text-xs">▲</span>
      </button>
    </div>

    <!-- Mobile Drawer Modal for Cart/Checkout -->
    <Teleport to="body">
      <div
        v-if="showMobileCart"
        class="lg:hidden fixed inset-0 z-50 bg-black/50 flex items-end justify-center"
        @click.self="showMobileCart = false"
      >
        <div class="bg-white w-full rounded-t-2xl max-h-[85vh] flex flex-col p-4 shadow-2xl animate-slide-up">
          <!-- Drawer Header -->
          <div class="flex justify-between items-center border-b border-slate-100 pb-3 mb-3 shrink-0">
            <h3 class="font-bold text-slate-800 text-base flex items-center gap-2">
              <span>🛒</span> Detail Belanja
            </h3>
            <button type="button" @click="showMobileCart = false" class="text-slate-500 hover:text-slate-800 font-bold p-1">✕</button>
          </div>

          <!-- Drawer Body (Items list) -->
          <div class="flex-1 overflow-y-auto min-h-0 divide-y divide-slate-100 pr-1">
            <div v-for="c in cart" :key="c.product_id" class="py-2.5 flex flex-col gap-1.5">
              <div class="flex justify-between items-start">
                <span class="font-bold text-sm text-slate-800 leading-snug">{{ c.name }}</span>
                <button type="button" class="text-red-500 text-xs ml-2" @click="removeLine(c.product_id)">Hapus</button>
              </div>
              <div class="flex justify-between items-center text-xs">
                <span class="text-slate-500 font-mono">@ Rp {{ money(c.price) }}</span>
                <span class="font-bold text-slate-800 font-mono">Rp {{ money(c.price * c.quantity) }}</span>
              </div>
              <div class="flex items-center gap-2">
                <button
                  type="button"
                  class="h-8 w-8 rounded-lg border border-slate-200 text-slate-600 font-bold bg-slate-50 flex items-center justify-center"
                  @click="decQty(c)"
                >
                  −
                </button>
                <span class="w-8 text-center font-bold text-sm font-mono">{{ c.quantity }}</span>
                <button
                  type="button"
                  class="h-8 w-8 rounded-lg border border-slate-200 text-slate-600 font-bold bg-slate-50 flex items-center justify-center disabled:opacity-40"
                  :disabled="c.quantity >= (stockFor(c.product_id) ?? c.maxStock)"
                  @click="incQty(c)"
                >
                  +
                </button>
              </div>
            </div>
          </div>

          <!-- Drawer Footer (Checkout form) -->
          <div class="border-t border-slate-100 pt-3 space-y-3 shrink-0 text-sm">
            <div class="flex justify-between text-xs">
              <span class="text-slate-600">Subtotal</span>
              <span class="font-bold text-slate-800 font-mono">Rp {{ money(subTotal) }}</span>
            </div>
            
            <div class="flex items-center justify-between gap-2 text-xs">
              <span class="text-slate-600">Diskon</span>
              <div class="flex items-center gap-1">
                <select v-model="discountType" class="rounded border border-slate-300 px-1 py-0.5 bg-slate-50">
                  <option value="percent">%</option>
                  <option value="fixed">Nominal</option>
                </select>
                <input
                  v-model.number="discountValue"
                  type="number"
                  min="0"
                  class="w-16 rounded border border-slate-300 px-1 py-0.5 font-mono text-right"
                />
              </div>
            </div>

            <div class="flex justify-between text-[11px] text-slate-500">
              <span>Pajak ({{ taxPercent }}%)</span>
              <span class="font-mono">Rp {{ money(tax) }}</span>
            </div>
            
            <div class="flex justify-between text-base font-extrabold text-slate-900 border-b border-slate-100 pb-2">
              <span>Total</span>
              <span class="font-mono text-blue-600">Rp {{ money(total) }}</span>
            </div>

            <!-- Payment Method -->
            <div class="grid grid-cols-4 gap-1">
              <button
                v-for="method in (['cash', 'qris', 'transfer', 'card'] as const)"
                :key="method"
                type="button"
                class="py-2 rounded-lg border text-[10px] font-extrabold uppercase"
                :class="paymentMethod === method ? 'bg-slate-900 border-slate-900 text-white' : 'border-slate-200 text-slate-600 bg-white'"
                @click="paymentMethod = method"
              >
                {{ method === 'cash' ? 'Tunai' : method }}
              </button>
            </div>

            <!-- Paid amount & quick keys -->
            <div v-if="paymentMethod === 'cash'" class="space-y-1.5">
              <div class="flex gap-2">
                <input
                  v-model.number="paidAmount"
                  type="number"
                  min="0"
                  placeholder="Uang Diterima"
                  class="flex-1 rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm focus:outline-none focus:ring-2 focus:ring-slate-900/20 text-right font-bold"
                />
                <button type="button" @click="setExactPay" class="px-3 py-2 rounded-lg border border-slate-300 text-xs font-bold">Pas</button>
              </div>
              <div class="flex flex-wrap gap-1">
                <button
                  v-for="cash in quickCashOptions"
                  :key="cash"
                  type="button"
                  class="px-2.5 py-1.5 rounded bg-slate-100 active:bg-slate-200 text-[10px] font-extrabold text-slate-700 font-mono"
                  @click="paidAmount = cash"
                >
                  {{ money(cash) }}
                </button>
              </div>
              <div class="flex justify-between items-center bg-slate-50 p-2.5 rounded-lg border border-slate-100">
                <span class="text-xs text-slate-600">Kembalian</span>
                <span class="font-extrabold text-emerald-600 font-mono text-base">Rp {{ money(change) }}</span>
              </div>
            </div>

            <button
              type="button"
              class="w-full rounded-xl bg-emerald-600 py-3 text-sm font-extrabold text-white shadow hover:bg-emerald-700 disabled:opacity-50 transition-all"
              :disabled="!canCheckout"
              @click="checkout"
            >
              {{ checkoutLoading ? 'Menyimpan…' : 'BAYAR & SELESAIKAN' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Receipts Modals & Printing remains functional -->
    <Teleport to="body">
      <div
        v-if="showReceipt && lastTransaction"
        class="modal-screen fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        @click.self="closeReceipt"
      >
        <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
          <div class="mb-4 flex justify-between gap-2">
            <h3 class="text-lg font-bold">Transaksi selesai</h3>
            <button type="button" class="text-slate-500 hover:text-slate-800" @click="closeReceipt">✕</button>
          </div>
          <div class="receipt-print text-sm text-slate-800">
            <p class="text-center font-bold text-base">{{ lastTransaction.store?.name || 'Toko' }}</p>
            <p v-if="lastTransaction.store?.address" class="text-center text-xs text-slate-500">
              {{ lastTransaction.store.address }}
            </p>
            <p v-if="lastTransaction.store?.phone" class="text-center text-xs">Telp: {{ lastTransaction.store.phone }}</p>
            <hr class="my-2 border-dashed border-slate-300" />
            <p class="text-xs">No: {{ lastTransaction.invoice_number }}</p>
            <p class="text-xs">{{ new Date(lastTransaction.created_at).toLocaleString('id-ID') }}</p>
            <p class="text-xs">Kasir: {{ lastTransaction.user?.name || '-' }}</p>
            <p class="text-xs">Bayar: {{ lastTransaction.payment_method }}</p>
            <hr class="my-2 border-dashed border-slate-300" />
            <ul class="space-y-1">
              <li v-for="it in lastTransaction.items" :key="it.id" class="flex justify-between gap-2 text-xs">
                <span class="min-w-0 flex-1">{{ it.product_name }} × {{ it.quantity }}</span>
                <span class="font-mono shrink-0">Rp {{ money(Number(it.line_total)) }}</span>
              </li>
            </ul>
            <hr class="my-2 border-dashed border-slate-300" />
            <div class="flex justify-between text-xs"><span>Subtotal</span><span class="font-mono">Rp {{ money(Number(lastTransaction.sub_total)) }}</span></div>
            <div class="flex justify-between text-xs"><span>Diskon</span><span class="font-mono">Rp {{ money(Number(lastTransaction.discount_amount)) }}</span></div>
            <div class="flex justify-between text-xs"><span>Pajak</span><span class="font-mono">Rp {{ money(Number(lastTransaction.tax_amount)) }}</span></div>
            <div class="flex justify-between text-sm font-bold"><span>Total</span><span class="font-mono">Rp {{ money(Number(lastTransaction.total)) }}</span></div>
            <div class="flex justify-between text-xs"><span>Dibayar</span><span class="font-mono">Rp {{ money(Number(lastTransaction.paid_amount)) }}</span></div>
            <div class="flex justify-between text-sm text-emerald-700 font-bold"><span>Kembalian</span><span class="font-mono">Rp {{ money(Number(lastTransaction.change_amount)) }}</span></div>
            <hr class="my-2 border-dashed border-slate-300" />
            <p class="text-center text-xs text-slate-500 whitespace-pre-wrap mt-3">
              {{ lastTransaction.store?.receipt_footer || 'Terima kasih atas kunjungan Anda' }}
            </p>
          </div>
          <div class="mt-4 flex gap-2">
            <button
              type="button"
              class="flex-1 rounded-lg bg-slate-900 py-2 font-medium text-white hover:bg-slate-800"
              @click="printReceipt"
            >
              Cetak struk
            </button>
            <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-50" @click="closeReceipt">
              Tutup
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <div
      v-if="showReceipt && lastTransaction"
      class="hidden print:block"
    >
      <div id="nessa-receipt-print" class="p-4 text-xs font-mono text-black">
        <p class="text-center font-bold text-base leading-tight uppercase">{{ lastTransaction.store?.name || 'Toko Nessa POS' }}</p>
        <p v-if="lastTransaction.store?.address" class="text-center text-[10px] leading-relaxed mt-0.5">{{ lastTransaction.store.address }}</p>
        <p v-if="lastTransaction.store?.phone" class="text-center text-[10px] mt-0.5">Telp: {{ lastTransaction.store.phone }}</p>
        <hr class="my-2 border-dashed border-black" />
        <p class="text-[10px]">No: {{ lastTransaction.invoice_number }}</p>
        <p class="text-[10px]">Tgl: {{ new Date(lastTransaction.created_at).toLocaleString('id-ID') }}</p>
        <p class="text-[10px]">Kasir: {{ lastTransaction.user?.name || '-' }}</p>
        <p class="text-[10px]">Metode: {{ lastTransaction.payment_method }}</p>
        <hr class="my-2 border-dashed border-black" />
        <div v-for="it in lastTransaction.items" :key="'print-'+it.id" class="flex justify-between text-[10px] my-1">
          <div class="max-w-[70%]">
            <div>{{ it.product_name }}</div>
            <div>{{ it.quantity }} x Rp {{ money(Number(it.price)) }}</div>
          </div>
          <span class="shrink-0">Rp {{ money(Number(it.line_total)) }}</span>
        </div>
        <hr class="my-2 border-dashed border-black" />
        <div class="flex justify-between text-[10px]"><span>Subtotal</span><span>Rp {{ money(Number(lastTransaction.sub_total)) }}</span></div>
        <div v-if="Number(lastTransaction.discount_amount) > 0" class="flex justify-between text-[10px]"><span>Diskon</span><span>-Rp {{ money(Number(lastTransaction.discount_amount)) }}</span></div>
        <div class="flex justify-between text-[10px]"><span>Pajak</span><span>Rp {{ money(Number(lastTransaction.tax_amount)) }}</span></div>
        <hr class="my-1 border-dashed border-black" />
        <div class="flex justify-between font-bold text-sm"><span>Total</span><span>Rp {{ money(Number(lastTransaction.total)) }}</span></div>
        <div class="flex justify-between text-[10px]"><span>Bayar</span><span>Rp {{ money(Number(lastTransaction.paid_amount)) }}</span></div>
        <div class="flex justify-between font-bold text-[10px]"><span>Kembalian</span><span>Rp {{ money(Number(lastTransaction.change_amount)) }}</span></div>
        <hr class="my-2 border-dashed border-black" />
        <p class="mt-4 text-center text-[10px] whitespace-pre-wrap">{{ lastTransaction.store?.receipt_footer || 'Terima kasih atas kunjungan Anda' }}</p>
      </div>
    </div>
  </AppShell>
</template>

<style scoped>
@media print {
  body * {
    visibility: hidden;
  }
  #nessa-receipt-print,
  #nessa-receipt-print * {
    visibility: visible;
  }
  #nessa-receipt-print {
    position: absolute;
    left: 0;
    top: 0;
    width: 80mm;
    max-width: 100%;
  }
}

.scrollbar-none::-webkit-scrollbar {
  display: none;
}
.scrollbar-none {
  -ms-overflow-style: none;
  scrollbar-width: none;
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
  animation: slideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
</style>

<style>
@media print {
  .modal-screen {
    display: none !important;
  }
}
</style>
