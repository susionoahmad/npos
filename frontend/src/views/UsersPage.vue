<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import AppShell from '../components/AppShell.vue'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'
import { pickErrorMessage, pickFieldErrors } from '../utils/laravelErrors'

type User = {
  id: number
  name: string
  email: string
  role: 'admin' | 'owner' | 'cashier' | string
  is_active: boolean
}

const auth = useAuthStore()

const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)

const search = ref('')
const items = ref<User[]>([])

const modalOpen = ref(false)
const editingId = ref<number | null>(null)
const form = ref({
  name: '',
  email: '',
  role: 'cashier',
  is_active: true,
  password: '',
})
const fieldErrors = ref<Record<string, string[]>>({})

const title = computed(() => (editingId.value ? 'Edit Pengguna' : 'Tambah Pengguna'))
const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return items.value
  return items.value.filter((u) => `${u.name} ${u.email} ${u.role}`.toLowerCase().includes(q))
})

async function fetchUsers() {
  loading.value = true
  error.value = null
  try {
    const { data } = await api.get<User[]>('/users')
    items.value = data || []
  } catch (e: any) {
    error.value = e?.response?.data?.message || e?.message || 'Gagal memuat pengguna'
  } finally {
    loading.value = false
  }
}

function openCreate() {
  if (!auth.user?.store_id) {
    alert('Silakan pilih toko tertentu di menu sidebar terlebih dahulu untuk menambah data.')
    return
  }
  editingId.value = null
  form.value = { name: '', email: '', role: 'cashier', is_active: true, password: '' }
  modalOpen.value = true
}

function openEdit(u: User) {
  editingId.value = u.id
  form.value = { name: u.name ?? '', email: u.email ?? '', role: u.role ?? 'cashier', is_active: !!u.is_active, password: '' }
  modalOpen.value = true
}

function closeModal() {
  modalOpen.value = false
}

async function save() {
  const payload: any = {
    name: form.value.name.trim(),
    email: form.value.email.trim(),
    role: form.value.role,
    is_active: !!form.value.is_active,
  }

  // backend update allows password optional; create requires password (StoreUserRequest)
  if (editingId.value) {
    if (form.value.password.trim()) payload.password = form.value.password
  } else {
    payload.password = form.value.password
  }

  if (!payload.name || !payload.email) return
  if (!editingId.value && !payload.password) return

  saving.value = true
  error.value = null
  fieldErrors.value = {}
  try {
    if (editingId.value) {
      await api.put(`/users/${editingId.value}`, payload)
    } else {
      await api.post('/users', payload)
    }
    closeModal()
    await fetchUsers()
  } catch (e: any) {
    fieldErrors.value = pickFieldErrors(e)
    error.value = pickErrorMessage(e, 'Gagal menyimpan pengguna')
  } finally {
    saving.value = false
  }
}

async function removeUser(u: User) {
  if (!confirm(`Hapus user "${u.name}"?`)) return
  error.value = null
  try {
    await api.delete(`/users/${u.id}`)
    await fetchUsers()
  } catch (e: any) {
    error.value = e?.response?.data?.message || e?.message || 'Gagal menghapus pengguna'
  }
}

const canManageUsers = computed(() => auth.isAdminOrOwner)

onMounted(() => {
  if (canManageUsers.value) fetchUsers()
})
</script>

