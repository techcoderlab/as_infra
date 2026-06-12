<script setup>
import { onMounted, ref, computed, onUnmounted } from 'vue'
import api from '../../utils/request'
import VueApexCharts from 'vue3-apexcharts'
import { useApiCache } from '@/composables/useApiCache'

// import AiBrainSettings from '@/components/Ai/AiBrainSettings.vue'

const loading = ref(true)
const stats = ref({
  overview: { total_leads: 0, new_leads: 0, hot_leads: 0, conversion_rate: 0, stale_leads: 0 },
  growth: { this_month: 0, last_month: 0, percentage: 0 },
  chart_data: {},
  top_sources: [],
})

// --- Chart Configuration (GHL Style) ---
const chartOptions = computed(() => ({
  chart: {
    type: 'area',
    toolbar: { show: false },
    zoom: { enabled: false },
    sparkline: { enabled: false },
  },
  colors: ['#6366f1'], // Indigo-500
  dataLabels: { enabled: false },
  stroke: { curve: 'smooth', width: 2 },
  fill: {
    type: 'gradient',
    gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.1, stops: [0, 90, 100] },
  },
  xaxis: {
    categories: Object.keys(stats.value.chart_data),
    labels: { show: false },
    axisBorder: { show: false },
    axisTicks: { show: false },
  },
  yaxis: { show: false },
  grid: { show: false },
  tooltip: { x: { format: 'dd MMM' }, theme: 'dark' },
}))

const chartSeries = computed(() => [
  {
    name: 'New Leads',
    data: Object.values(stats.value.chart_data),
  },
])

const { fetchDataWithCache } = useApiCache()

const fetchDashboard = async (event) => {
  let refresh = false
  const ttl = 20000 // 10 seconds
  if (event?.target?.value?.toLowerCase().trim() === 'sync') {
    console.log('Syncing dashboard data')
    refresh = true
  }

  loading.value = true
  try {
    // const res = await api.get('/leads/stats')
    // stats.value = res.data.stats

    const result = await fetchDataWithCache(
      'agency_dashboard_stats',
      () => Promise.all([api.get('/leads/stats')]),
      ttl,
      refresh,
    )
    const { data } = result[0]

    if (data) {
      stats.value = data.stats
    }
  } catch (e) {
    console.error('Dashboard failed to load', e)
  } finally {
    loading.value = false
  }
}

let fetchDataInterval = null
onMounted(() => {
  fetchDashboard()
  fetchDataInterval = setInterval(() => {
    fetchDashboard()
  }, 500000)
})

onUnmounted(() => {
  clearInterval(fetchDataInterval)
})
</script>

