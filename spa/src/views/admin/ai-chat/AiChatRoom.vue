<template>
  <div class="h-[calc(100vh-100px)] flex flex-col -m-4 sm:-m-8">
    <div
      class="bg-white dark:bg-slate-950 border-b border-slate-200 dark:border-slate-800 px-6 py-3 flex items-center justify-between z-10 transition-colors duration-300"
    >
      <div class="flex items-center gap-4">
        <button
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
        :submitButtonStyles.prop="styleConfig.submitButtonStyles"
        :auxiliaryStyle.prop="styleConfig.auxiliaryStyle"
        :attachmentButtonStyle.prop="styleConfig.attachmentButtonStyle"
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

const route = useRoute()
const router = useRouter()
const deepChatRef = ref(null)
const chatConfig = ref(null)
const history = ref([])
const chatId = route.params.id

// Pagination & Status
const nextCursor = ref(null)
const hasMoreMessages = ref(false)
const isLoadingMore = ref(false)
const connectionStatus = ref('checking')
const agentState = ref('')

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

    // Priority list of keys to look for
    const keys = [
      'response',
      'text',
      'result',
      'output',
      'content',
      'message',
      'reply',
      'data',
      'value',
    ]

    // Find the first key that exists in the object
    const foundKey = keys.find((key) => Object.prototype.hasOwnProperty.call(data, key))

    // Return the value of the found key, or the whole object if no keys match
    return foundKey !== undefined ? data[foundKey] : data
  } catch (e) {
    // Not valid JSON, return original input
    return input
  }
}

// Examples:
// extractJsonValue('{"response": "Hello!"}') -> "Hello!"
// extractJsonValue('{"status": 200, "result": "Success"}') -> "Success"
// extractJsonValue('Just a plain string') -> "Just a plain string"

const chatHandler = async (body, signals) => {
  // 1. Create a local controller for this specific request
  const controller = new AbortController()

  // DOCS: "triggered when the user clicks the stop button"
  signals.stopClicked.listener = () => {
    controller.abort()
  }

  agentState.value = 'Thinking...'

  try {
    const userMessage = body.messages[0]

    // Initial POST to prepare the message/thread
    const { data } = await request.post(`/ai-chats/${chatId}/message`, {
      text_content: userMessage.text,
    })

    // Convert absolute URL to relative to ensure it goes through our Vite proxy
    // This avoids CORS preflight issues with ngrok
    const streamUrl = new URL(data.stream_url)
    const relativeUrl = streamUrl.pathname + streamUrl.search

    // Start the Stream
    const response = await fetch(relativeUrl, {
      method: 'GET',
      headers: {
        Accept: 'text/event-stream',
      },
      signal: controller.signal,
      credentials: 'include',
    })

    if (!response.ok || !response.body) throw new Error('Stream error')

    // DOCS: "stops the loading bubble" - Call this ONLY when connection is valid
    signals.onOpen()

    const reader = response.body.getReader()
    const decoder = new TextDecoder()
    let buffer = ''
    let finalString = ''

    while (true) {
      const { done, value } = await reader.read()

      if (done) {
        // DOCS: "The stop button will be changed back to submit button"
        // signals.onResponse({ text: payload.data })
        signals.onClose()
        break
      }

      buffer += decoder.decode(value, { stream: true })
      // Split by double newline (standard SSE format)
      const lines = buffer.split(/\r?\n\r?\n/)
      buffer = lines.pop()

      for (const line of lines) {
        const cleanLine = line.trim()
        if (!cleanLine.startsWith('data:')) continue

        const jsonStr = cleanLine.replace(/^data:\s?/, '').trim()

        // Handle specific "DONE" signals from your backend
        if (jsonStr.includes('"type":"done"') || jsonStr === '[DONE]') {
          // console.log('Stream Finished', finalString)
          signals.onClose()
          signals.onResponse({ text: extractJsonValue(finalString) })
          console.log(finalString)
          return
        }

        // console.log('Clean Line:', cleanLine)

        try {
          const payload = JSON.parse(jsonStr)

          if (payload.type === 'token') {
            // DOCS: "adds text into the message bubble"
            // We do NOT use overwrite: true. We just send the new chunk.
            finalString += payload.data
            // signals.onResponse({ text: payload.data })
          } else if (payload.type === 'error') {
            finalString += payload.data
            signals.onResponse({ error: payload.data + ' please try again!' })
            // signals.onResponse({ error: payload.data })
            // console.error('Stream Error:', payload.data)
            return
          }
        } catch (e) {
          // Ignore partial JSON parse errors
        }
      }
    }
  } catch (e) {
    if (e.name === 'AbortError') {
      // User clicked stop, clean up UI
      signals.onClose()
      return
    }
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
      placeholder: { text: 'Type a message...', style: { color: c.placeholder } },
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
            #messages {width:100%; margin: 0 auto; padding-bottom: 200px; }
            ::-webkit-scrollbar { width: 8px; }
            ::-webkit-scrollbar-thumb { background: ${dark ? '#334155' : '#cbd5e1'}; borderRadius: 50%; }
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
