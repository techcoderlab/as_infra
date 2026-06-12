<script setup>
import { onMounted, ref, watch, computed } from 'vue'
import api from '@/utils/request'
import { formatDate, humanizeDate } from '@/utils/helpers'
import { Dialog, DialogPanel, DialogTitle, TransitionRoot } from '@headlessui/vue'

const loading = ref(false)
const showModal = ref(false)
const processing = ref(false)
const isEditing = ref(false)
const activeIntegrations = ref([])
const availableServices = ref([])

// Form State
const selectedServiceId = ref('')
const editingId = ref(null)

const configForm = ref({})
const metaForm = ref({
  is_active: true,
  is_brain: false,
})

// Track original values for "dirty" checking
const originalConfig = ref({})
const originalMeta = ref({})

const currentServiceDef = computed(() => {
  return availableServices.value.find((s) => s.id === selectedServiceId.value)
})

const fetchData = async () => {
  loading.value = true
  try {
    const [activeRes, availableRes] = await Promise.all([
      api.get('/integrations'),
      api.get('/integrations/services'),
    ])
    activeIntegrations.value = activeRes.data || []
    availableServices.value = availableRes.data || []
  } catch (error) {
    console.error('Fetch Error:', error)
  } finally {
    loading.value = false
  }
}

// Watcher: Auto-generate config fields when Service changes (Only in Create mode)
watch(currentServiceDef, (newService) => {
  if (newService && !isEditing.value) {
    const newConfig = {}
    newService.fields?.forEach((field) => {
      if (field.type === 'hidden') {
        newConfig[field.name] = field.value
      } else {
        newConfig[field.name] = ''
      }
    })
    configForm.value = newConfig
    // console.log(configForm.value)
  }
})

const openCreateModal = () => {
  isEditing.value = false
  editingId.value = null
  selectedServiceId.value = ''

  // Reset Forms
  configForm.value = {}
  currentServiceDef.value?.fields?.forEach((field) => {
    if (field.type === 'hidden') {
      configForm.value[field.name] = field.value
    }
  })
  metaForm.value = { is_active: true, is_brain: false }

  showModal.value = true
}

const openEditModal = (integration) => {
  isEditing.value = true
  editingId.value = integration.id
  selectedServiceId.value = integration.service

  // Load existing data
  // Assuming 'value' column in DB holds the config JSON
  configForm.value = { ...integration.masked_value }
  originalConfig.value = { ...integration.masked_value }

  // Load Meta
  metaForm.value = {
    is_active: Boolean(integration.is_active),
    is_brain: Boolean(integration.is_brain),
  }
  originalMeta.value = { ...metaForm.value }

  showModal.value = true
}

const saveIntegration = async () => {
  processing.value = true
  try {
    // Construct payload matching DB structure

    if (isEditing.value) {
      // Robust dirty checking
      const dirtyConfig = {}
      let hasConfigChanges = false

      for (const key in configForm.value) {
        if (configForm.value[key] !== originalConfig.value[key]) {
          dirtyConfig[key] = configForm.value[key]
          hasConfigChanges = true
        }
      }

      const payload = {
        service: selectedServiceId.value,
      }

      let hasMetaChanges = false
      if (metaForm.value.is_active !== originalMeta.value.is_active) {
        payload.is_active = metaForm.value.is_active
        hasMetaChanges = true
      }
      if (metaForm.value.is_brain !== originalMeta.value.is_brain) {
        payload.is_brain = metaForm.value.is_brain
        hasMetaChanges = true
      }

      if (hasConfigChanges) {
        payload.value = dirtyConfig
      }

      // Only send request if there are actual changes
      if (!hasConfigChanges && !hasMetaChanges) {
        showModal.value = false
        return
      }

      await api.put(`/integrations/${editingId.value}`, payload)
    } else {
      const payload = {
        service: selectedServiceId.value,
        value: configForm.value,
        is_active: metaForm.value.is_active,
        is_brain: metaForm.value.is_brain,
      }
      await api.post('/integrations', payload)
    }

    showModal.value = false
    await fetchData()
  } catch (err) {
    alert('Error saving: ' + (err.response?.data?.message || err.message))
  } finally {
    processing.value = false
  }
}

