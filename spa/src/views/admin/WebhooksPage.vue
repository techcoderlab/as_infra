<script setup>
import { onMounted, ref, reactive, computed } from 'vue'
import api from '../../utils/request'

const webhooks = ref([])
const forms = ref([])
const loading = ref(false)
const saving = ref(false)

// Modal state
const showModal = ref(false)
const isEditing = ref(false)
const editingId = ref(null)

const defaultForm = () => ({ name: '', url: '', method: 'POST', secret: '', events: [], form_id: null, is_active: true })
const form = reactive(defaultForm())
const errors = reactive({ url: '', events: '', form_id: '' })

const availableMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']

const availableEvents = [
  { label: 'Lead Created', value: 'lead.created' },
  { label: 'Lead Status Update', value: 'lead.updated.status' },
  { label: 'Any Update', value: 'lead.updated' },
  { label: 'Form Submission', value: 'form.submission' },
]

const SUBMISSION_EVENT = 'form.submission'

const handleEventChange = (eventValue) => {
  if (eventValue === SUBMISSION_EVENT && form.events.includes(SUBMISSION_EVENT)) {
    form.events = [SUBMISSION_EVENT]
  }
}

const isOtherDisabled = (eventValue) =>
  form.events.includes(SUBMISSION_EVENT) && eventValue !== SUBMISSION_EVENT

// --- Data Fetching ---
const fetchWebhooks = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/webhooks')
    webhooks.value = data
  } catch (e) {
    console.error(e)
  } finally {
    loading.value = false
  }
}

const fetchForms = async () => {
  try {
    const { data } = await api.get('/forms')
    forms.value = data
  } catch (e) {}
}

// --- Modal Control ---
const openCreate = () => {
  isEditing.value = false
  editingId.value = null
  Object.assign(form, defaultForm())
  Object.assign(errors, { url: '', events: '', form_id: '' })
  showModal.value = true
}

const openEdit = (hook) => {
  isEditing.value = true
  editingId.value = hook.id
  Object.assign(form, {
    name: hook.name || '',
    url: hook.url || '',
    method: hook.method || 'POST',
    secret: '', // never pre-fill secrets
    events: [...(hook.events || [])],
    form_id: hook.form_id || null,
    is_active: hook.is_active,
  })
  Object.assign(errors, { url: '', events: '', form_id: '' })
  showModal.value = true
}

// --- Validation ---
const validateForm = () => {
  let isValid = true
  Object.assign(errors, { url: '', events: '', form_id: '' })

  if (!form.url) {
    errors.url = 'Payload URL is required.'
    isValid = false
  }
  if (form.events.length === 0) {
    errors.events = 'Please select at least one event.'
    isValid = false
  }
  if (form.events.includes('form.submission') && !form.form_id) {
    errors.form_id = 'Please select a form to attach.'
    isValid = false
  }
  return isValid
}

// --- Save (Create or Update) ---
const saveWebhook = async () => {
  if (!validateForm()) return
  saving.value = true

  // Only include secret in payload if user typed one
  const payload = { ...form }
  if (!payload.secret) delete payload.secret

  try {
    if (isEditing.value) {
      await api.put(`/webhooks/${editingId.value}`, payload)
    } else {
      await api.post('/webhooks', payload)
    }
    showModal.value = false
    await fetchWebhooks()
  } catch (e) {
    const msg = e.response?.data?.message || (isEditing.value ? 'Failed to update webhook.' : 'Failed to create webhook.')
    alert(msg)
  } finally {
    saving.value = false
  }
}

// --- Delete ---
const deleteWebhook = async (id) => {
  if (!confirm('Permanently delete this webhook?')) return
  try {
    await api.delete(`/webhooks/${id}`)
    await fetchWebhooks()
  } catch (e) {
    alert(e.response?.data?.message || 'Failed to delete webhook.')
  }
}

