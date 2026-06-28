<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import AppShell from '../components/AppShell.vue'
import api from '../services/api'
import { pickErrorMessage, pickFieldErrors } from '../utils/laravelErrors'
import { useToastStore } from '../stores/toast'
import { useAuthStore } from '../stores/auth'

type Category = { id: number; name: string }
type Supplier = { id: number; name: string }
type Product = {
  id: number
  name: string
  barcode: string | null
  price: number
  buying_price: number
  stock: number
  expiry_date: string | null
  category_id: number | null
  supplier_id: number | null
  image: string | null
  image_url: string | null
  category?: Category | null
  supplier?: Supplier | null
}
type Paginated<T> = {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

const loading = ref(false)
const error = ref<string | null>(null)
const authStore = useAuthStore()

const search = ref('')
const page = ref(1)

const items = ref<Product[]>([])
const currentPage = ref(1)
const lastPage = ref(1)
const perPage = ref(20)
const total = ref(0)

const categories = ref<Category[]>([])
const suppliers = ref<Supplier[]>([])

const modalOpen = ref(false)
const editingId = ref<number | null>(null)
const saving = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})
const toast = useToastStore()

const form = ref({
  name: '',
  barcode: '',
  price: 0,
  buying_price: 0,
  stock: 0,
  expiry_date: '',
  category_id: '' as any,
  supplier_id: '' as any,
  image: '',
})

const uploadLoading = ref(false)
const tempImageUrl = ref('')