const connectGoogle = async () => {
  try {
    const res = await api.get('/google-business/connect')
    if (res.data?.url) {
      window.location.href = res.data.url
    }
  } catch (err) {
    console.error('Connect error:', err)
    alert('Failed to initialize Google connection')
  }
}

const disconnectIntegration = async (integration) => {
  if (!confirm(`Disconnect ${integration.name || integration.service}? This stops all syncs.`))
    return

  try {
    await api.delete(`/integrations/${integration.id}`)
    activeIntegrations.value = activeIntegrations.value.filter((i) => i.id !== integration.id)
  } catch (error) {
    console.error('Disconnect error:', error)
    alert('Failed to disconnect service')
  }
}

// Helpers
const getStatusBadge = (item) => {
  return item.is_active
    ? { label: 'Active', class: 'badge-green' }
    : { label: 'Inactive', class: 'badge-slate' }
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

onMounted(fetchData)
</script>

<template>
  <div class="space-y-6">
    <div class="page-header">
      <div>
        <h2 class="page-title">Integrations</h2>
        <p class="page-subtitle">Manage third-party service connections.</p>
      </div>
      <button @click="openCreateModal" class="btn-primary">+ Connect Service</button>
    </div>

    <div v-if="loading" class="text-center py-12 text-slate-500">Loading...</div>

    <div v-else class="table-container">
      <table class="table">
        <thead>
          <tr>
            <th>Service</th>
            <th>Type</th>
            <th>Status</th>
            <th>Created</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="activeIntegrations.length === 0">
            <td colspan="5" class="text-center py-12 text-slate-500 italic">
              No integrations connected yet.
            </td>
          </tr>

          <tr v-for="item in activeIntegrations" :key="item.id">
            <td>
              <div class="flex items-center gap-3">
                <div class="flex-shrink-0">
                  <img
                    v-if="availableServices.find((s) => s.id === item.service)?.logo"
                    :src="availableServices.find((s) => s.id === item.service)?.logo"
                    class="w-8 h-8 object-contain"
                    alt="Logo"
                  />
                  <div
                    v-else
                    class="w-8 h-8 rounded bg-slate-200 dark:bg-slate-800 flex items-center justify-center text-xs font-bold text-slate-500"
                  >
                    {{ item.service ? item.service.substring(0, 2).toUpperCase() : '?' }}
                  </div>
                </div>

                <div class="flex flex-col">
                  <span class="font-bold text-slate-900 dark:text-white capitalize">
                    {{ item.name || item.service }}
                  </span>
                  <span class="text-xs text-slate-400 font-mono"
                    >{{ item.service }} |
                    {{ JSON.stringify(item.masked_value).slice(0, 30).concat('') }}</span
                  >
                </div>
              </div>
            </td>
            <td>
              <span v-if="item.is_brain" class="badge badge-purple">AI Brain</span>
              <span v-else class="badge badge-blue">Service</span>
            </td>
            <td>
              <span :class="['badge', getStatusBadge(item).class]">
                {{ getStatusBadge(item).label }}
              </span>
            </td>
            <td>
              {{ formatDateString(item.created_at) }}
            </td>
            <td class="text-right space-x-2">
              <button
                @click="openEditModal(item)"
                class="text-blue-600 hover:text-blue-800 dark:text-blue-400 text-xs font-bold uppercase tracking-wide"
              >
                Edit
              </button>
              <span class="text-slate-300 dark:text-slate-700">|</span>
              <button
                @click="disconnectIntegration(item)"
                class="text-red-600 hover:text-red-800 dark:text-red-400 text-xs font-bold uppercase tracking-wide"
              >
                Disconnect
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <TransitionRoot appear :show="showModal" as="template">
      <Dialog as="div" @close="showModal = false" class="relative z-50">
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" />
        <div class="fixed inset-0 overflow-y-auto flex items-center justify-center p-4">
          <DialogPanel
            class="w-full max-w-2xl bg-white dark:bg-slate-900 rounded-xl shadow-xl p-6 border border-slate-200 dark:border-slate-800"
          >
            <DialogTitle
              class="text-lg font-bold mb-6 text-slate-900 dark:text-white border-b border-slate-100 dark:border-slate-800 pb-4"
            >
              {{ isEditing ? 'Edit Connection' : 'Connect Service' }}
            </DialogTitle>

            <div class="space-y-6">
              <div class="form-group">
                <label class="form-label">Provider</label>
                <select v-model="selectedServiceId" :disabled="isEditing" class="form-select">
                  <option value="" disabled>Select Provider...</option>
                  <option v-for="srv in availableServices" :key="srv.id" :value="srv.id">
                    {{ srv.name }}
                  </option>
                </select>
              </div>

              <div
                v-if="currentServiceDef"
                class="space-y-4 bg-slate-50 dark:bg-slate-950/50 p-4 rounded-lg border border-slate-200 dark:border-slate-700 custom-scrollbar max-h-96 overflow-y-auto"
              >
                <div v-for="field in currentServiceDef.fields" :key="field.name" class="form-group">
                  <label v-if="field.type !== 'hidden'" class="form-label">
                    {{ field.label }}
                    <span v-if="field.required" class="text-red-500">*</span>
                  </label>

                  <div
                    v-if="field.type === 'oauth'"
                    class="py-4 text-center border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-lg"
                  >
                    <p class="text-xs text-slate-500 mb-3">
                      Connect your Google Business Profile to sync reviews and automate replies.
                    </p>
                    <button
                      @click="connectGoogle"
                      type="button"
                      class="btn btn-secondary inline-flex items-center gap-2"
                    >
                      <svg class="w-4 h-4" viewBox="0 0 24 24">
                        <path
                          fill="currentColor"
                          d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                        />
                        <path
                          fill="currentColor"
                          d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                        />
                        <path
                          fill="currentColor"
                          d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z"
                        />
                        <path
                          fill="currentColor"
                          d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                        />
                      </svg>
                      Connect Google Business Profile
                    </button>
                  </div>
                  <textarea
                    v-else-if="field.type === 'textarea'"
                    v-model="configForm[field.name]"
                    :required="field.required"
                    :class="['form-input', field.class || '']"
                    :cols="field.cols || 0"
                    :rows="field.rows || 0"
                  ></textarea>
                  <input
                    v-else-if="field.type === 'hidden'"
                    v-model="configForm[field.name]"
                    :class="['form-input', 'hidden', field.class || '']"
                    :required="field.required"
                    :hidden="true"
                  />
                  <input
                    v-else
                    :type="field.type || 'text'"
                    v-model="configForm[field.name]"
                    :required="field.required"
                    :class="['form-input', field.class || '']"
                    :placeholder="field.placeholder || ''"
                    :autocomplete="field.type === 'password' ? 'new-password' : 'on'"
                  />
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-2">
                <div class="flex items-center justify-between card p-3 border-slate-200">
                  <span class="flex flex-col">
                    <span class="text-sm font-medium text-slate-900 dark:text-white"
                      >Active Status</span
                    >
                    <span class="text-xs text-slate-500">Enable or disable this integration</span>
                  </span>
                  <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" v-model="metaForm.is_active" class="sr-only peer" />
                    <div
                      class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-slate-300 dark:peer-focus:ring-slate-800 rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-slate-900 dark:peer-checked:bg-slate-100"
                    ></div>
                  </label>
                </div>

                <div class="flex items-center justify-between card p-3 border-slate-200">
                  <span class="flex flex-col">
                    <span class="text-sm font-medium text-slate-900 dark:text-white"
                      >AI Provider</span
                    >
                    <span class="text-xs text-slate-500">Use as a Brain (LLM) source</span>
                  </span>
                  <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" v-model="metaForm.is_brain" class="sr-only peer" />
                    <div
                      class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 dark:peer-focus:ring-purple-800 rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-purple-600"
                    ></div>
                  </label>
                </div>
              </div>
            </div>

            <div
              class="mt-8 flex justify-end gap-3 pt-4 border-t border-slate-100 dark:border-slate-800"
            >
              <button @click="showModal = false" class="btn-secondary">Cancel</button>
              <button
                @click="saveIntegration"
                :disabled="processing || !selectedServiceId"
                class="btn-primary"
              >
                <span v-if="processing">Saving...</span>
                <span v-else>{{ isEditing ? 'Update Service' : 'Connect Service' }}</span>
              </button>
            </div>
          </DialogPanel>
        </div>
      </Dialog>
    </TransitionRoot>
  </div>
</template>
