<script setup>
import { computed, ref } from 'vue'
import { API_BASE_URL, jarvisApi, telemetry } from '@/services/api'
import AppIcon from '@/components/AppIcon.vue'

const props = defineProps({
  healthStatus: {
    type: Object,
    required: true
  },
  trackers: {
    type: Array,
    required: true
  },
  memoryStats: {
    type: Object,
    required: true
  }
})

const emit = defineEmits(['updated'])

const triggeringBriefing = ref(false)
const briefingFeedback = ref('')
const briefingError = ref('')

const cycleTrackersCount = computed(
  () => props.trackers.filter((t) => t.type === 'CYCLE_STATE_MACHINE').length
)

const tableTrackersCount = computed(
  () => props.trackers.filter((t) => t.type !== 'CYCLE_STATE_MACHINE').length
)

const totalRecordsCount = computed(() =>
  props.trackers.reduce((acc, t) => acc + Number(t.record_count ?? 0), 0)
)

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

async function handleTriggerBriefing() {
  if (triggeringBriefing.value) return
  triggeringBriefing.value = true
  briefingFeedback.value = ''
  briefingError.value = ''
  try {
    await jarvisApi.triggerBriefing()
    briefingFeedback.value = 'Briefing matinal exécuté et transmis sur Discord (#briefing).'
    emit('updated')
  } catch (err) {
    briefingError.value = err.message || 'Échec du déclenchement du briefing.'
  } finally {
    triggeringBriefing.value = false
  }
}
</script>

