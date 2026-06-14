<template>
  <div class="space-y-6">
    <div class="page-header">
      <div>
        <h2 class="page-title">AI Chats</h2>
        <p class="page-subtitle">Manage your AI workforce.</p>
      </div>
      <div class="flex gap-2">
        <button @click="openModal()" class="btn-primary">+ New Chat</button>
        <button @click="loadChats" class="btn-secondary">Refresh</button>
      </div>
    </div>

    <div class="card p-4">
      <input v-model="search" type="text" placeholder="Search chats..." class="form-input pl-4" />
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
      <div
        v-for="chat in filteredChats"
        :key="chat.id"
        class="card p-6 flex flex-col h-full hover:shadow-md transition-shadow"
      >
        <template v-for="agent in [agentSlugs.find(a => a.id === chat.ai_agent_id)]" :key="chat.id + '-status'">
        <div class="flex justify-between items-center mb-4">
          <div class="flex items-center gap-3">
            <!-- If avatar_url is present, show it, otherwise show the first letter of the name -->
            <div
              class="w-10 h-10 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center font-bold text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700"
            >
              <span v-if="chat.avatar_url"
                ><img :src="chat.avatar_url" class="w-full h-full rounded-full"
              /></span>
              <span v-else>{{ chat.name[0] }}</span>
            </div>
            <div>
              <h3 class="font-bold text-slate-900 dark:text-white">{{ chat.name }}</h3>
            </div>
          </div>

        <!-- We use a single-item v-for array to mock a local variable assignment -->
        
          <span class="relative flex h-4 w-4 flex-shrink-0">
            <span
              v-if="agent?.is_active"
              class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"
            ></span>
            <span
              :class="[
                'relative inline-flex rounded-full h-4 w-4',
                agent.is_active ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-600'
              ]"
              :title="agent?.is_active ? 'Active' : 'Inactive'"
            ></span>
          </span>

          
        </div>
        <div class="flex-1 mb-6">
          <label class="form-label mt-2">Agent</label>
          <div
            class="text-xs font-mono bg-slate-50 dark:bg-slate-950 p-2 rounded border border-slate-100 dark:border-slate-800 break-all"
          >
            {{
              chat.webhook_url ||
              agent?.slug + (!agent?.is_active ? " is temporarily deactivated" : "")
            }}
          </div>
        </div>

        <div class="flex gap-2 mt-auto">

          
          <router-link 
            :to="agent?.is_active ? `/admin/ai-chats/${chat.id}` : ''" 
            class="btn-primary flex-1 text-center transition-all"
            :class="{ 'opacity-50 cursor-not-allowed pointer-events-none select-none': !agent?.is_active }"
          >
            Open Chat
          </router-link>

          <button @click="openModal(chat)" class="btn-icon">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
              ></path>
            </svg>
          </button>
          <button @click="deleteChat(chat.id)" class="btn-icon text-red-500 hover:bg-red-50">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
              />
            </svg>
          </button>
        </div>
        </template>

      </div>
    </div>

    <Transition name="modal">
      <div
        v-if="showModal"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm"
      >
        <div class="card w-full max-w-lg">
          <div class="card-header">
            <h3 class="card-title">{{ isEditing ? 'Edit' : 'Create' }} Chat</h3>
          </div>
          <div class="card-body space-y-4">
            <div class="form-group">
              <label class="form-label">Name</label><input v-model="form.name" class="form-input" />
            </div>
            <div class="form-group">
              <label class="form-label flex items-center justify-between cursor-pointer">
                <span>Use Custom Webhook</span>
                <div class="relative inline-flex items-center cursor-pointer">
                  <input type="checkbox" v-model="form.use_webhook" class="sr-only peer" />
                  <div
                    class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"
                  ></div>
                </div>
              </label>
            </div>

            <div v-if="form.use_webhook">
              <div class="form-group">
                <label class="form-label">Webhook URL</label
                ><input v-model="form.webhook_url" type="url" class="form-input" />
              </div>
              <div class="form-group">
                <label class="form-label">Secret</label
                ><input
                  v-model="form.webhook_secret"
                  type="password"
                  autocomplete="new-password"
                  class="form-input"
                />
              </div>
            </div>

            <div v-else class="form-group">
              <label class="form-label">Agent Slug</label>
              <select v-model="form.ai_agent_id" class="form-select w-full">
                <option value="" disabled>Select an agent...</option>
                <option
                  v-for="agent in agentSlugs"
                  :key="agent.id"
                  :value="agent.id"
                  :selected="form.ai_agent_id == agent.id"
                >
                  {{ agent.slug }}
                </option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Welcome Message</label
              ><textarea v-model="form.welcome_message" rows="3" class="form-textarea"></textarea>
            </div>
          </div>
          <div class="p-6 border-t border-slate-100 dark:border-slate-800 flex justify-end gap-2">
            <button @click="showModal = false" class="btn-secondary">Cancel</button>
            <button @click="saveChat" class="btn-primary">Save</button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import request from '@/utils/request'
