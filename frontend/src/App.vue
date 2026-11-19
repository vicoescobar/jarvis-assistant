<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { authStore } from '@/stores/auth'
import { jarvisApi, telemetry } from '@/services/api'
import AppIcon from '@/components/AppIcon.vue'
import DynamicTrackerCard from '@/components/DynamicTrackerCard.vue'
import DynamicTrackerDetail from '@/components/DynamicTrackerDetail.vue'
import ChatVoiceView from '@/views/ChatVoiceView.vue'
import MemoryRulesView from '@/views/MemoryRulesView.vue'

const activeView = ref('overview')
const autostartMic = ref(false)
const selectedTrackerId = ref('')
const quickCommand = ref('')
const sendingQuickCommand = ref(false)
const quickCommandFeedback = ref('')

const loginForm = reactive({
  email: '',
  password: ''
})

const healthStatus = ref({
  status: 'checking',
  user_data_readable: false,
  timestamp: ''
})

const trackers = ref([])
const memoryStats = ref({
  morningCount: 0,
  customCount: 0,
  totalCount: 0
})

const loadingDashboard = ref(false)
const triggeringBriefing = ref(false)
const briefingFeedback = ref('')
const dashboardError = ref('')

const cycleTrackers = computed(() =>
  trackers.value.filter((t) => t.type === 'CYCLE_STATE_MACHINE')
)

const collectionTrackers = computed(() =>
  trackers.value.filter((t) => t.type !== 'CYCLE_STATE_MACHINE')
)

const totalRecordsCount = computed(() =>
  trackers.value.reduce((acc, t) => acc + Number(t.record_count ?? 0), 0)
)

const latencyDeltaText = computed(() => {
  if (telemetry.lastLatencyMs === null || telemetry.avgLatencyMs === null) {
    return 'En attente de requête'
  }
  const diff = telemetry.lastLatencyMs - telemetry.avgLatencyMs
  const sign = diff > 0 ? `+${diff}` : `${diff}`
  return `${sign} ms vs moy. (${telemetry.avgLatencyMs} ms)`
})

const latencyBars = computed(() => {
  const samples =
    telemetry.samples.length > 0 ? telemetry.samples : [42, 58, 49, 64, 51, 45]
  const max = Math.max(...samples, 100)
  return samples.slice(-12).map((ms, idx, arr) => ({
    ms,
    heightPct: Math.max(14, Math.min(100, Math.round((ms / max) * 100))),
    isLatest: idx === arr.length - 1
  }))
})

const currentPeriodTitle = computed(() => {
  try {
    return new Intl.DateTimeFormat('fr-FR', {
      weekday: 'long',
      day: 'numeric',
      month: 'long'
    }).format(new Date())
  } catch {
    return 'Poste de commande'
  }
})

function parseUrlShortcuts() {
  const params = new URLSearchParams(window.location.search)
  const view = params.get('view')
  const autostart = params.get('autostart')

  if (view === 'voice' || view === 'chat') {
    activeView.value = 'voice'
  } else if (view === 'memory') {
    activeView.value = 'memory'
  } else if (view === 'overview') {
    activeView.value = 'overview'
  }

  if (autostart === 'true' || autostart === '1') {
    activeView.value = 'voice'
    autostartMic.value = true
  }
}

async function checkHealth() {
  try {
    const res = await jarvisApi.health()
    healthStatus.value = {
      status: res?.status || 'ok',
      user_data_readable: Boolean(res?.user_data_readable),
      timestamp: res?.timestamp || ''
    }
  } catch {
    healthStatus.value = {
      status: 'offline',
      user_data_readable: false,
      timestamp: ''
    }
  }
}

