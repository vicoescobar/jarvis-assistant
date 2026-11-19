<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { jarvisApi } from '@/services/api'
import AppIcon from '@/components/AppIcon.vue'

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
    error.value = err.message || 'Lecture de memory_rules.json impossible'
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
    error.value = err.message || 'Création refusée'
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
    error.value = err.message || 'Suppression refusée'
  }
}

onMounted(fetchMemory)
</script>

<template>
  <section class="enter-fade space-y-6">
    <!-- New Rule Form -->
    <form
      class="rounded-[16px] bg-graphite-card border hairline-border p-6 space-y-4"
      @submit.prevent="handleCreateRule"
    >
      <div class="flex flex-wrap items-baseline justify-between gap-2">
        <div>
          <h2 class="text-[16px] leading-[22px] font-medium text-ink-primary">
            Nouvelle règle contextuelle
          </h2>
          <p class="mt-0.5 text-xs text-ink-secondary">
            Stockée dans <span class="font-mono meta-mono text-ink-primary">memory_rules.json</span> et injectée dans le briefing de 08:30
          </p>
        </div>
      </div>

      <div
        v-if="error"
        class="rounded-[8px] border border-semantic-negative/40 bg-graphite-deep px-3.5 py-2 text-xs text-semantic-negative"
      >
        {{ error }}
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
        <div>
          <label class="block text-xs font-mono meta-mono text-ink-secondary mb-1.5">
            Scope
          </label>
          <select
            v-model="form.scope"
            class="h-9 w-full rounded-[8px] border hairline-border bg-graphite-deep px-3 text-xs text-ink-primary focus:border-[#ededed]/30 focus:outline-none"
          >
            <option
              v-for="sc in availableScopes"
              :key="sc"
              :value="sc"
            >
              {{ sc }}
            </option>
            <option value="__custom__">+ Nouveau scope</option>
          </select>
        </div>

        <div v-if="form.scope === '__custom__'">
          <label class="block text-xs font-mono meta-mono text-ink-secondary mb-1.5">
            Identifiant snake_case
          </label>
          <input
            v-model="form.customScopeName"
            type="text"
            required
            placeholder="ex. stage_filters"
            class="h-9 w-full rounded-[8px] border hairline-border bg-graphite-deep px-3 text-xs text-ink-primary placeholder:text-ink-muted focus:border-[#ededed]/30 focus:outline-none"
          />
        </div>

        <div class="flex items-end pb-2">
          <label class="inline-flex items-center gap-2 text-xs text-ink-secondary cursor-pointer">
            <input
              v-model="form.volatile"
              type="checkbox"
              class="h-3.5 w-3.5 rounded-[4px] border-ink-muted/40 bg-graphite-deep accent-[#ededed]"
            />
            <span>Volatile (expiration automatique)</span>
          </label>
        </div>

        <div v-if="form.volatile">
          <label class="block text-xs font-mono meta-mono text-ink-secondary mb-1.5">
            Expire le
          </label>
          <input
            v-model="form.expires_at"
            type="date"
            required
            class="h-9 w-full rounded-[8px] border hairline-border bg-graphite-deep px-3 text-xs text-ink-primary focus:border-[#ededed]/30 focus:outline-none"
          />
        </div>
      </div>

      <div>
        <label class="block text-xs font-mono meta-mono text-ink-secondary mb-1.5">
          Instruction
        </label>
        <div class="flex flex-col sm:flex-row gap-2">
          <input
            v-model="form.instruction"
            type="text"
            required
            placeholder="Prioriser les mails d’alternance Java ou décaler un cycle"
            class="h-9 flex-1 rounded-[8px] border hairline-border bg-graphite-deep px-3.5 text-sm text-ink-primary placeholder:text-ink-muted focus:border-[#ededed]/30 focus:outline-none"
          />
          <button
            type="submit"
            :disabled="submitting"
            class="btn-primary h-9 rounded-[8px] px-4 text-xs font-medium inline-flex items-center justify-center gap-1.5 disabled:opacity-40"
          >
            <AppIcon name="plus" />
            <span>Ajouter la règle</span>
          </button>
        </div>
      </div>
    </form>

    <!-- Morning Rules Block -->
    <div class="rounded-[16px] bg-graphite-card border hairline-border p-6 space-y-3">
      <div class="flex items-center justify-between border-b hairline-border pb-3">
        <div class="flex items-center gap-2">
          <span class="h-1.5 w-1.5 rounded-full bg-semantic-positive"></span>
          <h3 class="text-sm font-medium text-ink-primary">
            Règles permanentes du briefing
          </h3>
          <span class="text-xs font-mono meta-mono text-ink-muted">morning_rules</span>
        </div>
        <span class="text-xs font-mono meta-mono text-ink-muted">
          {{ morningRules.length }}
        </span>
      </div>

      <div v-if="loading" class="text-xs text-ink-muted py-2">
        Lecture en cours
      </div>
      <div
        v-else-if="morningRules.length === 0"
        class="text-xs text-ink-muted py-2"
      >
        Aucune règle dans morning_rules
      </div>

      <ul v-else class="divide-y divide-[#ededed]/[0.06]">
        <li
          v-for="rule in morningRules"
          :key="rule.id"
          class="py-3 flex items-start justify-between gap-4"
        >
          <div class="space-y-1">
            <p class="text-[14px] leading-[20px] text-ink-primary">{{ rule.instruction }}</p>
            <div class="flex flex-wrap items-center gap-3 text-xs font-mono meta-mono text-ink-muted">
              <span>{{ rule.id }}</span>
              <span v-if="rule.volatile" class="text-accent">
                expire {{ rule.expires_at || 'N/A' }}
              </span>
            </div>
          </div>
          <button
            type="button"
            class="pressable inline-flex items-center gap-1 text-xs font-mono meta-mono text-ink-muted hover:text-semantic-negative"
            @click="handleDeleteRule(rule.id)"
          >
            <AppIcon name="trash" />
            <span>Supprimer</span>
          </button>
        </li>
      </ul>
    </div>

    <!-- Custom Scopes -->
    <div
      v-for="(rules, scopeName) in customScopes"
      :key="scopeName"
      class="rounded-[16px] bg-graphite-card border hairline-border p-6 space-y-3"
    >
      <div class="flex items-center justify-between border-b hairline-border pb-3">
        <div class="flex items-center gap-2">
          <span class="h-1.5 w-1.5 rounded-full bg-accent"></span>
          <h3 class="text-sm font-medium text-ink-primary">
            Scope dynamique
          </h3>
          <span class="text-xs font-mono meta-mono text-ink-secondary">{{ scopeName }}</span>
        </div>
        <span class="text-xs font-mono meta-mono text-ink-muted">
          {{ (rules || []).length }}
        </span>
      </div>

      <div
        v-if="!rules || rules.length === 0"
        class="text-xs text-ink-muted py-1"
      >
        Aucune règle active dans ce scope
      </div>

      <ul v-else class="divide-y divide-[#ededed]/[0.06]">
        <li
          v-for="rule in rules"
          :key="rule.id"
          class="py-3 flex items-start justify-between gap-4"
        >
          <div class="space-y-1">
            <p class="text-[14px] leading-[20px] text-ink-primary">{{ rule.instruction }}</p>
            <div class="flex flex-wrap items-center gap-3 text-xs font-mono meta-mono text-ink-muted">
              <span>{{ rule.id }}</span>
              <span v-if="rule.volatile" class="text-accent">
                volatile · expire {{ rule.expires_at || 'N/A' }}
              </span>
            </div>
          </div>
          <button
            type="button"
            class="pressable inline-flex items-center gap-1 text-xs font-mono meta-mono text-ink-muted hover:text-semantic-negative"
            @click="handleDeleteRule(rule.id)"
          >
            <AppIcon name="trash" />
            <span>Supprimer</span>
          </button>
        </li>
      </ul>
    </div>
  </section>
</template>
