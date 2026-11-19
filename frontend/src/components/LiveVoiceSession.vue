<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { jarvisApi, telemetry } from '@/services/api'
import AppIcon from '@/components/AppIcon.vue'

const props = defineProps({
  autostart: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['updated'])

// Connection states: 'disconnected' | 'connecting' | 'listening' | 'speaking' | 'tool_running'
const sessionState = ref('disconnected')
const error = ref('')
const muted = ref(false)
const micLevel = ref(0)
const sessionModel = ref('')
const sessionVoice = ref('')
const eventsLog = ref([])

let ws = null
let mediaStream = null
let captureCtx = null
let playbackCtx = null
let workletNode = null
let scriptProcessor = null
let sourceNode = null
let activeSources = []
let nextPlayTime = 0

const stateMeta = computed(() => {
  switch (sessionState.value) {
    case 'connecting':
      return { label: 'Connexion au flux Gemini Live', dot: 'bg-ink-secondary' }
    case 'listening':
      return { label: muted.value ? 'Micro coupé' : 'À l’écoute (temps réel)', dot: 'bg-semantic-positive' }
    case 'speaking':
      return { label: 'Jarvis parle (barge-in actif)', dot: 'bg-accent' }
    case 'tool_running':
      return { label: 'Exécution d’outil Jarvis', dot: 'bg-accent' }
    default:
      return { label: 'Session vocale inactive', dot: 'bg-ink-muted' }
  }
})

const levelBars = computed(() => {
  const base = micLevel.value
  const multipliers = [0.45, 0.75, 1.0, 0.85, 0.6, 0.95, 0.7, 0.5]
  return multipliers.map((m) => {
    if (sessionState.value === 'disconnected' || muted.value) return 14
    const activeBoost = sessionState.value === 'speaking' ? 55 : base * 100
    return Math.max(14, Math.min(100, Math.round(activeBoost * m + 14)))
  })
})

function appendEvent(role, text, meta = '') {
  if (!text) return
  eventsLog.value.push({
    id: `${Date.now()}_${Math.random().toString(36).slice(2, 6)}`,
    role,
    text,
    meta,
    time: new Date().toISOString().slice(11, 19)
  })
  if (eventsLog.value.length > 40) {
    eventsLog.value.shift()
  }
}

function arrayBufferToBase64(buffer) {
  const bytes = new Uint8Array(buffer)
  let binary = ''
  const chunkSize = 0x8000
  for (let i = 0; i < bytes.length; i += chunkSize) {
    binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunkSize))
  }
  return btoa(binary)
}

function base64ToInt16Array(base64) {
  const binary = atob(base64)
  const len = binary.length
  const bytes = new Uint8Array(len)
  for (let i = 0; i < len; i++) {
    bytes[i] = binary.charCodeAt(i)
  }
  return new Int16Array(bytes.buffer)
}

function float32ToInt16Buffer(float32Array, inputSampleRate, targetSampleRate = 16000) {
  const ratio = inputSampleRate / targetSampleRate
  const newLength = Math.round(float32Array.length / ratio)
  const int16 = new Int16Array(newLength)
  let sumSq = 0

  for (let i = 0; i < newLength; i++) {
    const srcIdx = Math.min(float32Array.length - 1, Math.floor(i * ratio))
    const sample = Math.max(-1, Math.min(1, float32Array[srcIdx]))
    sumSq += sample * sample
    int16[i] = sample < 0 ? sample * 0x8000 : sample * 0x7fff
  }

  const rms = Math.sqrt(sumSq / Math.max(1, newLength))
  micLevel.value = Math.min(1, rms * 4.5)

  return int16.buffer
}

function flushPlaybackQueue() {
  for (const src of activeSources) {
    try {
      src.stop()
      src.disconnect()
    } catch {
      // Ignore already stopped buffer
    }
  }
  activeSources = []
  if (playbackCtx) {
    nextPlayTime = playbackCtx.currentTime
  }
}

function enqueuePcm24kAudio(base64Data) {
  if (!playbackCtx) return
  if (playbackCtx.state === 'suspended') {
    playbackCtx.resume().catch(() => {})
  }

  const int16 = base64ToInt16Array(base64Data)
  if (int16.length === 0) return

  const float32 = new Float32Array(int16.length)
  for (let i = 0; i < int16.length; i++) {
    float32[i] = int16[i] / 32768.0
  }

  const audioBuffer = playbackCtx.createBuffer(1, float32.length, 24000)
  audioBuffer.getChannelData(0).set(float32)

  const source = playbackCtx.createBufferSource()
  source.buffer = audioBuffer
  source.connect(playbackCtx.destination)

  const startAt = Math.max(playbackCtx.currentTime + 0.015, nextPlayTime)
  source.start(startAt)
  nextPlayTime = startAt + audioBuffer.duration

  activeSources.push(source)
  sessionState.value = 'speaking'

  source.onended = () => {
    activeSources = activeSources.filter((s) => s !== source)
    if (activeSources.length === 0 && sessionState.value === 'speaking') {
      sessionState.value = 'listening'
    }
  }
}

