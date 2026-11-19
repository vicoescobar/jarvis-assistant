<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { authStore } from '@/stores/auth'
import { jarvisApi, telemetry, API_BASE_URL } from '@/services/api'
import DynamicTrackerCard from '@/components/DynamicTrackerCard.vue'
import DynamicTrackerDetail from '@/components/DynamicTrackerDetail.vue'
import ChatVoiceView from '@/views/ChatVoiceView.vue'
import MemoryRulesView from '@/views/MemoryRulesView.vue'

const activeView = ref('overview')
const autostartMic = ref(false)
const selectedTrackerId = ref('')

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

const cycleTrackersCount = computed(
  () => trackers.value.filter((t) => t.type === 'CYCLE_STATE_MACHINE').length
)

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
      err.message || 'Impossible de synchroniser les données du cockpit.'
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
    briefingFeedback.value =
      'Briefing généré et envoyé sur Discord (#briefing).'
    await refreshCockpitData()
  } catch (err) {
    briefingFeedback.value =
      err.message || 'Échec du déclenchement du briefing.'
  } finally {
    triggeringBriefing.value = false
  }
}

function openVoiceShortcut() {
  autostartMic.value = true
  activeView.value = 'voice'
}

onMounted(async () => {
  parseUrlShortcuts()
  await checkHealth()
  if (authStore.isAuthenticated) {
    const restored = await authStore.restoreSession()
    if (restored) {
      await refreshCockpitData()
    }
  }
})
</script>

