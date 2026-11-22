<script setup>
import { onMounted, reactive, ref } from 'vue'
import { authStore } from '@/stores/auth'
import { themeStore } from '@/stores/theme'
import { jarvisApi } from '@/services/api'
import AppIcon from '@/components/AppIcon.vue'
import DynamicTrackerCard from '@/components/DynamicTrackerCard.vue'
import DynamicTrackerDetail from '@/components/DynamicTrackerDetail.vue'
import ChatVoiceView from '@/views/ChatVoiceView.vue'
import MemoryRulesView from '@/views/MemoryRulesView.vue'
import DebugView from '@/views/DebugView.vue'

// Views: 'overview' (Trackers) | 'voice' (Vocal) | 'memory' (Mémoire) | 'debug' (Debug)
const activeView = ref('overview')
const autostartMic = ref(false)
const selectedTrackerId = ref('')
const memoryViewRef = ref(null)

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
const dashboardError = ref('')
const toastMessage = ref('')
let toastTimer = null

function showToast(msg) {
  if (!msg) return
  toastMessage.value = String(msg)
  if (toastTimer) clearTimeout(toastTimer)
  toastTimer = setTimeout(() => {
    toastMessage.value = ''
  }, 3200)
}

function parseUrlShortcuts() {
  const params = new URLSearchParams(window.location.search)
  const view = params.get('view')
  const autostart = params.get('autostart')

  if (view === 'voice' || view === 'chat') {
    activeView.value = 'voice'
  } else if (view === 'memory') {
    activeView.value = 'memory'
  } else if (view === 'debug') {
    activeView.value = 'debug'
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
  if (!authStore.isAuthenticated || loadingDashboard.value) return
  loadingDashboard.value = true
  dashboardError.value = ''
  try {
    const [trackersData, memoryData] = await Promise.all([
      jarvisApi.getTrackers(),
      jarvisApi.getMemory(),
      checkHealth()
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

    if (activeView.value === 'memory' && memoryViewRef.value?.refresh) {
      await memoryViewRef.value.refresh()
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

onMounted(async () => {
  themeStore.init()
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
  <!-- Unauthenticated Login View -->
  <div
    v-if="!authStore.isAuthenticated"
    class="min-h-[100dvh] bg-graphite-bg text-ink-primary flex items-center justify-center px-5 py-10 safe-top safe-bottom"
  >
    <div class="enter-fade w-full max-w-[400px] rounded-[16px] card-surface p-6 space-y-6">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-2.5">
          <span class="h-2.5 w-2.5 rounded-full bg-accent"></span>
          <span class="text-[18px] font-medium text-ink-primary">Jarvis</span>
        </div>

        <button
          type="button"
          class="btn-secondary min-w-[44px] min-h-[44px] rounded-[10px] inline-flex items-center justify-center text-ink-secondary"
          :title="themeStore.isDark ? 'Activer le thème Pastel & Crème' : 'Activer le thème sombre'"
          @click="themeStore.toggle()"
        >
          <AppIcon :name="themeStore.isDark ? 'sun' : 'moon'" />
        </button>
      </div>

      <div>
        <h1 class="text-[24px] leading-[30px] font-medium text-ink-primary title-display">
          Poste de commande exécutif
        </h1>
        <p class="mt-1.5 text-[15px] sm:text-[13px] leading-[22px] sm:leading-[20px] text-ink-secondary">
          Trackers dynamiques, mémoire contextuelle et commandes vocales
        </p>
      </div>

      <div
        v-if="authStore.error"
        class="rounded-[8px] border border-pastel-rose/40 bg-pastel-rose-tint px-3.5 py-2.5 text-[14px] sm:text-xs text-pastel-rose"
      >
        {{ authStore.error }}
      </div>

      <form class="space-y-4" @submit.prevent="handleLogin">
        <div>
          <label class="block text-[14px] sm:text-xs font-mono meta-mono text-ink-secondary mb-1.5">
            Email
          </label>
          <input
            v-model="loginForm.email"
            type="email"
            required
            autocomplete="username"
            placeholder="owner@example.com"
            class="h-12 sm:h-10 w-full rounded-[10px] sm:rounded-[8px] border hairline-border bg-graphite-deep px-3.5 text-[16px] sm:text-sm text-ink-primary placeholder:text-ink-muted focus:border-accent focus:outline-none"
          />
        </div>

        <div>
          <label class="block text-[14px] sm:text-xs font-mono meta-mono text-ink-secondary mb-1.5">
            Mot de passe
          </label>
          <input
            v-model="loginForm.password"
            type="password"
            required
            autocomplete="current-password"
            placeholder="Mot de passe"
            class="h-12 sm:h-10 w-full rounded-[10px] sm:rounded-[8px] border hairline-border bg-graphite-deep px-3.5 text-[16px] sm:text-sm text-ink-primary placeholder:text-ink-muted focus:border-accent focus:outline-none"
          />
        </div>

        <button
          type="submit"
          :disabled="authStore.loading"
          class="btn-primary h-12 sm:h-10 w-full rounded-[10px] sm:rounded-[8px] text-[16px] sm:text-sm font-medium disabled:opacity-40"
        >
          {{ authStore.loading ? 'Vérification…' : 'Ouvrir la session' }}
        </button>
      </form>
    </div>
  </div>

  <!-- Authenticated Full Desktop & Mobile PWA Shell -->
  <div
    v-else
    class="min-h-screen bg-graphite-bg text-ink-primary flex flex-col pb-24 lg:pb-10"
  >
    <!-- Unified Sticky Top Header Bar (No Dictée button, No Briefing button; compact Refresh + Theme Toggle + Logout) -->
    <header class="sticky top-0 z-30 material-chrome border-b safe-top">
      <div class="mx-auto max-w-[1440px] px-4 sm:px-8 h-[64px] flex items-center justify-between gap-4">
        <!-- Brand -->
        <div class="flex items-center gap-3">
          <span class="h-2.5 w-2.5 rounded-full bg-accent"></span>
          <span class="text-[18px] font-medium text-ink-primary tracking-tight">
            Jarvis
          </span>
        </div>

        <!-- Desktop Primary Navigation Tabs -->
        <nav class="hidden lg:flex items-center gap-1 rounded-[12px] bg-graphite-deep p-1 border hairline-border">
          <button
            type="button"
            class="pressable h-9 rounded-[8px] px-4 text-[13px] font-medium inline-flex items-center gap-2"
            :class="
              activeView === 'overview'
                ? 'bg-graphite-card text-ink-primary shadow-sm'
                : 'text-ink-muted hover:text-ink-primary'
            "
            @click="activeView = 'overview'"
          >
            <AppIcon name="grid" />
            <span>Trackers</span>
          </button>

          <button
            type="button"
            class="pressable h-9 rounded-[8px] px-4 text-[13px] font-medium inline-flex items-center gap-2"
            :class="
              activeView === 'voice'
                ? 'bg-graphite-card text-ink-primary shadow-sm'
                : 'text-ink-muted hover:text-ink-primary'
            "
            @click="
              autostartMic = false;
              activeView = 'voice'
            "
          >
            <AppIcon name="mic" />
            <span>Vocal</span>
          </button>

          <button
            type="button"
            class="pressable h-9 rounded-[8px] px-4 text-[13px] font-medium inline-flex items-center gap-2"
            :class="
              activeView === 'memory'
                ? 'bg-graphite-card text-ink-primary shadow-sm'
                : 'text-ink-muted hover:text-ink-primary'
            "
            @click="activeView = 'memory'"
          >
            <AppIcon name="memory" />
            <span>Mémoire</span>
          </button>

          <button
            type="button"
            class="pressable h-9 rounded-[8px] px-3.5 text-[13px] font-medium inline-flex items-center gap-2"
            :class="
              activeView === 'debug'
                ? 'bg-graphite-card text-ink-primary shadow-sm'
                : 'text-ink-muted hover:text-ink-primary'
            "
            @click="activeView = 'debug'"
          >
            <AppIcon name="terminal" />
            <span>Debug</span>
          </button>
        </nav>

        <!-- Compact Header Icon Controls (44x44px hit targets: Refresh, Theme Toggle, Logout) -->
        <div class="flex items-center gap-2">
          <button
            type="button"
            :disabled="loadingDashboard"
            class="btn-secondary min-w-[44px] min-h-[44px] rounded-[10px] inline-flex items-center justify-center text-ink-secondary disabled:opacity-40"
            title="Actualiser les données"
            aria-label="Actualiser les données"
            @click="refreshCockpitData"
          >
            <span :class="loadingDashboard ? 'animate-spin' : ''">
              <AppIcon name="refresh" />
            </span>
          </button>

          <button
            type="button"
            class="btn-secondary min-w-[44px] min-h-[44px] rounded-[10px] inline-flex items-center justify-center text-ink-secondary"
            :title="themeStore.isDark ? 'Passer au thème Pastel & Crème' : 'Passer au thème sombre'"
            aria-label="Basculer le thème"
            @click="themeStore.toggle()"
          >
            <AppIcon :name="themeStore.isDark ? 'sun' : 'moon'" />
          </button>

          <button
            type="button"
            class="btn-secondary min-w-[44px] min-h-[44px] rounded-[10px] inline-flex items-center justify-center text-ink-secondary"
            title="Fermer la session"
            aria-label="Fermer la session"
            @click="authStore.logout()"
          >
            <AppIcon name="logout" />
          </button>
        </div>
      </div>
    </header>

    <!-- Main Fluid Content Canvas -->
    <main class="mx-auto w-full max-w-[1440px] flex-1 px-4 sm:px-8 py-6 space-y-6">
      <div
        v-if="dashboardError"
        class="rounded-[10px] border border-pastel-rose/40 bg-pastel-rose-tint px-4 py-3 text-[14px] sm:text-xs text-pastel-rose"
      >
        {{ dashboardError }}
      </div>

      <!-- VIEW 1: TRACKERS-FIRST HOME SCREEN (/) -->
      <div v-if="activeView === 'overview'" class="enter-fade space-y-6">
        <!-- Selected Tracker Detail Sheet -->
        <DynamicTrackerDetail
          v-if="selectedTrackerId"
          :tracker-id="selectedTrackerId"
          @close="selectedTrackerId = ''"
          @updated="refreshCockpitData"
        />

        <!-- Skeleton Loaders matching tracker card dimensions -->
        <div
          v-if="loadingDashboard && trackers.length === 0"
          class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5"
        >
          <div class="h-[228px] rounded-[14px] skeleton-block md:col-span-2"></div>
          <div class="h-[228px] rounded-[14px] skeleton-block"></div>
          <div class="h-[200px] rounded-[14px] skeleton-block"></div>
          <div class="h-[200px] rounded-[14px] skeleton-block"></div>
        </div>

        <!-- Empty State -->
        <div
          v-else-if="trackers.length === 0"
          class="rounded-[16px] card-surface p-8 text-center space-y-2"
        >
          <div class="text-[17px] sm:text-base font-medium text-ink-primary">
            Aucun tracker actif
          </div>
          <p class="text-[14px] sm:text-xs text-ink-secondary">
            Utilise l’onglet Vocal ou Discord (#chatbot) pour créer un nouveau tracker
          </p>
        </div>

        <!-- Dynamic Trackers Hero Grid -->
        <div
          v-else
          class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5"
        >
          <DynamicTrackerCard
            v-for="tracker in trackers"
            :key="tracker.id"
            :tracker="tracker"
            :class="tracker.type === 'CYCLE_STATE_MACHINE' ? 'md:col-span-2' : ''"
            @open="(id) => (selectedTrackerId = id)"
            @updated="refreshCockpitData"
            @toast="showToast"
          />
        </div>
      </div>

      <!-- VIEW 2: LEAN VOICE VIEW -->
      <ChatVoiceView
        v-else-if="activeView === 'voice'"
        :autostart-mic="autostartMic"
        @updated="refreshCockpitData"
      />

      <!-- VIEW 3: READ/DELETE-ONLY MEMORY RULES VIEW -->
      <MemoryRulesView
        v-else-if="activeView === 'memory'"
        ref="memoryViewRef"
        @updated="refreshCockpitData"
      />

      <!-- VIEW 4: DEBUG & DIAGNOSTICS VIEW -->
      <DebugView
        v-else-if="activeView === 'debug'"
        :health-status="healthStatus"
        :trackers="trackers"
        :memory-stats="memoryStats"
        @updated="refreshCockpitData"
      />
    </main>

    <!-- Non-blocking Floating Toast Notification -->
    <div
      v-if="toastMessage"
      class="fixed bottom-20 lg:bottom-6 right-4 sm:right-8 z-40 rounded-[10px] card-surface border hairline-border px-4 py-2.5 text-[13px] font-medium text-ink-primary shadow-md enter-fade flex items-center gap-2"
    >
      <span class="h-2 w-2 rounded-full bg-pastel-sage shrink-0"></span>
      <span>{{ toastMessage }}</span>
    </div>

    <!-- Mobile Translucent Bottom Navigation Bar (4 tabs: Trackers, Vocal, Mémoire, Debug) -->
    <nav
      class="lg:hidden fixed bottom-0 inset-x-0 z-30 material-chrome border-t safe-bottom grid grid-cols-4 text-[12px]"
    >
      <button
        type="button"
        class="pressable h-[64px] flex flex-col items-center justify-center gap-1"
        :class="activeView === 'overview' ? 'text-accent font-medium' : 'text-ink-muted'"
        @click="activeView = 'overview'"
      >
        <AppIcon name="grid" />
        <span>Trackers</span>
      </button>

      <button
        type="button"
        class="pressable h-[64px] flex flex-col items-center justify-center gap-1"
        :class="activeView === 'voice' ? 'text-accent font-medium' : 'text-ink-muted'"
        @click="
          autostartMic = false;
          activeView = 'voice'
        "
      >
        <AppIcon name="mic" />
        <span>Vocal</span>
      </button>

      <button
        type="button"
        class="pressable h-[64px] flex flex-col items-center justify-center gap-1"
        :class="activeView === 'memory' ? 'text-accent font-medium' : 'text-ink-muted'"
        @click="activeView = 'memory'"
      >
        <AppIcon name="memory" />
        <span>Mémoire</span>
      </button>

      <button
        type="button"
        class="pressable h-[64px] flex flex-col items-center justify-center gap-1"
        :class="activeView === 'debug' ? 'text-accent font-medium' : 'text-ink-muted'"
        @click="activeView = 'debug'"
      >
        <AppIcon name="terminal" />
        <span>Debug</span>
      </button>
    </nav>
  </div>
</template>
