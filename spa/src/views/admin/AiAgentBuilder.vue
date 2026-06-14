<!--
# ─────────────────────────────────────────────────────
# Module   : AiAgentBuilder.vue
# ─────────────────────────────────────────────────────
-->
<script setup>
import { onMounted, ref } from 'vue'
import api from '@/utils/request'
import { Dialog, DialogPanel, DialogTitle, TransitionRoot } from '@headlessui/vue'

// --- State ---
const agents = ref([])
const availableBrains = ref([])
const loading = ref(false)
const showModal = ref(false)
const processing = ref(false)

// --- Form State ---
const isEditing = ref(false)
const editingId = ref(null)

const form = ref({
  name: '',
  slug: '',
  brain: '',
  model: 'gpt-4o',
  system_prompt: '',
  user_prompt: '',
  tools: [],
  selected_trigger: '', // Changed: Single string for Radio button binding
  is_active: true,
})

// --- Configuration ---
const availableTools = ref([])

const availableTriggers = ref([])

// --- Data Fetching ---
const fetchData = async () => {
  loading.value = true
  try {
    const [agentsRes, servicesRes] = await Promise.all([
      api.get('/ai-agents'),
      api.get('/integrations/available'),
    ])
    console.log(agentsRes)
    agents.value = agentsRes.data.agents || []
    availableTriggers.value = agentsRes.data.availableTriggers || []
    availableTools.value = agentsRes.data.availableAiTools || []
    availableBrains.value = servicesRes.data || []
  } catch (e) {
    console.error('Failed to load data:', e)
  } finally {
    loading.value = false
  }
}

// --- Actions ---
const openCreateModal = () => {
  isEditing.value = false
  editingId.value = null

  form.value = {
    name: '',
    slug: '',
    brain: '',
    model: 'gpt-4o',
    system_prompt: 'You are a helpful assistant.',
    user_prompt: 'Process this data: {{data}}',
    tools: [],
    selected_trigger: '', // Reset trigger
    is_active: true,
  }

  showModal.value = true
}

const openEditModal = (agent) => {
  isEditing.value = true
  editingId.value = agent.id

  // Deep clone to avoid mutating table data directly
  const data = JSON.parse(JSON.stringify(agent))

  // LOGIC FIX: Extract the trigger value from the relationship array
  // The DB returns [{ event_class: '...', ... }], but UI needs the string ID.
  let currentTrigger = ''
  if (data.trigger) {
    const t = data.trigger
    // Handle both object (from DB relation) and string (if mixed)
    currentTrigger = typeof t === 'object' ? t.event_class : t
  }

  let toolsArray = []
  if (data.tools) {
    if (Array.isArray(data.tools)) {
      toolsArray = data.tools
    } else if (typeof data.tools === 'string') {
      try {
        toolsArray = JSON.parse(data.tools)
      } catch {
        toolsArray = []
      }
    }
  }

  form.value = {
    ...data,
    tools: toolsArray,
    selected_trigger: currentTrigger, // Bind to the radio button
  }

  showModal.value = true
}

const saveAgent = async () => {
  if (!form.value.name || !form.value.slug) return

  processing.value = true
  try {
    // Prepare payload
    const payload = { ...form.value }

    // LOGIC FIX: Convert singular radio selection to array for Backend
    payload.triggers = payload.selected_trigger ? [payload.selected_trigger] : []
    delete payload.selected_trigger // Clean up temporary field

    if (isEditing.value) {
      const { data } = await api.put(`/ai-agents/${editingId.value}`, payload)
      const index = agents.value.findIndex((a) => a.id === editingId.value)
      if (index !== -1) agents.value[index] = data
    } else {
      const { data } = await api.post('/ai-agents', payload)
      agents.value.unshift(data)
    }

    showModal.value = false
  } catch (e) {
    alert('Failed to save: ' + (e.response?.data?.message || e.message))
  } finally {
    processing.value = false
  }
}

