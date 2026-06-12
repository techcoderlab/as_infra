<script setup>
import { onMounted, ref } from 'vue'
import api from '@/utils/request'
import CopyButton from '@/components/ui/CopyButton.vue' // Ensure this path matches your structure
import { Dialog, DialogPanel, DialogTitle, TransitionRoot } from '@headlessui/vue'

const keys = ref([])
const loading = ref(false)
const showCreateModal = ref(false)
const showSuccessModal = ref(false)
const processing = ref(false)

// Form State
const isEditing = ref(false)
const editingId = ref(null)
const currentKey = ref(null)

const form = ref({
  name: '',
  abilities: [],
  expiration_days: 90,
})

const newKeyToken = ref('')
const permissionGroups = ref([])

const expiryOptions = [
  { label: '30 Days', value: 30 },
  { label: '60 Days', value: 60 },
  { label: '90 Days', value: 90 },
  { label: 'Never Expires', value: null },
]

const fetchKeys = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/api-keys')
    keys.value = data.keys || []
    permissionGroups.value = data.permission_groups || []
  } catch (e) {
    console.error(e)
  } finally {
    loading.value = false
  }
}

const openCreateModal = () => {
  isEditing.value = false
  editingId.value = null
  currentKey.value = null

  // Default to first available permission if possible, else empty
  const defaultAbility =
    permissionGroups.value.length > 0 && permissionGroups.value[0].scopes.length > 0
      ? [permissionGroups.value[0].scopes[0].id]
      : []

  form.value = {
    name: '',
    abilities: defaultAbility,
    expiration_days: 90,
  }

  showCreateModal.value = true
}

const openEditModal = (key) => {
  isEditing.value = true
  editingId.value = key.id
  currentKey.value = key
  form.value = {
    name: key.name,
    abilities: key.abilities?.length > 0 && !key.abilities?.includes('*') ? [...key.abilities] : [],
    // Expiration is not editable
  }
  showCreateModal.value = true
}

const saveKey = async () => {
  if (!form.value.name) return
  processing.value = true

  try {
    if (isEditing.value) {
      // Update
      const payload = {
        name: form.value.name,
      }

      if (form.value.abilities?.length > 0) {
        payload.abilities = form.value.abilities
      }

      const { data } = await api.put(`/api-keys/${editingId.value}`, payload)

      const index = keys.value.findIndex((k) => k.id === editingId.value)
      if (index !== -1) keys.value[index] = data.entry

      showCreateModal.value = false
    } else {
      // Create

      if (form.value.abilities?.length <= 0) delete form.value.abilities

      const { data } = await api.post('/api-keys', form.value)
      keys.value.unshift(data.entry)
      newKeyToken.value = data.token
      showCreateModal.value = false
      showSuccessModal.value = true
    }
  } catch (e) {
    console.error(`Failed: `, e)
    alert(`Failed: ` + (e.response?.data?.message || e.message))
  } finally {
    processing.value = false
  }
}

const rotateKey = async (key) => {
  if (
    !confirm(
      `Rotate key "${key.name}"?\n\nThis will DELETE the old key immediately and generate a new one.`,
    )
  )
    return

  processing.value = true
  try {
    const { data } = await api.post(`/api-keys/${key.id}/rotate`)

    keys.value = keys.value.filter((k) => k.id !== key.id)
    keys.value.unshift(data.entry)

    showCreateModal.value = false
    newKeyToken.value = data.token
    showSuccessModal.value = true
  } catch (e) {
    alert('Failed to rotate key: ' + (e.response?.data?.message || e.message))
  } finally {
    processing.value = false
  }
}

const revokeKey = async (key) => {
  if (!confirm(`Revoke "${key.name}"? This cannot be undone.`)) return
  try {
    await api.delete(`/api-keys/${key.id}`)
    keys.value = keys.value.filter((k) => k.id !== key.id)
    if (isEditing.value) showCreateModal.value = false
  } catch (e) {
    console.error(`Failed: `, e)
    alert(`Failed: ` + (e.response?.data?.message || e.message))
  }
}

