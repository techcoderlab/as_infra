<template>
  <div class="w-full h-full flex flex-col">
    <div
      v-if="!props.hideHeader"
      class="bg-white dark:bg-slate-950 border-b border-slate-200 dark:border-slate-800 px-6 py-3 flex items-center justify-between z-10 transition-colors duration-300"
    >
      <div class="flex items-center gap-4">
        <button
          v-if="!props.hideHeader"
          @click="router.back()"
          class="p-2 rounded-lg text-slate-500 hover:text-slate-700 hover:bg-slate-100 dark:text-slate-400 dark:hover:text-slate-200 dark:hover:bg-slate-800 transition-colors"
        >
          <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="2.5"
            stroke="currentColor"
            class="w-5 h-5"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"
            />
          </svg>
        </button>
        <div>
          <h2 class="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
            {{ chatConfig?.name || 'Loading...' }}
            <span
              v-if="connectionStatus === 'active'"
              class="flex h-2 w-2 relative"
              title="Workflow Active"
            >
              <span
                class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"
              ></span>
              <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
            </span>
            <span v-else class="h-2 w-2 rounded-full bg-red-500" title="Offline"></span>
          </h2>
          <div class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
            <span
              v-if="agentState"
              class="text-emerald-600 dark:text-emerald-400 font-medium flex items-center gap-1"
            >
              <span
                class="animate-spin h-2 w-2 border-2 border-current border-t-transparent rounded-full"
              ></span>
              {{ agentState }}
            </span>
            <span v-else-if="isLoadingMore" class="text-blue-500 animate-pulse"
              >Loading previous messages...</span
            >
            <span v-else-if="hasMoreMessages">Scroll up for more history</span>
            <span v-else>Chat history loaded</span>
          </div>
        </div>
      </div>
    </div>

    <div class="flex-1 relative overflow-hidden flex flex-col transition-colors duration-300">
      <deep-chat
        ref="deepChatRef"
        v-if="chatConfig && styleConfig"
        :history.prop="history"
        :introMessage.prop="introMessage"
        :mixedFiles="true"
        :microphone.prop="{
          files: { format: 'mp3', maxDurationSeconds: 120 },
          button: { position: 'outside-right' },
        }"
        :messageStyles.prop="styleConfig.messageStyles"
        :textInput.prop="styleConfig.textInput"
        :submitButtonStyles.prop="props.readonly ? { submit: { container: { default: { display: 'none' } } } } : styleConfig.submitButtonStyles"
        :auxiliaryStyle.prop="styleConfig.auxiliaryStyle"
        :attachmentButtonStyle.prop="props.readonly ? { styles: { default: { display: 'none' } } } : styleConfig.attachmentButtonStyle"
        class="deep-chat-host"
        style="width: 100%; height: 100%; border: none; background: transparent"
      ></deep-chat>

      <div v-else class="flex items-center justify-center h-full">
        <div class="animate-pulse flex flex-col items-center">
          <div class="h-12 w-12 bg-slate-200 dark:bg-slate-800 rounded-full mb-4"></div>
          <div class="h-4 w-32 bg-slate-200 dark:bg-slate-800 rounded"></div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, computed, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import request from '@/utils/request'
import 'deep-chat'

const props = defineProps({
  chatId: {
    type: [String, Number],
    default: null
  },
  readonly: {
    type: Boolean,
    default: false
  },
  hideHeader: {
    type: Boolean,
    default: false
  }
})


const route = useRoute()
const router = useRouter()
const deepChatRef = ref(null)
const chatConfig = ref(null)
const history = ref([])
const chatId = props.chatId || route.params.id

// Pagination & Status
const nextCursor = ref(null)
const hasMoreMessages = ref(false)
const isLoadingMore = ref(false)
const connectionStatus = ref('checking')
const agentState = ref('')
const isReadonly = ref(props.readonly)

const isDark = ref(document.documentElement.classList.contains('dark'))
const abortController = ref(null)
let observer = null
let scrollContainer = null

// --- 1. ROBUST CHAT HANDLER ---
// const chatHandler = async (body, signals) => {
//   // Cancel previous stream if user sends fast
//   if (abortController.value) abortController.value.abort()
//   abortController.value = new AbortController()

//   // FIX 1: Tiny delay to ensure Deep Chat commits the User Message to DOM
//   // This prevents the "I" bubble from floating to the top as a detached message.
//   await new Promise((r) => setTimeout(r, 50))

//   agentState.value = 'Thinking...'

