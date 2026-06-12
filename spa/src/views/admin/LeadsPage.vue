<script setup>
import { onMounted, ref, reactive, watch, computed } from 'vue'
import api from '../../utils/request'
import { useRouter } from 'vue-router'
import { useApiCache } from '@/composables/useApiCache'

const router = useRouter()
const leads = ref([])
const loading = ref(false)
const { fetchDataWithCache } = useApiCache()

// --- View Mode Persistence ---
const VIEW_MODE_KEY = 'leads_view_mode'
const getInitialViewMode = () => {
  const saved = localStorage.getItem(VIEW_MODE_KEY)
  return ['list', 'board'].includes(saved) ? saved : 'board'
}
const viewMode = ref(getInitialViewMode())

watch(viewMode, (newMode) => localStorage.setItem(VIEW_MODE_KEY, newMode))

// --- Pagination State ---
const pagination = ref({
  current_page: 1,
  last_page: 1,
  total: 0,
})

// Default Config (Overwritten by Backend)
const crmConfig = ref({
  entity_name_singular: 'Lead',
  entity_name_plural: 'Leads',
  statuses: [
    { slug: 'new', label: 'New', color: 'blue' },
    { slug: 'contacted', label: 'Contacted', color: 'yellow' },
    { slug: 'closed', label: 'Closed', color: 'green' },
  ],
})
const searchFilters = ref([])

// Stats
const stats = ref({
  total: 0,
  new_today: 0,
  hot_leads: 0,
  conversion_rate: '0%',
})

const showLead = ref(false)
const activeLead = ref(null)

// Filters
const filters = reactive({
  search: '',
  status: 'all',
  temperature: 'all',
  source: 'all',
  date_from: '',
  date_to: '',
})

// --- Dynamic Kanban Columns ---
const kanbanColumns = computed(() => {
  const cols = {}

  // Initialize columns based on Config Statuses
  crmConfig.value.statuses.forEach((status) => {
    cols[status.slug] = []
  })

  // Catch-all for deleted/unknown statuses
  cols['__other__'] = []

  // Sort leads into columns
  leads.value.forEach((lead) => {
    if (cols[lead.status]) {
      cols[lead.status].push(lead)
    } else {
      cols['__other__'].push(lead)
    }
  })

  return cols
})

// Status Color Helper
const getStatusColor = (statusSlug) => {
  const status = crmConfig.value.statuses.find((s) => s.slug === statusSlug)
  const color = status ? status.color : 'gray'

  const colorMap = {
    blue: 'bg-blue-100 text-blue-700 border-blue-200',
    green: 'bg-green-100 text-green-700 border-green-200',
    yellow: 'bg-yellow-100 text-yellow-700 border-yellow-200',
    red: 'bg-red-100 text-red-700 border-red-200',
    purple: 'bg-purple-100 text-purple-700 border-purple-200',
    gray: 'bg-slate-100 text-slate-600 border-slate-200',
  }

  return colorMap[color] || colorMap['gray']
}

// const selectedLeadIds = ref([])

// // Toggle individual selection
// const toggleSelection = (id) => {
//     const index = selectedLeadIds.value.indexOf(id)
//     if (index > -1) {
//         selectedLeadIds.value.splice(index, 1)
//     } else {
//         selectedLeadIds.value.push(id)
//     }
// }

// --- Selection State ---
const selectedLeadIds = ref([])
const isSelecting = computed(
  () => selectedLeadIds.value.length === leads.value.length && leads.value.length > 0,
)
const isLeadSelected = (id) => selectedLeadIds.value.includes(id)

// --- 1. SMART TOGGLE SELECT ALL ---
const toggleSelectAll = () => {
  // If everything is already selected, clear it.

  if (leads.value.length === 0) return

  if (selectedLeadIds.value.length === leads.value.length && leads.value.length > 0) {
    selectedLeadIds.value = []
  } else {
    // Select all leads currently in the list
    selectedLeadIds.value = leads.value.map((lead) => lead.id)
  }
}

// --- 2. SELECTION TOGGLE ---
const toggleLeadSelection = (id) => {
  const index = selectedLeadIds.value.indexOf(id)
  if (index > -1) {
    selectedLeadIds.value.splice(index, 1)
  } else {
    selectedLeadIds.value.push(id)
  }
}