const deleteAgent = async (agent) => {
  if (!confirm(`Delete agent "${agent.name}"?`)) return
  try {
    await api.delete(`/ai-agents/${agent.id}`)
    agents.value = agents.value.filter((a) => a.id !== agent.id)
  } catch {
    alert('Failed to delete agent')
  }
}

const generateSlug = () => {
  if (!isEditing.value && form.value.name) {
    form.value.slug = form.value.name
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/(^-|-$)+/g, '')
  }
}

onMounted(fetchData)
</script>

<template>
  <div class="space-y-6">
    <div class="page-header">
      <div>
        <h2 class="page-title">AI Agents</h2>
        <p class="page-subtitle">Configure autonomous agents and assign triggers.</p>
      </div>
      <button @click="openCreateModal" class="btn-primary">+ Create Agent</button>
    </div>

    <div v-if="loading" class="text-center py-12 text-slate-500">Loading...</div>

    <div v-else class="table-container">
      <table class="table">
        <thead>
          <tr>
            <th>Agent Name</th>
            <th>Brain / Model</th>
            <th>Trigger</th>
            <th>Tools</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="agents.length === 0">
            <td colspan="5" class="text-center py-12 text-slate-500 italic">
              No agents configured. Create one to get started.
            </td>
          </tr>
          <tr v-for="agent in agents" :key="agent.id">
            <td>
              <div class="flex items-center gap-4">
                <!-- Modern Visual Status Indicator -->
                <span class="relative flex h-4 w-4 flex-shrink-0">
                  <span
                    v-if="agent.is_active"
                    class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"
                  ></span>
                  <span
                    :class="[
                      'relative inline-flex rounded-full h-4 w-4',
                      agent.is_active ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-600'
                    ]"
                    :title="agent.is_active ? 'Active' : 'Inactive'"
                  ></span>
                </span>
                <div>
                  <div class="font-bold text-slate-900 dark:text-white">{{ agent.name }}</div>
                  <div class="text-xs text-slate-400 font-mono">{{ agent.slug }}</div>
                </div>
              </div>
            </td>
            <td>
              <div class="flex flex-col">
                <span class="text-sm font-medium">{{ agent.brain }}</span>
                <span class="text-xs text-slate-500">{{ agent.model }}</span>
              </div>
            </td>
            <td>
              <div class="flex flex-wrap gap-1">
                <span
                  v-if="!agent.trigger || [agent.trigger].length === 0"
                  class="badge badge-slate italic"
                >
                  Manual
                </span>
                <span
                  v-else
                  v-for="(trigger, idx) in [agent.trigger]"
                  :key="idx"
                  class="badge badge-slate"
                >
                  {{
                    availableTriggers.find((t) => t.id === (trigger.event_class || trigger))
                      ?.label || (trigger.event_class || trigger).split('\\').pop()
                  }}
                </span>
              </div>
            </td>
            <td>
              <div v-if="agent.tools?.length" class="relative group inline-block">
                <span class="text-xs text-blue-600 dark:text-blue-400 font-medium underline decoration-dashed cursor-help">
                  {{ agent.tools.length }} enabled
                </span>
                <!-- Tooltip -->
                <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 w-56 hidden group-hover:block bg-slate-800 dark:bg-slate-950 text-white text-xs rounded-lg p-2.5 shadow-xl z-20 border border-slate-700">
                  <div class="font-bold mb-1.5 text-slate-300 border-b border-slate-700 pb-1">Enabled Tools</div>
                  <ul class="list-disc pl-4 space-y-1 text-left">
                    <li v-for="t in agent.tools" :key="t" class="font-mono text-slate-200">
                      {{ availableTools.find(tool => tool.id === t)?.label || t }}
                    </li>
                  </ul>
                  <!-- Arrow -->
                  <div class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-slate-800 dark:border-t-slate-950"></div>
                </div>
              </div>
              <span v-else class="text-xs text-slate-400 italic">None</span>
            </td>
            <td class="text-right space-x-2">
              <button
                @click="openEditModal(agent)"
                class="text-blue-600 font-bold text-xs uppercase"
              >
                Edit
              </button>
              <span class="text-slate-300">|</span>
              <button @click="deleteAgent(agent)" class="text-red-600 font-bold text-xs uppercase">
                Delete
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
            class="w-full max-w-3xl bg-white dark:bg-slate-900 rounded-xl shadow-xl p-6 border border-slate-200 dark:border-slate-800"
          >
            <DialogTitle class="text-lg font-bold mb-4 text-slate-900 dark:text-white">
              {{ isEditing ? 'Edit Agent' : 'Create New Agent' }}
            </DialogTitle>

            <div class="space-y-5 max-h-[70vh] overflow-y-auto custom-scrollbar px-1 mt-2">
              <div class="flex items-center gap-2">
                <input
                  type="checkbox"
                  id="isActive"
                  v-model="form.is_active"
                  class="rounded border-slate-300 text-blue-600"
                />
                <label for="isActive" class="text-sm font-medium text-slate-700 dark:text-slate-300"
                  >Agent is Active</label
                >
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-group">
                  <label class="form-label">Name</label>
                  <input v-model="form.name" @input="generateSlug" class="form-input" />
                </div>
                <div class="form-group">
                  <label class="form-label">Slug</label>
                  <input v-model="form.slug" class="form-input" />
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-group">
                  <label class="form-label">Brain</label>
                  <select v-model="form.brain" class="form-input">
                    <option value="" disabled>Select Provider...</option>
                    <option
                      v-for="srv in availableBrains"
                      :key="srv.id"
                      :value="srv.service || srv.id"
                    >
                      {{ srv.name }}
                    </option>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">Model ID</label>
                  <input v-model="form.model" class="form-input" />
                </div>
              </div>

              <div class="form-group">
                <label class="form-label">System Prompt</label>
                <textarea v-model="form.system_prompt" rows="12" class="form-input"></textarea>
              </div>
              <div class="form-group">
                <label class="form-label">User Prompt</label>
                <textarea v-model="form.user_prompt" rows="12" class="form-input"></textarea>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="border border-slate-400 dark:border-slate-700 rounded-lg p-4">
                  <h4 class="text-sm font-bold text-slate-900 dark:text-white mb-3">
                    Event Trigger (Select One)
                  </h4>
                  <div class="space-y-2">
                    <div
                      v-for="trigger in availableTriggers"
                      :key="trigger.id"
                      class="flex items-center"
                    >
                      <input
                        type="radio"
                        :id="trigger.id"
                        :value="trigger.id"
                        v-model="form.selected_trigger"
                        name="agent_trigger"
                        class="border-slate-300 text-blue-600 focus:ring-blue-500"
                        :checked="form.selected_trigger === trigger.id"
                      />
                      <label
                        :for="trigger.id"
                        class="ml-2 text-sm text-slate-700 dark:text-slate-300 cursor-pointer"
                      >
                        {{ trigger.label }}
                      </label>
                    </div>
                  </div>
                </div>

                <div class="border border-slate-400 dark:border-slate-700 rounded-lg p-4">
                  <h4 class="text-sm font-bold text-slate-900 dark:text-white mb-3">Agent Tools</h4>
                  <div class="space-y-2">
                    <div v-for="tool in availableTools" :key="tool.id" class="flex items-start">
                      <input
                        type="checkbox"
                        :id="tool.id"
                        :value="tool.id"
                        v-model="form.tools"
                        class="mt-1 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                      />
                      <label :for="tool.id" class="ml-2 cursor-pointer">
                        <div class="text-sm text-slate-700 dark:text-slate-300">
                          {{ tool.label }}
                        </div>
                      </label>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div
              class="mt-6 flex justify-end gap-3 pt-4 border-t border-slate-100 dark:border-slate-800"
            >
              <button @click="showModal = false" class="btn-secondary">Cancel</button>
              <button @click="saveAgent" :disabled="processing" class="btn-primary">
                {{ processing ? 'Saving...' : 'Save Agent' }}
              </button>
            </div>
          </DialogPanel>
        </div>
      </Dialog>
    </TransitionRoot>
  </div>
</template>
