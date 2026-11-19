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

// Apple Direct Manipulation 1:1 Drag & Spring controller for the sheet
const sheetEl = ref(null)
const dragOffsetY = ref(0)
const isDragging = ref(false)
const springY = createSpringController(24)

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
  if (projectedY > 180 && releaseVelocity >= 0) {
    springY.animateTo(420, {
      initialVelocity: releaseVelocity,
      dampingRatio: 1.0,
      response: 0.28,
      onUpdate: (v) => {
        dragOffsetY.value = v
      },
      onComplete: () => {
        emit('close')
      }
    })
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
  dragOffsetY.value = 20
  springY.setImmediate(20)
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
  <section
    ref="sheetEl"
    class="rounded-[16px] bg-graphite-card border hairline-border p-6 space-y-6 will-change-transform"
    :style="{
      transform: `translate3d(0, ${dragOffsetY}px, 0)`
    }"
  >
    <!-- Tactile drag handle & header -->
    <div
      class="cursor-grab active:cursor-grabbing select-none touch-none pb-4 border-b hairline-border"
      @pointerdown="onPointerDown"
      @pointermove="onPointerMove"
      @pointerup="onPointerUp"
      @pointercancel="onPointerUp"
    >
      <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-ink-muted/30"></div>

      <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
          <div class="flex items-center gap-2 text-xs font-mono meta-mono text-ink-muted">
            <span>{{ tracker?.type || 'Tracker' }}</span>
            <span>·</span>
            <span>{{ trackerId }}</span>
          </div>
          <h2 class="mt-1 text-[20px] leading-[26px] font-medium text-ink-primary">
            {{ tracker?.display_name || trackerId }}
          </h2>
        </div>

        <div class="flex items-center gap-3" @pointerdown.stop>
          <label class="inline-flex items-center gap-2 text-xs text-ink-secondary cursor-pointer">
            <input
              v-model="includeArchive"
              type="checkbox"
              class="h-3.5 w-3.5 rounded-[4px] border-ink-muted/40 bg-graphite-deep accent-[#ededed]"
            />
            <span>Archives mensuelles</span>
          </label>

          <button
            type="button"
            class="btn-secondary h-8 rounded-[8px] px-3 text-xs font-medium inline-flex items-center gap-2"
            @click="emit('close')"
          >
            <span>Fermer</span>
            <kbd class="keycap">Esc</kbd>
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
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <span class="text-xs text-ink-muted">Étape active du cycle</span>
          <div class="mt-0.5 text-[18px] leading-[24px] font-medium text-ink-primary flex items-center gap-2">
            <span class="h-2 w-2 rounded-full bg-accent"></span>
            <span>{{ tracker.current_step }}</span>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <button
            type="button"
            :disabled="submitting"
            class="btn-primary h-8 rounded-[8px] px-3 text-xs font-medium inline-flex items-center gap-1.5"
            @click="handleCycleMutation('advance')"
          >
            <span>Avancer (+1)</span>
            <AppIcon name="arrow-right" />
          </button>
          <button
            type="button"
            :disabled="submitting"
            class="btn-secondary h-8 rounded-[8px] px-3 text-xs font-medium inline-flex items-center gap-1.5"
            @click="handleCycleMutation('skip')"
          >
            <AppIcon name="pause" />
            <span>Maintenir aujourd’hui</span>
          </button>
        </div>
      </div>

      <div class="flex flex-wrap gap-1.5 pt-1">
        <button
          v-for="(step, idx) in tracker.cycle_config?.steps || []"
          :key="idx"
          type="button"
          :disabled="submitting"
          class="pressable h-8 rounded-[6px] px-3 text-xs font-medium border"
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

    <!-- Dynamic Record Creation Form driven by fields_schema -->
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
            class="h-9 w-full rounded-[8px] border hairline-border bg-graphite-bg px-3 text-sm text-ink-primary placeholder:text-ink-muted focus:border-[#ededed]/30 focus:outline-none"
          />
        </div>
      </div>

      <div class="flex justify-end">
        <button
          type="submit"
          :disabled="submitting"
          class="btn-primary h-8 rounded-[8px] px-4 text-xs font-medium inline-flex items-center gap-1.5 disabled:opacity-40"
        >
          <AppIcon name="plus" />
          <span>Ajouter l’entrée</span>
        </button>
      </div>
    </form>

    <!-- Active Records Table -->
    <div class="space-y-3">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
          <h3 class="text-sm font-medium text-ink-primary">
            Entrées actives
          </h3>
          <span class="text-xs font-mono meta-mono text-ink-muted">
            {{ filteredRecords.length }}
          </span>
        </div>

        <div class="relative">
          <span class="pointer-events-none absolute inset-y-0 left-2.5 flex items-center text-ink-muted">
            <AppIcon name="search" />
          </span>
          <input
            v-model="searchQuery"
            type="search"
            placeholder="Rechercher"
            class="h-8 rounded-[8px] border hairline-border bg-graphite-deep pl-8 pr-3 text-xs text-ink-primary placeholder:text-ink-muted focus:border-[#ededed]/30 focus:outline-none"
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

      <div v-else class="overflow-x-auto rounded-[12px] border hairline-border">
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
</template>