// --- Reusable Bulk Action Handler ---
// This is organized so you can easily add 'delete' or 'update' later.
const handleBulkAction = async (actionType) => {
  if (selectedLeadIds.value.length === 0) return

  loading.value = true
  try {
    if (actionType === 'export') {
      await bulkExport()
    } else if (actionType === 'delete') {
      // Future logic: await api.post('/leads/bulk-delete', { ids: selectedLeadIds.value });
      // console.log('Bulk delete triggered for:', selectedLeadIds.value)
    } else if (actionType === 'status_update') {
      // Future logic: await api.post('/leads/bulk-update', { ids: selectedLeadIds.value, status: 'contacted' });
    }

    // Always clear selection after a successful non-export action
    if (actionType !== 'export') selectedLeadIds.value = []
  } catch (e) {
    console.error(`Bulk ${actionType} failed`, e)
  } finally {
    loading.value = false
  }
}

// --- 3. BULK EXPORT (Optimized for Hostinger) ---
const bulkExport = async () => {
  if (selectedLeadIds.value.length === 0) return
  loading.value = true
  try {
    const response = await api.post(
      '/leads/export',
      {
        ids: selectedLeadIds.value,
      },
      { responseType: 'blob' },
    )

    const url = window.URL.createObjectURL(new Blob([response.data]))
    const link = document.createElement('a')
    link.href = url
    link.setAttribute('download', `leads_export_${Date.now()}.csv`)
    document.body.appendChild(link)
    link.click()

    link.remove()
    window.URL.revokeObjectURL(url)
  } catch (e) {
    console.error('Export failed', e)
  } finally {
    loading.value = false
  }
}

// Import Trigger (Hidden Input)
const fileInput = ref(null)
const triggerImport = () => fileInput.value.click()
const handleImport = async (event) => {
  const file = event.target.files[0]
  if (!file) return

  const formData = new FormData()
  formData.append('from', 'system_inserted')
  formData.append('file', file)

  try {
    loading.value = true
    await api.post('/leads/import', formData)
    fetchData(1) // Refresh
  } catch (e) {
    console.error('Import failed', e)
    alert('Import failed')
  } finally {
    loading.value = false
    event.target.value = '' // Clear input
  }
}

const viewLead = (lead) => {
  activeLead.value = lead
  showLead.value = true
}
const closeLead = () => {
  showLead.value = false
  setTimeout(() => (activeLead.value = null), 200)
}
const getTempColor = (temp) => {
  const map = { cold: 'text-blue-500', warm: 'text-orange-500', hot: 'text-red-600 font-bold' }
  return map[temp] || 'text-gray-500'
}
const getTempBadgeColor = (temp) => {
  const map = {
    cold: 'bg-blue-400/10 text-blue-400 inset-ring-blue-500/20',
    warm: 'bg-orange-400/10 text-orange-400 inset-ring-orange-500/20',
    hot: 'bg-red-400/10 text-red-400 inset-ring-red-500/20',
  }
  return map[temp] || 'text-gray-500'
}
const clearFilters = () => {
  filters.search = ''
  filters.status = 'all'
  filters.temperature = 'all'
  filters.source = 'all'
  filters.date_from = ''
  filters.date_to = ''
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

const openLead = (lead) => {
  router.push({ name: 'lead-details', params: { id: lead.id } })
}

// --- 1. PROPER DEBOUNCING LOGIC ---
let searchTimer = null

// const fetchData = async (page = 1) => {
//   loading.value = true
//   try {
//     const statsRes = await api.get('/leads/stats')
//     stats.value = statsRes.data.stats || statsRes.data
//     if (statsRes.data.config) crmConfig.value = statsRes.data.config
//     if (statsRes.data.stats.leads_search_filters) {
//       searchFilters.value = statsRes.data.stats.leads_search_filters
//       // console.log('Search Filters from API:', searchFilters.value.sources)
//     }

//     const params = { ...filters, page } // Pass the page number
//     Object.keys(params).forEach((key) => {
//       if (params[key] === '' || params[key] === 'all') delete params[key]
//     })

//     // Kanban usually needs a higher limit to look full
//     if (viewMode.value === 'board') params.per_page = 50

//     const leadsRes = await api.get('/leads', { params })

//     // For Kanban, we usually want to Append data if loading more,
//     // but for List view, we Replace it.
//     if (page > 1 && viewMode.value === 'board') {
//       leads.value = [...leads.value, ...leadsRes.data.data]
//     } else {
//       leads.value = leadsRes.data.data
//     }

//     pagination.value = {
//       current_page: leadsRes.data.current_page,
//       last_page: leadsRes.data.last_page,
//       total: leadsRes.data.total,
//     }
//   } catch (e) {
//     console.error(e)
//   } finally {
//     loading.value = false
//   }
// }

// Watch filters with Debounce only for search

const fetchData = async (page = 1) => {
  loading.value = true

  let ttl = 20000

  try {
    const statsRes = await fetchDataWithCache('lead_stats', () => api.get('/leads/stats'), ttl)

    // const { data: statsRes } = result[0]
    // const { data: leadsRes } = result[1]

    stats.value = statsRes.stats || statsRes
    if (statsRes.config) crmConfig.value = statsRes.config
    if (statsRes.stats.leads_search_filters) {
      searchFilters.value = statsRes.stats.leads_search_filters
    }

    const params = { ...filters, page } // Pass the page number
    Object.keys(params).forEach((key) => {
      if (params[key] === '' || params[key] === 'all') delete params[key]
    })

    // Kanban usually needs a higher limit to look full
    if (viewMode.value === 'board') params.per_page = 50

    const result = await api.get('/leads', { params })

    const { data: leadsRes } = result

    // For Kanban, we usually want to Append data if loading more,
    // but for List view, we Replace it.
    if (page > 1 && viewMode.value === 'board') {
      leads.value = [...leads.value, ...leadsRes.data]
    } else {
      leads.value = leadsRes.data
    }

    pagination.value = {
      current_page: leadsRes.current_page,
      last_page: leadsRes.last_page,
      total: leadsRes.total,
    }
  } catch (e) {
    console.error(e)
  } finally {
    loading.value = false
  }
}

watch(
  () => filters.search,
  () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => fetchData(1), 500) // 500ms delay
  },
)