async function setupMicrophoneCapture() {
  mediaStream = await navigator.mediaDevices.getUserMedia({
    audio: {
      channelCount: 1,
      sampleRate: 16000,
      echoCancellation: true,
      noiseSuppression: true,
      autoGainControl: true
    }
  })

  const AudioCtx = window.AudioContext || window.webkitAudioContext
  captureCtx = new AudioCtx({ sampleRate: 16000 })
  playbackCtx = new AudioCtx({ sampleRate: 24000 })
  nextPlayTime = playbackCtx.currentTime

  sourceNode = captureCtx.createMediaStreamSource(mediaStream)

  if (captureCtx.audioWorklet) {
    try {
      const workletCode = `
        class JarvisPcmCaptureProcessor extends AudioWorkletProcessor {
          constructor() {
            super();
            this.buffer = new Float32Array(2048);
            this.offset = 0;
          }
          process(inputs) {
            const input = inputs[0];
            if (!input || !input[0]) return true;
            const channel = input[0];
            for (let i = 0; i < channel.length; i++) {
              this.buffer[this.offset++] = channel[i];
              if (this.offset >= this.buffer.length) {
                this.port.postMessage(this.buffer.slice(0));
                this.offset = 0;
              }
            }
            return true;
          }
        }
        registerProcessor('jarvis-pcm-capture', JarvisPcmCaptureProcessor);
      `
      const blob = new Blob([workletCode], { type: 'application/javascript' })
      const url = URL.createObjectURL(blob)
      await captureCtx.audioWorklet.addModule(url)
      URL.revokeObjectURL(url)

      workletNode = new AudioWorkletNode(captureCtx, 'jarvis-pcm-capture')
      workletNode.port.onmessage = (event) => {
        sendAudioChunk(event.data, captureCtx.sampleRate)
      }
      sourceNode.connect(workletNode)
      return
    } catch {
      // Fallback to ScriptProcessorNode below
    }
  }

  scriptProcessor = captureCtx.createScriptProcessor(2048, 1, 1)
  scriptProcessor.onaudioprocess = (event) => {
    const channelData = event.inputBuffer.getChannelData(0)
    sendAudioChunk(channelData, captureCtx.sampleRate)
  }
  sourceNode.connect(scriptProcessor)
  scriptProcessor.connect(captureCtx.destination)
}

function sendAudioChunk(float32Chunk, inputRate) {
  if (
    muted.value ||
    !ws ||
    ws.readyState !== WebSocket.OPEN ||
    sessionState.value === 'connecting'
  ) {
    micLevel.value = 0
    return
  }

  const pcmBuffer = float32ToInt16Buffer(float32Chunk, inputRate, 16000)
  const base64Audio = arrayBufferToBase64(pcmBuffer)

  ws.send(
    JSON.stringify({
      realtimeInput: {
        mediaChunks: [
          {
            mimeType: 'audio/pcm;rate=16000',
            data: base64Audio
          }
        ]
      }
    })
  )
}

async function handleServerMessage(rawEvent) {
  let payloadText = ''
  if (rawEvent.data instanceof Blob) {
    payloadText = await rawEvent.data.text()
  } else if (typeof rawEvent.data === 'string') {
    payloadText = rawEvent.data
  } else {
    return
  }

  let msg
  try {
    msg = JSON.parse(payloadText)
  } catch {
    return
  }

  if (msg.setupComplete) {
    sessionState.value = 'listening'
    appendEvent('system', 'Canal audio bidirectionnel établi (16 kHz PCM -> 24 kHz PCM)')
    return
  }

  // Handle user interruption (Barge-In)
  if (msg.serverContent?.interrupted) {
    flushPlaybackQueue()
    sessionState.value = 'listening'
    appendEvent('system', 'Interruption détectée (barge-in) — file audio purgée')
  }

  // Handle model audio and text parts
  const parts = msg.serverContent?.modelTurn?.parts || []
  for (const part of parts) {
    if (part.inlineData?.data && part.inlineData?.mimeType?.startsWith('audio/pcm')) {
      enqueuePcm24kAudio(part.inlineData.data)
    }
    if (part.text) {
      appendEvent('assistant', part.text)
    }
  }

  // Handle input/output transcriptions if emitted by Gemini Live
  if (msg.serverContent?.inputTranscription?.text) {
    appendEvent('user', msg.serverContent.inputTranscription.text)
  }
  if (msg.serverContent?.outputTranscription?.text) {
    appendEvent('assistant', msg.serverContent.outputTranscription.text)
  }

  // Handle real-time tool / function calls
  const functionCalls = msg.toolCall?.functionCalls || []
  if (functionCalls.length > 0) {
    sessionState.value = 'tool_running'
    const functionResponses = []

    for (const fc of functionCalls) {
      const toolName = fc.name
      const toolArgs = fc.args || {}
      appendEvent('tool', `Exécution de ${toolName}`, JSON.stringify(toolArgs))

      try {
        const res = await jarvisApi.executeLiveTool(toolName, toolArgs)
        functionResponses.push({
          id: fc.id,
          name: toolName,
          response: { output: res.result ?? res }
        })
        appendEvent('tool', `Résultat ${toolName} transmis (${telemetry.lastLatencyMs ?? 0} ms)`)
        emit('updated')
      } catch (err) {
        functionResponses.push({
          id: fc.id,
          name: toolName,
          response: { error: err.message || 'Tool execution error' }
        })
        appendEvent('tool', `Erreur ${toolName} : ${err.message}`)
      }
    }

    if (ws && ws.readyState === WebSocket.OPEN) {
      ws.send(
        JSON.stringify({
          toolResponse: {
            functionResponses
          }
        })
      )
    }
    sessionState.value = 'listening'
  }
}