//   try {
//     const userMessage = body.messages[0]

//     // 1. Save User Message
//     const { data } = await request.post(`/ai-chats/${chatId}/message`, {
//       text_content: userMessage.text,
//     })

//     // 2. Start Stream
//     const response = await fetch(data.stream_url, {
//       method: 'GET',
//       headers: { Accept: 'text/event-stream' },
//       signal: abortController.value.signal,
//     })

//     if (!response.ok) throw new Error('Network error')
//     if (!response.body) throw new Error('No readable stream')

//     const reader = response.body.getReader()
//     const decoder = new TextDecoder()
//     let buffer = ''
//     let fullText = ''

//     while (true) {
//       // const { done, value } = await reader.read()
//       // if (done) break

//       const { done, value } = await reader.read();
//       if (done) {
//         // FIX: Send a final signal indicating the message is finished
//         signals.onResponse({ text: fullText, overwrite: true });
//         break;
//       }

//       buffer += decoder.decode(value, { stream: true })

//       // SSE messages are separated by double newline
//       const lines = buffer.split(/\r?\n\r?\n/)
//       buffer = lines.pop()

//       for (const line of lines) {
//         // FIX 2: Relaxed Regex to catch data even if chunk has leading spaces/newlines
//         const cleanLine = line.trim()
//         if (!cleanLine.startsWith('data:')) continue

//         // Extract JSON (handle "data: " vs "data:")
//         const jsonStr = cleanLine.replace(/^data:\s?/, '').trim()

//         if (jsonStr === '[DONE]') break

//         try {
//           const payload = JSON.parse(jsonStr)

//           if (payload.type === 'token') {
//             fullText += payload.data
//             signals.onResponse({ text: fullText, overwrite: true })
//           } else if (payload.type === 'tool_start') {
//             agentState.value = `Using ${payload.data.tool}...`
//           } else if (payload.type === 'tool_end') {
//             agentState.value = 'Thinking...'
//           } else if (payload.type === 'error') {
//             signals.onResponse({ error: payload.data })
//           }
//         } catch (e) {
//           // Ignore keep-alive pings or partial json
//         }
//       }
//     }

//     // FIX 3: Explicitly stop the loading signal when loop finishes naturally
//     if (signals.stop) signals.stop()
//   } catch (e) {
//     if (e.name === 'AbortError') return
//     console.error('Stream Error:', e)
//     signals.onResponse({ error: 'AI Connection Failed' })
//   } finally {
//     agentState.value = ''
//     abortController.value = null
//   }
// }

/**
 * Safely extracts a value from a potential JSON string based on priority keys.
 * @param {string} input - The string to check and parse.
 * @returns {any} - The extracted value or the original input if not valid JSON.
 */
function extractJsonValue(input) {
  if (typeof input !== 'string' || !input.trim()) return input

  try {
    const data = JSON.parse(input)
    const keys = ['response', 'text', 'result', 'output', 'content', 'message', 'reply', 'data', 'value']
    const foundKey = keys.find((key) => Object.prototype.hasOwnProperty.call(data, key))
    const value = foundKey !== undefined ? data[foundKey] : data

    // Deep Chat requires `text` to be a STRING — coerce everything else
    if (typeof value === 'string') return value
    if (Array.isArray(value)) {
      return value.map((v) => (typeof v === 'string' ? v : JSON.stringify(v))).join('\n')
    }
    if (value !== null && typeof value === 'object') return JSON.stringify(value, null, 2)
    return String(value ?? input)
  } catch (e) {
    return input // plain text — pass through
  }
}

// Examples:
// extractJsonValue('{"response": "Hello!"}') -> "Hello!"
// extractJsonValue('{"status": 200, "result": "Success"}') -> "Success"
// extractJsonValue('Just a plain string') -> "Just a plain string"

