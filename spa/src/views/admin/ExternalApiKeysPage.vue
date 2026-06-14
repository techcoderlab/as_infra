<script setup>
import { onMounted, ref } from 'vue'
import api from '@/utils/request'
import CopyButton from '@/components/ui/CopyButton.vue'
import { Dialog, DialogPanel, DialogTitle, TransitionRoot } from '@headlessui/vue'

const keys = ref([])
const loading = ref(false)
const showCreateModal = ref(false)
const showSuccessModal = ref(false)
const processing = ref(false)

const form = ref({
  for: '',
})

const newKeyToken = ref('')
const newAppId = ref('')

const fetchKeys = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/external-api-keys')
    keys.value = data.keys || []
  } catch (e) {
    console.error(e)
  } finally {
    loading.value = false
  }
}

const openCreateModal = () => {
  form.value = { for: '' }
  showCreateModal.value = true
}

const saveKey = async () => {
  if (!form.value.for) return
  processing.value = true

  try {
    const { data } = await api.post('/external-api-keys', form.value)
    keys.value.unshift(data.entry)
    newAppId.value = data.entry.app_id
    newKeyToken.value = data.token
    showCreateModal.value = false
    showSuccessModal.value = true
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
      `Rotate key for "${key.for}"?\n\nThis will DELETE the old secret immediately and generate a new one.`,
    )
  )
    return

  processing.value = true
  try {
    const { data } = await api.post(`/external-api-keys/${key.id}/rotate`)

    keys.value = keys.value.filter((k) => k.id !== key.id)
    keys.value.unshift(data.entry)

    showCreateModal.value = false
    newAppId.value = data.entry.app_id
    newKeyToken.value = data.token
    showSuccessModal.value = true
  } catch (e) {
    alert('Failed to rotate key: ' + (e.response?.data?.message || e.message))
  } finally {
    processing.value = false
  }
}

const revokeKey = async (key) => {
  if (!confirm(`Revoke key for "${key.for}"? This cannot be undone.`)) return
  try {
    await api.delete(`/external-api-keys/${key.id}`)
    keys.value = keys.value.filter((k) => k.id !== key.id)
  } catch (e) {
    console.error(`Failed: `, e)
    alert(`Failed: ` + (e.response?.data?.message || e.message))
  }
}

const formatDateString = (dateString, placeholder = '---') => {
  if (!dateString) return placeholder
  return new Date(dateString).toLocaleDateString()
}

onMounted(fetchKeys)
</script>

<template>
  <div class="space-y-6">
    <div class="page-header">
      <div>
        <h2 class="page-title">External API Keys</h2>
        <p class="page-subtitle">Manage app-to-app access tokens (e.g. for Sidecar communication).</p>
      </div>
      <button @click="openCreateModal" class="btn-primary">+ Create Key</button>
    </div>

    <div v-if="loading" class="text-center py-12 text-slate-500">Loading...</div>
    <div v-else class="table-container">
      <table class="table">
        <thead>
          <tr>
            <th>Application (For)</th>
            <th>App ID</th>
            <th>Status</th>
            <th>Created</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="key in keys" :key="key.id">
            <td>
              <div class="font-bold text-slate-900 dark:text-white">{{ key.for }}</div>
            </td>
            <td>
              <code class="text-xs bg-slate-100 dark:bg-slate-800 px-2 py-1 rounded">{{ key.app_id }}</code>
            </td>
            <td>
              <span :class="['badge', key.is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700']">
                {{ key.is_active ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td>{{ formatDateString(key.created_at) }}</td>
            <td class="text-right space-x-2">
              <button
                @click="rotateKey(key)"
                title="Rotate Token"
                class="text-slate-500 hover:text-slate-800 dark:text-slate-400 text-xs font-bold uppercase tracking-wide"
              >
                Rotate
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
      <div v-if="keys.length === 0" class="text-center py-8 text-slate-500 text-sm">
        No external API keys found.
      </div>
    </div>

    <TransitionRoot appear :show="showCreateModal" as="template">
      <Dialog as="div" @close="showCreateModal = false" class="relative z-50">
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" />
        <div class="fixed inset-0 overflow-y-auto flex items-center justify-center p-4">
          <DialogPanel
            class="w-full max-w-lg bg-white dark:bg-slate-900 rounded-xl shadow-xl p-6 border border-slate-200 dark:border-slate-800"
          >
            <DialogTitle class="text-lg font-bold mb-4 text-slate-900 dark:text-white">Create External API Key</DialogTitle>

            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium mb-1 text-slate-700 dark:text-slate-300"
                  >Purpose (For)</label
                >
                <input
                  v-model="form.for"
                  class="w-full rounded-lg border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 px-3 py-2 text-sm dark:text-white"
                  placeholder="e.g. Python Sidecar Worker"
                />
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
                :disabled="processing || !form.for"
                class="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg disabled:opacity-50"
              >
                Generate Key
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
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
              </svg>
            </div>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Credentials Generated</h3>
            <p class="text-sm text-slate-500 my-4">
              Please copy these credentials now. The secret will not be shown again.
            </p>

            <div class="space-y-3 mb-6">
              <div class="text-left">
                <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">App ID</label>
                <div class="flex items-center gap-2 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-lg p-2">
                  <code class="flex-1 text-xs font-mono text-slate-800 dark:text-slate-200 break-all">{{ newAppId }}</code>
                  <CopyButton :text="newAppId" />
                </div>
              </div>

              <div class="text-left">
                <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">Secret Key</label>
                <div class="flex items-center gap-2 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-lg p-2">
                  <code class="flex-1 text-xs font-mono text-slate-800 dark:text-slate-200 break-all">{{ newKeyToken }}</code>
                  <CopyButton :text="newKeyToken" />
                </div>
              </div>
            </div>

            <button
              @click="showSuccessModal = false"
              class="w-full py-2 bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-medium rounded-lg"
            >
              I have copied them safely
            </button>
          </DialogPanel>
        </div>
      </Dialog>
    </TransitionRoot>
  </div>
</template>