async function startLiveSession() {
  if (sessionState.value !== 'disconnected') return
  error.value = ''
  sessionState.value = 'connecting'

  try {
    const session = await jarvisApi.createLiveSession()
    sessionModel.value =
      session.model || 'models/gemini-2.5-flash-native-audio-preview-12-2025'
    sessionVoice.value = session.voice_name || 'Puck'

    await setupMicrophoneCapture()

    ws = new WebSocket(session.ws_url_or_token)

    ws.onopen = () => {
      const setupPayload = {
        setup: {
          model: session.model,
          generationConfig: {
            responseModalities: ['AUDIO'],
            speechConfig: {
              voiceConfig: {
                prebuiltVoiceConfig: {
                  voiceName: session.voice_name || 'Puck'
                }
              }
            }
          },
          systemInstruction: {
            parts: [{ text: session.system_instruction }]
          },
          tools: Array.isArray(session.tools) && session.tools.length > 0
            ? [{ functionDeclarations: session.tools }]
            : []
        }
      }
      ws.send(JSON.stringify(setupPayload))
    }

    ws.onmessage = (event) => {
      handleServerMessage(event)
    }

    ws.onerror = () => {
      error.value = 'Erreur sur le flux WebSocket Gemini Live'
    }

    ws.onclose = (ev) => {
      stopLiveSession()
      if (ev.code !== 1000 && ev.reason) {
        error.value = `Flux Live fermé (${ev.reason})`
      }
    }
  } catch (err) {
    error.value = err.message || 'Impossible d’initialiser la session Gemini Live'
    stopLiveSession()
  }
}

function stopLiveSession() {
  flushPlaybackQueue()

  if (ws) {
    try {
      ws.onclose = null
      ws.close(1000)
    } catch {
      // Ignore
    }
    ws = null
  }

  if (workletNode) {
    try {
      workletNode.disconnect()
    } catch {}
    workletNode = null
  }

  if (scriptProcessor) {
    try {
      scriptProcessor.disconnect()
    } catch {}
    scriptProcessor = null
  }

  if (sourceNode) {
    try {
      sourceNode.disconnect()
    } catch {}
    sourceNode = null
  }

  if (mediaStream) {
    for (const track of mediaStream.getTracks()) {
      track.stop()
    }
    mediaStream = null
  }

  if (captureCtx) {
    captureCtx.close().catch(() => {})
    captureCtx = null
  }

  if (playbackCtx) {
    playbackCtx.close().catch(() => {})
    playbackCtx = null
  }

  micLevel.value = 0
  sessionState.value = 'disconnected'
}

function toggleMute() {
  muted.value = !muted.value
  if (muted.value) {
    micLevel.value = 0
  }
}

onMounted(() => {
  if (props.autostart) {
    startLiveSession()
  }
})

onBeforeUnmount(() => {
  stopLiveSession()
})
</script>

