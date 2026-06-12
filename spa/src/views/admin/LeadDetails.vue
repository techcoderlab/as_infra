<script setup>
import { onMounted, ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../../utils/request'
import { toTitleCase, formatDate, humanizeDate } from '../../utils/helpers'
import LeadActivityTimeline from '../../components/LeadActivityTimeline.vue'

const route = useRoute()
const router = useRouter()
const lead = ref(null)
const loading = ref(true)
const noteContent = ref('')
const timelineRef = ref(null)

// UI State
const activeTab = ref('activity')
const sidebarCollapsed = ref(false)

const tabs = computed(() => {
  const baseTabs = [
    { id: 'activity', label: 'Activities', icon: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z' },
    { id: 'overview', label: 'Overview', icon: 'M4 6h16M4 12h16M4 18h7' },
  ]

  if (lead.value?.source !== 'google_review') {
    baseTabs.push({
      id: 'review',
      label: 'Manage Review',
      icon: 'M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.518 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.921-.755 1.688-1.54 1.118l-3.976-2.888a1 1 0 00-1.175 0l-3.976 2.888c-.784.57-1.838-.197-1.539-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z',
    })
  }

  baseTabs.push({
    id: 'settings',
    label: 'Settings',
    icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z',
  })

  return baseTabs
})

const fetchLead = async () => {
  try {
    loading.value = true
    const { data } = await api.get(`/leads/${route.params.id}`)
    lead.value = data
    // console.log(lead.value)
  } catch (e) {
    console.error(e)
  } finally {
    loading.value = false
  }
}

const updateLead = async () => {
  try {
    await api.put(`/leads/${lead.value.id}`, {
      status: lead.value.status,
      temperature: lead.value.temperature,
    })
    timelineRef.value?.refresh()
  } catch (e) {
    console.log(e)
  }
}

const addNote = async () => {
  if (!noteContent.value) return
  try {
    const { data } = await api.post(`/leads/${lead.value.id}/note`, { content: noteContent.value })
    // If we're on the activity tab, the timeline will refresh or we can manually add
    if (lead.value.activities) {
      lead.value.activities.unshift(data)
    }
    timelineRef.value?.refresh()
    noteContent.value = ''
  } catch (e) {
    console.error(e)
  }
}

const getBadgeClass = (color) => {
  const map = {
    blue: 'badge-blue',
    green: 'badge-green',
    yellow: 'badge-yellow',
    red: 'badge-red',
    gray: 'badge-slate',
  }
  return map[color] || 'badge-slate'
}

const statusBadge = computed(() => {
  if (!lead.value?.crm_config?.statuses) return 'badge-slate'
  const s = lead.value.crm_config.statuses.find((x) => x.slug === lead.value.status)
  return s ? getBadgeClass(s.color) : 'badge-slate'
})

const tempBadge = computed(() => {
  const map = { hot: 'badge-red', warm: 'badge-yellow', cold: 'badge-blue' }
  return map[lead.value?.temperature] || 'badge-slate'
})

const availableStatuses = computed(
  () =>
    lead.value?.crm_config?.statuses || [
      { slug: 'new', label: 'New' },
      { slug: 'contacted', label: 'Contacted' },
      { slug: 'closed', label: 'Closed' },
    ],
)

const availableTemperatures = computed(
  () =>
    lead.value?.crm_config?.temperature_options || [
      { slug: 'hot', label: 'Hot' },
      { slug: 'warm', label: 'Warm' },
      { slug: 'cold', label: 'Cold' },
    ],
)

const formatToTitleCase = (key) => toTitleCase(key)

const getFieldLabel = (key) => {
  // If mapping exists, use it.
  if (lead.value?.form?.fields_needed?.[key]) {
    return lead.value.form.fields_needed[key]
  }
  return formatToTitleCase(key)
}
// const formatKey = (key) => key.replace(/_/g, ' ').replace(/\b\w/g, (l) => l.toUpperCase())

const postingReply = ref(false)
const postReviewReply = async () => {
  if (!lead.value.payload?.reply_draft) return
  try {
    postingReply.value = true
    await api.post(`/leads/${lead.value.id}/post-review-reply`)
    await fetchLead()
    timelineRef.value?.refresh()
  } catch (e) {
    console.error(e)
    alert('Failed to post reply')
  } finally {
    postingReply.value = false
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

const toggleSidebar = () => {
  sidebarCollapsed.value = !sidebarCollapsed.value
}

onMounted(fetchLead)
</script>

<template>
  <div class="h-full flex flex-col">
    <!-- Header -->
    <div class="page-header shrink-0">
      <div class="flex items-center gap-4">
        <button @click="router.back()" class="btn-icon">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M10 19l-7-7m0 0l7-7m-7 7h18"
            />
          </svg>
        </button>
        <div>
          <h2 class="page-title">{{ lead?.crm_config?.entity_name_singular }} Details</h2>
          <p class="page-subtitle">View and manage interaction history.</p>
        </div>
      </div>
      <div v-if="lead" class="flex items-center gap-3">
        <span class="hidden sm:block font-mono badge badge-slate">ID: {{ lead.id }}</span>
        <span :class="['badge', statusBadge]">{{ lead.status }}</span>
        <span :class="['badge', tempBadge]">{{ lead.temperature }}</span>
        <span v-if="lead.score && lead.score > 0" :class="['badge badge-purple']">{{
          `Score: ${lead.score}`
        }}</span>
        <!-- <span v-if="lead.activities?.length > 0" class="badge badge-slate">
          Last Interaction {{ formatDateString(lead.activities[0].created_at, true) }}
        </span> -->
      </div>
    </div>

    <div v-if="loading" class="flex-1 flex items-center justify-center text-slate-500">
      <div class="flex flex-col items-center gap-3">
        <div
          class="w-8 h-8 border-4 border-slate-300 border-t-slate-900 rounded-full animate-spin dark:border-slate-700 dark:border-t-white"
        ></div>
        <span>Loading details...</span>
      </div>
    </div>

    <div v-else-if="!lead" class="flex-1 flex items-center justify-center text-slate-500">
      <div class="flex flex-col items-center gap-3">
        <div class="text-xl font-semibold">
          {{ lead?.crm_config?.entity_name_singular }} Not Found
        </div>
        <button @click="router.back()" class="btn btn-primary btn-block">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M10 19l-7-7m0 0l7-7m-7 7h18"
            />
          </svg>
          &nbsp; <span class="font-semibold">Go Back</span>
        </button>
      </div>
    </div>

    <!-- Main Layout -->
    <div v-else class="details-layout flex-1 min-h-0">
      <!-- Sidebar Tabs -->
      <aside :class="['details-sidebar', { collapsed: sidebarCollapsed }]">
        <div class="details-sidebar-tabs h-full flex flex-col">
          <div class="flex-1 space-y-1">
            <button
              v-for="tab in tabs"
              :key="tab.id"
              @click="activeTab = tab.id"
              :class="['details-tab-item w-full', { active: activeTab === tab.id }]"
              :title="sidebarCollapsed ? tab.label : ''"
            >
              <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  :d="tab.icon"
                />
              </svg>
              <span v-if="!sidebarCollapsed" class="truncate">{{ tab.label }}</span>
            </button>
          </div>

          <!-- Collapse Toggle (Desktop only) -->
          <button
            @click="toggleSidebar"
            class="hidden lg:flex items-center gap-3 px-4 py-3 text-sm font-medium text-slate-500 hover:text-slate-900 dark:hover:text-slate-100 transition-colors mt-auto border-t border-slate-100 dark:border-slate-800"
          >
            <svg
              :class="[
                'w-5 h-5 transition-transform duration-300',
                { 'rotate-180': sidebarCollapsed },
              ]"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M13 5l-7 7 7 7"
              />
            </svg>
            <span v-if="!sidebarCollapsed">Collapse Sidebar</span>
          </button>
        </div>
      </aside>

      <!-- Content Area -->
      <main class="details-main-content h-full overflow-y-auto custom-scrollbar pr-1">
        <!-- Overview Tab -->
        <div
          v-if="activeTab === 'overview'"
          class="space-y-6 animate-in fade-in slide-in-from-bottom-2 duration-300"
        >
          <div class="card h-[40vh] overflow-y-auto custom-scrollbar">
            <div class="card-header sticky top-0 z-10">
              <span class="card-title">Core Information</span>
              <span class="text-xs text-slate-500"
                >Created {{ formatDateString(lead.created_at, false, true) }}</span
              >
            </div>
            <div class="card-body">
              <!-- {{ console.log(lead.payload) }} -->

              <div v-if="lead.displayable_fields" class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div v-for="(value, key) in lead.displayable_fields" :key="key" class="group">
                  <dt>
                    <span for="" class="form-label">{{ getFieldLabel(key) }}</span>
                  </dt>
                  <dd class="mt-1 text-sm text-slate-900 dark:text-white break-words px-1">
                    {{
                      (Array.isArray(value)
                        ? value.map(formatToTitleCase).join(', ')
                        : String(value)) || '---'
                    }}
                  </dd>
                </div>
              </div>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="card">
              <div class="card-header"><span class="card-title">Source Details</span></div>
              <div class="card-body space-y-4">
                <div>
                  <span class="form-label">Traffic Source</span>
                  <div class="mt-1 text-sm font-medium px-1">
                    {{ formatToTitleCase(lead.source) || 'Direct / Unknown' }}
                  </div>
                </div>
                <div v-if="lead.form" class="pt-2 border-t border-slate-50 dark:border-slate-800">
                  <span class="form-label">Originating Form</span>
                  <div class="mt-1 text-sm font-medium text-blue-600 dark:text-blue-400 px-1">
                    {{ lead.form.name }}
                  </div>
                </div>
              </div>
            </div>
            <div class="card">
              <div class="card-header"><span class="card-title">Quick Stats</span></div>
              <div class="card-body space-y-4">
                <div class="p-0 m-0">
                  <span class="form-label">Last Interaction</span>
                  <div class="mt-1 text-sm font-medium text-slate-600 dark:text-slate-400 px-1">
                    {{
                      lead.activities?.length > 0
                        ? formatDateString(lead.activities[0].created_at, true)
                        : 'No recent activity'
                    }}
                  </div>
                </div>
                <div class="p-0 m-0 pt-2 border-t border-slate-50 dark:border-slate-800">
                  <span class="form-label">Total Events</span>
                  <div class="mt-1 text-sm font-medium px-1">
                    {{ lead.activities?.length || 0 }} logged activities
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Review Management Tab -->
        <div
          v-if="activeTab === 'review' && lead.source !== 'google_review'"
          class="space-y-6 animate-in fade-in slide-in-from-bottom-2 duration-300"
        >
          <div class="card">
            <div class="card-header">
              <span class="card-title">Review Details</span>
              <div class="flex items-center gap-1">
                <span
                  v-for="i in 5"
                  :key="i"
                  :class="
                    i <= (lead.payload?.star_rating || 0) ? 'text-yellow-600' : 'text-slate-200'
                  "
                >
                  <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                    <path
                      d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"
                    />
                  </svg>
                </span>
              </div>
            </div>
            <div class="card-body">
              <blockquote
                class="border-l-4 border-slate-200 pl-4 py-2 italic text-slate-700 dark:text-slate-300 bg-slate-300/50 rounded dark:bg-slate-800/50"
              >
                "{{ lead.payload?.comment || 'No comment provided.' }}"
              </blockquote>
              <div class="mt-4 flex items-center gap-2 text-sm text-slate-500">
                <span class="font-bold">Reviewer:</span> {{ lead.payload?.reviewer_name }}
                <span class="mx-2">|</span>
                <span class="font-bold">Date:</span>
                {{ formatDateString(lead.payload?.create_time, true) }}
              </div>
            </div>
          </div>

          <div class="card">
            <div class="card-header">
              <span class="card-title">AI Crafted Reply</span>
              <span v-if="lead.status === 'posted'" class="badge badge-green">Sent to Google</span>
              <span v-else-if="lead.status === 'approved'" class="badge badge-yellow"
                >Ready for Posting</span
              >
              <span v-else class="badge badge-blue">Pending for Review</span>
            </div>
            <div class="card-body space-y-4">
              <div>
                <label class="form-label mb-2">Draft</label>
                <textarea
                  v-model="lead.payload.reply_draft"
                  class="form-textarea w-full"
                  rows="5"
                  :disabled="lead.status === 'posted'"
                ></textarea>
              </div>

              <div v-if="lead.status !== 'posted'" class="flex justify-end gap-3">
                <!-- <button @click="updateLead" class="btn btn-slate">Save Changes</button> -->
                <button
                  @click="postReviewReply"
                  :disabled="postingReply || !lead.payload?.reply_draft"
                  class="btn btn-primary"
                >
                  <span v-if="postingReply">Posting...</span>
                  <span v-else>Approve & Post to Google</span>
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Activity Tab -->
        <div
          v-if="activeTab === 'activity'"
          class="space-y-6 animate-in fade-in slide-in-from-bottom-2 duration-300"
        >
          <div class="card">
            <div class="card-header">
              <span class="card-title">Create Activity</span>
            </div>
            <div class="card-body px-6 py-4">
              <textarea
                v-model="noteContent"
                class="form-textarea border-none focus:outline-none focus:ring-0 focus:border-none transition-none shadow-none p-0 m-0 rounded-none bg-transparent dark:bg-transparent"
                rows="3"
                :placeholder="`Create an activity in a timeline about this ${lead?.crm_config?.entity_name_singular.toLowerCase()}.`"
              ></textarea>
              <div class="flex justify-end m-0 mt-2 p-0">
                <button
                  @click="addNote"
                  :disabled="!noteContent"
                  class="btn btn-secondary px-6 m-0 p-1 rounded"
                >
                  Add to timeline
                </button>
              </div>
            </div>
          </div>

          <div class="card h-[50vh] overflow-y-auto custom-scrollbar">
            <div class="card-header sticky top-0 z-20">
              <span class="card-title">Timeline</span>
            </div>
            <div class="card-body">
              <LeadActivityTimeline
                ref="timelineRef"
                :leadId="lead?.id"
                :job="lead?.latest_job"
                :crmConfig="lead?.crm_config"
                @job-completed="fetchLead"
              />
            </div>
          </div>
        </div>

        <!-- Settings Tab -->
        <div
          v-if="activeTab === 'settings'"
          class="space-y-6 animate-in fade-in slide-in-from-bottom-2 duration-300 w-full"
        >
          <div class="card">
            <div class="card-header">
              <span class="card-title">Status & Priority</span>
            </div>
            <div class="card-body space-y-8">
              <div class="form-group">
                <label class="form-label text-sm font-bold">Stage (Status)</label>
                <p class="text-xs text-slate-500 mb-2 font-mono">
                  Move this {{ lead?.crm_config?.entity_name_singular }} through your defined
                  pipeline.
                </p>
                <select v-model="lead.status" @change="updateLead" class="form-select max-w-sm">
                  <option v-for="s in availableStatuses" :key="s.slug" :value="s.slug">
                    {{ s.label }}
                  </option>
                </select>
              </div>

              <div class="form-group">
                <label class="form-label text-sm font-bold"
                  >{{ lead?.crm_config?.entity_name_singular }} Priority (Temperature)</label
                >
                <p class="text-xs text-slate-500 mb-2 font-mono">
                  Set how urgent this {{ lead?.crm_config?.entity_name_singular }} currently is.
                </p>
                <div class="flex flex-wrap gap-3">
                  <button
                    v-for="temp in availableTemperatures"
                    :key="temp.slug"
                    @click="
                      () => {
                        lead.temperature = temp.slug
                        updateLead()
                      }
                    "
                    :class="[
                      'px-4 py-2 rounded-lg text-sm font-medium border transition-all capitalize',
                      lead.temperature === temp.slug
                        ? 'bg-primary/10 border-primary shadow-md'
                        : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 text-slate-500 dark:text-slate-400 hover:border-slate-400',
                    ]"
                  >
                    {{ temp.label }}
                  </button>
                </div>
              </div>
            </div>
          </div>

          <div class="card border-red-200/50 dark:border-red-900/30">
            <div class="card-header">
              <span class="card-title text-red-600 dark:text-red-400">Danger Zone</span>
            </div>
            <div class="card-body">
              <p class="text-sm text-slate-500 mb-4">
                Deleting a {{ lead?.crm_config?.entity_name_singular }} is permanent and cannot be
                undone.
              </p>
              <button class="btn btn-danger cursor-not-allowed" disabled>
                Delete {{ lead?.crm_config?.entity_name_singular }} Instance
              </button>
            </div>
          </div>
        </div>
      </main>
    </div>
  </div>
</template>

<style scoped>
.animate-in {
  animation: animate-in 0.3s ease-out forwards;
}
@keyframes animate-in {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* Custom transitions for sidebar */
.details-sidebar {
  transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.details-tab-item {
  transition: all 0.2s ease;
}

@media (max-width: 1024px) {
  .details-sidebar-tabs {
    position: sticky;
    top: 0;
    z-index: 10;
  }
}
</style>