// Helpers
const getExpiryStatus = (key) => {
  if (!key.expires_at)
    return {
      label: 'Never',
      class: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    }

  const created = new Date(key.created_at).getTime()
  const expires = new Date(key.expires_at).getTime()
  const now = new Date().getTime()

  if (now > expires)
    return {
      label: 'Expired',
      class: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
    }

  const totalLifespan = expires - created
  const remaining = expires - now
  const percentage = (remaining / totalLifespan) * 100

  const dateStr = formatDateString(key.expires_at)

  if (percentage <= 10) {
    return {
      label: `Expires ${dateStr}`,
      class:
        'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400 border border-yellow-200 dark:border-yellow-800',
    }
  }

  return {
    label: `Expires ${dateStr}`,
    class:
      'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 border border-green-200 dark:border-green-800',
  }
}

const formatDateString = (
  dateString,
  humanize = false,
  time = false,
  placeholder = '---',
  locale = 'en',
) => {
  const formatted = humanize
    ? humanizeDate(dateString, time, locale)
    : formatDate(dateString, time, locale)
  if (!formatted) return placeholder
  return formatted
}

onMounted(fetchKeys)
</script>
<template>
  <div class="space-y-6">
    <div class="page-header">
      <div>
        <h2 class="page-title">API Keys</h2>
        <p class="page-subtitle">Manage access tokens.</p>
      </div>
      <button @click="openCreateModal" class="btn-primary">+ Create Key</button>
    </div>

    <div v-if="loading" class="text-center py-12 text-slate-500">Loading...</div>
    <div v-else class="table-container">
      <table class="table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Permissions</th>
            <th>Expiry</th>
            <th>Last Used</th>
            <th>Created</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="key in keys" :key="key.id">
            <td>
              <div class="font-bold text-slate-900 dark:text-white">{{ key.name }}</div>
            </td>
            <td>
              <div class="flex flex-wrap gap-1">
                <span
                  v-for="abi in key.abilities?.slice(0, 2)"
                  :key="abi"
                  class="badge badge-slate"
                >
                  <span v-if="key.abilities?.[0] === '*'" class="">Full Access</span>
                  <span v-else>{{ abi }}</span>
                </span>

                <span v-if="key.abilities?.length > 2" class="text-xs text-slate-400"
                  >+{{ key.abilities?.length - 2 }}</span
                >
              </div>
            </td>
            <td>
              <span :class="['badge', getExpiryStatus(key).class]">{{
                getExpiryStatus(key).label
              }}</span>
            </td>
            <td>
              {{ formatDateString(key.last_used_at, false, false, 'Never') }}
            </td>
            <td>{{ formatDateString(key.created_at) }}</td>
            <td class="text-right space-x-2">
              <button
                @click="rotateKey(key)"
                title="Rotate Token"
                class="text-slate-500 hover:text-slate-800 dark:text-slate-400 text-xs font-bold uppercase tracking-wide"
              >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                  />
                </svg>
              </button>
              <span class="text-slate-300 dark:text-slate-700">|</span>
              <button
                @click="openEditModal(key)"
                class="text-blue-600 hover:text-blue-800 dark:text-blue-400 text-xs font-bold uppercase tracking-wide"
              >
                Edit
              </button>
              <span class="text-slate-300 dark:text-slate-700">|</span>
              <button
                @click="revokeKey(key)"
                class="text-red-600 hover:text-red-800 dark:text-red-400 text-xs font-bold uppercase tracking-wide"
              >
                Revoke
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <TransitionRoot appear :show="showCreateModal" as="template">
      <Dialog as="div" @close="showCreateModal = false" class="relative z-50">
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" />
        <div class="fixed inset-0 overflow-y-auto flex items-center justify-center p-4">
          <DialogPanel
            class="w-full max-w-2xl bg-white dark:bg-slate-900 rounded-xl shadow-xl p-6 border border-slate-200 dark:border-slate-800"
          >
            <DialogTitle class="text-lg font-bold mb-4 text-slate-900 dark:text-white">{{
              isEditing ? 'Edit Key' : 'Create API Key'
            }}</DialogTitle>

            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium mb-1 text-slate-700 dark:text-slate-300"
                  >Name</label
                >
                <input
                  v-model="form.name"
                  class="w-full rounded-lg border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 px-3 py-2 text-sm dark:text-white"
                  placeholder="Key Name"
                />
              </div>

              <div v-if="!isEditing">
                <label class="block text-sm font-medium mb-1 text-slate-700 dark:text-slate-300"
                  >Expiration</label
                >
                <select
                  v-model="form.expiration_days"
                  class="w-full rounded-lg border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 px-3 py-2 text-sm dark:text-white"
                >
                  <option v-for="o in expiryOptions" :value="o.value">{{ o.label }}</option>
                </select>
              </div>

              <div>
                <label class="block text-sm font-medium mb-2 text-slate-700 dark:text-slate-300"
                  >Permissions
                  <span class="text-xs text-slate-500"
                    >(If not specified, all permissions will be granted)</span
                  ></label
                >
                <div
                  class="grid grid-cols-1 md:grid-cols-2 gap-4 max-h-60 overflow-y-auto border border-slate-200 dark:border-slate-800 rounded-lg p-3 custom-scrollbar"
                >
                  <div v-for="grp in permissionGroups" :key="grp.name" class="mb-2">
                    <h4 class="text-xs font-bold uppercase text-slate-500 mb-2">{{ grp.name }}</h4>
                    <div v-for="sc in grp.scopes" :key="sc.id" class="flex items-start mb-2">
                      <input
                        type="checkbox"
                        :value="sc.id"
                        v-model="form.abilities"
                        class="mt-1 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                      />
                      <div class="ml-2">
                        <span
                          class="block text-sm font-medium text-slate-900 dark:text-slate-200"
                          >{{ sc.label }}</span
                        >
                        <span class="block text-xs text-slate-500">{{ sc.desc }}</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
              <button
                @click="showCreateModal = false"
                class="px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 rounded-lg"
              >
                Cancel
              </button>
              <button
                @click="saveKey"
                :disabled="processing || !form.name"
                class="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg disabled:opacity-50"
              >
                Save
              </button>
            </div>
          </DialogPanel>
        </div>
      </Dialog>
    </TransitionRoot>

    <TransitionRoot appear :show="showSuccessModal" as="template">
      <Dialog as="div" @close="showSuccessModal = false" class="relative z-50">
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" />
        <div class="fixed inset-0 flex items-center justify-center p-4">
          <DialogPanel
            class="max-w-md w-full bg-white dark:bg-slate-900 p-6 rounded-xl shadow-xl text-center border border-slate-200 dark:border-slate-800"
          >
            <div
              class="w-12 h-12 bg-green-100 text-green-600 rounded-full flex items-center justify-center mx-auto mb-4"
            >
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M5 13l4 4L19 7"
                ></path>
              </svg>
            </div>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">API Key Created</h3>
            <p class="text-sm text-slate-500 my-4">
              Please copy your key now. It will not be shown again.
            </p>

            <div
              class="flex items-center gap-2 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-lg p-3 text-left"
            >
              <code class="flex-1 text-xs font-mono text-slate-800 dark:text-slate-200 break-all">{{
                newKeyToken
              }}</code>
              <CopyButton :text="newKeyToken" />
            </div>

            <button
              @click="showSuccessModal = false"
              class="mt-6 w-full py-2 bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-medium rounded-lg"
            >
              Done
            </button>
          </DialogPanel>
        </div>
      </Dialog>
    </TransitionRoot>
  </div>
</template>