const chatHandler = async (body, signals) => {
  const controller = new AbortController()
  signals.stopClicked.listener = () => controller.abort()

  agentState.value = 'Thinking...'

  // v1 fix — let Deep Chat commit the user bubble to the DOM first
  await new Promise((r) => setTimeout(r, 50))

  let finalString = ''
  let closed = false
  const close = () => { if (!closed) { closed = true; signals.onClose() } }

  try {
    const userMessage = body.messages[0]

    const { data } = await request.post(`/ai-chats/${chatId}/message`, {
      text_content: userMessage.text,
    })

    const streamUrl = new URL(data.stream_url)
    const response = await fetch(streamUrl.pathname + streamUrl.search, {
      method: 'GET',
      headers: { Accept: 'text/event-stream' },
      signal: controller.signal,
      credentials: 'include',
    })
    if (!response.ok || !response.body) throw new Error('Stream error')

    signals.onOpen()

    const reader = response.body.getReader()
    const decoder = new TextDecoder()
    let buffer = ''

    while (true) {
      const { done, value } = await reader.read()
      if (done) break

      buffer += decoder.decode(value, { stream: true })
      const lines = buffer.split(/\r?\n\r?\n/)
      buffer = lines.pop()

      for (const line of lines) {
        const cleanLine = line.trim()
        if (!cleanLine.startsWith('data:')) continue
        const jsonStr = cleanLine.replace(/^data:\s?/, '').trim()
        if (!jsonStr || jsonStr === '[DONE]') continue

        try {
          const payload = JSON.parse(jsonStr)

          if (payload.type === 'token') {
            finalString += payload.data
            // KEY: cumulative text + overwrite → REPLACES bubble content
            // each call instead of finalizing on the first call
            // signals.onResponse({ text: finalString, overwrite: true })

          } else if (payload.type === 'tool_start') {
            agentState.value = `Using ${payload.data.tool}...`
          } else if (payload.type === 'tool_end') {
            agentState.value = 'Thinking...'
          } else if (payload.type === 'error') {
            signals.onResponse({ error: payload.data })
            close()
            return
          } else if (payload.type === 'done') {
            signals.onResponse({ text: extractJsonValue(finalString) })
            close()
            return
          }
        } catch { /* partial JSON / keep-alive */ }
      }
    }

    // Stream ended without an explicit done event
    signals.onResponse({ text: extractJsonValue(finalString) })
    close()
  } catch (e) {
    if (e.name === 'AbortError') { close(); return }
    console.error('Stream Error:', e)
    signals.onResponse({ error: 'AI Connection Failed' })
  } finally {
    agentState.value = ''
  }
}
// --- 2. INFINITE SCROLL LOGIC ---
const setupScrollListener = () => {
  const element = deepChatRef.value
  if (!element || !element.shadowRoot) return

  const shadowRoot = element.shadowRoot
  let scrollRebindTimer = null

  const connectScroll = () => {
    clearTimeout(scrollRebindTimer)
    scrollRebindTimer = setTimeout(() => {
      const newContainer = shadowRoot.querySelector('#messages')
      if (!newContainer) return
      if (scrollContainer && scrollContainer !== newContainer) {
        scrollContainer.removeEventListener('scroll', handleScroll)
      }
      scrollContainer = newContainer
      scrollContainer.addEventListener('scroll', handleScroll)
    }, 50)
  }

  connectScroll()
  const shadowObserver = new MutationObserver(() => connectScroll())
  shadowObserver.observe(shadowRoot, { childList: true, subtree: true })
}

const handleScroll = async () => {
  if (!scrollContainer) return
  if (scrollContainer.scrollTop < 50 && hasMoreMessages.value && !isLoadingMore.value) {
    await loadMoreHistory()
  }
}

async function loadMoreHistory() {
  isLoadingMore.value = true
  const oldHeight = scrollContainer.scrollHeight
  const oldTop = scrollContainer.scrollTop

  try {
    const { data } = await request.get(`/ai-chats/${chatId}/history`, {
      params: { before_id: nextCursor.value },
    })

    if (data.messages.length > 0) {
      history.value = [...data.messages, ...history.value]
      nextCursor.value = data.next_cursor
      hasMoreMessages.value = data.has_more

      setTimeout(() => {
        const newHeight = scrollContainer.scrollHeight
        scrollContainer.scrollTop = newHeight - oldHeight + oldTop
        isLoadingMore.value = false
      }, 50)
    } else {
      isLoadingMore.value = false
    }
  } catch (e) {
    isLoadingMore.value = false
  }
}

