<template>
    <div class="h-[calc(100vh-100px)] flex flex-col -m-4 sm:-m-8">
      
      <div class="bg-white dark:bg-slate-950 border-b border-slate-200 dark:border-slate-800 px-6 py-3 flex items-center justify-between z-10 transition-colors duration-300">
        <div class="flex items-center gap-4">
            <button @click="router.back()" class="p-2 rounded-lg text-slate-500 hover:text-slate-700 hover:bg-slate-100 dark:text-slate-400 dark:hover:text-slate-200 dark:hover:bg-slate-800 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-5 h-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                </svg>
            </button>
            <div>
              <h2 class="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                  {{ chatConfig?.name || 'Loading...' }}
                  <span v-if="connectionStatus === 'active'" class="flex h-2 w-2 relative" title="Workflow Active">
                      <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                      <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                  </span>
                  <span v-else class="h-2 w-2 rounded-full bg-red-500" title="Offline"></span>
              </h2>
              <div class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                <span v-if="isLoadingMore" class="text-blue-500 animate-pulse">Loading previous messages...</span>
                <span v-else-if="hasMoreMessages">Scroll up for more history</span>
                <span v-else>Chat history loaded</span>
              </div>
            </div>
        </div>
      </div>
  
      <div class="flex-1 relative overflow-hidden flex flex-col bg-slate-50 dark:bg-slate-950 transition-colors duration-300">
        <deep-chat
            ref="deepChatRef"
            v-if="chatConfig && styleConfig"
            
            :demo="false"
            :history.prop="history"
            :connect.prop="requestConfig"
            :requestInterceptor.prop="requestInterceptor"
            :responseInterceptor.prop="handleResponse" 
            :introMessage.prop="introMessage"
            
            :mixedFiles="true"
            :microphone.prop="{
                files: { format: 'mp3', maxDurationSeconds: 120 },
                button: { position: 'outside-right' }
            }"
            
            :messageStyles.prop="styleConfig.messageStyles"
            :textInput.prop="styleConfig.textInput"
            :submitButtonStyles.prop="styleConfig.submitButtonStyles"
            :auxiliaryStyle.prop="styleConfig.auxiliaryStyle"
            :attachmentButtonStyle.prop="styleConfig.attachmentButtonStyle"
            
            class="deep-chat-host"
            style="width:100%; height:100%; border:none; background:transparent;"
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
import { ref, onMounted, onUnmounted, computed, nextTick } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import request from '@/utils/request';
import 'deep-chat';

const route = useRoute();
const router = useRouter();
const deepChatRef = ref(null);
const chatConfig = ref(null);
const history = ref([]); 
const chatId = route.params.id;

// Pagination & Status
const nextCursor = ref(null);
const hasMoreMessages = ref(false);
const isLoadingMore = ref(false);
const connectionStatus = ref('checking'); 

const isDark = ref(document.documentElement.classList.contains('dark'));
let observer = null;
let scrollContainer = null;

// --- 1. INFINITE SCROLL LOGIC (Preserved) ---
const setupScrollListener = () => {
    const element = deepChatRef.value;
    if (!element || !element.shadowRoot) return;

    const shadowRoot = element.shadowRoot;
    let scrollRebindTimer = null;

    const connectScroll = () => {
        clearTimeout(scrollRebindTimer);
        scrollRebindTimer = setTimeout(() => {
            const newContainer = shadowRoot.querySelector('#messages');
            if (!newContainer) return;
            if (scrollContainer && scrollContainer !== newContainer) {
                scrollContainer.removeEventListener('scroll', handleScroll);
            }
            scrollContainer = newContainer;
            scrollContainer.addEventListener('scroll', handleScroll);
        }, 50);
    };

    connectScroll();
    const shadowObserver = new MutationObserver(() => { connectScroll(); });
    shadowObserver.observe(shadowRoot, { childList: true, subtree: true });
};

let scrollLocked = false;
let pendingLoad = false;

const handleScroll = async () => {
    if (!scrollContainer || scrollLocked) {
        if (scrollContainer && scrollContainer.scrollTop < 50) pendingLoad = true;
        return;
    }
    if (scrollContainer.scrollTop < 50 && hasMoreMessages.value && !isLoadingMore.value) {
        await loadMoreHistory();
    }
};

async function loadMoreHistory() {
    isLoadingMore.value = true;
    const oldHeight = scrollContainer.scrollHeight;
    const oldTop = scrollContainer.scrollTop;

    try {
        const { data } = await request.get(`/ai-chats/${chatId}/history`, {
            params: { before_id: nextCursor.value }
        });

        if (data.messages.length > 0) {
            history.value = [...data.messages, ...history.value];
            nextCursor.value = data.next_cursor;
            hasMoreMessages.value = data.has_more;

            setTimeout(() => {
                const newHeight = scrollContainer.scrollHeight;
                scrollContainer.scrollTop = newHeight - oldHeight + oldTop;
                isLoadingMore.value = false;
            }, 50);
        } else {
            isLoadingMore.value = false;
        }
    } catch (e) {
        console.error(e);
        isLoadingMore.value = false;
    }
}

