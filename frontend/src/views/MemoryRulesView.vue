<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { jarvisApi } from '@/services/api'

const emit = defineEmits(['updated'])

const morningRules = ref([])
const customScopes = ref({})
const loading = ref(false)
const submitting = ref(false)
const error = ref('')

const form = reactive({
  scope: 'morning_rules',
  customScopeName: '',
  instruction: '',
  volatile: false,
  expires_at: ''
})

const availableScopes = computed(() => {
  const keys = Object.keys(customScopes.value || {})
  return ['morning_rules', ...keys]
})

async function fetchMemory() {
  loading.value = true
  error.value = ''
  try {
    const data = await jarvisApi.getMemory()
    morningRules.value = Array.isArray(data?.morning_rules)
      ? data.morning_rules
      : []
    customScopes.value =
      data?.custom_scopes && typeof data.custom_scopes === 'object'
        ? data.custom_scopes
        : {}
  } catch (err) {
    error.value = err.message || 'Impossible de charger les règles mémoire.'
  } finally {
    loading.value = false
  }
}

async function handleCreateRule() {
  const targetScope =
    form.scope === '__custom__'
      ? form.customScopeName.trim().toLowerCase().replace(/[^a-z0-9_]/g, '_')
      : form.scope

  if (!targetScope || !form.instruction.trim() || submitting.value) return

  submitting.value = true
  error.value = ''
  try {
    await jarvisApi.createMemoryRule({
      scope: targetScope,
      instruction: form.instruction.trim(),
      volatile: Boolean(form.volatile),
      expires_at: form.volatile && form.expires_at ? form.expires_at : null
    })
    form.instruction = ''
    form.volatile = false
    form.expires_at = ''
    form.customScopeName = ''
    form.scope = targetScope
    await fetchMemory()
    emit('updated')
  } catch (err) {
    error.value = err.message || 'Création de la règle impossible.'
  } finally {
    submitting.value = false
  }
}

async function handleDeleteRule(ruleId) {
  error.value = ''
  try {
    await jarvisApi.deleteMemoryRule(ruleId)
    await fetchMemory()
    emit('updated')
  } catch (err) {
    error.value = err.message || 'Suppression impossible.'
  }
}

onMounted(fetchMemory)
</script>

