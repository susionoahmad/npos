<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import AppShell from '../components/AppShell.vue'
import api from '../services/api'
import { pickErrorMessage, pickFieldErrors } from '../utils/laravelErrors'
import { useAuthStore } from '../stores/auth'

type Category = { id: number; name: string }
type Paginated<T> = {
  data: T[]
  current_page: number
  last_page: number
  total: number
}

const loading = ref(false)
const error = ref<string | null>(null)
const authStore = useAuthStore()

const search = ref('')
const page = ref(1)
const perPage = ref(50)

const items = ref<Category[]>([])
const currentPage = ref(1)
const lastPage = ref(1)
const total = ref(0)

const modalOpen = ref(false)
const editingId = ref<number | null>(null)
const formName = ref('')
const saving = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})

const title = computed(() => (editingId.value ? 'Edit Kategori' : 'Tambah Kategori'))
const canPrev = computed(() => currentPage.value > 1)
const canNext = computed(() => currentPage.value < lastPage.value)

async function fetchCategories() {
  loading.value = true
  error.value = null
  try {
    const params: any = { page: page.value, per_page: perPage.value }
    if (search.value.trim()) params.search = search.value.trim()
    const { data } = await api.get<Paginated<Category>>('/categories', { params })
    items.value = data.data || []
    currentPage.value = data.current_page || 1
    lastPage.value = data.last_page || 1
    total.value = data.total ?? items.value.length
  } catch (e: any) {
    error.value = e?.response?.data?.message || e?.message || 'Gagal memuat kategori'
  } finally {
    loading.value = false
  }
}

function openCreate() {
  if (!authStore.user?.store_id) {
    alert('Silakan pilih toko tertentu di menu sidebar terlebih dahulu untuk menambah data.')
    return
  }
  editingId.value = null
  formName.value = ''
  modalOpen.value = true
}

function openEdit(c: Category) {
  editingId.value = c.id
  formName.value = c.name
  modalOpen.value = true
}

function closeModal() {
  modalOpen.value = false
}

async function save() {
  const name = formName.value.trim()
  if (!name) return
  saving.value = true
  error.value = null
  fieldErrors.value = {}
  try {
    if (editingId.value) {
      await api.put(`/categories/${editingId.value}`, { name })
    } else {
      await api.post('/categories', { name })
    }
    closeModal()
    await fetchCategories()
  } catch (e: any) {
    fieldErrors.value = pickFieldErrors(e)
    error.value = pickErrorMessage(e, 'Gagal menyimpan kategori')
  } finally {
    saving.value = false
  }
}

async function remove(c: Category) {
  if (!confirm(`Hapus kategori "${c.name}"?`)) return
  error.value = null
  try {
    await api.delete(`/categories/${c.id}`)
    await fetchCategories()
  } catch (e: any) {
    error.value = e?.response?.data?.message || e?.message || 'Gagal menghapus kategori'
  }
}

let searchTimer: number | null = null
function onSearchInput() {
  if (searchTimer) window.clearTimeout(searchTimer)
  searchTimer = window.setTimeout(() => {
    page.value = 1
    fetchCategories()
  }, 350)
}

function prev() {
  if (!canPrev.value) return
  page.value = currentPage.value - 1
  fetchCategories()
}

function next() {
  if (!canNext.value) return
  page.value = currentPage.value + 1
  fetchCategories()
}

onMounted(fetchCategories)
</script>

<template>
  <AppShell>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-2xl font-bold">Kategori</h2>
        <p class="text-sm text-slate-500">Kelola kategori per toko.</p>
      </div>

      <div class="flex items-center gap-2 w-full sm:w-auto">
        <input
          v-model="search"
          class="min-w-0 flex-1 sm:w-[200px] md:w-[260px] rounded-lg border bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-slate-300"
          placeholder="Cari kategori..."
          @input="onSearchInput"
        />
        <button class="shrink-0 rounded-lg bg-slate-900 px-3 py-2 text-sm text-white hover:bg-slate-800" type="button" @click="openCreate">
          Tambah
        </button>
      </div>
    </div>

    <div v-if="error" class="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
      {{ error }}
    </div>

    <div class="overflow-hidden rounded-xl border bg-white">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-left text-slate-600">
            <tr>
              <th class="px-4 py-3 w-[90px]">ID</th>
              <th class="px-4 py-3">Nama</th>
              <th class="px-4 py-3 text-right w-[180px]">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="3" class="px-4 py-10 text-center text-slate-500">Loading...</td>
            </tr>
            <tr v-else-if="items.length === 0">
              <td colspan="3" class="px-4 py-10 text-center text-slate-500">Tidak ada data</td>
            </tr>
            <tr v-else v-for="(c, index) in items" :key="c.id" class="border-t">
              <td class="px-4 py-3 text-slate-500">{{ (currentPage - 1) * perPage + index + 1 }}</td>
              <td class="px-4 py-3 font-medium">{{ c.name }}</td>
              <td class="px-4 py-3 text-right">
                <button class="rounded-lg border px-3 py-1.5 text-sm hover:bg-slate-50" type="button" @click="openEdit(c)">
                  Edit
                </button>
                <button class="ml-2 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-sm text-red-700 hover:bg-red-100" type="button" @click="remove(c)">
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
      <div class="w-full max-w-md rounded-xl bg-white p-4 shadow">
        <div class="mb-3 flex items-center justify-between">
          <h3 class="text-lg font-semibold">{{ title }}</h3>
          <button class="rounded-lg px-2 py-1 text-slate-500 hover:bg-slate-100" type="button" @click="closeModal">✕</button>
        </div>

        <label class="mb-1 block text-sm font-medium text-slate-700">Nama</label>
        <input
          v-model="formName"
          class="w-full rounded-lg border bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-slate-300"
          :class="fieldErrors.name ? 'border-red-300 bg-red-50' : ''"
          maxlength="100"
          placeholder="Contoh: Snack"
          @keyup.enter="save"
        />
        <div v-if="fieldErrors.name?.length" class="mt-1 text-xs text-red-700">
          {{ fieldErrors.name[0] }}
        </div>

        <div class="mt-4 flex justify-end gap-2">
          <button class="rounded-lg border px-3 py-2 text-sm hover:bg-slate-50" type="button" @click="closeModal">Batal</button>
          <button
            class="rounded-lg bg-slate-900 px-3 py-2 text-sm text-white hover:bg-slate-800 disabled:opacity-60"
            type="button"
            :disabled="saving || !formName.trim()"
            @click="save"
          >
            {{ saving ? 'Menyimpan...' : 'Simpan' }}
          </button>
        </div>
      </div>
    </div>
  </AppShell>
</template>
