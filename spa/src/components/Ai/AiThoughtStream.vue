<template>
  <div class="bg-slate-900 text-slate-300 rounded-lg overflow-hidden font-mono text-xs border border-slate-700">
    <div class="bg-slate-800 px-4 py-2 border-b border-slate-700 font-bold text-slate-100 flex justify-between">
       <span>AGENT EXECUTION LOG</span>
       <span v-if="loading" class="loading loading-dots loading-xs"></span>
    </div>

    <div class="p-4 h-64 overflow-y-auto space-y-3">
        <div v-if="!thoughts || thoughts.length === 0" class="text-slate-500 italic text-center mt-10">
            No agent activity recorded for this lead.
        </div>

        <div v-for="(log, idx) in thoughts" :key="idx" class="animate-fade-in">
            <div class="flex items-center gap-2 mb-1">
                <span class="text-green-500">>></span>
                <span class="text-blue-400 font-bold">[{{ log.step }}]</span>
                <span class="text-slate-400 text-[10px]">{{ log.timestamp }}</span>
            </div>

            <div class="pl-6 text-slate-300 break-words whitespace-pre-wrap">
                {{ log.detail }}
            </div>
        </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
    thoughts: {
        type: Array,
        default: () => []
    },
    loading: Boolean
});
</script>
