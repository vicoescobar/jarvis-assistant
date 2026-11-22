<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { jarvisApi } from '@/services/api'
import AppIcon from '@/components/AppIcon.vue'
import { renderMarkdown, stripMarkdownForTts } from '@/utils/markdown'

const props = defineProps({
  autostartMic: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['updated'])

// States: 'ready' | 'listening' | 'processing'
const voiceState = ref('ready')
const speakAloud = ref(true)
const error = ref('')

// Hold only the current exchange to minimize memory & visual clutter
const latestTranscript = ref('')
const latestReply = ref('')

let recognition = null

const stateLabel = computed(() => {
  switch (voiceState.value) {
    case 'listening':
      return 'Écoute en cours…'
    case 'processing':
      return 'Traitement…'
    default:
      return 'Prêt'
  }
})

const renderedReplyHtml = computed(() => {
  return renderMarkdown(latestReply.value)
})

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
    voiceState.value = 'listening'
    error.value = ''
  }

  instance.onend = () => {
    if (voiceState.value === 'listening') {
      voiceState.value = 'ready'
    }
  }

  instance.onerror = (event) => {
    voiceState.value = 'ready'
    if (event.error !== 'aborted') {
      error.value = `Micro : ${event.error}`
    }
  }

  instance.onresult = (event) => {
    const transcript = event.results?.[0]?.[0]?.transcript || ''
    if (transcript.trim()) {
      dispatchSpokenCommand(transcript.trim())
    } else {
      voiceState.value = 'ready'
    }
  }

  return instance
}

function toggleMicrophone() {
  if (voiceState.value === 'processing') return

  if (!recognition) {
    recognition = initSpeechRecognition()
  }
  if (!recognition) {
    error.value = 'Reconnaissance vocale Web Speech indisponible sur ce navigateur'
    return
  }

  if (voiceState.value === 'listening') {
    recognition.stop()
    voiceState.value = 'ready'
  } else {
    if ('speechSynthesis' in window) {
      window.speechSynthesis.cancel()
    }
    try {
      recognition.start()
    } catch {
      // Ignore duplicate start
    }
  }
}

function speakCleanText(markdownReply) {
  if (!speakAloud.value || !('speechSynthesis' in window) || !markdownReply) {
    return
  }
  window.speechSynthesis.cancel()
  const cleanText = stripMarkdownForTts(markdownReply)
  if (!cleanText) return

  const utterance = new SpeechSynthesisUtterance(cleanText)
  utterance.lang = 'fr-FR'
  window.speechSynthesis.speak(utterance)
}

async function dispatchSpokenCommand(transcriptText) {
  latestTranscript.value = transcriptText
  latestReply.value = ''
  voiceState.value = 'processing'
  error.value = ''

  try {
    const response = await jarvisApi.sendChatMessage(transcriptText)
    const reply = response?.reply || response?.output || 'Exécuté.'
    latestReply.value = reply
    speakCleanText(reply)
    emit('updated')
  } catch (err) {
    error.value = err.message || 'Échec de l’appel vocal vers Jarvis'
  } finally {
    voiceState.value = 'ready'
  }
}

onMounted(() => {
  if (props.autostartMic) {
    toggleMicrophone()
  }
})

onBeforeUnmount(() => {
  if (recognition && voiceState.value === 'listening') {
    try {
      recognition.stop()
    } catch {}
  }
  if ('speechSynthesis' in window) {
    window.speechSynthesis.cancel()
  }
})
</script>

<template>
  <section class="enter-fade rounded-[16px] card-surface p-6 sm:p-8 flex flex-col items-center justify-center min-h-[420px] gap-6">
    <!-- Top state pill & TTS toggle -->
    <div class="w-full flex items-center justify-between gap-3">
      <div class="inline-flex items-center gap-2 rounded-full bg-graphite-deep border hairline-border px-3.5 py-1.5 text-[14px] sm:text-xs font-medium text-ink-primary">
        <span
          class="h-2.5 w-2.5 rounded-full transition-colors duration-150"
          :class="
            voiceState === 'listening'
              ? 'bg-accent'
              : voiceState === 'processing'
                ? 'bg-pastel-butter'
                : 'bg-pastel-sage'
          "
        ></span>
        <span>{{ stateLabel }}</span>
      </div>

      <button
        type="button"
        class="pressable min-h-[44px] sm:min-h-[36px] rounded-[10px] sm:rounded-[8px] px-3.5 text-[14px] sm:text-xs inline-flex items-center gap-2 border"
        :class="
          speakAloud
            ? 'surface-raised text-ink-primary'
            : 'bg-graphite-deep hairline-border text-ink-muted hover:text-ink-secondary'
        "
        title="Activer ou couper la lecture vocale TTS"
        @click="speakAloud = !speakAloud"
      >
        <AppIcon name="volume" />
        <span>Lecture vocale {{ speakAloud ? 'active' : 'coupée' }}</span>
      </button>
    </div>

    <!-- Central Tactile Microphone Button -->
    <div class="py-4 flex flex-col items-center gap-4">
      <button
        type="button"
        :disabled="voiceState === 'processing'"
        class="pressable h-28 w-28 sm:h-24 sm:w-24 rounded-full flex items-center justify-center border-2 transition-[background-color,border-color,transform] duration-150 disabled:opacity-50"
        :class="
          voiceState === 'listening'
            ? 'bg-accent text-white border-accent shadow-lg'
            : 'bg-graphite-deep text-ink-primary hairline-border hover:border-accent/60'
        "
        aria-label="Activer le microphone"
        @click="toggleMicrophone"
      >
        <svg
          xmlns="http://www.w3.org/2000/svg"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.6"
          stroke-linecap="round"
          stroke-linejoin="round"
          class="w-10 h-10 sm:w-8 sm:h-8"
        >
          <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z" />
          <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
          <line x1="12" x2="12" y1="19" y2="22" />
        </svg>
      </button>

      <p class="text-[14px] sm:text-xs text-ink-muted text-center max-w-sm">
        {{
          voiceState === 'listening'
            ? 'Parle maintenant — touche à nouveau pour arrêter'
            : voiceState === 'processing'
              ? 'Transmission de la commande vocale à Jarvis…'
              : 'Touche le micro pour dicter une commande vocale directe'
        }}
      </p>
    </div>

    <div
      v-if="error"
      class="w-full max-w-2xl rounded-[10px] border border-pastel-rose/40 bg-pastel-rose-tint px-4 py-3 text-[14px] sm:text-xs text-pastel-rose"
    >
      {{ error }}
    </div>

    <!-- Current Exchange Display (Latest Spoken Transcript + Formatted Markdown Reply) -->
    <div
      v-if="latestTranscript || latestReply"
      class="w-full max-w-2xl space-y-3"
    >
      <div
        v-if="latestTranscript"
        class="rounded-[12px] bg-graphite-deep border hairline-border px-4 py-3"
      >
        <div class="text-[12px] font-mono meta-mono text-ink-muted mb-1">
          Commande dictée
        </div>
        <p class="text-[15px] sm:text-sm text-ink-primary">
          {{ latestTranscript }}
        </p>
      </div>

      <div
        v-if="latestReply"
        class="rounded-[12px] surface-raised px-5 py-4"
      >
        <div class="text-[12px] font-mono meta-mono text-ink-muted mb-2">
          Réponse Jarvis
        </div>
        <!-- Safe Markdown HTML rendered output -->
        <!-- eslint-disable-next-line vue/no-v-html -->
        <div
          class="md-content text-[15px] sm:text-sm text-ink-primary"
          v-html="renderedReplyHtml"
        ></div>
      </div>
    </div>
  </section>
</template>