<template>
  <div class="p-6 space-y-8 min-h-screen">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Dashboard</h1>
        <p class="text-slate-500 text-sm mt-1">
          Real-time performance metrics for your lead generation.
        </p>
      </div>
      <button
        @click="fetchDashboard"
        value="sync"
        class="flex items-center gap-2 px-4 py-2 btn-primary border border-slate-200 dark:border-slate-800 rounded-lg text-sm font-semibold hover:shadow-lg transition-all"
      >
        <svg
          :class="{ 'animate-spin': loading }"
          class="w-4 h-4"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
          />
        </svg>
        Sync Data
      </button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
      <div
        class="bg-white dark:bg-slate-900 p-6 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-lg"
      >
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs font-bold text-slate-400 uppercase tracking-widest">Efficiency</span>
          <div class="p-2 bg-indigo-50 dark:bg-indigo-500/10 rounded-lg text-indigo-600">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"
              />
            </svg>
          </div>
        </div>
        <div class="text-3xl font-black text-slate-900 dark:text-white">
          {{ stats.overview.conversion_rate }}%
        </div>
        <div class="text-sm font-medium text-slate-500 mt-1">Opportunities-to-Close Rate</div>
      </div>

      <div
        class="bg-white dark:bg-slate-900 p-6 rounded-2xl border-2 shadow-lg"
        :class="
          stats.overview.stale_leads > 0
            ? 'border-red-100 dark:border-red-900/30'
            : 'border-slate-200 dark:border-slate-800'
        "
      >
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs font-bold text-slate-400 uppercase tracking-widest"
            >Revenue Leak</span
          >
          <div
            :class="
              stats.overview.stale_leads > 0
                ? 'bg-red-50 text-red-600'
                : 'bg-slate-50 text-slate-400'
            "
            class="p-2 rounded-lg"
          >
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
              />
            </svg>
          </div>
        </div>
        <div
          class="text-3xl font-black"
          :class="
            stats.overview.stale_leads > 0 ? 'text-red-600' : 'text-slate-900 dark:text-white'
          "
        >
          {{ stats.overview.stale_leads }}
        </div>
        <div class="text-sm font-medium text-slate-500 mt-1">Unattended (>24h)</div>
      </div>

      <div
        class="bg-white dark:bg-slate-900 p-6 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-lg"
      >
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs font-bold text-slate-400 uppercase tracking-widest"
            >Pipeline Growth</span
          >
          <span
            :class="
              stats.growth.percentage >= 0 ? 'text-green-600 bg-green-50' : 'text-red-600 bg-red-50'
            "
            class="text-[10px] font-bold px-2 py-0.5 rounded-full"
          >
            {{ stats.growth.percentage >= 0 ? '+' : '' }}{{ stats.growth.percentage }}%
          </span>
        </div>
        <div class="text-3xl font-black text-slate-900 dark:text-white">
          {{ stats.growth.this_month }}
        </div>
        <div class="text-sm font-medium text-slate-500 mt-1">Opportunities This Month</div>
      </div>

      <div class="bg-indigo-600 p-6 rounded-2xl shadow-xl shadow-indigo-500/20">
        <div class="flex items-center justify-between mb-2 text-indigo-200">
          <span class="text-xs font-bold uppercase tracking-widest">Hot Pipeline</span>
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.5-7 3 3 3 6 1 9 3-2 4-3 6-3 0 1.5-1 4-2.5 5.5z"
            />
          </svg>
        </div>
        <div class="text-3xl font-black text-white">{{ stats.overview.hot_leads }}</div>
        <div class="text-sm font-medium text-indigo-100 mt-1">High Intent Opportunities</div>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div
        class="lg:col-span-2 bg-white dark:bg-slate-900 p-6 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-lg"
      >
        <div class="flex items-center justify-between mb-6">
          <h3 class="font-bold text-slate-900 dark:text-white italic">7-Day Acquisition Trend</h3>
          <span class="text-xs text-slate-400 font-medium">Daily Opportunities Captured</span>
        </div>
        <div class="h-64">
          <VueApexCharts width="100%" height="100%" :options="chartOptions" :series="chartSeries" />
        </div>
      </div>

      <div
        class="bg-white dark:bg-slate-900 p-6 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-lg"
      >
        <h3 class="font-bold text-slate-900 dark:text-white mb-6">Traffic Integrity</h3>
        <div class="space-y-4">
          <div v-for="source in stats.top_sources" :key="source.source" class="flex flex-col">
            <div class="flex justify-between text-sm mb-1">
              <span class="capitalize font-medium text-slate-600 dark:text-slate-400">{{
                source.source.replace('_', ' ')
              }}</span>
              <span class="font-bold text-slate-900 dark:text-white">{{ source.count }}</span>
            </div>
            <div class="w-full bg-slate-100 dark:bg-slate-800 h-1.5 rounded-full overflow-hidden">
              <div
                class="bg-indigo-500 h-full transition-all duration-500"
                :style="{ width: (source.count / stats.overview.total_leads) * 100 + '%' }"
              ></div>
            </div>
          </div>
          <div
            v-if="stats.top_sources.length === 0"
            class="text-center py-8 text-slate-400 text-sm"
          >
            No source data available yet.
          </div>
        </div>
      </div>

      <!-- <AiBrainSettings /> -->
    </div>
  </div>
</template>