// --- Method badge color ---
const methodColor = (method) => {
  const map = {
    POST: 'bg-blue-50 text-blue-600 dark:bg-blue-900/20 dark:text-blue-400',
    PUT: 'bg-amber-50 text-amber-600 dark:bg-amber-900/20 dark:text-amber-400',
    PATCH: 'bg-orange-50 text-orange-600 dark:bg-orange-900/20 dark:text-orange-400',
    GET: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/20 dark:text-emerald-400',
    DELETE: 'bg-red-50 text-red-600 dark:bg-red-900/20 dark:text-red-400',
  }
  return map[method] || 'bg-slate-100 text-slate-600'
}

onMounted(() => {
  fetchWebhooks()
  fetchForms()
})
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="page-header">
      <div>
        <h2 class="page-title">Webhooks</h2>
        <p class="page-subtitle">Manage outgoing event triggers to external systems.</p>
      </div>
      <button @click="openCreate" class="btn-primary">+ Add Webhook</button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-12 text-slate-500">Loading...</div>

    <!-- Grid -->
    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <div
        v-if="webhooks.length === 0"
        class="col-span-full py-16 text-center border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-xl text-slate-400"
      >
        <svg class="w-10 h-10 mx-auto mb-3 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z" />
        </svg>
        <p class="font-medium">No webhooks yet</p>
        <p class="text-sm mt-1">Click <strong>+ Add Webhook</strong> to get started.</p>
      </div>

      <div v-for="hook in webhooks" :key="hook.id" class="card p-5 group flex flex-col justify-between gap-4">
        <!-- Top row -->
        <div class="flex justify-between items-start">
          <div class="flex items-center gap-3">
            <div class="p-2 bg-purple-50 dark:bg-purple-900/20 text-purple-600 rounded-lg flex-shrink-0">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
              </svg>
            </div>
            <div>
              <div class="flex items-center gap-2">
                <h3 class="font-bold text-slate-900 dark:text-white text-sm">{{ hook.name || 'Unnamed' }}</h3>
                <span v-if="hook.is_active" class="badge badge-green">Active</span>
                <span v-else class="badge badge-slate">Inactive</span>
              </div>
              <span v-if="hook.form" class="text-[10px] text-slate-500">Attached: {{ hook.form.name }}</span>
            </div>
          </div>
          <!-- Method badge -->
          <span class="text-[10px] font-bold font-mono px-2 py-0.5 rounded" :class="methodColor(hook.method || 'POST')">
            {{ hook.method || 'POST' }}
          </span>
        </div>

        <!-- URL -->
        <div>
          <label class="form-label">Endpoint</label>
          <div class="text-xs font-mono bg-slate-50 dark:bg-slate-950 p-2 rounded border border-slate-100 dark:border-slate-800 break-all">
            {{ hook.url }}
          </div>
        </div>

        <!-- Events -->
        <div>
          <label class="form-label">Triggers on</label>
          <div class="flex flex-wrap gap-1 mt-1">
            <span v-for="ev in hook.events" :key="ev" class="badge badge-slate text-[10px]">{{ ev }}</span>
          </div>
        </div>

        <!-- Actions -->
        <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
          <button
            @click="openEdit(hook)"
            class="text-xs font-bold text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-white uppercase tracking-wide transition-colors"
          >
            Edit
          </button>
          <span class="text-slate-300 dark:text-slate-700">|</span>
          <button
            @click="deleteWebhook(hook.id)"
            class="text-xs font-bold text-red-400 hover:text-red-600 uppercase tracking-wide transition-colors"
          >
            Delete
          </button>
        </div>
      </div>
    </div>

    <!-- Create / Edit Modal -->
    <Transition name="modal">
      <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="showModal = false"></div>
        <div class="relative w-full max-w-lg bg-white dark:bg-slate-950 shadow-2xl rounded-xl border border-slate-200 dark:border-slate-800 flex flex-col max-h-[90vh]">
          <!-- Header -->
          <div class="p-6 border-b border-slate-100 dark:border-slate-800">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">
              {{ isEditing ? 'Edit Webhook' : 'New Webhook' }}
            </h3>
          </div>

          <!-- Body -->
          <div class="p-6 overflow-y-auto space-y-4">
            <!-- Name -->
            <div class="form-group">
              <label class="form-label">Name</label>
              <input v-model="form.name" class="form-input" placeholder="e.g. N8N Integration" />
            </div>

            <!-- URL + Method row -->
            <div class="form-group">
              <label class="form-label">Endpoint URL <span class="text-red-500">*</span></label>
              <div class="flex gap-2">
                <select v-model="form.method" class="form-select w-28 flex-shrink-0">
                  <option v-for="m in availableMethods" :key="m" :value="m">{{ m }}</option>
                </select>
                <input
                  v-model="form.url"
                  type="url"
                  class="form-input flex-1"
                  placeholder="https://..."
                  :class="{ 'border-red-500': errors.url }"
                />
              </div>
              <span v-if="errors.url" class="text-xs text-red-500 mt-1">{{ errors.url }}</span>
            </div>

            <!-- Secret -->
            <div class="form-group">
              <label class="form-label">
                Secret
                <span v-if="isEditing" class="text-xs text-slate-400 normal-case font-normal ml-1">(leave blank to keep existing)</span>
              </label>
              <input
                v-model="form.secret"
                class="form-input"
                type="password"
                autocomplete="new-password"
                placeholder="Optional signing secret"
              />
            </div>

            <!-- Active toggle (edit only) -->
            <div v-if="isEditing" class="flex items-center justify-between p-3 bg-slate-50 dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800">
              <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Webhook Active</span>
              <button
                type="button"
                @click="form.is_active = !form.is_active"
                class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors"
                :class="form.is_active ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-700'"
              >
                <span
                  class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                  :class="form.is_active ? 'translate-x-6' : 'translate-x-1'"
                />
              </button>
            </div>

            <!-- Events -->
            <div class="form-group">
              <label class="form-label">Trigger Events <span class="text-red-500">*</span></label>
              <div
                class="space-y-2 max-h-40 overflow-y-auto border border-slate-200 dark:border-slate-700 rounded-lg p-3"
                :class="{ 'border-red-500': errors.events }"
              >
                <label
                  v-for="ev in availableEvents"
                  :key="ev.value"
                  class="flex items-center gap-2.5 cursor-pointer select-none"
                  :class="{ 'opacity-40 cursor-not-allowed': isOtherDisabled(ev.value) }"
                >
                  <input
                    type="checkbox"
                    v-model="form.events"
                    :value="ev.value"
                    class="rounded border-slate-300 text-slate-900 focus:ring-slate-900"
                    :disabled="isOtherDisabled(ev.value)"
                    @change="handleEventChange(ev.value)"
                  />
                  <span class="text-sm text-slate-700 dark:text-slate-300">{{ ev.label }}</span>
                </label>
              </div>
              <span v-if="errors.events" class="text-xs text-red-500 mt-1">{{ errors.events }}</span>
            </div>

            <!-- Form selection (conditional) -->
            <div v-if="form.events.includes('form.submission')" class="form-group">
              <label class="form-label">Attach Form <span class="text-red-500">*</span></label>
              <select v-model="form.form_id" class="form-select" :class="{ 'border-red-500': errors.form_id }">
                <option :value="null" disabled>Choose a form</option>
                <option v-for="f in forms" :key="f.id" :value="f.id">{{ f.name }}</option>
              </select>
              <span v-if="errors.form_id" class="text-xs text-red-500 mt-1">{{ errors.form_id }}</span>
            </div>
          </div>

          <!-- Footer -->
          <div class="flex justify-end gap-3 p-6 border-t border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/50 rounded-b-xl">
            <button @click="showModal = false" class="btn-secondary">Cancel</button>
            <button @click="saveWebhook" class="btn-primary" :disabled="saving">
              {{ saving ? 'Saving...' : isEditing ? 'Update Webhook' : 'Create Webhook' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}
.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}
</style>