async function refreshCockpitData() {
  if (!authStore.isAuthenticated) return
  loadingDashboard.value = true
  dashboardError.value = ''
  try {
    const [trackersData, memoryData] = await Promise.all([
      jarvisApi.getTrackers(),
      jarvisApi.getMemory()
    ])
    trackers.value = Array.isArray(trackersData?.trackers)
      ? trackersData.trackers
      : []

    const morningCount = Array.isArray(memoryData?.morning_rules)
      ? memoryData.morning_rules.length
      : 0
    const scopes = memoryData?.custom_scopes || {}
    const customCount = Object.values(scopes).reduce(
      (sum, arr) => sum + (Array.isArray(arr) ? arr.length : 0),
      0
    )
    memoryStats.value = {
      morningCount,
      customCount,
      totalCount: morningCount + customCount
    }
  } catch (err) {
    dashboardError.value =
      err.message || 'Synchronisation du volume user_data refusée'
  } finally {
    loadingDashboard.value = false
  }
}

async function handleLogin() {
  const ok = await authStore.login(loginForm.email, loginForm.password)
  if (ok) {
    loginForm.password = ''
    await refreshCockpitData()
  }
}

async function handleTriggerBriefing() {
  if (triggeringBriefing.value) return
  triggeringBriefing.value = true
  briefingFeedback.value = ''
  try {
    await jarvisApi.triggerBriefing()
    briefingFeedback.value = 'Briefing transmis sur Discord (#briefing)'
    await refreshCockpitData()
  } catch (err) {
    briefingFeedback.value = err.message || 'Échec du déclenchement'
  } finally {
    triggeringBriefing.value = false
  }
}

async function handleQuickCommand() {
  const cmd = quickCommand.value.trim()
  if (!cmd || sendingQuickCommand.value) return
  sendingQuickCommand.value = true
  quickCommandFeedback.value = ''
  try {
    const res = await jarvisApi.sendCommand(cmd, false)
    quickCommand.value = ''
    quickCommandFeedback.value =
      res?.output || res?.reply || 'Instruction exécutée par 01-voice-agent-core'
    await refreshCockpitData()
  } catch (err) {
    quickCommandFeedback.value = err.message || 'Échec de l’instruction'
  } finally {
    sendingQuickCommand.value = false
  }
}

function openVoiceShortcut() {
  autostartMic.value = true
  activeView.value = 'voice'
}

function handleGlobalKeydown(e) {
  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault()
    autostartMic.value = false
    activeView.value = 'voice'
  }
}

onMounted(async () => {
  window.addEventListener('keydown', handleGlobalKeydown)
  parseUrlShortcuts()
  await checkHealth()
  if (authStore.isAuthenticated) {
    const restored = await authStore.restoreSession()
    if (restored) {
      await refreshCockpitData()
    }
  }
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', handleGlobalKeydown)
})
</script>