<template>
  <section class="space-y-6">
    <!-- New Rule Form -->
    <form
      class="rounded-2xl border border-slate-800 bg-slate-900/80 p-5 space-y-4"
      @submit.prevent="handleCreateRule"
    >
      <div class="flex items-center justify-between">
        <div>
          <h2 class="text-base font-semibold text-white">
            Ajouter une Règle Mémoire (<code class="font-mono text-indigo-300">memory_rules.json</code>)
          </h2>
          <p class="text-xs text-slate-400">
            Injectée dynamiquement dans le briefing 08:30 et l'agent vocal. Les règles volatiles expirées sont purgées automatiquement.
          </p>
        </div>
      </div>

      <div
        v-if="error"
        class="rounded-lg border border-amber-700/50 bg-amber-950/40 px-3 py-2 text-xs text-amber-200"
      >
        {{ error }}
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div>
          <label class="block text-xs font-mono text-slate-300 mb-1">Scope</label>
          <select
            v-model="form.scope"
            class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-xs text-white focus:border-indigo-500 focus:outline-none"
          >
            <option
              v-for="sc in availableScopes"
              :key="sc"
              :value="sc"
            >
              {{ sc }}
            </option>
            <option value="__custom__">+ Nouveau scope snake_case...</option>
          </select>
        </div>

        <div v-if="form.scope === '__custom__'">
          <label class="block text-xs font-mono text-slate-300 mb-1">
            Nom du scope (snake_case)
          </label>
          <input
            v-model="form.customScopeName"
            type="text"
            required
            placeholder="ex: stage_filters"
            class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-xs text-white focus:border-indigo-500 focus:outline-none"
          />
        </div>

        <div class="flex items-end gap-3">
          <label class="inline-flex items-center gap-2 text-xs text-slate-300 cursor-pointer pb-2">
            <input
              v-model="form.volatile"
              type="checkbox"
              class="rounded border-slate-700 bg-slate-800 text-indigo-600"
            />
            Règle volatile (temporaire)
          </label>
        </div>

        <div v-if="form.volatile">
          <label class="block text-xs font-mono text-slate-300 mb-1">
            Date d'expiration
          </label>
          <input
            v-model="form.expires_at"
            type="date"
            required
            class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-xs text-white focus:border-indigo-500 focus:outline-none"
          />
        </div>
      </div>

      <div>
        <label class="block text-xs font-mono text-slate-300 mb-1">
          Instruction exécutive
        </label>
        <div class="flex flex-col sm:flex-row gap-2">
          <input
            v-model="form.instruction"
            type="text"
            required
            placeholder="Ex: Prioriser tous les mails contenant 'offre alternance Java' ou 'entretien technique'..."
            class="flex-1 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white focus:border-indigo-500 focus:outline-none"
          />
          <button
            type="submit"
            :disabled="submitting"
            class="rounded-lg bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 px-4 py-2 text-xs font-semibold text-white"
          >
            Enregistrer la règle
          </button>
        </div>
      </div>
    </form>

    <!-- Morning Rules -->
    <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-5 space-y-3">
      <div class="flex items-center justify-between">
        <h3 class="text-sm font-semibold text-white">
          Scope principal : <code class="font-mono text-indigo-300">morning_rules</code>
        </h3>
        <span class="text-xs font-mono text-slate-400">
          {{ morningRules.length }} règle(s)
        </span>
      </div>

      <div v-if="loading" class="text-xs text-slate-400">Chargement...</div>
      <div
        v-else-if="morningRules.length === 0"
        class="text-xs text-slate-400 py-2"
      >
        Aucune règle enregistrée dans <code class="font-mono">morning_rules</code>.
      </div>

      <ul v-else class="divide-y divide-slate-800">
        <li
          v-for="rule in morningRules"
          :key="rule.id"
          class="py-3 flex items-start justify-between gap-4"
        >
          <div class="space-y-1">
            <p class="text-sm text-slate-100">{{ rule.instruction }}</p>
            <div class="flex flex-wrap items-center gap-2 text-[11px] font-mono text-slate-400">
              <span>{{ rule.id }}</span>
              <span
                v-if="rule.volatile"
                class="rounded bg-indigo-950 border border-indigo-700/40 px-2 py-0.5 text-indigo-300"
              >
                Volatile · expire {{ rule.expires_at || 'N/A' }}
              </span>
            </div>
          </div>
          <button
            type="button"
            class="text-xs font-mono text-slate-400 hover:text-indigo-300"
            @click="handleDeleteRule(rule.id)"
          >
            Supprimer
          </button>
        </li>
      </ul>
    </div>

    <!-- Custom Scopes -->
    <div
      v-for="(rules, scopeName) in customScopes"
      :key="scopeName"
      class="rounded-2xl border border-slate-800 bg-slate-900/80 p-5 space-y-3"
    >
      <div class="flex items-center justify-between">
        <h3 class="text-sm font-semibold text-white">
          Scope dynamique : <code class="font-mono text-indigo-300">{{ scopeName }}</code>
        </h3>
        <span class="text-xs font-mono text-slate-400">
          {{ (rules || []).length }} règle(s)
        </span>
      </div>

      <div
        v-if="!rules || rules.length === 0"
        class="text-xs text-slate-400 py-1"
      >
        Scope vide.
      </div>

      <ul v-else class="divide-y divide-slate-800">
        <li
          v-for="rule in rules"
          :key="rule.id"
          class="py-3 flex items-start justify-between gap-4"
        >
          <div class="space-y-1">
            <p class="text-sm text-slate-100">{{ rule.instruction }}</p>
            <div class="flex flex-wrap items-center gap-2 text-[11px] font-mono text-slate-400">
              <span>{{ rule.id }}</span>
              <span
                v-if="rule.volatile"
                class="rounded bg-indigo-950 border border-indigo-700/40 px-2 py-0.5 text-indigo-300"
              >
                Volatile · expire {{ rule.expires_at || 'N/A' }}
              </span>
            </div>
          </div>
          <button
            type="button"
            class="text-xs font-mono text-slate-400 hover:text-indigo-300"
            @click="handleDeleteRule(rule.id)"
          >
            Supprimer
          </button>
        </li>
      </ul>
    </div>
  </section>
</template>
