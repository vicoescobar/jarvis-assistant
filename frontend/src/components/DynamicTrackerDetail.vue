<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { jarvisApi } from '@/services/api'
import AppIcon from '@/components/AppIcon.vue'
import { createSpringController, project, rubberband } from '@/utils/spring'

const props = defineProps({
  trackerId: {
    type: String,
    required: true
  }
})

const emit = defineEmits(['close', 'updated'])

const tracker = ref(null)
const loading = ref(false)
const includeArchive = ref(false)
const searchQuery = ref('')
const error = ref('')
const submitting = ref(false)
const formValues = reactive({})

// Apple Direct Manipulation 1:1 Drag & Spring controller for the mobile bottom sheet / desktop panel
const sheetEl = ref(null)
const dragOffsetY = ref(0)
const isDragging = ref(false)
const springY = createSpringController(32)

let pointerStartY = 0
let initialSheetY = 0
let velocityHistory = []

function onPointerDown(e) {
  if (e.button && e.button !== 0) return
  const target = e.currentTarget
  target.setPointerCapture(e.pointerId)
  springY.stop()
  isDragging.value = true
  pointerStartY = e.clientY
  initialSheetY = dragOffsetY.value
  velocityHistory = [{ y: e.clientY, t: performance.now() }]
}

function onPointerMove(e) {
  if (!isDragging.value) return
  const now = performance.now()
  velocityHistory.push({ y: e.clientY, t: now })
  if (velocityHistory.length > 6) {
    velocityHistory.shift()
  }

  const deltaY = e.clientY - pointerStartY
  const rawY = initialSheetY + deltaY

  // Apple Rubber-banding when pulling upward past the top resting bound (0)
  if (rawY < 0) {
    dragOffsetY.value = -rubberband(Math.abs(rawY), 240, 0.55)
  } else {
    dragOffsetY.value = rawY
  }
  springY.setImmediate(dragOffsetY.value)
}

function computeReleaseVelocity() {
  if (velocityHistory.length < 2) return 0
  const first = velocityHistory[0]
  const last = velocityHistory[velocityHistory.length - 1]
  const dt = (last.t - first.t) / 1000
  if (dt <= 0.001) return 0
  return (last.y - first.y) / dt
}

function onPointerUp() {
  if (!isDragging.value) return
  isDragging.value = false

  const releaseVelocity = computeReleaseVelocity()
  const projectedY = dragOffsetY.value + project(releaseVelocity, 0.992)

  // Symmetric exit along the same path if flicked downward
  if (projectedY > 140 && releaseVelocity >= 0) {
    dismissSheet(releaseVelocity)
  } else {
    springY.animateTo(0, {
      initialVelocity: releaseVelocity,
      dampingRatio: 0.86,
      response: 0.34,
      onUpdate: (v) => {
        dragOffsetY.value = v
      }
    })
  }
}

function dismissSheet(initialVel = 0) {
  springY.animateTo(480, {
    initialVelocity: initialVel,
    dampingRatio: 1.0,
    response: 0.26,
    onUpdate: (v) => {
      dragOffsetY.value = v
    },
    onComplete: () => {
      emit('close')
    }
  })
}

const fieldsSchema = computed(() => {
  if (!tracker.value) return []
  const schema = Array.isArray(tracker.value.fields_schema)
    ? tracker.value.fields_schema
    : []
  if (schema.length > 0) return schema

  const first = (tracker.value.records || [])[0]
  if (!first) {
    return [{ name: 'note', type: 'string', required: true }]
  }
  return Object.keys(first)
    .filter((k) => !['id', 'created_at', 'updated_at'].includes(k))
    .map((k) => ({ name: k, type: 'string', required: false }))
})

const filteredRecords = computed(() => {
  const list = Array.isArray(tracker.value?.records)
    ? [...tracker.value.records].reverse()
    : []
  const q = searchQuery.value.trim().toLowerCase()
  if (!q) return list
  return list.filter((rec) => JSON.stringify(rec).toLowerCase().includes(q))
})

function resetForm() {
  Object.keys(formValues).forEach((k) => delete formValues[k])
  for (const field of fieldsSchema.value) {
    if (field.type === 'date') {
      formValues[field.name] = new Date().toISOString().slice(0, 10)
    } else {
      formValues[field.name] = ''
    }
  }
}

async function fetchDetail() {
  loading.value = true
  error.value = ''
  try {
    tracker.value = await jarvisApi.getTracker(
      props.trackerId,
      includeArchive.value
    )
    resetForm()
  } catch (err) {
    error.value = err.message || 'Impossible de charger ce tracker'
  } finally {
    loading.value = false
  }
}

