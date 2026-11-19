<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { jarvisApi } from '@/services/api'

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

const fieldsSchema = computed(() => {
  if (!tracker.value) return []
  const schema = Array.isArray(tracker.value.fields_schema)
    ? tracker.value.fields_schema
    : []
  if (schema.length > 0) return schema

  // Fallback inference from existing records if fields_schema is empty
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
  return list.filter((rec) =>
    JSON.stringify(rec).toLowerCase().includes(q)
  )
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
    error.value = err.message || 'Impossible de charger ce tracker.'
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
    error.value = err.message || 'Erreur lors de la création.'
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
    error.value = err.message || 'Suppression impossible.'
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
    error.value = err.message || 'Mise à jour du cycle impossible.'
  } finally {
    submitting.value = false
  }
}

watch(() => props.trackerId, fetchDetail)
watch(includeArchive, fetchDetail)
onMounted(fetchDetail)
</script>

<template>
  <section class="rounded-2xl border border-slate-800 bg-slate-900 p-5 space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-800 pb-4">
      <div>
        <div class="flex items-center gap-2">
          <span class="rounded bg-indigo-950 border border-indigo-700/40 px-2 py-0.5 text-xs font-mono text-indigo-300">
            {{ tracker?.type || 'TRACKER' }}
          </span>
          <span class="text-xs font-mono text-slate-400">{{ trackerId }}</span>
        </div>
        <h2 class="mt-1 text-xl font-bold text-white">
          {{ tracker?.display_name || trackerId }}
        </h2>
      </div>

      <div class="flex items-center gap-3">
        <label class="inline-flex items-center gap-2 text-xs text-slate-300 cursor-pointer">
          <input
            v-model="includeArchive"
            type="checkbox"
            class="rounded border-slate-700 bg-slate-800 text-indigo-600 focus:ring-indigo-500"
          />
          Inclure archives 30j+
        </label>
        <button
          type="button"
          class="rounded-lg border border-slate-700 hover:bg-slate-800 px-3 py-1.5 text-xs font-medium text-slate-200"
          @click="emit('close')"
        >
          Fermer
        </button>
      </div>
    </div>

    <div
      v-if="error"
      class="rounded-lg border border-amber-700/50 bg-amber-950/40 px-3 py-2 text-xs text-amber-200"
    >
      {{ error }}
    </div>

    <!-- Cycle state machine controls -->
    <div
      v-if="tracker?.type === 'CYCLE_STATE_MACHINE'"
      class="rounded-xl border border-slate-800 bg-slate-950/70 p-4 space-y-3"
    >
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <span class="text-xs text-slate-400">Position actuelle du cycle</span>
          <div class="text-lg font-semibold text-indigo-300">
            {{ tracker.current_step }}
          </div>
        </div>
        <div class="flex items-center gap-2">
          <button
            type="button"
            :disabled="submitting"
            class="rounded-lg bg-indigo-600 hover:bg-indigo-500 px-3 py-1.5 text-xs font-medium text-white"
            @click="handleCycleMutation('advance')"
          >
            Avancer (+1)
          </button>
          <button
            type="button"
            :disabled="submitting"
            class="rounded-lg bg-slate-800 hover:bg-slate-700 px-3 py-1.5 text-xs font-medium text-slate-200"
            @click="handleCycleMutation('skip')"
          >
            Maintenir aujourd'hui (Skip)
          </button>
        </div>
      </div>

      <div class="flex flex-wrap gap-2 pt-2">
        <button
          v-for="(step, idx) in tracker.cycle_config?.steps || []"
          :key="idx"
          type="button"
          :disabled="submitting"
          class="rounded-lg px-3 py-1.5 text-xs font-medium transition"
          :class="
            idx === Number(tracker.cycle_config?.current_index ?? 0)
              ? 'bg-indigo-600 text-white'
              : 'bg-slate-800/80 text-slate-300 hover:bg-slate-700'
          "
          @click="handleCycleMutation('set', idx)"
        >
          {{ idx + 1 }}. {{ step }}
        </button>
      </div>
    </div>

    <!-- Dynamic Record Creation Form driven by fields_schema -->
    <form
      class="rounded-xl border border-slate-800 bg-slate-950/60 p-4 space-y-3"
      @submit.prevent="handleCreateRecord"
    >
      <div class="text-xs font-semibold uppercase tracking-wider text-slate-400">
        Nouvelle entrée ({{ fieldsSchema.length }} champ(s))
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <div v-for="field in fieldsSchema" :key="field.name">
          <label class="block text-xs font-mono text-slate-300 mb-1">
            {{ field.name }}
            <span v-if="field.required" class="text-indigo-400">*</span>
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
            :placeholder="field.type || 'texte'"
            class="w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-1.5 text-sm text-white focus:border-indigo-500 focus:outline-none"
          />
        </div>
      </div>
      <div class="flex justify-end">
        <button
          type="submit"
          :disabled="submitting"
          class="rounded-lg bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 px-4 py-2 text-xs font-semibold text-white"
        >
          Ajouter l'entrée
        </button>
      </div>
    </form>

    <!-- Filter & Active Records List -->
    <div class="space-y-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-semibold text-slate-200">
          Entrées actives ({{ filteredRecords.length }})
        </h3>
        <input
          v-model="searchQuery"
          type="search"
          placeholder="Filtrer les entrées..."
          class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-1.5 text-xs text-slate-200 focus:border-indigo-500 focus:outline-none"
        />
      </div>

      <div v-if="loading" class="text-xs text-slate-400 py-4">
        Chargement des données...
      </div>

      <div
        v-else-if="filteredRecords.length === 0"
        class="rounded-xl border border-slate-800/80 bg-slate-950/40 p-6 text-center text-xs text-slate-400"
      >
        Aucune entrée enregistrée.
      </div>

      <div v-else class="overflow-x-auto rounded-xl border border-slate-800">
        <table class="min-w-full divide-y divide-slate-800 text-left text-xs">
          <thead class="bg-slate-950 text-slate-400 font-mono">
            <tr>
              <th
                v-for="field in fieldsSchema"
                :key="field.name"
                class="px-3 py-2.5"
              >
                {{ field.name }}
              </th>
              <th class="px-3 py-2.5">Date</th>
              <th class="px-3 py-2.5 text-right">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800/70 bg-slate-900/60">
            <tr v-for="rec in filteredRecords" :key="rec.id">
              <td
                v-for="field in fieldsSchema"
                :key="field.name"
                class="px-3 py-2.5 text-slate-200"
              >
                {{ rec[field.name] ?? '—' }}
              </td>
              <td class="px-3 py-2.5 font-mono text-slate-400">
                {{ (rec.updated_at || rec.created_at || '').slice(0, 16).replace('T', ' ') }}
              </td>
              <td class="px-3 py-2.5 text-right">
                <button
                  type="button"
                  class="text-slate-400 hover:text-indigo-300 font-mono"
                  @click="handleDeleteRecord(rec.id)"
                >
                  Supprimer
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Monthly Archive View if requested -->
    <div
      v-if="includeArchive && Array.isArray(tracker?.archive)"
      class="rounded-xl border border-slate-800 bg-slate-950/60 p-4 space-y-2"
    >
      <h4 class="text-xs font-semibold uppercase tracking-wider text-indigo-300">
        Archives mensuelles compressées ({{ tracker.archive.length }})
      </h4>
      <div v-if="tracker.archive.length === 0" class="text-xs text-slate-400">
        Aucune archive mensuelle disponible.
      </div>
      <pre
        v-else
        class="overflow-x-auto text-xs font-mono text-slate-300 whitespace-pre-wrap"
      >{{ JSON.stringify(tracker.archive, null, 2) }}</pre>
    </div>
  </section>
</template>
