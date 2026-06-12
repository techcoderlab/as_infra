<script setup>
import { ref } from 'vue'

const props = defineProps({
  text: { type: String, required: true },
  title: { type: String, default: 'Copy' }
})

const copied = ref(false)

const copy = async () => {
  try {
    await navigator.clipboard.writeText(props.text)
    copied.value = true
    
    setTimeout(() => {
      copied.value = false
    }, 2000)
  } catch (err) {
    console.error('Failed to copy', err)
  }
}
</script>

<template>
  <button 
    @click.stop="copy" 
    class="relative group flex items-center justify-center transition-all duration-300 ease-in-out"
    :title="title"
    :class="copied ? 'w-16 bg-green-50 rounded-full px-2' : 'w-6 text-slate-400 hover:text-primary'"
  >
    <svg 
      v-if="!copied"
      xmlns="http://www.w3.org/2000/svg" 
      class="h-4 w-4 transition-transform group-hover:scale-110" 
      fill="none" 
      viewBox="0 0 24 24" 
      stroke="currentColor" 
      stroke-width="2"
    >
      <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
    </svg>

    <span 
        v-else 
        class="text-xs font-bold text-green-600 animate-in fade-in slide-in-from-bottom-1 duration-200 flex items-center gap-1"
    >
        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
        Copied
    </span>
  </button>
</template>