// Watch other filters (Immediate fetch)
watch(
  [
    () => filters.status,
    () => filters.temperature,
    () => filters.source,
    () => filters.date_from,
    () => filters.date_to,
    viewMode,
  ],
  () => {
    fetchData(1)
  },
)

const loadMore = () => {
  if (pagination.value.current_page < pagination.value.last_page) {
    fetchData(pagination.value.current_page + 1)
  }
}

onMounted(() => fetchData(1))
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <h2 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
          {{ crmConfig.entity_name_plural }} Pipeline
        </h2>
        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">
          Manage {{ crmConfig.entity_name_plural.toLowerCase() }} and track progress.
        </p>
      </div>

      <div class="flex items-center gap-3">
        <div class="bg-slate-100 dark:bg-slate-800 p-1 rounded-lg flex items-center">
          <button
            @click="viewMode = 'list'"
            title="List View"
            :class="[
              'px-3 py-1.5 text-sm font-medium rounded-md transition-all',
              viewMode === 'list' ? 'bg-white dark:bg-slate-700 shadow-lg' : 'text-slate-500',
            ]"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M4 6h16M4 12h16M4 18h16"
              />
            </svg>
          </button>
          <button
            @click="viewMode = 'board'"
            title="Kanban View"
            :class="[
              'px-3 py-1.5 text-sm font-medium rounded-md transition-all',
              viewMode === 'board' ? 'bg-white dark:bg-slate-700 shadow-lg' : 'text-slate-500',
            ]"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"
              />
            </svg>
          </button>
        </div>

        <!-- <div class="flex items-center gap-3"> -->
        <button
          @click="triggerImport"
          class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium bg-white dark:bg-slate-950 border dark:border-slate-800 rounded-lg hover:bg-slate-50"
        >
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"
            />
          </svg>
          Import
        </button>

        <button
          @click="toggleSelectAll"
          :class="[
            'inline-flex items-center gap-2 px-4 py-2 text-sm font-bold rounded-lg border transition-all duration-300',
            isSelecting
              ? 'bg-indigo-600 text-white border-indigo-600'
              : 'bg-white dark:bg-slate-950 border-slate-200 dark:border-slate-800 text-slate-700 dark:text-slate-300',
          ]"
        >
          <svg
            v-if="isSelecting"
            class="w-4 h-4"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M6 18L18 6M6 6l12 12"
            />
          </svg>
          <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"
            />
          </svg>

          {{ isSelecting ? 'Deselect All' : 'Select All' }}
        </button>
        <!-- </div> -->

        <button
          @click="fetchData"
          class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-medium dark:text-slate-200 bg-white dark:bg-slate-950 border dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-900"
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
      </div>
    </div>

    <div
      class="bg-white dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800 shadow-lg space-y-4"
    >
      <div class="relative">
        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
          <svg
            class="h-4 w-4 text-slate-400"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
            />
          </svg>
        </div>
        <input
          v-model="filters.search"
          type="text"
          placeholder="Search by ID, Email, Source..."
          class="block w-full pl-10 pr-3 py-2 border rounded-lg dark:bg-slate-900 dark:border-slate-700"
        />
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        <div>
          <label class="block text-xs font-medium text-slate-500 mb-1">Status</label>
          <select
            v-model="filters.status"
            class="block w-full py-1.5 px-2 text-sm border border-slate-300 dark:border-slate-700 rounded-lg dark:bg-slate-900 dark:text-slate-200"
          >
            <option value="all">All Statuses</option>
            <option v-for="status in crmConfig.statuses" :key="status.slug" :value="status.slug">
              {{ status.label }}
            </option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-500 mb-1">Temperature</label>
          <select
            v-model="filters.temperature"
            class="block w-full py-1.5 px-2 text-sm border border-slate-300 dark:border-slate-700 rounded-lg dark:bg-slate-900 dark:text-slate-200"
          >
            <option value="all">All Temps</option>

            <option
              v-for="temperature in searchFilters.temperatures"
              :key="temperature"
              :value="temperature"
            >
              {{ temperature }}
            </option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-500 mb-1">Source</label>
          <select
            v-model="filters.source"
            class="block w-full py-1.5 px-2 text-sm border border-slate-300 dark:border-slate-700 rounded-lg dark:bg-slate-900 dark:text-slate-200"
          >
            <option value="all">All Sources</option>
            <option v-for="source in searchFilters.sources" :key="source" :value="source">
              {{ source }}
            </option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-500 mb-1">From Date</label>
          <input
            v-model="filters.date_from"
            type="date"
            class="block w-full py-1.5 px-2 text-sm border border-slate-300 dark:border-slate-700 rounded-lg dark:bg-slate-900 dark:text-slate-200"
          />
        </div>
        <div class="flex items-end gap-2">
          <div class="flex-1">
            <label class="block text-xs font-medium text-slate-500 mb-1">To Date</label>
            <input
              v-model="filters.date_to"
              type="date"
              class="block w-full py-1.5 px-2 text-sm border border-slate-300 dark:border-slate-700 rounded-lg dark:bg-slate-900 dark:text-slate-200"
            />
          </div>
          <button
            @click="clearFilters"
            title="Clear Filters"
            class="mb-[1px] p-2 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors border border-transparent hover:border-slate-200 dark:hover:border-slate-700"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              class="h-5 w-5"
              viewBox="0 0 20 20"
              fill="currentColor"
            >
              <path
                fill-rule="evenodd"
                d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                clip-rule="evenodd"
              />
            </svg>
          </button>
        </div>
      </div>
    </div>

    <div
      v-if="leads.length === 0"
      class="col-span-full py-12 text-center border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-xl text-slate-500"
    >
      No {{ crmConfig.entity_name_singular }} to Show.
    </div>
    <div v-else>
      <div>
        <p class="text-lg font-bold tracking-tight text-slate-900 dark:text-white">
          {{ leads?.length }} Latest {{ crmConfig.entity_name_plural }}
          <span class="text-sm text-slate-500 dark:text-slate-400 mt-1"
            >(Press "Load More {{ crmConfig.entity_name_plural }}" button below to see more)</span
          >
        </p>
      </div>

      <div v-if="viewMode === 'board'" class="overflow-x-auto pb-4 custom-scrollbar">
        <div class="flex gap-6 min-w-full mt-5">
          <div
            v-for="status in crmConfig.statuses"
            :key="status.slug"
            class="min-w-[300px] w-full bg-slate-50 dark:bg-slate-900/30 rounded-xl p-4 border border-slate-200 dark:border-slate-800 flex flex-col shadow-lg"
          >
            <div class="flex items-center justify-between mb-4">
              <h3 class="font-bold text-slate-700 dark:text-slate-200">{{ status.label }}</h3>
              <span
                class="text-xs font-bold px-2 py-1 rounded-full bg-slate-200"
                :class="getStatusColor(status.slug).split(' ')[1]"
              >
                {{ kanbanColumns[status.slug]?.length || 0 }}
              </span>
            </div>

            <div class="space-y-3 flex-1 overflow-y-auto max-h-[70vh] custom-scrollbar">
              <div
                v-for="lead in kanbanColumns[status.slug]"
                :key="lead.id"
                class="relative group bg-white dark:bg-slate-950 px-4 py-3 rounded-xl border-2 transition-all select-none cursor-pointer"
                :class="[
                  isLeadSelected(lead.id)
                    ? 'border-indigo-500 bg-indigo-50/20 shadow-md ring-2 ring-indigo-500/5'
                    : 'border-slate-200 dark:border-slate-800 hover:border-slate-300',
                ]"
                @click.self="openLead(lead)"
              >
                <div
                  @click.stop="toggleLeadSelection(lead.id)"
                  class="absolute bottom-2 right-2 z-20 transition-all duration-200"
                  :class="[
                    isLeadSelected(lead.id)
                      ? 'opacity-100 scale-110'
                      : 'opacity-0 group-hover:opacity-100 scale-100',
                  ]"
                >
                  <div
                    class="w-5 h-5 rounded border-2 flex items-center justify-center transition-colors"
                    :class="
                      isLeadSelected(lead.id)
                        ? 'bg-indigo-600 border-indigo-600'
                        : 'bg-white border-slate-300'
                    "
                  >
                    <!-- <svg v-if="isLeadSelected(lead.id)" class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="4" d="M5 13l4 4L19 7" />
                                    </svg> -->

                    <svg
                      v-if="isLeadSelected(lead.id)"
                      class="w-3.5 h-3.5 text-white"
                      fill="none"
                      viewBox="0 0 24 24"
                      stroke="currentColor"
                    >
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M6 18L18 6M6 6l12 12"
                      />
                    </svg>
                    <svg
                      v-else
                      class="w-3.5 h-3.5 text-white"
                      fill="none"
                      viewBox="0 0 24 24"
                      stroke="currentColor"
                    >
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"
                      />
                    </svg>
                  </div>
                </div>

                <div @click="openLead(lead)" class="mt-2 z-10">
                  <div class="flex justify-between items-start mb-1">
                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest"
                      >From {{ lead.source }}</span
                    >
                    <span class="flex items-baseline justify-between gap-1">
                      <span
                        :class="[
                          'w-2 h-2 rounded-full',
                          lead.temperature === 'hot'
                            ? 'bg-red-500 animate-pulse'
                            : lead.won
                              ? 'bg-green-500 animate-pulse'
                              : '',
                        ]"
                      ></span>
                      <span
                        v-if="lead.status == 'closed' && lead.won"
                        class="text-green-500 text-[10px] font-bold uppercase tracking-widest"
                        >WON</span
                      >
                    </span>
                  </div>
                  <div class="font-bold text-sm text-slate-900 dark:text-white truncate">
                    {{ lead.payload?.email || lead.payload?.phone || 'No Contact Info' }}
                  </div>
                </div>
                <div class="flex justify-between items-baseline mt-1">
                  <button @click="viewLead(lead)" class="font-bold text-[12px] text-blue-500 z-50">
                    View
                  </button>
                  <div class="text-[10px] text-slate-500 mt-1">
                    {{ formatDateString(lead.created_at, true, true) }}
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div
            v-if="kanbanColumns['__other__'] && kanbanColumns['__other__'].length > 0"
            class="min-w-[300px] bg-red-50 dark:bg-red-900/10 rounded-xl p-4 border border-red-200"
          >
            <h3 class="font-bold text-red-700 mb-4">Unmapped</h3>
            <div class="space-y-3 flex-1 overflow-y-auto max-h-[70vh] custom-scrollbar">
              <div
                v-for="lead in kanbanColumns['__other__']"
                :key="lead.id"
                @click="viewLead(lead)"
                class="bg-white p-4 rounded-lg border border-red-200 cursor-pointer"
              >
                <div class="text-xs text-red-500 font-bold mb-1">Status: {{ lead.status }}</div>
                <div class="font-medium text-sm">
                  {{ lead.payload?.email || lead.payload?.phone || 'No Contact Info' }}
                </div>
              </div>
            </div>
          </div>
        </div>
        <div
          v-if="viewMode === 'board' && pagination.current_page < pagination.last_page"
          class="mt-6 flex justify-center"
        >
          <button
            @click="loadMore"
            class="px-6 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-full shadow-lg text-sm font-medium hover:bg-slate-50"
          >
            {{ loading ? 'Loading...' : 'Load More ' + crmConfig.entity_name_plural }}
          </button>
        </div>
      </div>

      <div
        v-else
        class="table-container pb-4 overflow-x-auto overflow-y-scroll custom-scrollbar mt-5"
      >
        <table class="table">
          <thead>
            <tr>
              <th class="px-6 py-4"></th>
              <th class="px-6 py-4">ID</th>
              <th class="px-6 py-4">{{ crmConfig.entity_name_singular }}</th>
              <th class="px-6 py-4">Pipeline Stage (Status)</th>
              <th class="px-6 py-4">Priority (Temp)</th>
              <th class="px-6 py-4">Created</th>
              <th class="px-6 py-4 text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="lead in leads"
              :key="lead.id"
              :class="isLeadSelected(lead.id) ? 'bg-indigo-50/30' : ''"
            >
              <td class="px-6 py-4">
                <button
                  @click="toggleLeadSelection(lead.id)"
                  :class="isLeadSelected(lead.id) ? 'text-indigo-600' : 'text-slate-300'"
                >
                  <svg
                    v-if="isLeadSelected(lead.id)"
                    class="w-5 h-5"
                    fill="currentColor"
                    viewBox="0 0 20 20"
                  >
                    <path
                      fill-rule="evenodd"
                      d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                      clip-rule="evenodd"
                    />
                  </svg>
                  <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <circle cx="12" cy="12" r="10" stroke-width="2" />
                  </svg>
                </button>
              </td>
              <td class="px-6 py-4 font-mono text-xs">{{ lead.id }}</td>
              <td class="px-6 py-4">
                <p>{{ 'From ' + lead.source || 'Undefined' }}</p>
                <button @click="viewLead(lead)" class="mt-1 text-blue-400">View Information</button>
              </td>
              <td class="px-6 py-4">
                <span
                  class="px-2 py-1 rounded-full text-xs border"
                  :class="getStatusColor(lead.status)"
                >
                  {{ crmConfig.statuses.find((s) => s.slug === lead.status)?.label || lead.status }}
                </span>
              </td>
              <td class="px-6 py-4">
                <span :class="getTempColor(lead.temperature)">● {{ lead.temperature }}</span>
              </td>
              <td class="px-6 py-4">{{ formatDateString(lead.created_at, true) }}</td>
              <td class="px-6 py-4 text-right">
                <button @click="openLead(lead)" class="ml-3 text-blue-400 hover:underline">
                  Open
                </button>
              </td>
            </tr>
          </tbody>
        </table>

        <div
          v-if="viewMode === 'list'"
          class="px-6 py-4 flex items-center justify-between border-t border-slate-200 dark:border-slate-800"
        >
          <span class="text-sm text-slate-500">
            Showing {{ leads.length }} of {{ pagination.total }} {{ crmConfig.entity_name_plural }}
          </span>
          <div class="flex gap-2">
            <button
              @click="fetchData(pagination.current_page - 1)"
              :disabled="pagination.current_page === 1"
              class="px-3 py-1 border rounded disabled:opacity-50 dark:border-slate-700 dark:text-white"
            >
              Prev
            </button>
            <button
              @click="fetchData(pagination.current_page + 1)"
              :disabled="pagination.current_page === pagination.last_page"
              class="px-3 py-1 border rounded disabled:opacity-50 dark:border-slate-700 dark:text-white"
            >
              Next
            </button>
          </div>
        </div>
      </div>

      <Transition name="modal">
        <div
          v-if="showLead"
          class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6"
          role="dialog"
          aria-modal="true"
        >
          <div
            class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity"
            @click="closeLead"
          ></div>
          <div
            class="relative w-full max-w-2xl bg-white dark:bg-slate-950 rounded-xl shadow-2xl border border-slate-200 dark:border-slate-800 flex flex-col max-h-[85vh] transform transition-all"
          >
            <div
              class="flex items-center justify-between px-6 py-4 border-b border-slate-300/60 dark:border-slate-800 rounded-t-xl"
            >
              <h3 class="text-lg font-semibold text-slate-900 dark:text-white">
                {{ crmConfig.entity_name_singular }} Details
                <span
                  class="inline-flex items-center rounded-md mr-1 px-2 py-1 text-xs font-medium inset-ring"
                  :class="getTempBadgeColor(activeLead.temperature)"
                  >{{ activeLead.temperature }}</span
                >
              </h3>
              <button
                @click="closeLead"
                class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-500 dark:hover:bg-slate-900 transition-colors"
              >
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M6 18L18 6M6 6l12 12"
                  />
                </svg>
              </button>
            </div>
            <div class="overflow-y-auto p-6">
              <div v-if="activeLead" class="space-y-6">
                <div
                  class="grid grid-cols-2 gap-4 rounded-lg bg-slate-50 dark:bg-slate-900 py-2 px-4 border border-slate-300/60 dark:border-slate-800"
                >
                  <div>
                    <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider"
                      >{{ crmConfig.entity_name_singular }} ID</span
                    >
                    <p class="mt-1 text-sm font-mono text-slate-700 dark:text-slate-300">
                      {{ activeLead.id }}
                    </p>
                  </div>
                  <div>
                    <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider"
                      >Added On</span
                    >
                    <p class="mt-1 text-sm text-slate-700 dark:text-slate-300">
                      {{ formatDateString(activeLead.created_at, false, true) }}
                    </p>
                  </div>
                </div>

                <div>
                  <h4 class="text-sm font-medium text-slate-900 dark:text-white mb-3">
                    {{ crmConfig.entity_name_singular }} Data
                  </h4>
                  <div class="space-y-3">
                    <div
                      v-for="(value, key) in activeLead.payload"
                      :key="key"
                      class="group rounded-lg border border-slate-200 dark:border-slate-800 py-2 px-4 hover:border-primary/50 transition-colors"
                    >
                      <dt class="text-xs font-medium text-slate-500 uppercase mb-1.5">{{ key }}</dt>
                      <dd
                        class="text-sm text-slate-800 dark:text-slate-200 break-all leading-relaxed"
                      >
                        {{ typeof value === 'object' ? JSON.stringify(value, null, 2) : value }}
                      </dd>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div class="border-t border-slate-300/60 dark:border-slate-800 px-6 py-4 rounded-b-xl">
              <button
                @click="closeLead"
                class="w-full inline-flex justify-center items-center px-4 py-2 border border-slate-300 dark:border-slate-700 btn-secondary"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </div>

    <Transition name="slide-up">
      <div
        v-if="selectedLeadIds.length > 0"
        class="fixed bottom-10 left-1/2 -translate-x-1/2 z-50 w-full max-w-2xl px-4"
      >
        <div
          class="bg-slate-900 dark:bg-white text-white dark:text-slate-900 shadow-2xl rounded-2xl p-2 flex items-center justify-between border border-white/10 dark:border-slate-200"
        >
          <div class="flex items-center gap-4 px-4">
            <div
              class="h-10 w-10 rounded-xl bg-indigo-500 flex items-center justify-center text-white font-bold"
            >
              {{ selectedLeadIds.length }}
            </div>
            <div class="hidden sm:block">
              <p class="text-sm font-bold leading-tight">
                Selected
                {{
                  selectedLeadIds.length > 1
                    ? crmConfig.entity_name_plural
                    : crmConfig.entity_name_singular
                }}
              </p>
              <button
                @click="selectedLeadIds = []"
                class="text-[10px] uppercase tracking-widest opacity-60 hover:opacity-100 transition-opacity"
              >
                Deselect All
              </button>
            </div>
          </div>

          <div class="flex items-center gap-1 bg-white/5 dark:bg-slate-50 p-1 rounded-xl">
            <button
              @click="handleBulkAction('export')"
              class="flex items-center gap-2 p-2.5 hover:bg-white/10 dark:hover:bg-slate-200 rounded-lg transition-all group"
              title="Export CSV"
            >
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"
                />
              </svg>
              <span>Export</span>
            </button>

            <!-- <button @click="handleBulkAction('status_update')" class="p-2.5 hover:bg-white/10 dark:hover:bg-slate-200 rounded-lg transition-all opacity-40 hover:opacity-100" title="Bulk Update Status">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>
                    </button>

                    <button @click="handleBulkAction('delete')" class="p-2.5 hover:bg-red-500/20 text-red-400 rounded-lg transition-all" title="Delete Leads">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                    </button> -->
          </div>
        </div>
      </div>
    </Transition>
    <input type="file" ref="fileInput" @change="handleImport" class="hidden" accept=".csv" />
  </div>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition:
    opacity 0.2s ease,
    transform 0.2s ease;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
  transform: scale(0.95);
}
</style>