async function handleCreateRecord() {
  if (submitting.value) return
  submitting.value = true
  error.value = ''
  try {
    const payload = {}
    for (const field of fieldsSchema.value) {
      const raw = formValues[field.name]
      if (field.type === 'number' && raw !== '' && raw !== null) {
        payload[field.name] = Number(raw)
      } else {
        payload[field.name] = raw
      }
    }
    await jarvisApi.createRecord(props.trackerId, payload)
    await fetchDetail()
    emit('updated')
  } catch (err) {
    error.value = err.message || 'Création refusée'
  } finally {
    submitting.value = false
  }
}

async function handleDeleteRecord(recordId) {
  error.value = ''
  try {
    await jarvisApi.deleteRecord(props.trackerId, recordId)
    await fetchDetail()
    emit('updated')
  } catch (err) {
    error.value = err.message || 'Suppression refusée'
  }
}

async function handleCycleMutation(action, targetIndex = null) {
  submitting.value = true
  error.value = ''
  try {
    await jarvisApi.mutateCycle(props.trackerId, action, targetIndex)
    await fetchDetail()
    emit('updated')
  } catch (err) {
    error.value = err.message || 'Mise à jour du cycle refusée'
  } finally {
    submitting.value = false
  }
}

function handleKeydown(e) {
  if (e.key === 'Escape') {
    emit('close')
  }
}

watch(() => props.trackerId, fetchDetail)
watch(includeArchive, fetchDetail)

onMounted(() => {
  window.addEventListener('keydown', handleKeydown)
  dragOffsetY.value = 32
  springY.setImmediate(32)
  springY.animateTo(0, {
    dampingRatio: 1.0,
    response: 0.32,
    onUpdate: (v) => {
      dragOffsetY.value = v
    }
  })
  fetchDetail()
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', handleKeydown)
  springY.stop()
})
</script>

