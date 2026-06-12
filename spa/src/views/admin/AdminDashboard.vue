<script setup>
import { onMounted, ref, computed } from 'vue'
import request from '@/utils/request' // Using your project's request utility
import VueApexCharts from 'vue3-apexcharts'

const loading = ref(true)
const stats = ref({
  overview: {
    total_tenants: 0,
    active_tenants: 0,
    new_trials: 0,
    system_health: 100,
    churned_tenants: 0,
  },
  growth: { this_month: 0, last_month: 0, percentage: 0 },
  chart_data: {},
  top_plans: [],
})

// --- Chart Configuration (Shared Style) ---
const chartOptions = computed(() => ({
  chart: {
    type: 'area',
    toolbar: { show: false },
    zoom: { enabled: false },
    sparkline: { enabled: false },
  },
  colors: ['#10b981'], // Emerald-500 (Distinct color for Admin)
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
    name: 'New Tenants',
    data: Object.values(stats.value.chart_data),
  },
])

const fetchDashboard = async () => {
  loading.value = true
  try {
    // Ensure you create this endpoint or mock it in backend
    const res = await request.get('/admin/stats')
    stats.value = res.data.stats
  } catch (e) {
    console.error('Admin Dashboard failed to load', e)
    // Fallback data for UI testing if API fails
    stats.value = {
      overview: { system_health: 98, churned_tenants: 2, new_trials: 5, total_tenants: 120 },
      growth: { this_month: 1200, percentage: 15 },
      chart_data: { Mon: 1, Tue: 3, Wed: 2, Thu: 5, Fri: 3, Sat: 6, Sun: 4 },
      top_plans: [
        { name: 'Agency Pro', count: 45, total: 120 },
        { name: 'Starter', count: 30, total: 120 },
        { name: 'Enterprise', count: 10, total: 120 },
      ],
    }
  } finally {
    loading.value = false
  }
}

// onMounted(fetchDashboard)
</script>

<template>
  <div class="p-6 space-y-8 bg-slate-50 dark:bg-slate-950 min-h-screen">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Admin Overview</h1>
        <p class="text-slate-500 text-sm mt-1">
          System-wide performance metrics and tenant growth.
        </p>
      </div>
      <button
        @click="fetchDashboard"
        class="flex items-center gap-2 px-4 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-lg text-sm font-semibold hover:shadow-sm transition-all"
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
        class="bg-white dark:bg-slate-900 p-6 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm"
      >
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs font-bold text-slate-400 uppercase tracking-widest"
            >System Health</span
          >
          <div class="p-2 bg-emerald-50 dark:bg-emerald-500/10 rounded-lg text-emerald-600">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
              />
            </svg>
          </div>
        </div>
        <div class="text-3xl font-black text-slate-900 dark:text-white">
          {{ stats.overview.system_health }}%
        </div>
        <div class="text-sm font-medium text-slate-500 mt-1">Uptime / Reliability</div>
      </div>

      <div
        class="bg-white dark:bg-slate-900 p-6 rounded-2xl border-2 shadow-sm"
        :class="
          stats.overview.churned_tenants > 0
            ? 'border-red-100 dark:border-red-900/30'
            : 'border-slate-200 dark:border-slate-800'
        "
      >
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs font-bold text-slate-400 uppercase tracking-widest">Churn Risk</span>
          <div
            :class="
              stats.overview.churned_tenants > 0
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
                d="M13 10V3L4 14h7v7l9-11h-7z"
              />
            </svg>
          </div>
        </div>
        <div
          class="text-3xl font-black"
          :class="
            stats.overview.churned_tenants > 0 ? 'text-red-600' : 'text-slate-900 dark:text-white'
          "
        >
          {{ stats.overview.churned_tenants }}
        </div>
        <div class="text-sm font-medium text-slate-500 mt-1">Cancellations this month</div>
      </div>

      <div
        class="bg-white dark:bg-slate-900 p-6 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm"
      >
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs font-bold text-slate-400 uppercase tracking-widest">MRR Growth</span>
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
          ${{ stats.growth.this_month }}
        </div>
        <div class="text-sm font-medium text-slate-500 mt-1">New Recurring Revenue</div>
      </div>

      <div class="bg-indigo-600 p-6 rounded-2xl shadow-xl shadow-indigo-500/20">
        <div class="flex items-center justify-between mb-2 text-indigo-200">
          <span class="text-xs font-bold uppercase tracking-widest">Active Trials</span>
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"
            />
          </svg>
        </div>
        <div class="text-3xl font-black text-white">{{ stats.overview.new_trials }}</div>
        <div class="text-sm font-medium text-indigo-100 mt-1">Potential Conversions</div>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div
        class="lg:col-span-2 bg-white dark:bg-slate-900 p-6 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm"
      >
        <div class="flex items-center justify-between mb-6">
          <h3 class="font-bold text-slate-900 dark:text-white italic">Tenant Acquisition</h3>
          <span class="text-xs text-slate-400 font-medium">Last 7 Days</span>
        </div>
        <div class="h-64">
          <VueApexCharts width="100%" height="100%" :options="chartOptions" :series="chartSeries" />
        </div>
      </div>

      <div
        class="bg-white dark:bg-slate-900 p-6 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm"
      >
        <h3 class="font-bold text-slate-900 dark:text-white mb-6">Plan Distribution</h3>
        <div class="space-y-4">
          <div v-for="plan in stats.top_plans" :key="plan.name" class="flex flex-col">
            <div class="flex justify-between text-sm mb-1">
              <span class="capitalize font-medium text-slate-600 dark:text-slate-400">{{
                plan.name
              }}</span>
              <span class="font-bold text-slate-900 dark:text-white">{{ plan.count }}</span>
            </div>
            <div class="w-full bg-slate-100 dark:bg-slate-800 h-1.5 rounded-full overflow-hidden">
              <div
                class="bg-indigo-500 h-full transition-all duration-500"
                :style="{ width: (plan.count / plan.total) * 100 + '%' }"
              ></div>
            </div>
          </div>
          <div v-if="stats.top_plans.length === 0" class="text-center py-8 text-slate-400 text-sm">
            No plan data available.
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