// --- 3. LIFECYCLE ---
onMounted(async () => {
  try {
    const configRes = await request.get(`/ai-chats`)
    
    chatConfig.value = configRes.data.chats.find((c) => c.id == chatId)
    // console.log(`chatConfig.value: ${JSON.stringify(chatConfig.value, null, 2)}`)

    const agt = configRes.data.agents.find((c) => c.id == chatConfig.value?.ai_agent_id)
    // console.log(`agt: ${JSON.stringify(agt, null, 2)}`)

    // props.readonly = chatConfig.value?.target_type != 'user' || agt?.is_active !== true

    isReadonly.value = chatConfig.value?.target_type != 'user' || agt?.is_active !== true

    const attachChat = () => {
      const el = deepChatRef.value
      if (!el || el.__handlerAttached) return

      el.demo = false
      el.connect = { handler: chatHandler }
      el.__handlerAttached = true
    }

    await nextTick()
    setTimeout(attachChat, 0)

    const { data } = await request.get(`/ai-chats/${chatId}/history`)
    history.value = data.messages
    nextCursor.value = data.next_cursor
    hasMoreMessages.value = data.has_more

    const data2 = await request.get(`/ai-chats/${chatId}/status`)
    connectionStatus.value = data2.data.status

    setTimeout(setupScrollListener, 1000)
  } catch (e) {
    console.error('Error init chat', e)
  }

  observer = new MutationObserver(() => {
    isDark.value = document.documentElement.classList.contains('dark')
  })
  observer.observe(document.documentElement, { attributes: true })
})

onUnmounted(() => {
  if (abortController.value) abortController.value.abort()
  if (observer) observer.disconnect()
  if (scrollContainer) scrollContainer.removeEventListener('scroll', handleScroll)
})

// --- 4. CONFIGS ---
const introMessage = computed(() =>
  chatConfig.value?.welcome_message && history.value.length === 0
    ? { text: chatConfig.value.welcome_message }
    : undefined,
)

const styleConfig = computed(() => {
  const dark = isDark.value
  const c = {
    bg: dark ? '#020617' : '#f8fafc',
    inputBg: dark ? '#0f172a' : '#ffffff',
    text: dark ? '#f8fafc' : '#0f172a',
    placeholder: dark ? '#64748b' : '#94a3b8',
    border: dark ? '#1D293D' : '#CAD5E2',
    userBubble: dark ? '#1D293D' : '#E2E8F0',
    aiBubble: dark ? '#020617' : '#F8FAFC',
  }

  return {
    textInput: {
      disabled: props.readonly,
      placeholder: { text: props.readonly ? 'Chat is read-only' : 'Type a message...', style: { color: c.placeholder } },
      styles: {
        container: {
          backgroundColor: c.inputBg,
          borderRadius: '12px',
          border: `1px solid ${c.border}`,
          maxWidth: '800px',
          padding: '10px 16px',
          boxShadow: dark ? 'none' : '0 1px 2px 0 rgb(0 0 0 / 0.05)',
          transition: 'all 0.3s ease',
        },
        text: { color: c.text, fontSize: '0.95rem' },
      },
    },
    submitButtonStyles: {
      submit: {
        container: {
          default: {
            backgroundColor: dark ? '#ffffff' : '#0f172a',
            borderRadius: '8px',
            width: '34px',
            height: '34px',
            margin: '10px',
          },
          hover: { transform: 'scale(1.05)', opacity: '0.9' },
          click: { transform: 'scale(0.95)' },
        },
        svg: { styles: { default: { color: dark ? '#0f172a' : '#ffffff', fontSize: '0.9rem' } } },
      },
    },
    attachmentButtonStyle: {
      styles: {
        default: {
          filter: dark ? 'invert(1) brightness(2)' : 'none',
          margin: '10px',
          opacity: '0.6',
        },
        hover: { opacity: '1' },
      },
    },
    messageStyles: {
      default: {
        shared: {
          bubble: {
            borderRadius: '10px',
            padding: '12px 16px',
            fontSize: '0.95rem',
            lineHeight: '1.6',
            marginTop: '30px',
            backgroundColor: 'transparent',
          },
        },
        user: {
          bubble: { backgroundColor: c.userBubble, color: c.text },
        },

        ai: {
          bubble: {
            backgroundColor: `${c.aiBubble} !important`,
            color: c.text,
            border: `1px solid ${c.border}`,
          },
        },
      },
    },
    auxiliaryStyle: `
      #messages {
        width: 100%; 
        padding: 24px 32px; 
        padding-bottom: ${props.readonly ? '40px' : '150px'}; 
        box-sizing: border-box; 
      }
      ::-webkit-scrollbar { width: 6px; }
      ::-webkit-scrollbar-thumb { background: ${dark ? '#334155' : '#cbd5e1'}; border-radius: 10px; }
      ::-webkit-scrollbar-track { background: transparent; }
      .deep-chat-host { width: 100%; height: 100%; display: block; background: transparent; border: none; }
    `,
  }
})
</script>

<style scoped>
:deep(deep-chat) {
  display: block;
  width: 100%;
  height: 100%;
  border: none !important;
}
</style>