<template>
  <!-- Unauthenticated Login View -->
  <div
    v-if="!authStore.isAuthenticated"
    class="min-h-screen bg-graphite-bg text-ink-primary flex items-center justify-center px-6 py-12"
  >
    <div class="enter-fade w-full max-w-[380px] rounded-[16px] bg-graphite-card border hairline-border p-6 space-y-6">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-2.5">
          <span class="h-2 w-2 rounded-full bg-accent"></span>
          <span class="text-[16px] font-medium text-ink-primary">Jarvis</span>
        </div>
        <span class="text-xs font-mono meta-mono text-ink-muted">
          API {{ healthStatus.status }}
        </span>
      </div>

      <div>
        <h1 class="text-[24px] leading-[30px] font-medium text-ink-primary title-display">
          Poste de commande exécutif
        </h1>
        <p class="mt-1.5 text-[13px] leading-[20px] text-ink-secondary">
          Trackers dynamiques, mémoire contextuelle et passerelle vocale n8n
        </p>
      </div>

      <div
        v-if="authStore.error"
        class="rounded-[8px] border border-semantic-negative/40 bg-graphite-deep px-3 py-2 text-xs text-semantic-negative"
      >
        {{ authStore.error }}
      </div>

      <form class="space-y-4" @submit.prevent="handleLogin">
        <div>
          <label class="block text-xs font-mono meta-mono text-ink-secondary mb-1.5">
            Email
          </label>
          <input
            v-model="loginForm.email"
            type="email"
            required
            autocomplete="username"
            placeholder="owner@example.com"
            class="h-10 w-full rounded-[8px] border hairline-border bg-graphite-deep px-3 text-sm text-ink-primary placeholder:text-ink-muted focus:border-[#ededed]/30 focus:outline-none"
          />
        </div>

        <div>
          <label class="block text-xs font-mono meta-mono text-ink-secondary mb-1.5">
            Mot de passe
          </label>
          <input
            v-model="loginForm.password"
            type="password"
            required
            autocomplete="current-password"
            placeholder="Mot de passe"
            class="h-10 w-full rounded-[8px] border hairline-border bg-graphite-deep px-3 text-sm text-ink-primary placeholder:text-ink-muted focus:border-[#ededed]/30 focus:outline-none"
          />
        </div>

        <button
          type="submit"
          :disabled="authStore.loading"
          class="btn-primary h-10 w-full rounded-[8px] text-sm font-medium disabled:opacity-40"
        >
          {{ authStore.loading ? 'Vérification' : 'Ouvrir la session' }}
        </button>
      </form>
    </div>
  </div>

  <!-- Authenticated Full Desktop & Mobile Shell -->
  <div
    v-else
    class="min-h-screen bg-graphite-bg text-ink-primary flex flex-col lg:flex-row pb-16 lg:pb-0"
  >
    <!-- Desktop Sidebar (248px, Graphite Deep #0f0f10, 4px grid) -->
    <aside class="hidden lg:flex lg:w-[248px] lg:shrink-0 bg-graphite-deep border-r hairline-border p-4 flex-col justify-between">
      <div>
        <!-- Brand Row -->
        <div class="h-10 px-2 flex items-center justify-between">
          <div class="flex items-center gap-2.5">
            <span class="h-2 w-2 rounded-full bg-accent"></span>
            <span class="text-[16px] font-medium text-ink-primary">Jarvis</span>
          </div>
          <span
            class="inline-flex items-center gap-1.5 text-xs font-mono meta-mono text-ink-muted"
            title="Statut passerelle Laravel Sanctum"
          >
            <span
              class="h-1.5 w-1.5 rounded-full"
              :class="
                healthStatus.status === 'ok'
                  ? 'bg-semantic-positive'
                  : 'bg-semantic-negative'
              "
            ></span>
            <span>{{ healthStatus.status }}</span>
          </span>
        </div>

        <!-- Primary Navigation Group (24px above label, 8px below, 36px rows, 4px gap) -->
        <div class="mt-6 mb-2 px-2 text-xs text-ink-muted">
          Poste de commande
        </div>
        <nav class="space-y-1">
          <button
            type="button"
            class="pressable h-9 w-full rounded-[8px] px-2.5 text-[14px] flex items-center justify-between"
            :class="
              activeView === 'overview'
                ? 'surface-raised text-ink-primary font-medium'
                : 'text-ink-secondary hover:text-ink-primary hover:bg-graphite-card'
            "
            @click="activeView = 'overview'"
          >
            <span class="flex items-center gap-2.5">
              <span class="w-[18px] flex items-center justify-center text-ink-muted">
                <AppIcon name="grid" />
              </span>
              <span>Trackers et cycles</span>
            </span>
            <span class="text-xs font-mono meta-mono text-ink-muted">
              {{ trackers.length }}
            </span>
          </button>

          <button
            type="button"
            class="pressable h-9 w-full rounded-[8px] px-2.5 text-[14px] flex items-center justify-between"
            :class="
              activeView === 'voice'
                ? 'surface-raised text-ink-primary font-medium'
                : 'text-ink-secondary hover:text-ink-primary hover:bg-graphite-card'
            "
            @click="
              autostartMic = false;
              activeView = 'voice'
            "
          >
            <span class="flex items-center gap-2.5">
              <span class="w-[18px] flex items-center justify-center text-ink-muted">
                <AppIcon name="chat" />
              </span>
              <span>Canal direct et voix</span>
            </span>
            <kbd class="keycap">⌘K</kbd>
          </button>

          <button
            type="button"
            class="pressable h-9 w-full rounded-[8px] px-2.5 text-[14px] flex items-center justify-between"
            :class="
              activeView === 'memory'
                ? 'surface-raised text-ink-primary font-medium'
                : 'text-ink-secondary hover:text-ink-primary hover:bg-graphite-card'
            "
            @click="activeView = 'memory'"
          >
            <span class="flex items-center gap-2.5">
              <span class="w-[18px] flex items-center justify-center text-ink-muted">
                <AppIcon name="memory" />
              </span>
              <span>Règles mémoire</span>
            </span>
            <span class="text-xs font-mono meta-mono text-ink-muted">
              {{ memoryStats.totalCount }}
            </span>
          </button>
        </nav>

        <!-- Direct Trackers Jump List -->
        <template v-if="trackers.length > 0">
          <div class="mt-6 mb-2 px-2 text-xs text-ink-muted">
            Registre actif
          </div>
          <div class="space-y-1">
            <button
              v-for="tr in trackers"
              :key="tr.id"
              type="button"
              class="pressable h-8 w-full rounded-[8px] px-2.5 text-[13px] flex items-center justify-between"
              :class="
                selectedTrackerId === tr.id && activeView === 'overview'
                  ? 'surface-raised text-ink-primary'
                  : 'text-ink-secondary hover:text-ink-primary hover:bg-graphite-card'
              "
              @click="
                activeView = 'overview';
                selectedTrackerId = tr.id
              "
            >
              <span class="truncate">{{ tr.display_name || tr.id }}</span>
              <span class="text-xs font-mono meta-mono text-ink-muted ml-2">
                {{
                  tr.type === 'CYCLE_STATE_MACHINE'
                    ? tr.current_step
                    : tr.record_count ?? 0
                }}
              </span>
            </button>
          </div>
        </template>
      </div>

      <!-- Sidebar Footer -->
      <div class="pt-4 border-t hairline-border space-y-2">
        <button
          type="button"
          class="btn-secondary h-9 w-full rounded-[8px] px-3 text-xs font-medium inline-flex items-center justify-between"
          @click="openVoiceShortcut"
        >
          <span class="inline-flex items-center gap-2">
            <AppIcon name="mic" />
            <span>Dictée directe</span>
          </span>
          <span class="h-1.5 w-1.5 rounded-full bg-accent"></span>
        </button>

        <div class="flex items-center justify-between px-1 pt-1 text-xs text-ink-muted">
          <span class="truncate font-mono meta-mono">
            {{ authStore.user?.email || 'session active' }}
          </span>
          <button
            type="button"
            class="pressable text-ink-muted hover:text-ink-primary inline-flex items-center gap-1"
            title="Fermer la session"
            @click="authStore.logout()"
          >
            <AppIcon name="logout" />
          </button>
        </div>
      </div>
    </aside>

    <!-- Main Fluid Content Canvas (capped at 1480px) -->
    <div class="flex-1 min-w-0">
      <div class="mx-auto max-w-[1480px] px-5 sm:px-8 py-6 space-y-6">
        <!-- Top Page Bar (56px height rhythm) -->
        <header class="flex flex-wrap items-center justify-between gap-4 pb-4 border-b hairline-border">
          <div>
            <div class="text-xs font-mono meta-mono text-ink-muted capitalize">
              {{ currentPeriodTitle }}
            </div>
            <h1 class="mt-0.5 text-[28px] font-medium text-ink-primary title-display">
              {{
                activeView === 'overview'
                  ? 'Poste de commande et trackers'
                  : activeView === 'voice'
                    ? 'Canal direct et reconnaissance vocale'
                    : 'Règles mémoire et filtres contextuels'
              }}
            </h1>
          </div>

          <!-- Primary & Secondary actions (at most 2) -->
          <div class="flex items-center gap-2">
            <button
              type="button"
              class="btn-secondary h-9 rounded-[8px] px-3.5 text-xs font-medium inline-flex items-center gap-2"
              @click="refreshCockpitData"
            >
              <AppIcon name="refresh" />
              <span>Actualiser</span>
            </button>
            <button
              type="button"
              :disabled="triggeringBriefing"
              class="btn-primary h-9 rounded-[8px] px-4 text-xs font-medium inline-flex items-center gap-2 disabled:opacity-40"
              @click="handleTriggerBriefing"
            >
              <AppIcon name="play" />
              <span>{{ triggeringBriefing ? 'Exécution 08:30' : 'Lancer le briefing' }}</span>
            </button>
          </div>
        </header>

        <div
          v-if="briefingFeedback"
          class="rounded-[8px] bg-graphite-card border hairline-border px-4 py-2.5 text-xs font-mono meta-mono text-ink-secondary flex items-center justify-between"
        >
          <span>{{ briefingFeedback }}</span>
          <button
            type="button"
            class="text-ink-muted hover:text-ink-primary"
            @click="briefingFeedback = ''"
          >
            Fermer
          </button>
        </div>

        <div
          v-if="dashboardError"
          class="rounded-[8px] border border-semantic-negative/40 bg-graphite-card px-4 py-2.5 text-xs text-semantic-negative"
        >
          {{ dashboardError }}
        </div>

        <!-- VIEW 1: OVERVIEW & DYNAMIC TRACKERS -->
        <div v-if="activeView === 'overview'" class="enter-fade space-y-6">
          <!-- Row 1: 4 Compact Stat Cards (Numbers only + reference comparison, equal height) -->
          <section class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded-[12px] bg-graphite-card border hairline-border p-5 flex flex-col justify-between h-[112px]">
              <div class="text-xs text-ink-muted">Trackers actifs</div>
              <div class="text-[28px] font-medium text-ink-primary figure-tabular">
                {{ trackers.length }}
              </div>
              <div class="text-xs font-mono meta-mono text-ink-secondary">
                {{ cycleTrackers.length }} cycles · {{ collectionTrackers.length }} tables
              </div>
            </div>

            <div class="rounded-[12px] bg-graphite-card border hairline-border p-5 flex flex-col justify-between h-[112px]">
              <div class="text-xs text-ink-muted">Entrées indexées</div>
              <div class="text-[28px] font-medium text-ink-primary figure-tabular">
                {{ totalRecordsCount }}
              </div>
              <div class="text-xs font-mono meta-mono text-ink-secondary">
                Volume user_data ({{ healthStatus.user_data_readable ? 'LOCK_EX' : 'hors ligne' }})
              </div>
            </div>

            <div class="rounded-[12px] bg-graphite-card border hairline-border p-5 flex flex-col justify-between h-[112px]">
              <div class="text-xs text-ink-muted">Règles mémoire</div>
              <div class="text-[28px] font-medium text-ink-primary figure-tabular">
                {{ memoryStats.totalCount }}
              </div>
              <div class="text-xs font-mono meta-mono text-ink-secondary">
                {{ memoryStats.morningCount }} permanentes · {{ memoryStats.customCount }} scopes
              </div>
            </div>

            <div class="rounded-[12px] bg-graphite-card border hairline-border p-5 flex flex-col justify-between h-[112px]">
              <div class="text-xs text-ink-muted">Latence passerelle</div>
              <div class="text-[28px] font-medium text-ink-primary figure-tabular">
                {{ telemetry.lastLatencyMs !== null ? `${telemetry.lastLatencyMs} ms` : '—' }}
              </div>
              <div class="text-xs font-mono meta-mono text-ink-secondary truncate">
                {{ latencyDeltaText }}
              </div>
            </div>
          </section>

          <!-- Selected Tracker Fluid Sheet (Direct Manipulation Drag + Esc) -->
          <DynamicTrackerDetail
            v-if="selectedTrackerId"
            :tracker-id="selectedTrackerId"
            @close="selectedTrackerId = ''"
            @updated="refreshCockpitData"
          />

          <!-- Row 2: Asymmetric Split (2/3 Dynamic Trackers Deck + 1/3 LLMOps Telemetry & Direct Command) -->
          <section class="grid grid-cols-1 xl:grid-cols-3 gap-6 items-start">
            <!-- 2/3 Column: Schema-Driven Dynamic Trackers -->
            <div class="xl:col-span-2 space-y-4">
              <div class="flex items-baseline justify-between">
                <h2 class="text-[16px] leading-[22px] font-medium text-ink-primary">
                  Registre dynamique (trackers_registry.json)
                </h2>
                <span class="text-xs font-mono meta-mono text-ink-muted">
                  {{ trackers.length }} schémas actifs
                </span>
              </div>

              <div
                v-if="loadingDashboard && trackers.length === 0"
                class="rounded-[12px] bg-graphite-card border hairline-border p-6 text-xs font-mono meta-mono text-ink-muted"
              >
                Synchronisation de trackers_registry.json
              </div>

              <div
                v-else-if="trackers.length === 0"
                class="rounded-[12px] bg-graphite-card border hairline-border p-6 space-y-2"
              >
                <div class="text-sm font-medium text-ink-primary">
                  Aucun tracker initialisé dans trackers_registry.json
                </div>
                <p class="text-xs text-ink-secondary">
                  Envoie une instruction à Jarvis via la barre d’exécution rapide à droite ou dans l’onglet Canal direct
                </p>
              </div>

              <div
                v-else
                class="grid grid-cols-1 md:grid-cols-2 gap-4"
              >
                <DynamicTrackerCard
                  v-for="tracker in trackers"
                  :key="tracker.id"
                  :tracker="tracker"
                  :class="tracker.type === 'CYCLE_STATE_MACHINE' ? 'md:col-span-2' : ''"
                  @open="(id) => (selectedTrackerId = id)"
                  @updated="refreshCockpitData"
                />
              </div>
            </div>

            <!-- 1/3 Column: Moment 2 — Precision LLMOps Telemetry Chart & Direct Agent Command -->
            <div class="space-y-4">
              <!-- LLMOps Latency Chart Card (One accent on latest bar, muted grid, mono labels) -->
              <div class="rounded-[12px] bg-graphite-card border hairline-border p-5 space-y-4">
                <div class="flex items-baseline justify-between">
                  <div>
                    <div class="text-xs text-ink-muted">Télémétrie LLMOps</div>
                    <h3 class="mt-0.5 text-[15px] font-medium text-ink-primary">
                      Temps de réponse API et webhooks n8n
                    </h3>
                  </div>
                  <span class="text-xs font-mono meta-mono text-accent">
                    {{ telemetry.lastLatencyMs !== null ? `${telemetry.lastLatencyMs} ms` : 'repos' }}
                  </span>
                </div>

                <!-- Bar Chart on Muted Grid -->
                <div class="rounded-[8px] bg-graphite-deep border hairline-border p-3.5 space-y-2">
                  <div class="h-[112px] flex items-end gap-1.5 border-b hairline-border pb-2">
                    <div
                      v-for="(bar, idx) in latencyBars"
                      :key="idx"
                      class="flex-1 flex flex-col justify-end h-full"
                    >
                      <div
                        class="w-full rounded-t-[3px] transition-colors duration-150"
                        :class="bar.isLatest ? 'bg-accent' : 'bg-graphite-hover'"
                        :style="{ height: `${bar.heightPct}%` }"
                        :title="`${bar.ms} ms`"
                      ></div>
                    </div>
                  </div>
                  <div class="flex items-center justify-between text-[11px] font-mono meta-mono text-ink-muted">
                    <span>12 derniers appels</span>
                    <span>{{ telemetry.lastEndpoint || '/api/trackers' }}</span>
                  </div>
                </div>
              </div>

              <!-- Direct Orchestrator Command Box (01-voice-agent-core) -->
              <form
                class="rounded-[12px] bg-graphite-card border hairline-border p-5 space-y-3"
                @submit.prevent="handleQuickCommand"
              >
                <div class="flex items-center justify-between">
                  <span class="text-[14px] font-medium text-ink-primary">
                    Instruction directe (01-voice-agent-core)
                  </span>
                  <span class="text-xs font-mono meta-mono text-ink-muted">
                    Meta-Tools + Workspace
                  </span>
                </div>

                <p class="text-xs text-ink-secondary">
                  Crée ou modifie un tracker, ajoute une tâche Google Tasks ou planifie un événement sans quitter le cockpit
                </p>

                <div class="flex gap-2">
                  <input
                    v-model="quickCommand"
                    type="text"
                    placeholder="Instruction exécutive"
                    class="h-9 flex-1 rounded-[8px] border hairline-border bg-graphite-deep px-3 text-xs text-ink-primary placeholder:text-ink-muted focus:border-[#ededed]/30 focus:outline-none"
                  />
                  <button
                    type="submit"
                    :disabled="sendingQuickCommand || !quickCommand.trim()"
                    class="btn-primary h-9 rounded-[8px] px-3.5 text-xs font-medium disabled:opacity-40"
                  >
                    {{ sendingQuickCommand ? 'En cours' : 'Exécuter' }}
                  </button>
                </div>

                <p
                  v-if="quickCommandFeedback"
                  class="rounded-[8px] bg-graphite-deep border hairline-border p-3 text-xs font-mono meta-mono text-ink-secondary whitespace-pre-wrap"
                >
                  {{ quickCommandFeedback }}
                </p>
              </form>
            </div>
          </section>
        </div>

        <!-- VIEW 2: SHARED CHAT & VOICE -->
        <ChatVoiceView
          v-else-if="activeView === 'voice'"
          :autostart-mic="autostartMic"
        />

        <!-- VIEW 3: MEMORY RULES -->
        <MemoryRulesView
          v-else-if="activeView === 'memory'"
          @updated="refreshCockpitData"
        />
      </div>
    </div>

    <!-- Mobile Translucent Bottom Navigation Bar (Apple Material Chrome) -->
    <nav
      class="lg:hidden fixed bottom-0 inset-x-0 z-30 material-chrome grid grid-cols-3 text-xs"
    >
      <button
        type="button"
        class="pressable py-3 flex flex-col items-center gap-1"
        :class="activeView === 'overview' ? 'text-ink-primary font-medium' : 'text-ink-muted'"
        @click="activeView = 'overview'"
      >
        <AppIcon name="grid" />
        <span>Trackers</span>
      </button>
      <button
        type="button"
        class="pressable py-3 flex flex-col items-center gap-1"
        :class="activeView === 'voice' ? 'text-ink-primary font-medium' : 'text-ink-muted'"
        @click="activeView = 'voice'"
      >
        <AppIcon name="mic" />
        <span>Canal vocal</span>
      </button>
      <button
        type="button"
        class="pressable py-3 flex flex-col items-center gap-1"
        :class="activeView === 'memory' ? 'text-ink-primary font-medium' : 'text-ink-muted'"
        @click="activeView = 'memory'"
      >
        <AppIcon name="memory" />
        <span>Mémoire</span>
      </button>
    </nav>
  </div>
</template>
