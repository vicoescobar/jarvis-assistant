<script setup>
import { nextTick, onMounted, ref } from 'vue'
import { jarvisApi, telemetry } from '@/services/api'
import AppIcon from '@/components/AppIcon.vue'
import LiveVoiceSession from '@/components/LiveVoiceSession.vue'

const props = defineProps({
  autostartMic: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['updated'])

// Mode: 'live' (Gemini Multimodal Live WebSocket) | 'chat' (Talkie-Walkie / Discord #chatbot)
const voiceMode = ref('live')
const messages = ref([])
const inputText = ref('')
const loading = ref(false)
const sending = ref(false)
const listening = ref(false)
const speakAloud = ref(false)
const error = ref('')
const chatContainer = ref(null)
const inputEl = ref(null)

let recognition = null

function initSpeechRecognition() {
  const SpeechRecognition =
    window.SpeechRecognition || window.webkitSpeechRecognition
  if (!SpeechRecognition) {
    return null
  }
  const instance = new SpeechRecognition()
  instance.lang = 'fr-FR'
  instance.interimResults = false
  instance.maxAlternatives = 1

  instance.onstart = () => {
    listening.value = true
    error.value = ''
  }

  instance.onend = () => {
    listening.value = false
  }

  instance.onerror = (event) => {
    listening.value = false
    error.value = `Micro : ${event.error}`
  }

  instance.onresult = (event) => {
    const transcript = event.results?.[0]?.[0]?.transcript || ''
    if (transcript.trim()) {
      inputText.value = transcript.trim()
      handleSendMessage()
    }
  }

  return instance
}

function toggleMicrophone() {
  if (!recognition) {
    recognition = initSpeechRecognition()
  }
  if (!recognition) {
    error.value = 'Reconnaissance vocale Web Speech indisponible sur ce navigateur'
    return
  }

  if (listening.value) {
    recognition.stop()
  } else {
    try {
      recognition.start()
    } catch {
      // Already active
    }
  }
}

function speakReply(text) {
  if (!speakAloud.value || !('speechSynthesis' in window) || !text) return
  window.speechSynthesis.cancel()
  const utterance = new SpeechSynthesisUtterance(text)
  utterance.lang = 'fr-FR'
  window.speechSynthesis.speak(utterance)
}

async function scrollToBottom() {
  await nextTick()
  if (chatContainer.value) {
    chatContainer.value.scrollTop = chatContainer.value.scrollHeight
  }
}

async function loadHistory() {
  loading.value = true
  error.value = ''
  try {
    const data = await jarvisApi.getChatHistory()
    messages.value = Array.isArray(data?.messages) ? data.messages : []
    await scrollToBottom()
  } catch (err) {
    error.value = err.message || 'Impossible de charger discord_chat_history.json'
  } finally {
    loading.value = false
  }
}

async function handleSendMessage() {
  const text = inputText.value.trim()
  if (!text || sending.value) return

  inputText.value = ''
  sending.value = true
  error.value = ''

  messages.value.push({
    role: 'user',
    content: text,
    timestamp: new Date().toISOString()
  })
  await scrollToBottom()

  try {
    const response = await jarvisApi.sendChatMessage(text)
    const reply = response?.reply || response?.output || 'Exécuté'
    messages.value.push({
      role: 'assistant',
      content: reply,
      timestamp: new Date().toISOString()
    })
    speakReply(reply)
    await scrollToBottom()
    emit('updated')
  } catch (err) {
    error.value = err.message || 'Échec de l’appel n8n'
  } finally {
    sending.value = false
  }
}

onMounted(async () => {
  await loadHistory()
})
</script>

<template>
  <section class="enter-fade rounded-[16px] bg-graphite-card border hairline-border flex flex-col min-h-[520px]">
    <!-- Top Mode Switcher Header: Gemini Live WebSocket vs Talkie-Walkie Discord #chatbot -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b hairline-border px-4 sm:px-5 py-3.5">
      <div class="grid grid-cols-2 sm:inline-flex rounded-[10px] bg-graphite-deep p-1 border hairline-border gap-1">
        <button
          type="button"
          class="pressable h-10 sm:h-8 rounded-[8px] px-3.5 text-[14px] sm:text-xs font-medium inline-flex items-center justify-center gap-2"
          :class="
            voiceMode === 'live'
              ? 'surface-raised text-ink-primary'
              : 'text-ink-muted hover:text-ink-secondary'
          "
          @click="voiceMode = 'live'"
        >
          <span class="h-2 w-2 rounded-full bg-accent"></span>
          <span>Live Temps Réel</span>
        </button>

        <button
          type="button"
          class="pressable h-10 sm:h-8 rounded-[8px] px-3.5 text-[14px] sm:text-xs font-medium inline-flex items-center justify-center gap-2"
          :class="
            voiceMode === 'chat'
              ? 'surface-raised text-ink-primary'
              : 'text-ink-muted hover:text-ink-secondary'
          "
          @click="voiceMode = 'chat'"
        >
          <AppIcon name="chat" />
          <span>Talkie-Walkie (#chatbot)</span>
        </button>
      </div>

      <div v-if="voiceMode === 'chat'" class="flex items-center justify-between sm:justify-end gap-2">
        <span
          v-if="telemetry.lastLatencyMs !== null"
          class="text-[13px] sm:text-xs font-mono meta-mono text-ink-muted"
        >
          {{ telemetry.lastLatencyMs }} ms
        </span>

        <div class="flex items-center gap-2">
          <button
            type="button"
            class="pressable h-10 sm:h-8 rounded-[8px] px-3 text-[14px] sm:text-xs inline-flex items-center gap-2 border"
            :class="
              speakAloud
                ? 'surface-raised text-ink-primary'
                : 'bg-graphite-deep hairline-border text-ink-muted hover:text-ink-secondary'
            "
            title="Activer ou couper la lecture vocale"
            @click="speakAloud = !speakAloud"
          >
            <AppIcon name="volume" />
            <span>TTS</span>
          </button>

          <button
            type="button"
            class="btn-secondary h-10 sm:h-8 rounded-[8px] px-3 text-[14px] sm:text-xs inline-flex items-center gap-2"
            title="Synchroniser l'historique"
            @click="loadHistory"
          >
            <AppIcon name="refresh" />
            <span>Sync</span>
          </button>
        </div>
      </div>
    </div>

    <!-- MODE 1: GEMINI MULTIMODAL LIVE BIDIRECTIONAL WEBSOCKET -->
    <LiveVoiceSession
      v-if="voiceMode === 'live'"
      :autostart="autostartMic"
      @updated="emit('updated')"
    />

    <!-- MODE 2: TALKIE-WALKIE / PERSISTENT DISCORD #CHATBOT -->
    <template v-else>
      <div
        ref="chatContainer"
        class="flex-1 overflow-y-auto p-4 sm:p-5 space-y-3.5 max-h-[58dvh]"
      >
        <div v-if="loading" class="text-[14px] sm:text-xs font-mono meta-mono text-ink-muted">
          Lecture de discord_chat_history.json
        </div>

        <div
          v-else-if="messages.length === 0"
          class="rounded-[12px] border hairline-border bg-graphite-deep/60 p-6 text-center text-[15px] sm:text-xs text-ink-muted"
        >
          Aucun échange enregistré dans la fenêtre courante
        </div>

        <div
          v-for="(msg, idx) in messages"
          :key="idx"
          class="flex"
          :class="msg.role === 'user' ? 'justify-end' : 'justify-start'"
        >
          <div
            class="max-w-[90%] sm:max-w-[72%] rounded-[14px] sm:rounded-[12px] px-4 py-3 text-[15px] sm:text-[14px] leading-[22px] sm:leading-[20px] whitespace-pre-wrap break-words"
            :class="
              msg.role === 'user'
                ? 'surface-raised text-ink-primary'
                : 'bg-graphite-deep border hairline-border text-ink-primary'
            "
          >
            <div class="flex items-center justify-between gap-4 text-[12px] sm:text-[11px] font-mono meta-mono text-ink-muted mb-1">
              <span>{{ msg.role === 'user' ? 'Victor' : 'Jarvis' }}</span>
              <span v-if="msg.timestamp">{{ msg.timestamp.slice(11, 16) }}</span>
            </div>
            <div class="text-ink-primary">{{ msg.content || msg.text }}</div>
          </div>
        </div>

        <div v-if="sending" class="flex justify-start">
          <div class="rounded-[12px] bg-graphite-deep border hairline-border px-4 py-3 text-[14px] sm:text-xs font-mono meta-mono text-ink-secondary flex items-center gap-2">
            <span class="h-2 w-2 rounded-full bg-accent"></span>
            <span>Exécution du workflow 04-discord-chatbot</span>
          </div>
        </div>
      </div>

      <div
        v-if="error"
        class="mx-4 sm:mx-5 mb-2 rounded-[8px] border border-semantic-negative/40 bg-graphite-deep px-3.5 py-2.5 text-[14px] sm:text-xs text-semantic-negative"
      >
        {{ error }}
      </div>

      <form
        class="border-t hairline-border p-3 sm:p-4 flex items-center gap-2 bg-graphite-deep/40 rounded-b-[16px]"
        @submit.prevent="handleSendMessage"
      >
        <button
          type="button"
          class="pressable h-12 sm:h-10 rounded-[10px] sm:rounded-[8px] px-3.5 text-[15px] sm:text-xs font-medium inline-flex items-center gap-2 border shrink-0"
          :class="
            listening
              ? 'bg-accent text-[#141415] border-accent'
              : 'btn-secondary text-ink-primary'
          "
          title="Dictée vocale Web Speech"
          @click="toggleMicrophone"
        >
          <AppIcon name="mic" />
          <span>{{ listening ? 'Écoute' : 'Dictée' }}</span>
        </button>

        <input
          ref="inputEl"
          v-model="inputText"
          type="text"
          placeholder="Instruction pour Jarvis"
          class="h-12 sm:h-10 min-w-0 flex-1 rounded-[10px] sm:rounded-[8px] border hairline-border bg-graphite-deep px-3.5 text-[16px] sm:text-sm text-ink-primary placeholder:text-ink-muted focus:border-[#ededed]/30 focus:outline-none"
        />

        <button
          type="submit"
          :disabled="sending || !inputText.trim()"
          class="btn-primary h-12 sm:h-10 rounded-[10px] sm:rounded-[8px] px-4 text-[15px] sm:text-xs font-medium inline-flex items-center gap-2 shrink-0 disabled:opacity-40"
        >
          <span>Envoyer</span>
          <kbd class="keycap hidden sm:inline-flex bg-[#141415]/15 border-[#141415]/20 text-[#141415]">↵</kbd>
        </button>
      </form>
    </template>
  </section>
</template>
