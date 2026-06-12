<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import api from '../utils/request'
// import { formatDate, humanizeDate } from '../utils/helpers'

const props = defineProps({
  leadId: { type: Number, required: true },
  job: { type: Object, required: true },
  crmConfig: { type: Object, required: false },
})

const activities = ref([])
const loading = ref(false)
const loadingMore = ref(false)
const page = ref(1)
const hasMore = ref(false)

const aiJob = ref({
  status: props.job?.status ?? 'pending',
  completed_at: props.job?.completed_at ?? null,
  attempts: props.job?.attempts ?? 0,
})

const fetchActivities = async (isLoadMore = false, silent = false) => {
  if (isLoadMore) {
    loadingMore.value = true
  } else if (!silent) {
    loading.value = true
    page.value = 1
  }

  try {
    const res = await api.get(`/leads/${props.leadId}/activities`, {
      params: { page: page.value },
    })

    if (isLoadMore) {
      activities.value = [...activities.value, ...res.data.data]
    } else {
      activities.value = res.data.data
    }

    hasMore.value = res.data.next_page_url !== null
  } catch (e) {
    console.error('Timeline failed', e)
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

const loadMore = () => {
  if (hasMore.value && !loadingMore.value) {
    page.value++
    fetchActivities(true)
  }
}

// Automatic Refresh (Polling)
const emit = defineEmits(['job-completed'])

const MIN_INTERVAL = 30_000 // 30s (active)
const MAX_INTERVAL = 500_000 // 500s (idle cap)
const BACKOFF_FACTOR = 2
const IDLE_THRESHOLD = 2 // number of unchanged polls before backoff

let pollTimer = null
let pollInterval = MIN_INTERVAL
let idleCount = 0

let lastProbeResetAt = Date.now()

let lastSnapshot = {
  status: null,
  attempts: null,
  completed_at: null,
}

const startPolling = async () => {
  if (pollTimer) {
    clearTimeout(pollTimer)
    pollTimer = null
  }

  const runPoll = async () => {
    if (!aiJob.value) return

    const FIVE_MINUTES = 5 * 60 * 1000
    const now = Date.now()

    // 🔁 HARD PROBE RESET every 5 minutes
    if (now - lastProbeResetAt >= FIVE_MINUTES) {
      pollInterval = MIN_INTERVAL
      idleCount = 0
      lastProbeResetAt = now
    }

    try {
      const res = await api.get(`/ai-jobs/${props.leadId}/monitor`)
      const { status, attempts, completed_at } = res.data

      const hasChanged =
        status !== lastSnapshot.status ||
        attempts !== lastSnapshot.attempts ||
        completed_at !== lastSnapshot.completed_at

      console.log('interval:', pollInterval, 'changed:', hasChanged)

      // Sync local state
      aiJob.value.status = status
      aiJob.value.attempts = attempts
      aiJob.value.completed_at = completed_at

      lastSnapshot = { status, attempts, completed_at }

      // ADAPTIVE INTERVAL LOGIC
      if (hasChanged) {
        if (status === 'completed' || status === 'failed') {
          // await fetchActivities()
          if (page.value === 1 && !loading.value && !loadingMore.value) {
            fetchActivities(false, true)
            emit('job-completed')
          }
        }

        idleCount = 0
        pollInterval = MIN_INTERVAL
      } else {
        idleCount++

        if (idleCount >= IDLE_THRESHOLD) {
          pollInterval = Math.min(Math.round(pollInterval * BACKOFF_FACTOR), MAX_INTERVAL)
        }
      }
    } catch (e) {
      if (e.response?.status === 404) {
        console.warn('Job not found, stopping poll.')
        // return
      }

      pollInterval = Math.min(Math.round(pollInterval * BACKOFF_FACTOR), MAX_INTERVAL)
    }

    pollTimer = setTimeout(runPoll, pollInterval)
  }

  runPoll()
}

onMounted(() => {
  fetchActivities() // commented because it is fetching by polling
  // startPolling()
  // startPollingForLogs()
})

onUnmounted(() => {
  if (pollTimer) {
    clearTimeout(pollTimer)
    pollTimer = null
  }
})

defineExpose({
  refresh: () => fetchActivities(),
})

// Color Mapping for "Wow Factor"
// const config = {
//     system: {  color: 'bg-blue-500', label: 'System Action' },
//     external_api: { color: 'bg-orange-500', label: 'External Action' },
//     status_change: { color: 'bg-purple-500', label: 'Pipeline Move' },
//     note: { color: 'bg-green-500', label: 'User Notes' },
//     default: {  color: 'bg-yellow-400', label: 'User Action' }
// }

// 1. Define your SVG Paths separately to keep config clean
const ICONS = {
  server:
    'M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 01-2 2v4a2 2 0 012 2h14a2 2 0 012-2v-4a2 2 0 01-2-2m-2-4h.01M17 16h.01',
  zap: 'M13 10V3L4 14h7v7l9-11h-7z',
  switch: 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4',
  document:
    'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01',
  user: 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
  fire: 'M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z M9.879 16.121A3 3 0 1012.015 11L11 14H9c0 .768.293 1.536.879 2.121z',

  whatsapp:
    'M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z',

  brain:
    'M15 13a4.17 4.17 0 0 1-3-4 4.17 4.17 0 0 1-3 4 M17.598 6.5A3 3 0 1 0 12 5a3 3 0 1 0-5.598 1.5 M17.997 5.125a4 4 0 0 1 2.526 5.77 M18 18a4 4 0 0 0 2-7.464 M19.967 17.483A4 4 0  1 1 12 18a4 4 0 1 1-7.967-.517 M6 18a4 4 0 0 1-2-7.464 M6.003 5.125a4 4 0 0 0-2.526 5.77',

  alert: 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
}

// 2. The Master Configuration
const configMap = {
  // --- System & External Core ---
  system: { color: 'bg-blue-500', icon: ICONS.server, label: 'System Action' },
  system_inserted: { color: 'bg-blue-600', icon: ICONS.user, label: 'User' },
  external_system_inserted: { color: 'bg-blue-700', icon: ICONS.zap, label: 'External API' },

  // --- Pipeline/Status ---
  system_updated_status: {
    color: 'bg-purple-500',
    icon: ICONS.switch,
    label: 'User',
  },
  external_system_updated_status: {
    color: 'bg-purple-600',
    icon: ICONS.switch,
    label: 'External API',
  },

  // --- Temperature (New Visuals) ---
  system_updated_temperature: {
    color: 'bg-rose-500',
    icon: ICONS.fire,
    label: 'User',
  },
  external_system_updated_temperature: {
    color: 'bg-rose-600',
    icon: ICONS.fire,
    label: 'External API',
  },

  // --- Notes ---
  system_added_note: { color: 'bg-green-500', icon: ICONS.document, label: 'User Note' },
  external_system_added_note: {
    color: 'bg-green-600',
    icon: ICONS.document,
    label: 'External API Note',
  },

  // --- Form Submissions ---
  system_form_inserted: {
    color: 'bg-pink-500',
    icon: ICONS.document,
    label: 'Form Submission',
  },
  external_system_form_inserted: {
    color: 'bg-pink-600',
    icon: ICONS.document,
    label: 'Form Submission',
  },

  // --- AI ---
  ai_updated: { color: 'bg-orange-700', icon: ICONS.brain, label: 'AI Agent' },
  mcp_updated: { color: 'bg-orange-500', icon: ICONS.brain, label: 'AI Agent' },

  // --- WhatsApp ---
  message_received: {
    color: 'bg-green-700',
    icon: ICONS.whatsapp,
    label: 'Message Received',
  },
  ai_reply: {
    color: 'bg-orange-700',
    icon: ICONS.brain,
    label: 'Message Sent',
  },

  // --- Errors & Defaults ---
  system_error: { color: 'bg-red-600', icon: ICONS.alert, label: 'System Error' },
  default: { color: 'bg-yellow-400', icon: ICONS.user, label: 'User Action' },
}

// 3. Helper function to use in template
const getItemConfig = (type) => {
  return configMap[type] || configMap['default']
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

onMounted(fetchActivities)
</script>

<template>
  <div class="space-y-6">
    <div
      v-if="aiJob?.status === 'pending'"
      class="text-blue-500 dark:text-blue-400 rounded-lg py-2"
    >
      Please wait, AI is processing this
      {{ props?.crmConfig?.entity_name_singular.toLowerCase() }}...
    </div>
    <div
      v-else-if="aiJob?.status === 'failed'"
      class="text-red-500 dark:text-red-400 rounded-lg py-2"
    >
      <div class="flex items-center justify-between gap-2">
        <span
          >AI has failed to process this
          {{ props?.crmConfig?.entity_name_singular.toLowerCase() }}.</span
        >
        <button
          @click="retryAiJob"
          :disabled="true"
          class="btn btn-danger btn-sm py-1 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          Retry
        </button>
      </div>
    </div>

    <div v-if="loading" class="animate-pulse space-y-4">
      <div v-for="i in 5" :key="i" class="flex gap-4">
        <div class="w-8 h-8 bg-slate-200 dark:bg-slate-800 rounded-full flex-shrink-0"></div>
        <div class="flex-1 space-y-2 py-1">
          <div class="h-3 bg-slate-200 dark:bg-slate-800 rounded w-1/4"></div>
          <div class="h-2 bg-slate-200 dark:bg-slate-800 rounded w-3/4"></div>
        </div>
      </div>
    </div>
    <div v-else-if="activities.length > 0" class="relative">
      <div
        class="absolute left-5 top-2 bottom-4 w-0.5 bg-slate-200 dark:bg-slate-800"
        aria-hidden="true"
      ></div>

      <div class="space-y-4 relative">
        <div v-for="item in activities" :key="item.id" class="relative flex gap-4">
          <div
            :class="getItemConfig(item.type).color"
            class="relative z-10 flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center ring-2 ring-white dark:ring-slate-950/50"
          >
            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="1"
                :d="getItemConfig(item.type).icon"
              />
            </svg>
          </div>

          <div class="flex-1 pt-1">
            <div class="flex justify-between items-start mb-1">
              <span class="text-xs font-bold text-slate-900 dark:text-white">
                {{ getItemConfig(item.type).label }}
              </span>
              <span class="text-[10px] font-medium text-slate-400 italic whitespace-nowrap ml-2">
                {{ formatDateString(item.created_at, true, true) }}
              </span>
            </div>
            <p
              class="text-xs text-slate-600 dark:text-slate-400 leading-relaxed"
              style="white-space: pre-line"
            >
              {{ item.content }}
            </p>
          </div>
        </div>
      </div>

      <!-- Load More Button -->
      <div v-if="hasMore" class="flex justify-center pt-8">
        <button
          @click="loadMore"
          :disabled="loadingMore"
          class="group relative flex items-center gap-2 px-6 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-full shadow-sm hover:shadow-md hover:border-blue-500 dark:hover:border-blue-500 transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          <span
            v-if="loadingMore"
            class="w-4 h-4 border-2 border-blue-500 border-t-transparent rounded-full animate-spin"
          ></span>
          <svg
            v-else
            class="w-4 h-4 text-slate-400 group-hover:text-blue-500 transition-colors"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M19 9l-7 7-7-7"
            />
          </svg>
          <span
            class="text-xs font-bold text-slate-600 dark:text-slate-400 group-hover:text-blue-500 transition-colors"
          >
            {{ loadingMore ? 'Loading activities...' : 'Load more activities' }}
          </span>
        </button>
      </div>
    </div>

    <div
      v-else
      class="text-center py-10 bg-slate-50 dark:bg-slate-900/50 rounded-xl border-2 border-dashed border-slate-200 dark:border-slate-800"
    >
      <p class="text-sm text-slate-400 italic">No activities logged for this yet.</p>
    </div>
  </div>
</template>
