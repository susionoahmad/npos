<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import AppShell from '../components/AppShell.vue'
import api from '../services/api'
import { pickErrorMessage, pickFieldErrors } from '../utils/laravelErrors'
import { useAuthStore } from '../stores/auth'

type Supplier = {
  id: number
  name: string
  phone: string | null
  email: string | null
  address: string | null
}

const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const authStore = useAuthStore()

const search = ref('')
const items = ref<Supplier[]>([])

const modalOpen = ref(false)
const editingId = ref<number | null>(null)
const form = ref({
  name: '',
  phone: '',
  email: '',
  address: '',
})
const fieldErrors = ref<Record<string, string[]>>({})

const title = computed(() => (editingId.value ? 'Edit Supplier' : 'Tambah Supplier'))

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return items.value
  return items.value.filter((s) => {
    const hay = `${s.name || ''} ${s.phone || ''} ${s.email || ''} ${s.address || ''}`.toLowerCase()
    return hay.includes(q)
  })
})

async function fetchSuppliers() {
  loading.value = true
  error.value = null
  try {
    const { data } = await api.get<Supplier[]>('/suppliers')
    items.value = data || []
  } catch (e: any) {
    error.value = e?.response?.data?.message || e?.message || 'Gagal memuat supplier'
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
  form.value = { name: '', phone: '', email: '', address: '' }
  modalOpen.value = true
}

function openEdit(s: Supplier) {
  editingId.value = s.id
  form.value = {
    name: s.name ?? '',
    phone: s.phone ?? '',
    email: s.email ?? '',
    address: s.address ?? '',
  }
  modalOpen.value = true
}

function closeModal() {
  modalOpen.value = false
}

async function save() {
  const payload = {
    name: form.value.name.trim(),
    phone: form.value.phone.trim() ? form.value.phone.trim() : null,
    email: form.value.email.trim() ? form.value.email.trim() : null,
    address: form.value.address.trim() ? form.value.address.trim() : null,
  }
  if (!payload.name) return

  saving.value = true
  error.value = null
  fieldErrors.value = {}
  try {
    if (editingId.value) {
      await api.put(`/suppliers/${editingId.value}`, payload)
    } else {
      await api.post('/suppliers', payload)
    }
    closeModal()
    await fetchSuppliers()
  } catch (e: any) {
    fieldErrors.value = pickFieldErrors(e)
    error.value = pickErrorMessage(e, 'Gagal menyimpan supplier')
  } finally {
    saving.value = false
  }
}

async function removeSupplier(s: Supplier) {
  if (!confirm(`Hapus supplier "${s.name}"?`)) return
  error.value = null
  try {
    await api.delete(`/suppliers/${s.id}`)
    await fetchSuppliers()
  } catch (e: any) {
    error.value = e?.response?.data?.message || e?.message || 'Gagal menghapus supplier'
  }
}

onMounted(fetchSuppliers)
</script>

<template>
  <AppShell>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-2xl font-bold">Supplier</h2>
        <p class="text-sm text-slate-500">Kelola supplier per toko.</p>
      </div>

      <div class="flex items-center gap-2">
        <input
          v-model="search"
          class="w-[320px] rounded-lg border bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-slate-300"
          placeholder="Cari supplier..."
        />
        <button class="rounded-lg bg-slate-900 px-3 py-2 text-sm text-white hover:bg-slate-800" type="button" @click="openCreate">
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
              <th class="px-4 py-3 w-[160px]">Phone</th>
              <th class="px-4 py-3 w-[220px]">Email</th>
              <th class="px-4 py-3">Alamat</th>
              <th class="px-4 py-3 w-[180px] text-right">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="6" class="px-4 py-10 text-center text-slate-500">Loading...</td>
            </tr>
            <tr v-else-if="filtered.length === 0">
              <td colspan="6" class="px-4 py-10 text-center text-slate-500">Tidak ada data</td>
            </tr>
            <tr v-else v-for="s in filtered" :key="s.id" class="border-t">
              <td class="px-4 py-3 text-slate-500">{{ s.id }}</td>
              <td class="px-4 py-3 font-medium">{{ s.name }}</td>
              <td class="px-4 py-3 text-slate-600">{{ s.phone || '-' }}</td>
              <td class="px-4 py-3 text-slate-600">{{ s.email || '-' }}</td>
              <td class="px-4 py-3 text-slate-600">{{ s.address || '-' }}</td>
              <td class="px-4 py-3 text-right">
                <button class="rounded-lg border px-3 py-1.5 text-sm hover:bg-slate-50" type="button" @click="openEdit(s)">
                  Edit
                </button>
                <button
                  class="ml-2 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-sm text-red-700 hover:bg-red-100"
                  type="button"
                  @click="removeSupplier(s)"
                >
                  Hapus
                </button>
              </td>
            </tr>
          </tbody>
        </table>
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
              maxlength="120"
            />
            <div v-if="fieldErrors.name?.length" class="mt-1 text-xs text-red-700">{{ fieldErrors.name[0] }}</div>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Phone</label>
            <input
              v-model="form.phone"
              class="w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-slate-300"
              :class="fieldErrors.phone ? 'border-red-300 bg-red-50' : ''"
              maxlength="32"
            />
            <div v-if="fieldErrors.phone?.length" class="mt-1 text-xs text-red-700">{{ fieldErrors.phone[0] }}</div>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Email</label>
            <input
              v-model="form.email"
              type="email"
              class="w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-slate-300"
              :class="fieldErrors.email ? 'border-red-300 bg-red-50' : ''"
              maxlength="120"
            />
            <div v-if="fieldErrors.email?.length" class="mt-1 text-xs text-red-700">{{ fieldErrors.email[0] }}</div>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Alamat</label>
            <input
              v-model="form.address"
              class="w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-slate-300"
              :class="fieldErrors.address ? 'border-red-300 bg-red-50' : ''"
            />
            <div v-if="fieldErrors.address?.length" class="mt-1 text-xs text-red-700">{{ fieldErrors.address[0] }}</div>
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