<template>
  <!-- Mobile Scrim + Bottom Sheet on < md, Inline Panel on md+ -->
  <div class="fixed inset-0 z-40 flex items-end md:static md:block md:z-auto">
    <!-- Dimming scrim on mobile (Apple Design §12: "Dim to focus") -->
    <div
      class="fixed inset-0 bg-black/65 md:hidden"
      @click="dismissSheet()"
    ></div>

    <section
      ref="sheetEl"
      class="relative z-10 w-full max-h-[88dvh] overflow-y-auto rounded-t-[20px] md:max-h-none md:overflow-visible md:rounded-[16px] bg-graphite-card border hairline-border p-4 sm:p-6 space-y-5 safe-bottom will-change-transform"
      :style="{
        transform: `translate3d(0, ${dragOffsetY}px, 0)`
      }"
    >
      <!-- Tactile drag handle & header -->
      <div
        class="cursor-grab active:cursor-grabbing select-none touch-none pb-3.5 border-b hairline-border sticky top-0 bg-graphite-card z-10 -mx-4 px-4 sm:static sm:mx-0 sm:px-0"
        @pointerdown="onPointerDown"
        @pointermove="onPointerMove"
        @pointerup="onPointerUp"
        @pointercancel="onPointerUp"
      >
        <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-ink-muted/30"></div>

        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="min-w-0">
            <div class="flex items-center gap-2 text-xs font-mono meta-mono text-ink-muted">
              <span>{{ tracker?.type || 'Tracker' }}</span>
              <span>·</span>
              <span class="truncate">{{ trackerId }}</span>
            </div>
            <h2 class="mt-1 text-[18px] sm:text-[20px] leading-[24px] sm:leading-[26px] font-medium text-ink-primary truncate">
              {{ tracker?.display_name || trackerId }}
            </h2>
          </div>

          <div class="flex items-center gap-2 sm:gap-3" @pointerdown.stop>
            <label class="inline-flex items-center gap-2 text-xs text-ink-secondary cursor-pointer">
              <input
                v-model="includeArchive"
                type="checkbox"
                class="h-4 w-4 sm:h-3.5 sm:w-3.5 rounded-[4px] border-ink-muted/40 bg-graphite-deep accent-[#ededed]"
              />
              <span>Archives</span>
            </label>

            <button
              type="button"
              class="btn-secondary h-9 sm:h-8 rounded-[8px] px-3 text-xs font-medium inline-flex items-center gap-2"
              @click="emit('close')"
            >
              <span>Fermer</span>
              <kbd class="keycap hidden sm:inline-flex">Esc</kbd>
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="error"
        class="rounded-[8px] bg-graphite-deep border border-semantic-negative/40 px-3.5 py-2.5 text-xs text-semantic-negative"
      >
        {{ error }}
      </div>

      <!-- Cycle state machine controls -->
      <div
        v-if="tracker?.type === 'CYCLE_STATE_MACHINE'"
        class="rounded-[12px] bg-graphite-deep border hairline-border p-4 space-y-3"
      >
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div>
            <span class="text-xs text-ink-muted">Étape active du cycle</span>
            <div class="mt-0.5 text-[18px] leading-[24px] font-medium text-ink-primary flex items-center gap-2">
              <span class="h-2 w-2 rounded-full bg-accent"></span>
              <span>{{ tracker.current_step }}</span>
            </div>
          </div>
          <div class="grid grid-cols-2 sm:flex items-center gap-2">
            <button
              type="button"
              :disabled="submitting"
              class="btn-primary h-10 sm:h-8 rounded-[8px] px-3 text-xs font-medium inline-flex items-center justify-center gap-1.5"
              @click="handleCycleMutation('advance')"
            >
              <span>Avancer (+1)</span>
              <AppIcon name="arrow-right" />
            </button>
            <button
              type="button"
              :disabled="submitting"
              class="btn-secondary h-10 sm:h-8 rounded-[8px] px-3 text-xs font-medium inline-flex items-center justify-center gap-1.5"
              @click="handleCycleMutation('skip')"
            >
              <AppIcon name="pause" />
              <span>Maintenir</span>
            </button>
          </div>
        </div>

        <div class="grid grid-cols-2 sm:flex sm:flex-wrap gap-1.5 pt-1">
          <button
            v-for="(step, idx) in tracker.cycle_config?.steps || []"
            :key="idx"
            type="button"
            :disabled="submitting"
            class="pressable h-10 sm:h-8 rounded-[6px] px-3 text-xs font-medium border text-left sm:text-center truncate"
            :class="
              idx === Number(tracker.cycle_config?.current_index ?? 0)
                ? 'surface-raised text-ink-primary'
                : 'bg-graphite-bg border-transparent text-ink-muted hover:text-ink-primary'
            "
            @click="handleCycleMutation('set', idx)"
          >
            <span class="font-mono meta-mono mr-1.5 text-ink-muted">0{{ idx + 1 }}</span>
            <span>{{ step }}</span>
          </button>
        </div>
      </div>

      <!-- Dynamic Record Creation Form driven by fields_schema (16px text on mobile to prevent Android/iOS input zoom) -->
      <form
        class="rounded-[12px] bg-graphite-deep border hairline-border p-4 space-y-4"
        @submit.prevent="handleCreateRecord"
      >
        <div class="flex items-center justify-between">
          <span class="text-xs font-medium text-ink-secondary">
            Nouvelle entrée
          </span>
          <span class="text-xs font-mono meta-mono text-ink-muted">
            {{ fieldsSchema.length }} champs
          </span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
          <div v-for="field in fieldsSchema" :key="field.name">
            <label class="block text-xs font-mono meta-mono text-ink-secondary mb-1.5">
              {{ field.name }}
              <span v-if="field.required" class="text-accent">*</span>
            </label>
            <input
              v-model="formValues[field.name]"
              :type="
                field.type === 'number'
                  ? 'number'
                  : field.type === 'date'
                    ? 'date'
                    : 'text'
              "
              :required="Boolean(field.required)"
              :placeholder="field.name"
              class="h-10 sm:h-9 w-full rounded-[8px] border hairline-border bg-graphite-bg px-3 text-[16px] sm:text-sm text-ink-primary placeholder:text-ink-muted focus:border-[#ededed]/30 focus:outline-none"
            />
          </div>
        </div>

        <div class="flex justify-end">
          <button
            type="submit"
            :disabled="submitting"
            class="btn-primary h-10 sm:h-8 w-full sm:w-auto rounded-[8px] px-4 text-xs font-medium inline-flex items-center justify-center gap-1.5 disabled:opacity-40"
          >
            <AppIcon name="plus" />
            <span>Ajouter l’entrée</span>
          </button>
        </div>
      </form>

      <!-- Active Records List: Stacked Cards on Mobile (< sm), Table on Desktop (sm+) -->
      <div class="space-y-3">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5">
          <div class="flex items-center gap-2">
            <h3 class="text-sm font-medium text-ink-primary">
              Entrées actives
            </h3>
            <span class="text-xs font-mono meta-mono text-ink-muted">
              {{ filteredRecords.length }}
            </span>
          </div>

          <div class="relative w-full sm:w-64">
            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-ink-muted">
              <AppIcon name="search" />
            </span>
            <input
              v-model="searchQuery"
              type="search"
              placeholder="Rechercher"
              class="h-10 sm:h-8 w-full rounded-[8px] border hairline-border bg-graphite-deep pl-9 pr-3 text-[16px] sm:text-xs text-ink-primary placeholder:text-ink-muted focus:border-[#ededed]/30 focus:outline-none"
            />
          </div>
        </div>

        <div v-if="loading" class="text-xs text-ink-muted py-4">
          Lecture de data_{{ trackerId }}.json
        </div>

        <div
          v-else-if="filteredRecords.length === 0"
          class="rounded-[12px] border hairline-border bg-graphite-deep/50 p-6 text-center text-xs text-ink-muted"
        >
          Aucune entrée pour ce filtre
        </div>

        <template v-else>
          <!-- Mobile Stacked Cards (< 640px, unslop §6: simpler on mobile instead of zoomed-down table) -->
          <div class="space-y-2 sm:hidden">
            <div
              v-for="rec in filteredRecords"
              :key="rec.id"
              class="rounded-[10px] bg-graphite-deep border hairline-border p-3.5 space-y-2"
            >
              <div
                v-for="field in fieldsSchema"
                :key="field.name"
                class="flex items-baseline justify-between gap-3 text-xs"
              >
                <span class="font-mono meta-mono text-ink-muted shrink-0">
                  {{ field.name }}
                </span>
                <span
                  class="text-ink-primary text-right break-words"
                  :class="field.type === 'number' ? 'font-mono meta-mono' : ''"
                >
                  {{ rec[field.name] ?? '—' }}
                </span>
              </div>

              <div class="pt-2 border-t hairline-border flex items-center justify-between text-xs font-mono meta-mono text-ink-muted">
                <span>{{ (rec.updated_at || rec.created_at || '').slice(0, 16).replace('T', ' ') }}</span>
                <button
                  type="button"
                  class="pressable h-8 px-2.5 rounded-[6px] bg-graphite-raised text-ink-secondary hover:text-semantic-negative inline-flex items-center gap-1.5"
                  @click="handleDeleteRecord(rec.id)"
                >
                  <AppIcon name="trash" />
                  <span>Supprimer</span>
                </button>
              </div>
            </div>
          </div>

          <!-- Desktop Aligned Table (sm+) -->
          <div class="hidden sm:block overflow-x-auto rounded-[12px] border hairline-border">
            <table class="min-w-full divide-y divide-[#ededed]/[0.06] text-left text-xs">
              <thead class="bg-graphite-deep text-ink-muted font-mono meta-mono">
                <tr>
                  <th
                    v-for="field in fieldsSchema"
                    :key="field.name"
                    class="px-3.5 py-2.5 font-normal"
                  >
                    {{ field.name }}
                  </th>
                  <th class="px-3.5 py-2.5 font-normal text-right">Mise à jour</th>
                  <th class="px-3.5 py-2.5 font-normal text-right">Action</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-[#ededed]/[0.06] bg-graphite-card">
                <tr
                  v-for="rec in filteredRecords"
                  :key="rec.id"
                  class="hover:bg-graphite-raised/50 transition-colors duration-150"
                >
                  <td
                    v-for="field in fieldsSchema"
                    :key="field.name"
                    class="px-3.5 py-2.5 text-ink-primary"
                    :class="field.type === 'number' ? 'font-mono meta-mono text-right' : ''"
                  >
                    {{ rec[field.name] ?? '—' }}
                  </td>
                  <td class="px-3.5 py-2.5 font-mono meta-mono text-ink-muted text-right">
                    {{ (rec.updated_at || rec.created_at || '').slice(0, 16).replace('T', ' ') }}
                  </td>
                  <td class="px-3.5 py-2.5 text-right">
                    <button
                      type="button"
                      class="pressable inline-flex items-center gap-1 text-ink-muted hover:text-semantic-negative font-mono meta-mono"
                      @click="handleDeleteRecord(rec.id)"
                    >
                      <AppIcon name="trash" />
                      <span>Supprimer</span>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </div>

      <!-- Monthly Archive View -->
      <div
        v-if="includeArchive && Array.isArray(tracker?.archive)"
        class="rounded-[12px] border hairline-border bg-graphite-deep p-4 space-y-2"
      >
        <div class="flex items-center justify-between">
          <h4 class="text-xs font-medium text-ink-secondary">
            Archives mensuelles (archive_{{ trackerId }}.json)
          </h4>
          <span class="text-xs font-mono meta-mono text-ink-muted">
            {{ tracker.archive.length }}
          </span>
        </div>
        <div v-if="tracker.archive.length === 0" class="text-xs text-ink-muted">
          Aucune archive enregistrée
        </div>
        <pre
          v-else
          class="overflow-x-auto text-xs font-mono meta-mono text-ink-secondary whitespace-pre-wrap"
        >{{ JSON.stringify(tracker.archive, null, 2) }}</pre>
      </div>
    </section>
  </div>
</template>
