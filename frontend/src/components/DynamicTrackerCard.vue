<script setup>
import { computed, ref } from 'vue'
import { jarvisApi } from '@/services/api'
import AppIcon from '@/components/AppIcon.vue'

const props = defineProps({
  tracker: {
    type: Object,
    required: true
  }
})

const emit = defineEmits(['open', 'updated'])

const busy = ref(false)
const statusNote = ref('')

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
    const res = await jarvisApi.mutateCycle(props.tracker.id, action, targetIndex)
    if (action === 'advance') {
      statusNote.value = `Étape active : ${res.current_step}`
    } else if (action === 'skip') {
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
    class="rounded-[12px] bg-graphite-card border hairline-border p-4 sm:p-5 flex flex-col justify-between gap-4 sm:gap-5"
  >
    <div class="space-y-4">
      <!-- Top meta row -->
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <div class="flex items-center gap-2 text-xs text-ink-muted">
            <span class="w-[18px] flex items-center justify-center text-ink-muted">
              <AppIcon :name="typeMeta.icon" />
            </span>
            <span>{{ typeMeta.label }}</span>
            <span class="text-ink-muted/50">·</span>
            <span class="font-mono meta-mono truncate">{{ tracker.id }}</span>
          </div>
          <h3 class="mt-1.5 text-[16px] leading-[22px] font-medium text-ink-primary truncate">
            {{ tracker.display_name || tracker.id }}
          </h3>
        </div>

        <div class="flex items-center gap-2 shrink-0">
          <span
            v-if="tracker.include_in_morning_briefing"
            class="inline-flex items-center gap-1.5 text-xs font-mono meta-mono text-ink-muted"
            title="Inclus dans le briefing de 08:30"
          >
            <span class="h-1.5 w-1.5 rounded-full bg-semantic-positive"></span>
            08:30
          </span>
        </div>
      </div>

      <!-- MOMENT 1: Tactile Cycle Step-Sequencer Rail (2 cols on Android mobile, up to 4 on desktop) -->
      <div
        v-if="tracker.type === 'CYCLE_STATE_MACHINE'"
        class="rounded-[8px] bg-graphite-deep border hairline-border p-3.5 space-y-3"
      >
        <div class="flex items-baseline justify-between gap-2">
          <div class="min-w-0">
            <div class="text-xs text-ink-muted">Étape en cours</div>
            <div class="mt-0.5 text-[17px] sm:text-[18px] leading-[24px] font-medium text-ink-primary flex items-center gap-2">
              <span class="h-2 w-2 rounded-full bg-accent shrink-0"></span>
              <span class="truncate">{{ tracker.current_step || cycleSteps[currentIndex] || 'Aucune étape' }}</span>
            </div>
          </div>
          <span
            v-if="tracker.cycle_config?.last_updated"
            class="text-xs font-mono meta-mono text-ink-muted shrink-0"
          >
            {{ tracker.cycle_config.last_updated }}
          </span>
        </div>

        <!-- Physical Step Rail: 2 columns on mobile for comfortable thumb targets, 4 on sm+ -->
        <div
          v-if="cycleSteps.length"
          class="grid grid-cols-2 sm:grid-cols-4 gap-2"
        >
          <button
            v-for="(step, idx) in cycleSteps"
            :key="idx"
            type="button"
            :disabled="busy"
            class="pressable min-h-[48px] sm:min-h-[44px] text-left rounded-[6px] px-3 py-2 border flex flex-col justify-between"
            :class="
              idx === currentIndex
                ? 'surface-raised text-ink-primary border-[#ededed]/20'
                : 'bg-graphite-bg/70 border-transparent text-ink-muted hover:text-ink-secondary hover:bg-graphite-raised/50'
            "
            @click="handleCycleAction('set', idx)"
          >
            <div class="flex items-center justify-between text-[11px] font-mono meta-mono w-full">
              <span>0{{ idx + 1 }}</span>
              <span
                class="h-1.5 w-1.5 rounded-full"
                :class="idx === currentIndex ? 'bg-accent' : 'bg-ink-muted/30'"
              ></span>
            </div>
            <div class="mt-1 text-xs font-medium truncate w-full">
              {{ step }}
            </div>
          </button>
        </div>
      </div>

      <!-- COLLECTION_TABLE / ACTIVITY_LOG Readout -->
      <div
        v-else
        class="rounded-[8px] bg-graphite-deep border hairline-border p-3.5 flex items-end justify-between"
      >
        <div>
          <div class="text-xs text-ink-muted">Entrées actives</div>
          <div class="mt-1 text-[28px] font-medium text-ink-primary figure-tabular">
            {{ tracker.record_count ?? 0 }}
          </div>
        </div>
        <div class="text-right">
          <div class="text-xs text-ink-muted">Champs définis</div>
          <div class="mt-0.5 text-xs font-mono meta-mono text-ink-secondary">
            {{ (tracker.fields_schema || []).length }} colonnes
          </div>
        </div>
      </div>

      <p
        v-if="statusNote"
        class="text-xs font-mono meta-mono text-ink-secondary"
      >
        {{ statusNote }}
      </p>
    </div>

    <!-- Bottom controls: 40px touch height on mobile, 32px on desktop -->
    <div class="flex items-center gap-2 pt-3 border-t hairline-border">
      <template v-if="tracker.type === 'CYCLE_STATE_MACHINE'">
        <button
          type="button"
          :disabled="busy"
          class="btn-primary h-10 sm:h-8 flex-1 rounded-[8px] px-3 text-xs font-medium inline-flex items-center justify-center gap-2 disabled:opacity-40"
          @click="handleCycleAction('advance')"
        >
          <span>Avancer</span>
          <AppIcon name="arrow-right" />
        </button>
        <button
          type="button"
          :disabled="busy"
          class="btn-secondary h-10 sm:h-8 rounded-[8px] px-3 text-xs font-medium inline-flex items-center justify-center gap-1.5 disabled:opacity-40"
          title="Maintenir cette étape et verrouiller jusqu'au prochain briefing"
          @click="handleCycleAction('skip')"
        >
          <AppIcon name="pause" />
          <span>Maintenir</span>
        </button>
        <button
          type="button"
          class="btn-secondary h-10 sm:h-8 min-w-[40px] sm:min-w-[32px] rounded-[8px] px-2.5 text-xs font-mono meta-mono text-ink-secondary"
          title="Inspecter l'historique"
          @click="emit('open', tracker.id)"
        >
          {{ tracker.record_count ?? 0 }}
        </button>
      </template>

      <button
        v-else
        type="button"
        class="btn-secondary h-10 sm:h-8 w-full rounded-[8px] px-3.5 text-xs font-medium inline-flex items-center justify-between"
        @click="emit('open', tracker.id)"
      >
        <span>Inspecter et ajouter des entrées</span>
        <span class="font-mono meta-mono text-ink-muted">{{ tracker.record_count ?? 0 }}</span>
      </button>
    </div>
  </article>
</template>