<template>
  <div class="flex-1 flex flex-col justify-between p-4 sm:p-6 gap-5 overflow-y-auto">
    <!-- Top Live Status Instrument Console -->
    <div class="rounded-[12px] bg-graphite-deep border hairline-border p-4 sm:p-5 space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2.5">
          <span class="h-2.5 w-2.5 rounded-full" :class="stateMeta.dot"></span>
          <span class="text-[16px] sm:text-[15px] font-medium text-ink-primary">
            {{ stateMeta.label }}
          </span>
        </div>

        <div class="flex items-center gap-2 text-[13px] sm:text-xs font-mono meta-mono text-ink-muted">
          <span v-if="sessionModel">{{ sessionModel.replace('models/', '') }}</span>
          <span v-if="sessionVoice">· {{ sessionVoice }}</span>
        </div>
      </div>

      <!-- Tactile Audio Level Meter (8 hardware bars) -->
      <div class="h-16 rounded-[8px] bg-graphite-bg border hairline-border px-4 py-3 flex items-end justify-center gap-2">
        <div
          v-for="(pct, idx) in levelBars"
          :key="idx"
          class="w-3 sm:w-2.5 rounded-t-[3px] transition-[height,background-color] duration-120"
          :class="
            sessionState === 'speaking' || sessionState === 'tool_running'
              ? 'bg-accent'
              : sessionState === 'listening' && !muted
                ? 'bg-ink-primary'
                : 'bg-graphite-raised'
          "
          :style="{ height: `${pct}%` }"
        ></div>
      </div>

      <!-- Primary Live Controls (48px tall on mobile) -->
      <div class="flex flex-wrap items-center gap-2.5">
        <button
          v-if="sessionState === 'disconnected'"
          type="button"
          class="btn-primary h-12 sm:h-10 flex-1 rounded-[10px] sm:rounded-[8px] px-5 text-[15px] sm:text-xs font-medium inline-flex items-center justify-center gap-2"
          @click="startLiveSession"
        >
          <AppIcon name="mic" />
          <span>Démarrer la conversation Live</span>
        </button>

        <template v-else>
          <button
            type="button"
            class="pressable h-12 sm:h-10 flex-1 rounded-[10px] sm:rounded-[8px] px-4 text-[15px] sm:text-xs font-medium inline-flex items-center justify-center gap-2 border"
            :class="
              muted
                ? 'bg-graphite-raised border-semantic-negative/50 text-semantic-negative'
                : 'btn-secondary text-ink-primary'
            "
            @click="toggleMute"
          >
            <AppIcon name="mic" />
            <span>{{ muted ? 'Réactiver le micro' : 'Couper le micro' }}</span>
          </button>

          <button
            type="button"
            class="btn-secondary h-12 sm:h-10 rounded-[10px] sm:rounded-[8px] px-4 text-[15px] sm:text-xs font-medium inline-flex items-center justify-center gap-2 text-semantic-negative"
            @click="stopLiveSession"
          >
            <AppIcon name="close" />
            <span>Terminer</span>
          </button>
        </template>
      </div>
    </div>

    <div
      v-if="error"
      class="rounded-[10px] border border-semantic-negative/40 bg-graphite-deep px-4 py-3 text-[14px] sm:text-xs text-semantic-negative"
    >
      {{ error }}
    </div>

    <!-- Real-Time Transcript & Tool Execution Log -->
    <div class="flex-1 min-h-[220px] rounded-[12px] bg-graphite-deep border hairline-border p-4 space-y-3 overflow-y-auto">
      <div class="flex items-center justify-between border-b hairline-border pb-2.5">
        <span class="text-[14px] sm:text-xs font-medium text-ink-secondary">
          Flux temps réel & appels d’outils (Function Calling)
        </span>
        <span class="text-[13px] sm:text-xs font-mono meta-mono text-ink-muted">
          {{ eventsLog.length }} événements
        </span>
      </div>

      <div
        v-if="eventsLog.length === 0"
        class="py-8 text-center text-[14px] sm:text-xs text-ink-muted"
      >
        Lance la session Live pour parler avec Jarvis en continu (interruption vocale instantanée et exécution directe sur tes trackers et Google Workspace)
      </div>

      <div
        v-for="ev in eventsLog"
        :key="ev.id"
        class="rounded-[10px] px-3.5 py-2.5 text-[14px] sm:text-xs space-y-1 border"
        :class="
          ev.role === 'tool'
            ? 'surface-raised border-accent/30 text-ink-primary'
            : ev.role === 'user'
              ? 'bg-graphite-card hairline-border text-ink-primary'
              : 'bg-graphite-bg hairline-border text-ink-secondary'
        "
      >
        <div class="flex items-center justify-between text-[12px] sm:text-[11px] font-mono meta-mono text-ink-muted">
          <span>
            {{
              ev.role === 'tool'
                ? 'Outil Jarvis'
                : ev.role === 'user'
                  ? 'Victor'
                  : ev.role === 'assistant'
                    ? 'Jarvis Live'
                    : 'Système'
            }}
          </span>
          <span>{{ ev.time }}</span>
        </div>
        <div class="text-ink-primary break-words">{{ ev.text }}</div>
        <div v-if="ev.meta" class="font-mono meta-mono text-[12px] text-ink-muted break-all">
          {{ ev.meta }}
        </div>
      </div>
    </div>
  </div>
</template>