<template>
  <section class="enter-fade space-y-6">
    <!-- 1. Manual Actions (Morning Briefing Trigger) -->
    <div class="rounded-[16px] card-surface p-5 sm:p-6 space-y-4">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <span class="text-[12px] font-mono meta-mono text-ink-muted">
            Actions manuelles · Workflow 02
          </span>
          <h2 class="mt-0.5 text-[18px] sm:text-[16px] font-medium text-ink-primary">
            Déclenchement manuel du Briefing Matinal
          </h2>
          <p class="mt-1 text-[14px] sm:text-xs text-ink-secondary">
            Agrège Google Calendar, Gmail 24h, Google Tasks (@default), applique les mutations de trackers et publie sur Discord (#briefing)
          </p>
        </div>

        <button
          type="button"
          :disabled="triggeringBriefing"
          class="btn-primary h-12 sm:h-10 rounded-[10px] sm:rounded-[8px] px-5 text-[15px] sm:text-xs font-medium inline-flex items-center justify-center gap-2 shrink-0 disabled:opacity-40"
          @click="handleTriggerBriefing"
        >
          <AppIcon name="play" />
          <span>{{ triggeringBriefing ? 'Exécution en cours…' : 'Lancer le briefing' }}</span>
        </button>
      </div>

      <div
        v-if="briefingFeedback"
        class="rounded-[10px] bg-pastel-sage-tint border border-pastel-sage/40 px-4 py-3 text-[14px] sm:text-xs font-mono meta-mono text-pastel-sage"
      >
        {{ briefingFeedback }}
      </div>

      <div
        v-if="briefingError"
        class="rounded-[10px] bg-pastel-rose-tint border border-pastel-rose/40 px-4 py-3 text-[14px] sm:text-xs font-mono meta-mono text-pastel-rose"
      >
        {{ briefingError }}
      </div>
    </div>

    <!-- 2. System Health & Storage Status -->
    <div class="rounded-[16px] card-surface p-5 sm:p-6 space-y-4">
      <h2 class="text-[17px] sm:text-[15px] font-medium text-ink-primary">
        Santé & Système (/api/health)
      </h2>

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
        <div class="rounded-[12px] bg-graphite-deep border hairline-border p-4">
          <div class="text-[13px] sm:text-xs text-ink-muted">Statut passerelle API</div>
          <div class="mt-1.5 flex items-center gap-2 text-[16px] font-mono meta-mono font-medium text-ink-primary">
            <span
              class="h-2.5 w-2.5 rounded-full"
              :class="healthStatus.status === 'ok' ? 'bg-pastel-sage' : 'bg-pastel-rose'"
            ></span>
            <span>{{ healthStatus.status }}</span>
          </div>
        </div>

        <div class="rounded-[12px] bg-graphite-deep border hairline-border p-4">
          <div class="text-[13px] sm:text-xs text-ink-muted">Volume user_data (LOCK_EX)</div>
          <div class="mt-1.5 text-[16px] font-mono meta-mono font-medium text-ink-primary">
            {{ healthStatus.user_data_readable ? 'Accessible (1000:1000)' : 'Inaccessible' }}
          </div>
        </div>

        <div class="rounded-[12px] bg-graphite-deep border hairline-border p-4">
          <div class="text-[13px] sm:text-xs text-ink-muted">Endpoint API configuré</div>
          <div class="mt-1.5 text-[13px] font-mono meta-mono text-ink-secondary truncate">
            {{ API_BASE_URL }}
          </div>
        </div>
      </div>
    </div>

    <!-- 3. Metrics & Observability (KPIs + Latency Chart) -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
      <div class="rounded-[12px] card-surface p-4 sm:p-5 flex flex-col justify-between min-h-[112px]">
        <div class="text-[13px] sm:text-xs text-ink-muted">Trackers actifs</div>
        <div class="text-[28px] font-medium text-ink-primary figure-tabular my-1">
          {{ trackers.length }}
        </div>
        <div class="text-[12px] font-mono meta-mono text-ink-secondary">
          {{ cycleTrackersCount }} cycles · {{ tableTrackersCount }} tables
        </div>
      </div>

      <div class="rounded-[12px] card-surface p-4 sm:p-5 flex flex-col justify-between min-h-[112px]">
        <div class="text-[13px] sm:text-xs text-ink-muted">Entrées indexées</div>
        <div class="text-[28px] font-medium text-ink-primary figure-tabular my-1">
          {{ totalRecordsCount }}
        </div>
        <div class="text-[12px] font-mono meta-mono text-ink-secondary">
          data_&lt;tracker_id&gt;.json
        </div>
      </div>

      <div class="rounded-[12px] card-surface p-4 sm:p-5 flex flex-col justify-between min-h-[112px]">
        <div class="text-[13px] sm:text-xs text-ink-muted">Règles mémoire</div>
        <div class="text-[28px] font-medium text-ink-primary figure-tabular my-1">
          {{ memoryStats.totalCount }}
        </div>
        <div class="text-[12px] font-mono meta-mono text-ink-secondary">
          {{ memoryStats.morningCount }} perm. · {{ memoryStats.customCount }} scopes
        </div>
      </div>

      <div class="rounded-[12px] card-surface p-4 sm:p-5 flex flex-col justify-between min-h-[112px]">
        <div class="text-[13px] sm:text-xs text-ink-muted">Latence API / n8n</div>
        <div class="text-[28px] font-medium text-ink-primary figure-tabular my-1">
          {{ telemetry.lastLatencyMs !== null ? `${telemetry.lastLatencyMs} ms` : '—' }}
        </div>
        <div class="text-[12px] font-mono meta-mono text-ink-secondary">
          Moyenne : {{ telemetry.avgLatencyMs !== null ? `${telemetry.avgLatencyMs} ms` : '—' }}
        </div>
      </div>
    </div>

    <!-- Latency Histogram -->
    <div class="rounded-[16px] card-surface p-5 sm:p-6 space-y-4">
      <div class="flex items-baseline justify-between">
        <div>
          <div class="text-[13px] sm:text-xs text-ink-muted">Observabilité LLMOps</div>
          <h3 class="mt-0.5 text-[16px] sm:text-[15px] font-medium text-ink-primary">
            Historique de latence des 12 derniers appels
          </h3>
        </div>
        <span class="text-[13px] sm:text-xs font-mono meta-mono text-accent">
          {{ telemetry.lastEndpoint || '/api/trackers' }}
        </span>
      </div>

      <div class="rounded-[10px] bg-graphite-deep border hairline-border p-4 space-y-2">
        <div class="h-[100px] flex items-end gap-2 border-b hairline-border pb-2">
          <div
            v-for="(bar, idx) in latencyBars"
            :key="idx"
            class="flex-1 flex flex-col justify-end h-full"
          >
            <div
              class="w-full rounded-t-[4px] transition-colors duration-150"
              :class="bar.isLatest ? 'bg-accent' : 'bg-graphite-hover'"
              :style="{ height: `${bar.heightPct}%` }"
              :title="`${bar.ms} ms`"
            ></div>
          </div>
        </div>
        <div class="flex items-center justify-between text-[12px] font-mono meta-mono text-ink-muted">
          <span>Échantillons récents (ms)</span>
          <span>Dernier : {{ telemetry.lastLatencyMs ?? 0 }} ms</span>
        </div>
      </div>
    </div>
  </section>
</template>