import { useApiCache } from '@/composables/useApiCache'

const chats = ref([])
const showModal = ref(false)
const isEditing = ref(false)
const search = ref('')
const statuses = ref({})
const form = ref({
  name: '',
  webhook_url: '',
  webhook_secret: '',
  welcome_message: '',
  ai_agent_id: null,
  use_webhook: false,
})
const { fetchDataWithCache } = useApiCache()

// Scaffolding for future DB fetch
const agentSlugs = ref([])
// const agentSlugs = ref([
//   { label: 'Customer Support Agent', value: 'customer-support' },
//   { label: 'Sales Assistant', value: 'sales-assistant' },
//   { label: 'Technical Helper', value: 'tech-helper' },
// ])

const filteredChats = computed(() =>
  !search.value
    ? chats.value
    : chats.value.filter((c) => c.name.toLowerCase().includes(search.value.toLowerCase())),
)
onMounted(loadChats)

async function loadChats() {
  try {
    const result = await fetchDataWithCache('ai_chats', () =>
      Promise.all([request.get('/ai-chats')]),
    )
    const { data } = result[0]

    if (data) {
      chats.value = data.chats
      agentSlugs.value = data.agents
    }

    // console.log(data.agents)

    // const { data } = await request.get('/ai-chats')
    // chats.value = data.chats
    // agentSlugs.value = data.agents

    // chats.value.forEach(async (c) => {
    //   try {
    //     const { data } = await request.get(`/ai-chats/${c.id}/status`)
    //     statuses.value[c.id] = data.status
    //   } catch {
    //     statuses.value[c.id] = 'inactive'
    //   }
    // })
  } catch (e) {}
}
function openModal(c = null) {
  isEditing.value = !!c
  if (c) {
    form.value = {
      ...c,
      use_webhook: !!c.webhook_url, // Infer from existing data
      ai_agent_id: c.ai_agent_id || '',
    }
  } else {
    form.value = {
      name: '',
      webhook_url: '',
      webhook_secret: '',
      welcome_message: '',
      ai_agent_id: null,
      use_webhook: false,
    }
  }
  showModal.value = true
}
async function saveChat() {
  try {
    const payload = { ...form.value }
    // Clean up payload based on mode
    if (payload.use_webhook) {
      payload.ai_agent_id = null
    } else {
      payload.webhook_url = null
      payload.webhook_secret = null
    }
    // Remove temporary UI flag before sending if backend doesn't accept it
    // delete payload.use_webhook

    isEditing.value
      ? await request.put(`/ai-chats/${form.value.id}`, payload)
      : await request.post('/ai-chats', payload)
    showModal.value = false
    loadChats()
  } catch (e) {
    alert('Failed')
  }
}
async function deleteChat(id) {
  if (confirm('Delete?')) {
    await request.delete(`/ai-chats/${id}`)
    loadChats()
  }
}
</script>
