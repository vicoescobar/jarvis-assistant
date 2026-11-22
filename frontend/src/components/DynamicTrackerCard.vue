<script setup>
import { computed, ref } from 'vue'
import { jarvisApi } from '@/services/api'
import AppIcon from '@/components/AppIcon.vue'
import CustomTrackerView from '@/components/CustomTrackerView.vue'

const props = defineProps({
  tracker: {
    type: Object,
    required: true
  }
})

const emit = defineEmits(['open', 'updated', 'toast'])

const busy = ref(false)
const statusNote = ref('')
const showStandardOverride = ref(false)

const hasCustomView = computed(() => {
  const cv = props.tracker?.custom_view
  if (!cv || !cv.enabled) return false
  const hasHtml = cv.html && String(cv.html).trim() !== ''
  const hasJs = cv.js && String(cv.js).trim() !== ''
  return Boolean(hasHtml || hasJs)
})

const isRenderingCustomView = computed(() => {
  return hasCustomView.value && !showStandardOverride.value
})

const typeMeta = computed(() => {
  switch (props.tracker.type) {
    case 'CYCLE_STATE_MACHINE':
      return { label: 'Cycle séquentiel', icon: 'cycle' }
    case 'COLLECTION_TABLE':
      return { label: 'Table de suivi', icon: 'table' }
    case 'ACTIVITY_LOG':
      return { label: 'Journal d’activité', icon: 'activity' }
    default:
      return { label: 'Tracker', icon: 'grid' }
  }
})

const cycleSteps = computed(() => {
  return Array.isArray(props.tracker.cycle_config?.steps)
    ? props.tracker.cycle_config.steps
    : []
})

const currentIndex = computed(() => {
  return Number(props.tracker.cycle_config?.current_index ?? 0)
})