<template>
  <AppShell>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-2xl font-bold">Pengguna</h2>
        <p class="text-sm text-slate-500">Kelola pengguna (hanya admin/owner).</p>
      </div>

      <div class="flex items-center gap-2 w-full sm:w-auto">
        <input
          v-model="search"
          class="min-w-0 flex-1 sm:w-[240px] md:w-[320px] rounded-lg border bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-slate-300"
          placeholder="Cari nama / email / role..."
          :disabled="!canManageUsers"
        />
        <button
          class="shrink-0 rounded-lg bg-slate-900 px-3 py-2 text-sm text-white hover:bg-slate-800 disabled:opacity-60"
          type="button"
          :disabled="!canManageUsers"
          @click="openCreate"
        >
          Tambah
        </button>
      </div>
    </div>

    <div v-if="!canManageUsers" class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
      Akun ini tidak punya akses kelola pengguna.
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
              <th class="px-4 py-3 w-[260px]">Email</th>
              <th class="px-4 py-3 w-[140px]">Role</th>
              <th class="px-4 py-3 w-[120px]">Status</th>
              <th class="px-4 py-3 w-[180px] text-right">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!canManageUsers">
              <td colspan="6" class="px-4 py-10 text-center text-slate-500">Tidak ada akses</td>
            </tr>
            <tr v-else-if="loading">
              <td colspan="6" class="px-4 py-10 text-center text-slate-500">Loading...</td>
            </tr>
            <tr v-else-if="filtered.length === 0">
              <td colspan="6" class="px-4 py-10 text-center text-slate-500">Tidak ada data</td>
            </tr>
            <tr v-else v-for="(u, index) in filtered" :key="u.id" class="border-t">
              <td class="px-4 py-3 text-slate-500">{{ index + 1 }}</td>
              <td class="px-4 py-3 font-medium">{{ u.name }}</td>
              <td class="px-4 py-3 text-slate-600">{{ u.email }}</td>
              <td class="px-4 py-3 text-slate-600">{{ u.role }}</td>
              <td class="px-4 py-3">
                <span
                  class="inline-flex rounded-full px-2 py-0.5 text-xs"
                  :class="u.is_active ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-700 border border-slate-200'"
                >
                  {{ u.is_active ? 'Aktif' : 'Nonaktif' }}
                </span>
              </td>
              <td class="px-4 py-3 text-right">
                <button class="rounded-lg border px-3 py-1.5 text-sm hover:bg-slate-50" type="button" @click="openEdit(u)">
                  Edit
                </button>
                <button
                  class="ml-2 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-sm text-red-700 hover:bg-red-100"
                  type="button"
                  @click="removeUser(u)"
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
            <label class="mb-1 block text-sm font-medium text-slate-700">Role</label>
            <select
              v-model="form.role"
              class="w-full rounded-lg border bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-slate-300 disabled:bg-slate-100 disabled:text-slate-500"
              :class="fieldErrors.role ? 'border-red-300 bg-red-50' : ''"
              :disabled="auth.user?.role === 'admin'"
            >
              <option value="cashier">cashier</option>
              <option value="admin">admin</option>
              <option value="owner">owner</option>
            </select>
            <div v-if="fieldErrors.role?.length" class="mt-1 text-xs text-red-700">{{ fieldErrors.role[0] }}</div>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Status</label>
            <select
              v-model="form.is_active"
              class="w-full rounded-lg border bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-slate-300"
              :class="fieldErrors.is_active ? 'border-red-300 bg-red-50' : ''"
            >
              <option :value="true">Aktif</option>
              <option :value="false">Nonaktif</option>
            </select>
            <div v-if="fieldErrors.is_active?.length" class="mt-1 text-xs text-red-700">{{ fieldErrors.is_active[0] }}</div>
          </div>
          <div class="md:col-span-2">
            <label class="mb-1 block text-sm font-medium text-slate-700">
              Password <span class="text-xs text-slate-500">{{ editingId ? '(kosongkan jika tidak diganti)' : '(wajib)' }}</span>
            </label>
            <input
              v-model="form.password"
              type="password"
              class="w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-slate-300"
              :class="fieldErrors.password ? 'border-red-300 bg-red-50' : ''"
            />
            <div v-if="fieldErrors.password?.length" class="mt-1 text-xs text-red-700">{{ fieldErrors.password[0] }}</div>
          </div>
        </div>

        <div class="mt-4 flex justify-end gap-2">
          <button class="rounded-lg border px-3 py-2 text-sm hover:bg-slate-50" type="button" @click="closeModal">Batal</button>
          <button
            class="rounded-lg bg-slate-900 px-3 py-2 text-sm text-white hover:bg-slate-800 disabled:opacity-60"
            type="button"
            :disabled="saving || !form.name.trim() || !form.email.trim() || (!editingId && !form.password)"
            @click="save"
          >
            {{ saving ? 'Menyimpan...' : 'Simpan' }}
          </button>
        </div>
      </div>
    </div>
  </AppShell>
</template>
