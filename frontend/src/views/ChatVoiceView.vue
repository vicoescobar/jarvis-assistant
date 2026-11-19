<script setup>
import { nextTick, onMounted, ref } from 'vue'
import { jarvisApi, telemetry } from '@/services/api'

const props = defineProps({
  autostartMic: {
    type: Boolean,
    default: false
  }
})

const messages = ref([])
const inputText = ref('')
const loading = ref(false)
const sending = ref(false)
const listening = ref(false)
const speakAloud = ref(false)
const error = ref('')
const chatContainer = ref(null)

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
    error.value = 'Reconnaissance vocale Web Speech non supportée sur ce navigateur.'
    return
  }

  if (listening.value) {
    recognition.stop()
  } else {
    try {
      recognition.start()
    } catch {
      // Already started
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
    error.value = err.message || 'Impossible de charger l’historique.'
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
    const reply = response?.reply || response?.output || 'Exécuté.'
    messages.value.push({
      role: 'assistant',
      content: reply,
      timestamp: new Date().toISOString()
    })
    speakReply(reply)
    await scrollToBottom()
  } catch (err) {
    error.value = err.message || 'Erreur lors de l’envoi à Jarvis.'
  } finally {
    sending.value = false
  }
}

onMounted(async () => {
  await loadHistory()
  if (props.autostartMic) {
    speakAloud.value = true
    toggleMicrophone()
  }
})
</script>

<template>
  <section class="rounded-2xl border border-slate-800 bg-slate-900/90 flex flex-col h-[72vh] min-h-[480px]">
    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-800 px-5 py-3.5">
      <div>
        <h2 class="text-base font-semibold text-white">
          Canal Direct Jarvis (Partagé avec Discord #chatbot)
        </h2>
        <p class="text-xs text-slate-400">
          Historique unifié <code class="font-mono text-indigo-300">discord_chat_history.json</code> — Texte & Reconnaissance Vocale
        </p>
      </div>

      <div class="flex items-center gap-3">
        <span
          v-if="telemetry.lastLatencyMs !== null"
          class="rounded bg-slate-800 px-2 py-1 text-xs font-mono text-slate-300"
        >
          {{ telemetry.lastLatencyMs }} ms
        </span>
        <label class="inline-flex items-center gap-1.5 text-xs text-slate-300 cursor-pointer">
          <input
            v-model="speakAloud"
            type="checkbox"
            class="rounded border-slate-700 bg-slate-800 text-indigo-600"
          />
          Synthèse vocale (TTS)
        </label>
        <button
          type="button"
          class="rounded-lg border border-slate-700 hover:bg-slate-800 px-2.5 py-1 text-xs text-slate-300"
          @click="loadHistory"
        >
          Actualiser
        </button>
      </div>
    </div>

    <!-- Messages list -->
    <div
      ref="chatContainer"
      class="flex-1 overflow-y-auto p-5 space-y-3"
    >
      <div v-if="loading" class="text-xs text-slate-400">
        Synchronisation de l'historique...
      </div>

      <div
        v-else-if="messages.length === 0"
        class="rounded-xl border border-slate-800 bg-slate-950/50 p-6 text-center text-xs text-slate-400"
      >
        Aucun message dans l'historique actif. Écris une instruction ou utilise le bouton micro.
      </div>

      <div
        v-for="(msg, idx) in messages"
        :key="idx"
        class="flex"
        :class="msg.role === 'user' ? 'justify-end' : 'justify-start'"
      >
        <div
          class="max-w-[85%] sm:max-w-[75%] rounded-2xl px-4 py-2.5 text-sm whitespace-pre-wrap"
          :class="
            msg.role === 'user'
              ? 'bg-indigo-600 text-white'
              : 'bg-slate-800/90 border border-slate-700/70 text-slate-100'
          "
        >
          <div class="text-[11px] font-mono opacity-70 mb-1">
            {{ msg.role === 'user' ? 'Victor' : 'Jarvis' }}
            <span v-if="msg.timestamp">
              · {{ msg.timestamp.slice(11, 16) }}
            </span>
          </div>
          <div>{{ msg.content || msg.text }}</div>
        </div>
      </div>

      <div v-if="sending" class="flex justify-start">
        <div class="rounded-2xl bg-slate-800/70 border border-slate-700 px-4 py-2.5 text-xs text-indigo-300 font-mono">
          Exécution de l'orchestrateur n8n en cours...
        </div>
      </div>
    </div>

    <div
      v-if="error"
      class="mx-5 mb-2 rounded-lg border border-amber-700/50 bg-amber-950/40 px-3 py-1.5 text-xs text-amber-200"
    >
      {{ error }}
    </div>

    <!-- Input & Microphone bar -->
    <form
      class="border-t border-slate-800 p-4 flex items-center gap-2"
      @submit.prevent="handleSendMessage"
    >
      <button
        type="button"
        class="rounded-xl px-3.5 py-2.5 text-xs font-semibold transition flex items-center gap-1.5"
        :class="
          listening
            ? 'bg-indigo-500 text-white ring-2 ring-indigo-400 animate-pulse'
            : 'bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700'
        "
        title="Dictée vocale directe"
        @click="toggleMicrophone"
      >
        <span>🎙️</span>
        <span>{{ listening ? 'Écoute...' : 'Voix' }}</span>
      </button>

      <input
        v-model="inputText"
        type="text"
        placeholder="Instruction directe (ex: Ajoute une tâche, décale le cycle sport, résume mes mails)..."
        class="flex-1 rounded-xl border border-slate-700 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-indigo-500 focus:outline-none"
      />

      <button
        type="submit"
        :disabled="sending || !inputText.trim()"
        class="rounded-xl bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 px-4 py-2.5 text-xs font-semibold text-white transition"
      >
        Envoyer
      </button>
    </form>
  </section>
</template>
