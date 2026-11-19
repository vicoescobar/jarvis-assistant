<script setup>
import { computed, ref } from 'vue'
import { jarvisApi } from '@/services/api'

const props = defineProps({
  tracker: {
    type: Object,
    required: true
  }
})

const emit = defineEmits(['open', 'updated'])

const busy = ref(false)
const feedback = ref('')

const typeLabel = computed(() => {
  switch (props.tracker.type) {
    case 'CYCLE_STATE_MACHINE':
      return 'Cycle'
    case 'COLLECTION_TABLE':
      return 'Table'
    case 'ACTIVITY_LOG':
      return 'Journal'
    default:
      return props.tracker.type || 'Tracker'
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

async function handleCycleAction(action) {
  if (busy.value) return
  busy.value = true
  feedback.value = ''
  try {
    const res = await jarvisApi.mutateCycle(props.tracker.id, action)
    feedback.value =
      action === 'advance'
        ? `Étape active : ${res.current_step}`
        : `Étape maintenue (${res.current_step}) — verrou actif jusqu'à demain`
    emit('updated')
  } catch (err) {
    feedback.value = err.message || 'Action impossible.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <article
    class="rounded-xl border border-slate-800 bg-slate-900/80 p-5 flex flex-col justify-between gap-4 hover:border-indigo-500/50 transition"
  >
    <div>
      <div class="flex items-start justify-between gap-2">
        <div>
          <span
            class="inline-flex items-center rounded-md bg-slate-800 px-2 py-0.5 text-xs font-mono text-indigo-300"
          >
            {{ typeLabel }}
          </span>
          <h3 class="mt-2 text-lg font-semibold text-white">
            {{ tracker.display_name || tracker.id }}
          </h3>
          <p class="text-xs font-mono text-slate-400">ID: {{ tracker.id }}</p>
        </div>

        <span
          v-if="tracker.include_in_morning_briefing"
          class="rounded-full bg-indigo-950/80 border border-indigo-700/40 px-2.5 py-0.5 text-xs text-indigo-300"
        >
          Briefing 08:30
        </span>
      </div>

      <!-- CYCLE_STATE_MACHINE summary -->
      <div
        v-if="tracker.type === 'CYCLE_STATE_MACHINE'"
        class="mt-4 rounded-lg bg-slate-950/70 border border-slate-800 p-3"
      >
        <div class="flex items-center justify-between text-xs text-slate-400">
          <span>Étape active</span>
          <span v-if="tracker.cycle_config?.last_updated">
            MAJ {{ tracker.cycle_config.last_updated }}
          </span>
        </div>
        <div class="mt-1 text-base font-semibold text-indigo-300">
          {{ tracker.current_step || cycleSteps[currentIndex] || 'Non défini' }}
        </div>

        <div
          v-if="cycleSteps.length"
          class="mt-2.5 flex flex-wrap items-center gap-1.5"
        >
          <span
            v-for="(step, idx) in cycleSteps"
            :key="idx"
            class="rounded px-2 py-0.5 text-xs transition"
            :class="
              idx === currentIndex
                ? 'bg-indigo-600 text-white font-medium'
                : 'bg-slate-800/80 text-slate-400'
            "
          >
            {{ idx + 1 }}. {{ step }}
          </span>
        </div>
      </div>

      <!-- COLLECTION_TABLE / ACTIVITY_LOG summary -->
      <div
        v-else
        class="mt-4 rounded-lg bg-slate-950/70 border border-slate-800 p-3 flex items-center justify-between"
      >
        <div>
          <div class="text-xs text-slate-400">Entrées actives</div>
          <div class="text-xl font-bold text-white">
            {{ tracker.record_count ?? 0 }}
          </div>
        </div>
        <div class="text-right">
          <div class="text-xs text-slate-400">Champs schéma</div>
          <div class="text-xs font-mono text-slate-300">
            {{ (tracker.fields_schema || []).length }} champ(s)
          </div>
        </div>
      </div>

      <p
        v-if="feedback"
        class="mt-2.5 text-xs text-indigo-300 bg-indigo-950/50 border border-indigo-800/40 rounded px-2.5 py-1.5"
      >
        {{ feedback }}
      </p>
    </div>

    <div class="flex flex-wrap items-center gap-2 pt-2 border-t border-slate-800/80">
      <template v-if="tracker.type === 'CYCLE_STATE_MACHINE'">
        <button
          type="button"
          :disabled="busy"
          class="flex-1 rounded-lg bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 px-3 py-2 text-xs font-medium text-white transition"
          @click="handleCycleAction('advance')"
        >
          Valider & Avancer
        </button>
        <button
          type="button"
          :disabled="busy"
          class="rounded-lg bg-slate-800 hover:bg-slate-700 disabled:opacity-50 px-3 py-2 text-xs font-medium text-slate-200 transition"
          @click="handleCycleAction('skip')"
        >
          Maintenir (Skip)
        </button>
      </template>

      <button
        type="button"
        class="rounded-lg border border-slate-700 hover:border-indigo-500/60 bg-slate-900 px-3 py-2 text-xs font-medium text-slate-200 transition"
        :class="tracker.type !== 'CYCLE_STATE_MACHINE' ? 'w-full' : ''"
        @click="emit('open', tracker.id)"
      >
        Ouvrir les données ({{ tracker.record_count ?? 0 }})
      </button>
    </div>
  </article>
</template>