async function handleCycleAction(action, targetIndex = null) {
  if (busy.value) return
  busy.value = true
  statusNote.value = ''
  try {
    const normalizedAction =
      action === 'advance_cycle'
        ? 'advance'
        : action === 'skip_or_shift_cycle' || action === 'skip_cycle'
          ? 'skip'
          : action
    const res = await jarvisApi.mutateCycle(
      props.tracker.id,
      normalizedAction,
      targetIndex
    )
    if (normalizedAction === 'advance') {
      statusNote.value = `Étape active : ${res.current_step}`
    } else if (normalizedAction === 'skip') {
      statusNote.value = `Maintenu sur ${res.current_step} jusqu’à demain`
    } else {
      statusNote.value = `Positionné sur ${res.current_step}`
    }
    emit('updated')
  } catch (err) {
    statusNote.value = err.message || 'Action refusée'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <article
    class="rounded-[14px] sm:rounded-[12px] card-surface p-5 flex flex-col justify-between gap-5"
  >
    <div class="space-y-4">
      <!-- Top meta row + optional Custom/Standard View toggle -->
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <div class="flex items-center gap-2 text-[13px] sm:text-xs text-ink-muted">
            <span class="w-5 sm:w-[18px] flex items-center justify-center text-ink-muted">
              <AppIcon :name="typeMeta.icon" />
            </span>
            <span>{{ typeMeta.label }}</span>
            <span class="text-ink-muted/50">·</span>
            <span class="font-mono meta-mono truncate">{{ tracker.id }}</span>
          </div>
          <h3 class="mt-1.5 text-[18px] sm:text-[16px] leading-[24px] sm:leading-[22px] font-medium text-ink-primary text-balance">
            {{ tracker.display_name || tracker.id }}
          </h3>
        </div>

        <div class="flex items-center gap-2 shrink-0">
          <button
            v-if="hasCustomView"
            type="button"
            class="pressable min-h-[36px] rounded-[8px] px-2.5 text-[12px] font-mono meta-mono border transition-colors"
            :class="
              isRenderingCustomView
                ? 'bg-pastel-lavender-tint border-pastel-lavender/40 text-pastel-lavender'
                : 'bg-graphite-deep hairline-border text-ink-muted hover:text-ink-primary'
            "
            :title="
              isRenderingCustomView
                ? 'Basculer vers la vue standard'
                : 'Revenir à la vue personnalisée'
            "
            @click="showStandardOverride = !showStandardOverride"
          >
            {{ isRenderingCustomView ? 'Vue perso' : 'Vue standard' }}
          </button>

          <span
            v-if="tracker.include_in_morning_briefing"
            class="inline-flex items-center gap-1.5 rounded-[6px] bg-pastel-sage-tint px-2 py-0.5 text-[12px] font-mono meta-mono text-pastel-sage"
            title="Inclus dans le briefing de 08:30"
          >
            <span class="h-1.5 w-1.5 rounded-full bg-pastel-sage"></span>
            08:30
          </span>
        </div>
      </div>

      <!-- MODE A: Isolated Sandboxed Micro-App Custom View (<iframe sandbox="allow-scripts">) -->
      <CustomTrackerView
        v-if="isRenderingCustomView"
        :tracker="tracker"
        :records="tracker.recent_records || null"
        :busy="busy"
        @cycle-action="(act) => handleCycleAction(act)"
        @open-detail="emit('open', tracker.id)"
        @refresh-requested="emit('updated')"
        @toast="(msg) => emit('toast', msg)"
        @fallback-standard="showStandardOverride = true"
      />

      <!-- MODE B: Standard Generic Schema-Driven View -->
      <template v-else>
        <!-- Tactile Cycle Step-Sequencer Rail -->
        <div
          v-if="tracker.type === 'CYCLE_STATE_MACHINE'"
          class="rounded-[10px] sm:rounded-[8px] bg-graphite-deep border hairline-border p-4 sm:p-3.5 space-y-3.5"
        >
          <div class="flex items-baseline justify-between gap-2">
            <div class="min-w-0">
              <div class="text-[13px] sm:text-xs text-ink-muted">Étape en cours</div>
              <div class="mt-1 text-[20px] sm:text-[18px] leading-[26px] sm:leading-[24px] font-medium text-ink-primary flex items-center gap-2.5">
                <span class="h-2.5 w-2.5 sm:h-2 sm:w-2 rounded-full bg-pastel-sage shrink-0"></span>
                <span class="truncate">{{ tracker.current_step || cycleSteps[currentIndex] || 'Aucune étape' }}</span>
              </div>
            </div>
            <span
              v-if="tracker.cycle_config?.last_updated"
              class="text-[13px] sm:text-xs font-mono meta-mono text-ink-muted shrink-0"
            >
              {{ tracker.cycle_config.last_updated }}
            </span>
          </div>

          <div
            v-if="cycleSteps.length"
            class="grid grid-cols-2 sm:grid-cols-4 gap-2"
          >
            <div
              v-for="(step, idx) in cycleSteps"
              :key="idx"
              class="min-h-[56px] sm:min-h-[44px] text-left rounded-[8px] sm:rounded-[6px] px-3.5 py-2.5 sm:px-3 sm:py-2 border flex flex-col justify-between"
              :class="
                idx === currentIndex
                  ? 'bg-graphite-card text-ink-primary border-pastel-sage/50 shadow-sm'
                  : 'bg-graphite-bg/70 border-transparent text-ink-muted'
              "
            >
              <div class="flex items-center justify-between text-xs sm:text-[11px] font-mono meta-mono w-full">
                <span>0{{ idx + 1 }}</span>
                <span
                  class="h-2 w-2 sm:h-1.5 sm:w-1.5 rounded-full"
                  :class="idx === currentIndex ? 'bg-pastel-sage' : 'bg-ink-muted/30'"
                ></span>
              </div>
              <div class="mt-1 text-[15px] sm:text-xs font-medium truncate w-full">
                {{ step }}
              </div>
            </div>
          </div>
        </div>

        <!-- COLLECTION_TABLE / ACTIVITY_LOG Readout -->
        <div
          v-else
          class="rounded-[10px] sm:rounded-[8px] bg-graphite-deep border hairline-border p-4 sm:p-3.5 flex items-end justify-between"
        >
          <div>
            <div class="text-[13px] sm:text-xs text-ink-muted">Entrées actives</div>
            <div class="mt-1 text-[32px] sm:text-[28px] font-medium text-ink-primary figure-tabular">
              {{ tracker.record_count ?? 0 }}
            </div>
          </div>
          <div class="text-right">
            <div class="text-[13px] sm:text-xs text-ink-muted">Champs définis</div>
            <div class="mt-0.5 text-[14px] sm:text-xs font-mono meta-mono text-ink-secondary">
              {{ (tracker.fields_schema || []).length }} colonnes
            </div>
          </div>
        </div>
      </template>

      <p
        v-if="statusNote"
        class="text-[14px] sm:text-xs font-mono meta-mono text-ink-secondary"
      >
        {{ statusNote }}
      </p>
    </div>

    <!-- Bottom controls (only for COLLECTION_TABLE / ACTIVITY_LOG in standard view; CYCLE_STATE_MACHINE default view stays purely informative) -->
    <div
      v-if="tracker.type !== 'CYCLE_STATE_MACHINE' && !isRenderingCustomView"
      class="flex items-center gap-2.5 pt-3.5 border-t hairline-border"
    >
      <button
        type="button"
        class="btn-secondary h-12 sm:h-9 w-full rounded-[10px] sm:rounded-[8px] px-4 text-[15px] sm:text-xs font-medium inline-flex items-center justify-between"
        @click="emit('open', tracker.id)"
      >
        <span>Inspecter et ajouter des entrées</span>
        <span class="font-mono meta-mono text-ink-muted">{{ tracker.record_count ?? 0 }}</span>
      </button>
    </div>
  </article>
</template>