<template>
  <div class="min-h-screen bg-slate-950 text-slate-100 flex flex-col pb-16 sm:pb-0">
    <!-- Top Header -->
    <header class="border-b border-slate-800/90 bg-slate-900/80 backdrop-blur sticky top-0 z-30">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 py-3 flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
          <div class="h-9 w-9 rounded-xl bg-indigo-600/20 border border-indigo-500/40 flex items-center justify-center text-indigo-400 font-bold">
            J
          </div>
          <div>
            <div class="flex items-center gap-2">
              <h1 class="text-sm sm:text-base font-bold tracking-tight text-white">
                JARVIS COCKPIT
              </h1>
              <span
                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-mono border"
                :class="
                  healthStatus.status === 'ok'
                    ? 'bg-emerald-950/70 border-emerald-700/50 text-emerald-300'
                    : 'bg-amber-950/70 border-amber-700/50 text-amber-300'
                "
              >
                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                API {{ healthStatus.status }}
              </span>
            </div>
            <p class="text-[11px] font-mono text-slate-400 hidden sm:block">
              {{ API_BASE_URL }}
            </p>
          </div>
        </div>

        <!-- Desktop Navigation -->
        <nav
          v-if="authStore.isAuthenticated"
          class="hidden sm:flex items-center gap-1.5 rounded-xl bg-slate-950 p-1 border border-slate-800"
        >
          <button
            type="button"
            class="rounded-lg px-3.5 py-1.5 text-xs font-medium transition"
            :class="
              activeView === 'overview'
                ? 'bg-indigo-600 text-white'
                : 'text-slate-300 hover:text-white'
            "
            @click="activeView = 'overview'"
          >
            Cockpit & Trackers
          </button>
          <button
            type="button"
            class="rounded-lg px-3.5 py-1.5 text-xs font-medium transition"
            :class="
              activeView === 'voice'
                ? 'bg-indigo-600 text-white'
                : 'text-slate-300 hover:text-white'
            "
            @click="
              autostartMic = false;
              activeView = 'voice'
            "
          >
            Chat & Voix
          </button>
          <button
            type="button"
            class="rounded-lg px-3.5 py-1.5 text-xs font-medium transition"
            :class="
              activeView === 'memory'
                ? 'bg-indigo-600 text-white'
                : 'text-slate-300 hover:text-white'
            "
            @click="activeView = 'memory'"
          >
            Règles Mémoire ({{ memoryStats.totalCount }})
          </button>
        </nav>

        <!-- Actions right -->
        <div v-if="authStore.isAuthenticated" class="flex items-center gap-2">
          <button
            type="button"
            class="rounded-xl bg-indigo-600 hover:bg-indigo-500 px-3 py-1.5 text-xs font-semibold text-white transition flex items-center gap-1.5"
            @click="openVoiceShortcut"
          >
            <span>🎙️</span>
            <span class="hidden md:inline">Commande Vocale</span>
          </button>
          <button
            type="button"
            class="rounded-xl border border-slate-700 hover:bg-slate-800 px-3 py-1.5 text-xs text-slate-300"
            @click="authStore.logout()"
          >
            Déconnexion
          </button>
        </div>
      </div>
    </header>

    <!-- Main Container -->
    <main class="mx-auto w-full max-w-7xl flex-1 px-4 sm:px-6 py-6">
      <!-- Unauthenticated Login Screen -->
      <div
        v-if="!authStore.isAuthenticated"
        class="mx-auto max-w-md mt-10 rounded-2xl border border-slate-800 bg-slate-900/90 p-6 space-y-5"
      >
        <div>
          <span class="rounded bg-indigo-950 border border-indigo-700/40 px-2.5 py-0.5 text-xs font-mono text-indigo-300">
            Laravel Sanctum Auth
          </span>
          <h2 class="mt-2 text-xl font-bold text-white">
            Connexion au Cockpit Jarvis
          </h2>
          <p class="text-xs text-slate-400 mt-1">
            Accès sécurisé aux trackers dynamiques, règles mémoire et orchestrateur n8n.
          </p>
        </div>

        <div
          v-if="authStore.error"
          class="rounded-lg border border-amber-700/50 bg-amber-950/40 px-3 py-2 text-xs text-amber-200"
        >
          {{ authStore.error }}
        </div>

        <form class="space-y-4" @submit.prevent="handleLogin">
          <div>
            <label class="block text-xs font-mono text-slate-300 mb-1">
              Email propriétaire
            </label>
            <input
              v-model="loginForm.email"
              type="email"
              required
              autocomplete="username"
              placeholder="owner@example.com"
              class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3.5 py-2.5 text-sm text-white focus:border-indigo-500 focus:outline-none"
            />
          </div>

          <div>
            <label class="block text-xs font-mono text-slate-300 mb-1">
              Mot de passe
            </label>
            <input
              v-model="loginForm.password"
              type="password"
              required
              autocomplete="current-password"
              placeholder="••••••••••••"
              class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3.5 py-2.5 text-sm text-white focus:border-indigo-500 focus:outline-none"
            />
          </div>

          <button
            type="submit"
            :disabled="authStore.loading"
            class="w-full rounded-xl bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 py-2.5 text-sm font-semibold text-white transition"
          >
            {{ authStore.loading ? 'Vérification...' : 'Ouvrir la session' }}
          </button>
        </form>
      </div>

      <!-- Authenticated Views -->
      <div v-else class="space-y-6">
        <!-- VIEW 1: OVERVIEW & DYNAMIC TRACKERS -->
        <template v-if="activeView === 'overview'">
          <!-- Observability / LLMOps & Executive Actions Bar -->
          <section class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <!-- LLMOps / Observability Card -->
            <div class="lg:col-span-2 rounded-2xl border border-slate-800 bg-slate-900/80 p-5 flex flex-col justify-between gap-4">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                  <span class="text-xs font-mono uppercase tracking-wider text-indigo-400">
                    Observabilité & LLMOps
                  </span>
                  <h2 class="text-base font-bold text-white">
                    État du Système & Latence Orchestrateur
                  </h2>
                </div>
                <button
                  type="button"
                  class="rounded-lg border border-slate-700 hover:bg-slate-800 px-3 py-1 text-xs text-slate-300"
                  @click="refreshCockpitData"
                >
                  Rafraîchir
                </button>
              </div>

              <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="rounded-xl bg-slate-950/80 border border-slate-800 p-3">
                  <div class="text-[11px] text-slate-400">Volume user_data</div>
                  <div class="mt-1 text-sm font-mono font-semibold text-emerald-300">
                    {{ healthStatus.user_data_readable ? 'LOCK_EX OK' : 'Vérifier' }}
                  </div>
                </div>

                <div class="rounded-xl bg-slate-950/80 border border-slate-800 p-3">
                  <div class="text-[11px] text-slate-400">Trackers actifs</div>
                  <div class="mt-1 text-lg font-bold text-white">
                    {{ trackers.length }}
                    <span class="text-xs font-normal text-slate-400">
                      ({{ cycleTrackersCount }} cycle)
                    </span>
                  </div>
                </div>

                <div class="rounded-xl bg-slate-950/80 border border-slate-800 p-3">
                  <div class="text-[11px] text-slate-400">Règles mémoire</div>
                  <div class="mt-1 text-lg font-bold text-white">
                    {{ memoryStats.totalCount }}
                  </div>
                </div>

                <div class="rounded-xl bg-slate-950/80 border border-slate-800 p-3">
                  <div class="text-[11px] text-slate-400">Latence API / n8n</div>
                  <div class="mt-1 text-sm font-mono font-semibold text-indigo-300">
                    {{
                      telemetry.lastLatencyMs !== null
                        ? `${telemetry.lastLatencyMs} ms (moy. ${telemetry.avgLatencyMs} ms)`
                        : '—'
                    }}
                  </div>
                </div>
              </div>
            </div>

            <!-- Executive Quick Actions Card -->
            <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-5 flex flex-col justify-between gap-3">
              <div>
                <span class="text-xs font-mono uppercase tracking-wider text-indigo-400">
                  Orchestration Matinale
                </span>
                <h2 class="text-base font-bold text-white mt-0.5">
                  Briefing Exécutif (Workflow 02)
                </h2>
                <p class="text-xs text-slate-400 mt-1">
                  Agrège Google Calendar, Gmail 24h, Google Tasks (@default) et exécute les mutations de trackers.
                </p>
              </div>

              <p
                v-if="briefingFeedback"
                class="text-xs text-indigo-300 bg-indigo-950/50 border border-indigo-800/40 rounded-lg px-3 py-2"
              >
                {{ briefingFeedback }}
              </p>

              <button
                type="button"
                :disabled="triggeringBriefing"
                class="w-full rounded-xl bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 px-4 py-2.5 text-xs font-semibold text-white transition"
                @click="handleTriggerBriefing"
              >
                {{
                  triggeringBriefing
                    ? 'Exécution du briefing en cours...'
                    : 'Lancer le briefing maintenant'
                }}
              </button>
            </div>
          </section>

          <div
            v-if="dashboardError"
            class="rounded-xl border border-amber-700/50 bg-amber-950/40 px-4 py-3 text-xs text-amber-200"
          >
            {{ dashboardError }}
          </div>

          <!-- Selected Tracker Detail Drawer / Section -->
          <DynamicTrackerDetail
            v-if="selectedTrackerId"
            :tracker-id="selectedTrackerId"
            @close="selectedTrackerId = ''"
            @updated="refreshCockpitData"
          />

          <!-- Dynamic Trackers Grid -->
          <section class="space-y-3">
            <div class="flex items-center justify-between">
              <h2 class="text-base font-bold text-white">
                Trackers Dynamiques (<code class="font-mono text-xs text-indigo-300">trackers_registry.json</code>)
              </h2>
              <span class="text-xs font-mono text-slate-400">
                100% Schema-Driven
              </span>
            </div>

            <div
              v-if="loadingDashboard && trackers.length === 0"
              class="text-xs text-slate-400 py-6"
            >
              Chargement des trackers...
            </div>

            <div
              v-else-if="trackers.length === 0"
              class="rounded-2xl border border-slate-800 bg-slate-900/50 p-8 text-center space-y-2"
            >
              <p class="text-sm font-semibold text-slate-200">
                Aucun tracker enregistré dans <code class="font-mono text-indigo-300">trackers_registry.json</code>.
              </p>
              <p class="text-xs text-slate-400">
                Demande à Jarvis dans l'onglet « Chat & Voix » de créer un tracker (cycle, table ou journal d'activité).
              </p>
            </div>

            <div
              v-else
              class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4"
            >
              <DynamicTrackerCard
                v-for="tracker in trackers"
                :key="tracker.id"
                :tracker="tracker"
                @open="(id) => (selectedTrackerId = id)"
                @updated="refreshCockpitData"
              />
            </div>
          </section>
        </template>

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
    </main>

    <!-- Mobile Bottom Navigation Bar (PWA) -->
    <nav
      v-if="authStore.isAuthenticated"
      class="sm:hidden fixed bottom-0 inset-x-0 z-30 border-t border-slate-800 bg-slate-900/95 backdrop-blur grid grid-cols-3 text-xs"
    >
      <button
        type="button"
        class="py-3 flex flex-col items-center gap-0.5"
        :class="activeView === 'overview' ? 'text-indigo-400 font-semibold' : 'text-slate-400'"
        @click="activeView = 'overview'"
      >
        <span>📊</span>
        <span>Trackers</span>
      </button>
      <button
        type="button"
        class="py-3 flex flex-col items-center gap-0.5"
        :class="activeView === 'voice' ? 'text-indigo-400 font-semibold' : 'text-slate-400'"
        @click="activeView = 'voice'"
      >
        <span>🎙️</span>
        <span>Chat & Voix</span>
      </button>
      <button
        type="button"
        class="py-3 flex flex-col items-center gap-0.5"
        :class="activeView === 'memory' ? 'text-indigo-400 font-semibold' : 'text-slate-400'"
        @click="activeView = 'memory'"
      >
        <span>🧠</span>
        <span>Mémoire</span>
      </button>
    </nav>
  </div>
</template>
