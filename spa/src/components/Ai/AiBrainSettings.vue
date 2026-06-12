<template>
  <div class="card bg-base-100 shadow-xl border border-base-200">
    <div class="card-body">
      <div class="flex justify-between items-start">
        <div>
          <h2 class="card-title text-primary flex items-center gap-2">
            <svg
              xmlns="http://www.w3.org/2000/svg"
              class="h-6 w-6"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M13 10V3L4 14h7v7l9-11h-7z"
              />
            </svg>
            Global AI Agent
          </h2>
          <p class="text-xs text-base-content/70 mt-1">Overall LLM (Brain) Quota Usage.</p>
        </div>
      </div>

      <div class="mt-6 p-4 bg-base-200/50 rounded-lg">
        <div class="flex justify-between text-sm mb-2">
          <span class="font-semibold">Monthly Credits</span>
          <span class="text-primary">{{ stats.used }} / {{ stats.limit }}</span>
        </div>
        <progress
          class="progress w-full h-3"
          :class="progressColor"
          :value="stats.used"
          :max="stats.limit"
        ></progress>
        <div class="text-xs text-right mt-1 text-base-content/60">{{ stats.percentage }}% Used</div>
      </div>

      <div class="mt-6">
        <label class="label">
          <span class="label-text">AI Provider Model</span>
        </label>
        <select
          class="select select-bordered w-full"
          v-model="form.ai_provider_default"
          @change="saveSettings"
        >
          <option value="openai">OpenAI (GPT-4)</option>
          <option value="gemini">Google Gemini (Pro)</option>
          <option value="claude">Anthropic Claude (Sonnet)</option>
        </select>
        <div class="text-xs mt-2 text-warning" v-if="!hasApiKey">
          ⚠️ You must configure the API Key for this provider in
          <router-link to="/admin/api-keys" class="link">API Keys</router-link>.
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import request from '@/utils/request'

const stats = ref({ used: 0, limit: 100, percentage: 0 })
const form = ref({ ai_provider_default: 'openai' })
const settings = ref({})

const progressColor = computed(() => {
  if (stats.value.percentage > 90) return 'progress-error'
  if (stats.value.percentage > 75) return 'progress-warning'
  return 'progress-primary'
})

const hasApiKey = computed(() => {
  // In a real app, check if the specific key exists in settings
  const key = `${form.value.ai_provider_default}_api_key`
  return settings.value[key] && settings.value[key].length > 0
})

const fetchStats = async () => {
  try {
    const { data } = await request.get('/ai-agent/stats')
    stats.value = {
      used: data.used,
      limit: data.limit,
      percentage: data.percentage,
    }
    settings.value = data.settings[0] || {}
    // console.log('Settings:', data)
    // Populate form
    form.value.ai_provider_default = data.settings.ai_provider_default || 'openai'
  } catch (e) {
    console.error('Failed to load AI stats', e)
  }
}

const saveSettings = async () => {
  try {
    await request.post('/ai-agent/settings', form.value)
    console.log('AI settings saved')
    fetchStats()
  } catch (e) {
    alert('Failed to save settings')
    console.error('Failed to save AI settings', e)
  }
}

onMounted(fetchStats)
</script>