// --- 2. INTERCEPTORS (Preserved) ---
const requestInterceptor = (requestDetails) => {
    if (requestDetails.body instanceof FormData) {
        const newForm = new FormData();
        let extractedText = '';
        const entries = Array.from(requestDetails.body.entries());
        
        entries.forEach(([key, value]) => {
            if (key === 'files') newForm.append('files[]', value);
            else if (key === 'message1') {
                newForm.append(key, value);
                try { if (typeof value === 'string') extractedText = JSON.parse(value).text; } catch (e) {}
            } else newForm.append(key, value);
        });
        if (extractedText) newForm.append('text_content', extractedText);
        requestDetails.body = newForm;
    }
    return requestDetails;
};

const handleResponse = (response) => {
    if (response.output) return { text: response.output };
    if (response.data && typeof response.data === 'string') return { text: response.data };
    return response;
};

onMounted(async () => {
    try {
        const configRes = await request.get(`/ai-chats`);
        chatConfig.value = configRes.data.find(c => c.id == chatId);
        
        const { data } = await request.get(`/ai-chats/${chatId}/history`);
        history.value = data.messages;
        nextCursor.value = data.next_cursor;
        hasMoreMessages.value = data.has_more;

        const data2  = await request.get(`/ai-chats/${chatId}/status`);        
        connectionStatus.value = data2.data.status;

        setTimeout(setupScrollListener, 1000);
    } catch (e) { console.error("Error init chat", e); }

    observer = new MutationObserver(() => {
        isDark.value = document.documentElement.classList.contains('dark');
    });
    observer.observe(document.documentElement, { attributes: true });
});

onUnmounted(() => { 
    if (observer) observer.disconnect(); 
    if (scrollContainer) scrollContainer.removeEventListener('scroll', handleScroll);
});

// --- 3. CONFIGS & STYLING (Updated for Slate Palette) ---
const placeholderText = computed(() => 'Type a message...');
const introMessage = computed(() => 
    (chatConfig.value?.welcome_message && history.value.length === 0) 
    ? { text: chatConfig.value.welcome_message } 
    : undefined
);

const requestConfig = computed(() => {
    if (!chatConfig.value) return {};
    return {
        url: `${import.meta.env.VITE_API_BASE_URL || ''}/ai-chats/${chatId}/chat`,
        method: 'POST',
        headers: { 'Authorization': `Bearer ${localStorage.getItem('token') || ''}` }
    };
});

const styleConfig = computed(() => {
    const dark = isDark.value;
    
    // Exact Tailwind Slate Palette Mapping
    const c = {
        // Backgrounds
        bg: dark ? '#020617' : '#f8fafc',       // Slate-950 / Slate-50
        inputBg: dark ? '#0f172a' : '#ffffff',  // Slate-900 / White
        
        // Text & UI
        text: dark ? '#f8fafc' : '#0f172a',     // Slate-50 / Slate-900
        placeholder: dark ? '#64748b' : '#94a3b8', // Slate-500 / Slate-400
        border: dark ? '#1e293b' : '#e2e8f0',   // Slate-800 / Slate-200
        
        // Bubbles
        userBubble: dark ? '#1e293b' : '#f1f5f9', // Slate-800 / Slate-100
        aiBubble: dark ? '#020617' : '#ffffff',   // Slate-950 / White
    };

    return {
        textInput: {
            placeholder: { text: placeholderText.value, style: { color: c.placeholder } },
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
                text: { color: c.text, fontSize: '0.95rem' }
            }
        },
        submitButtonStyles: {
            submit: {
                container: {
                    default: { backgroundColor: dark ? '#ffffff' : '#0f172a',  borderRadius: '8px', width: '34px', height: '34px', margin: '10px', transition: 'transform 0.2s' },
                    hover: { transform: 'scale(1.05)', opacity: '0.9' },
                    click: { transform: 'scale(0.95)' }
                },
                svg: { styles: { default: { color: dark ? '#0f172a' : '#ffffff', fontSize: '0.9rem' } } }
            }
        },
        attachmentButtonStyle: {
            styles: {
                default: {
                    filter: dark ? 'invert(1) brightness(2)' : 'none',          
                    margin: '10px',
                    opacity: '0.6',
                    transition: 'opacity 0.2s'
                },
                hover: { opacity: '1' }
            }
        },
        messageStyles: {
            default: {
                shared: { bubble: { borderRadius: '12px', padding: '12px 16px', fontSize: '0.95rem', lineHeight: '1.6', marginTop: '24px'} },
                user: { bubble: { backgroundColor: c.userBubble, color: c.text } },
                ai: { bubble: { backgroundColor: c.aiBubble, color: c.text, border: `1px solid ${c.border}` } }
            }
        },
        auxiliaryStyle: `
            #messages {width:100%; margin: 0 auto; padding-bottom: 200px; }
            ::-webkit-scrollbar { width: 6px; }
            ::-webkit-scrollbar-thumb { background: ${dark ? '#334155' : '#cbd5e1'}; borderRadius: 4px; }
        `
    };
});
</script>

<style scoped>
:deep(deep-chat) {
    display: block;
    width: 100%;
    height: 100%;
}
</style>