async function onImageChange(e: Event) {
  const target = e.target as HTMLInputElement
  const file = target.files?.[0]
  if (!file) return

  uploadLoading.value = true
  const fd = new FormData()
  fd.append('image', file)

  try {
    const { data } = await api.post('/products/upload-image', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    form.value.image = data.path
    tempImageUrl.value = getImageUrl(data.path)
  } catch (err: any) {
    alert(err?.response?.data?.message || 'Gagal mengupload gambar')
  } finally {
    uploadLoading.value = false
  }
}

function clearImage() {
  form.value.image = ''
  tempImageUrl.value = ''
}

const getImageUrl = (path: string | null) => {
  if (!path) return ''
  if (path.startsWith('http')) return path
  const apiBase = api.defaults.baseURL || 'http://127.0.0.1:8000/api'
  const host = apiBase.replace(/\/api$/, '')
  return `${host}/storage/${path}`
}

const title = computed(() => (editingId.value ? 'Edit Produk' : 'Tambah Produk'))
const canPrev = computed(() => currentPage.value > 1)
const canNext = computed(() => currentPage.value < lastPage.value)

function money(n: any) {
  const num = Number(n ?? 0)
  return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(num)
}

async function fetchLookups() {
  try {
    const [catRes, supRes] = await Promise.all([api.get('/categories', { params: { per_page: 200 } }), api.get('/suppliers')])
    categories.value = catRes.data?.data || catRes.data || []
    suppliers.value = supRes.data || []
  } catch {
    // ignore
  }
}

async function fetchProducts() {
  loading.value = true
  error.value = null
  try {
    const params: any = { page: page.value }
    if (search.value.trim()) params.search = search.value.trim()
    const { data } = await api.get<Paginated<Product>>('/products', { params })
    items.value = data.data || []
    currentPage.value = data.current_page || 1
    lastPage.value = data.last_page || 1
    perPage.value = data.per_page || 20
    total.value = data.total ?? items.value.length
  } catch (e: any) {
    error.value = e?.response?.data?.message || e?.message || 'Gagal memuat produk'
  } finally {
    loading.value = false
  }
}

let searchTimer: number | null = null
function onSearchInput() {
  if (searchTimer) window.clearTimeout(searchTimer)
  searchTimer = window.setTimeout(() => {
    page.value = 1
    fetchProducts()
  }, 350)
}

function openCreate() {
  if (!authStore.user?.store_id) {
    alert('Silakan pilih toko tertentu di menu sidebar terlebih dahulu untuk menambah data.')
    return
  }
  editingId.value = null
  form.value = {
    name: '',
    barcode: '',
    price: 0,
    buying_price: 0,
    stock: 0,
    expiry_date: '',
    category_id: '',
    supplier_id: '',
    image: '',
  }
  tempImageUrl.value = ''
  modalOpen.value = true
}

async function openEdit(id: number) {
  error.value = null
  try {
    const { data } = await api.get<Product>(`/products/${id}`)
    editingId.value = id
    form.value = {
      name: data.name ?? '',
      barcode: data.barcode ?? '',
      price: Number(data.price ?? 0),
      buying_price: Number(data.buying_price ?? 0),
      stock: Number(data.stock ?? 0),
      expiry_date: data.expiry_date ? data.expiry_date.substring(0, 10) : '',
      category_id: data.category_id ?? '',
      supplier_id: data.supplier_id ?? '',
      image: data.image ?? '',
    }
    tempImageUrl.value = getImageUrl(data.image)
    modalOpen.value = true
  } catch (e: any) {
    error.value = e?.response?.data?.message || e?.message || 'Gagal memuat detail produk'
  }
}

function closeModal() {
  modalOpen.value = false
}

async function save() {
  const payload = {
    name: form.value.name.trim(),
    barcode: form.value.barcode.trim() ? form.value.barcode.trim() : null,
    price: Number(form.value.price),
    buying_price: Number(form.value.buying_price),
    stock: Number(form.value.stock),
    expiry_date: form.value.expiry_date ? form.value.expiry_date : null,
    category_id: form.value.category_id ? Number(form.value.category_id) : null,
    supplier_id: form.value.supplier_id ? Number(form.value.supplier_id) : null,
    image: form.value.image ? form.value.image : null,
  }
  if (!payload.name) return

  saving.value = true
  error.value = null
  fieldErrors.value = {}
  try {
    if (editingId.value) {
      await api.put(`/products/${editingId.value}`, payload)
      toast.success('Produk diperbarui')
    } else {
      await api.post('/products', payload)
      toast.success('Produk ditambahkan')
    }
    closeModal()
    await fetchProducts()
  } catch (e: any) {
    fieldErrors.value = pickFieldErrors(e)
    error.value = pickErrorMessage(e, 'Gagal menyimpan produk')
  } finally {
    saving.value = false
  }
}

async function remove(p: Product) {
  if (!confirm(`Hapus produk "${p.name}"?`)) return
  error.value = null
  try {
    await api.delete(`/products/${p.id}`)
    toast.success('Produk dihapus')
    await fetchProducts()
  } catch (e: any) {
    error.value = e?.response?.data?.message || e?.message || 'Gagal menghapus produk'
  }
}

const importing = ref(false)
async function importCsv(ev: Event) {
  ev.preventDefault()
  const input = document.getElementById('csv') as HTMLInputElement | null
  const file = input?.files?.[0]
  if (!file) {
    error.value = 'Pilih file CSV dulu'
    return
  }
  importing.value = true
  error.value = null
  fieldErrors.value = {}
  try {
    const fd = new FormData()
    fd.append('file', file)
    const res = await api.post('/products/import-csv', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
    const inserted = (res.data && (res.data.inserted ?? res.data.count ?? res.data.total)) || 0
    toast.success(`Import selesai, ${inserted} produk ditambah`)
    if (input) input.value = ''
    await fetchProducts()
  } catch (e: any) {
    fieldErrors.value = pickFieldErrors(e)
    error.value = pickErrorMessage(e, 'Gagal import CSV')
  } finally {
    importing.value = false
  }
}

function downloadTemplate() {
  const headers = ['name', 'price', 'barcode', 'buying_price', 'stock', 'expiry_date'].join(',')
  const sample = ['Kopi Latte Susu', '28000', '899200200003', '16000', '100', '2026-12-31'].join(',')
  const csvContent = headers + '\n' + sample
  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.setAttribute('href', url)
  link.setAttribute('download', 'template_import_produk.csv')
  link.style.visibility = 'hidden'
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

function prev() {
  if (!canPrev.value) return
  page.value = currentPage.value - 1
  fetchProducts()
}
function next() {
  if (!canNext.value) return
  page.value = currentPage.value + 1
  fetchProducts()
}

onMounted(async () => {
  await fetchLookups()
  await fetchProducts()
})
</script>

<template>
  <AppShell>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-2xl font-bold">Produk</h2>
        <p class="text-sm text-slate-500">Kelola produk, stok, dan harga.</p>
      </div>

      <div class="flex items-center gap-2 w-full sm:w-auto">
        <input
          v-model="search"
          class="min-w-0 flex-1 sm:w-[240px] md:w-[320px] rounded-lg border bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-slate-300"
          placeholder="Cari nama / barcode..."
          @input="onSearchInput"
        />
        <button class="shrink-0 rounded-lg bg-slate-900 px-3 py-2 text-sm text-white hover:bg-slate-800" type="button" @click="openCreate">
          Tambah
        </button>
      </div>
    </div>

    <form class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border bg-white p-3" @submit="importCsv">
      <div class="text-sm font-medium">Import CSV</div>
      <input
        id="csv"
        type="file"
        accept=".csv,text/csv"
        class="text-xs text-slate-500 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border file:border-slate-200 file:text-xs file:font-bold file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200 file:shadow-sm cursor-pointer"
      />
      <button
        class="rounded-lg border px-3 py-1.5 text-sm hover:bg-slate-50 disabled:opacity-60"
        type="submit"
        :disabled="importing"
      >
        {{ importing ? 'Uploading...' : 'Upload' }}
      </button>
      <button
        type="button"
        class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 transition-all flex items-center gap-1.5"
        @click="downloadTemplate"
      >
        📥 Unduh Template
      </button>
      <div class="text-xs text-slate-500">
        Header minimal: <span class="font-semibold">name,price</span>. Opsional: <span class="font-semibold">barcode,stock,expiry_date</span>
      </div>
    </form>

    <div v-if="error" class="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
      {{ error }}
    </div>

    <div class="overflow-hidden rounded-xl border bg-white">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-left text-slate-600">
            <tr>
              <th class="px-4 py-3 w-[70px]">ID</th>
              <th class="px-4 py-3 w-[80px]">Gambar</th>
              <th class="px-4 py-3">Nama</th>
              <th class="px-4 py-3 w-[160px]">Kategori</th>
              <th class="px-4 py-3 w-[150px]">Barcode</th>
              <th class="px-4 py-3 w-[120px] text-right">Harga Beli</th>
              <th class="px-4 py-3 w-[120px] text-right">Harga Jual</th>
              <th class="px-4 py-3 w-[110px] text-right">Stok</th>
              <th class="px-4 py-3 w-[140px]">Exp</th>
              <th class="px-4 py-3 w-[180px] text-right">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="10" class="px-4 py-10 text-center text-slate-500">Loading...</td>
            </tr>
            <tr v-else-if="items.length === 0">
              <td colspan="10" class="px-4 py-10 text-center text-slate-500">Tidak ada data</td>
            </tr>
            <tr v-else v-for="(p, index) in items" :key="p.id" class="border-t">
              <td class="px-4 py-3 text-slate-500">{{ (currentPage - 1) * perPage + index + 1 }}</td>
              <td class="px-4 py-3">
                <img
                  v-if="p.image"
                  :src="getImageUrl(p.image)"
                  class="h-10 w-10 rounded object-cover bg-slate-100 border border-slate-200 shadow-sm"
                  alt="Product"
                />
                <div v-else class="h-10 w-10 rounded bg-slate-100 border border-slate-200 flex items-center justify-center text-[10px] text-slate-400 font-medium">
                  N/A
                </div>
              </td>
              <td class="px-4 py-3 font-medium">{{ p.name }}</td>
              <td class="px-4 py-3">{{ p.category?.name || '-' }}</td>
              <td class="px-4 py-3 text-slate-500">{{ p.barcode || '-' }}</td>
              <td class="px-4 py-3 text-right text-slate-400 font-mono">Rp {{ money(p.buying_price) }}</td>
              <td class="px-4 py-3 text-right font-semibold text-slate-700 font-mono">Rp {{ money(p.price) }}</td>
              <td class="px-4 py-3 text-right">{{ money(p.stock) }}</td>
              <td class="px-4 py-3 text-slate-500">{{ p.expiry_date ? p.expiry_date.substring(0, 10) : '-' }}</td>
              <td class="px-4 py-3 text-right">
                <button class="rounded-lg border px-3 py-1.5 text-sm hover:bg-slate-50" type="button" @click="openEdit(p.id)">
                  Edit
                </button>
                <button
                  class="ml-2 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-sm text-red-700 hover:bg-red-100"
                  type="button"
                  @click="remove(p)"
                >
                  Hapus
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="flex items-center justify-between gap-2 border-t bg-white px-4 py-3 text-sm">
        <div class="text-slate-500">
          Halaman {{ currentPage }} / {{ lastPage }} • Total {{ total }}
        </div>
        <div class="flex gap-2">
          <button class="rounded-lg border px-3 py-1.5 text-sm hover:bg-slate-50 disabled:opacity-50" type="button" :disabled="!canPrev" @click="prev">
            Prev
          </button>
          <button class="rounded-lg border px-3 py-1.5 text-sm hover:bg-slate-50 disabled:opacity-50" type="button" :disabled="!canNext" @click="next">
            Next
          </button>
        </div>
      </div>
    </div>

    <div v-if="modalOpen" class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @click.self="closeModal">
      <div class="w-full max-w-2xl rounded-xl bg-white p-4 shadow">
        <div class="mb-3 flex items-center justify-between">
          <h3 class="text-lg font-semibold">{{ title }}</h3>
          <button class="rounded-lg px-2 py-1 text-slate-500 hover:bg-slate-100" type="button" @click="closeModal">✕</button>
        </div>

        <div class="grid gap-3 md:grid-cols-2">
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Nama</label>
            <input
              v-model="form.name"
              class="w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-slate-300"
              :class="fieldErrors.name ? 'border-red-300 bg-red-50' : ''"
              maxlength="150"
            />
            <div v-if="fieldErrors.name?.length" class="mt-1 text-xs text-red-700">{{ fieldErrors.name[0] }}</div>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Barcode</label>
            <input
              v-model="form.barcode"
              class="w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-slate-300"
              :class="fieldErrors.barcode ? 'border-red-300 bg-red-50' : ''"
              maxlength="64"
            />
            <div v-if="fieldErrors.barcode?.length" class="mt-1 text-xs text-red-700">{{ fieldErrors.barcode[0] }}</div>
          </div>

          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Harga Beli (Rp)</label>
            <input
              v-model.number="form.buying_price"
              type="number"
              min="0"
              step="0.01"
              class="w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-slate-300"
              :class="fieldErrors.buying_price ? 'border-red-300 bg-red-50' : ''"
            />
            <div v-if="fieldErrors.buying_price?.length" class="mt-1 text-xs text-red-700">{{ fieldErrors.buying_price[0] }}</div>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Harga Jual (Rp)</label>
            <input
              v-model.number="form.price"
              type="number"
              min="0"
              step="0.01"
              class="w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-slate-300"
              :class="fieldErrors.price ? 'border-red-300 bg-red-50' : ''"
            />
            <div v-if="fieldErrors.price?.length" class="mt-1 text-xs text-red-700">{{ fieldErrors.price[0] }}</div>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Stok</label>
            <input
              v-model.number="form.stock"
              type="number"
              min="0"
              step="1"
              class="w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-slate-300"
              :class="fieldErrors.stock ? 'border-red-300 bg-red-50' : ''"
            />
            <div v-if="fieldErrors.stock?.length" class="mt-1 text-xs text-red-700">{{ fieldErrors.stock[0] }}</div>
          </div>

          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Expiry Date</label>
            <input
              v-model="form.expiry_date"
              type="date"
              class="w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-slate-300"
              :class="fieldErrors.expiry_date ? 'border-red-300 bg-red-50' : ''"
            />
            <div v-if="fieldErrors.expiry_date?.length" class="mt-1 text-xs text-red-700">{{ fieldErrors.expiry_date[0] }}</div>
          </div>
            <div class="grid gap-3 md:grid-cols-2">
              <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Kategori</label>
                <select
                  v-model="form.category_id"
                  class="w-full rounded-lg border bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-slate-300"
                  :class="fieldErrors.category_id ? 'border-red-300 bg-red-50' : ''"
                >
                  <option value="">(Tanpa kategori)</option>
                  <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <div v-if="fieldErrors.category_id?.length" class="mt-1 text-xs text-red-700">{{ fieldErrors.category_id[0] }}</div>
              </div>
              <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Supplier</label>
                <select
                  v-model="form.supplier_id"
                  class="w-full rounded-lg border bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-slate-300"
                  :class="fieldErrors.supplier_id ? 'border-red-300 bg-red-50' : ''"
                >
                  <option value="">(Tanpa supplier)</option>
                  <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
                <div v-if="fieldErrors.supplier_id?.length" class="mt-1 text-xs text-red-700">{{ fieldErrors.supplier_id[0] }}</div>
              </div>
            </div>
            
            <div class="md:col-span-2 border-t border-slate-100 pt-3">
              <label class="mb-1 block text-sm font-medium text-slate-700">Gambar Produk</label>
              <div class="flex items-center gap-4">
                <div class="h-16 w-16 shrink-0 rounded-lg border border-slate-200 bg-slate-50 overflow-hidden flex items-center justify-center">
                  <img
                    v-if="tempImageUrl"
                    :src="tempImageUrl"
                    class="h-full w-full object-cover"
                    alt="Preview"
                  />
                  <span v-else class="text-[10px] text-slate-400 font-semibold uppercase">No Pic</span>
                </div>
                <div class="flex flex-col gap-1">
                  <input
                    type="file"
                    accept="image/*"
                    class="text-xs text-slate-500 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border file:border-slate-200 file:text-xs file:font-bold file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200 file:shadow-sm cursor-pointer"
                    @change="onImageChange"
                    :disabled="uploadLoading"
                  />
                  <div v-if="form.image" class="flex gap-2">
                    <button type="button" @click="clearImage" class="text-xs text-red-600 hover:underline">Hapus Gambar</button>
                  </div>
                  <div v-if="uploadLoading" class="text-xs text-slate-400 font-medium">Mengupload...</div>
                </div>
              </div>
              <div v-if="fieldErrors.image?.length" class="mt-1 text-xs text-red-700">{{ fieldErrors.image[0] }}</div>
            </div>
          </div>

        <div class="mt-4 flex justify-end gap-2">
          <button class="rounded-lg border px-3 py-2 text-sm hover:bg-slate-50" type="button" @click="closeModal">Batal</button>
          <button
            class="rounded-lg bg-slate-900 px-3 py-2 text-sm text-white hover:bg-slate-800 disabled:opacity-60"
            type="button"
            :disabled="saving || !form.name.trim()"
            @click="save"
          >
            {{ saving ? 'Menyimpan...' : 'Simpan' }}
          </button>
        </div>
      </div>
    </div>
  </AppShell>
</template>
