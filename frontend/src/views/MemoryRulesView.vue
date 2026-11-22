<script setup>
import { computed, onMounted, ref } from 'vue'
import { jarvisApi } from '@/services/api'
import AppIcon from '@/components/AppIcon.vue'

const emit = defineEmits(['updated'])

const morningRules = ref([])
const customScopes = ref({})
const loading = ref(false)
const deletingRuleId = ref('')
const error = ref('')
const toastMessage = ref('')

let toastTimer = null

const customScopeEntries = computed(() => {
  return Object.entries(customScopes.value || {})
})

const totalCustomRulesCount = computed(() => {
  return customScopeEntries.value.reduce(
    (acc, [, rules]) => acc + (Array.isArray(rules) ? rules.length : 0),
    0
  )
})

function showToast(msg) {
  toastMessage.value = msg
  if (toastTimer) clearTimeout(toastTimer)
  toastTimer = setTimeout(() => {
    toastMessage.value = ''
  }, 3000)
}

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

async function handleDeleteRule(ruleId) {
  if (deletingRuleId.value) return
  deletingRuleId.value = ruleId
  error.value = ''
  try {
    await jarvisApi.deleteMemoryRule(ruleId)
    await fetchMemory()
    showToast(`Règle ${ruleId} supprimée`)
    emit('updated')
  } catch (err) {
    error.value = err.message || 'Suppression refusée'
  } finally {
    deletingRuleId.value = ''
  }
}

defineExpose({ refresh: fetchMemory })
onMounted(fetchMemory)
</script>

<template>
  <section class="enter-fade space-y-6">
    <!-- Subtle non-blocking toast notification -->
    <div
      v-if="toastMessage"
      class="rounded-[10px] bg-pastel-sage-tint border border-pastel-sage/40 px-4 py-2.5 text-[14px] sm:text-xs font-mono meta-mono text-pastel-sage flex items-center justify-between"
    >
      <span>{{ toastMessage }}</span>
      <button
        type="button"
        class="text-pastel-sage hover:opacity-75"
        @click="toastMessage = ''"
      >
        Fermer
      </button>
    </div>

    <div
      v-if="error"
      class="rounded-[10px] border border-pastel-rose/40 bg-pastel-rose-tint px-4 py-3 text-[14px] sm:text-xs text-pastel-rose"
    >
      {{ error }}
    </div>

    <!-- Skeleton Placeholders while loading -->
    <div v-if="loading" class="space-y-5">
      <div class="rounded-[16px] card-surface p-6 space-y-3">
        <div class="h-5 w-56 rounded-[6px] skeleton-block"></div>
        <div class="h-14 w-full rounded-[10px] skeleton-block"></div>
        <div class="h-14 w-full rounded-[10px] skeleton-block"></div>
      </div>
      <div class="rounded-[16px] card-surface p-6 space-y-3">
        <div class="h-5 w-48 rounded-[6px] skeleton-block"></div>
        <div class="h-14 w-full rounded-[10px] skeleton-block"></div>
      </div>
    </div>

    <template v-else>
      <!-- SECTION 1: Règles permanentes du briefing (morning_rules) -->
      <div class="rounded-[16px] card-surface p-5 sm:p-6 space-y-4">
        <div class="flex items-center justify-between border-b hairline-border pb-3.5">
          <div class="flex items-center gap-2.5 min-w-0">
            <span class="h-2.5 w-2.5 rounded-full bg-pastel-sage shrink-0"></span>
            <div>
              <h2 class="text-[17px] sm:text-[15px] font-medium text-ink-primary">
                Règles permanentes du briefing
              </h2>
              <p class="text-[13px] sm:text-xs font-mono meta-mono text-ink-muted">
                morning_rules
              </p>
            </div>
          </div>

          <span class="rounded-[6px] bg-pastel-sage-tint px-2.5 py-1 text-[13px] sm:text-xs font-mono meta-mono text-pastel-sage shrink-0">
            {{ morningRules.length }}
          </span>
        </div>

        <div
          v-if="morningRules.length === 0"
          class="text-[14px] sm:text-xs text-ink-muted py-3"
        >
          Aucune règle enregistrée dans morning_rules
        </div>

        <ul v-else class="divide-y hairline-border">
          <li
            v-for="rule in morningRules"
            :key="rule.id"
            class="py-3.5 flex flex-col sm:flex-row sm:items-start justify-between gap-3 sm:gap-4"
          >
            <div class="space-y-1.5 min-w-0">
              <p class="text-[15px] sm:text-[14px] leading-[22px] sm:leading-[20px] text-ink-primary break-words">
                {{ rule.instruction }}
              </p>
              <div class="flex flex-wrap items-center gap-2.5 text-[12px] font-mono meta-mono text-ink-muted">
                <span class="truncate">{{ rule.id }}</span>
                <span
                  v-if="rule.volatile"
                  class="rounded-[5px] bg-pastel-lavender-tint px-2 py-0.5 text-pastel-lavender"
                >
                  volatile · expire {{ rule.expires_at || 'N/A' }}
                </span>
              </div>
            </div>

            <button
              type="button"
              :disabled="deletingRuleId === rule.id"
              class="pressable self-end sm:self-start min-h-[40px] sm:min-h-[32px] px-3 rounded-[8px] bg-pastel-rose-tint text-pastel-rose inline-flex items-center gap-1.5 text-[13px] sm:text-xs font-mono meta-mono shrink-0 disabled:opacity-40"
              @click="handleDeleteRule(rule.id)"
            >
              <AppIcon name="trash" />
              <span>{{ deletingRuleId === rule.id ? 'Suppression…' : 'Supprimer' }}</span>
            </button>
          </li>
        </ul>
      </div>

      <!-- SECTION 2: Scopes contextuels (custom_scopes) -->
      <div class="space-y-4">
        <div class="flex items-center justify-between px-1">
          <div class="flex items-center gap-2.5">
            <span class="h-2.5 w-2.5 rounded-full bg-pastel-lavender"></span>
            <h2 class="text-[17px] sm:text-[15px] font-medium text-ink-primary">
              Scopes contextuels (custom_scopes)
            </h2>
          </div>
          <span class="rounded-[6px] bg-pastel-lavender-tint px-2.5 py-1 text-[13px] sm:text-xs font-mono meta-mono text-pastel-lavender">
            {{ totalCustomRulesCount }} règle(s) · {{ customScopeEntries.length }} scope(s)
          </span>
        </div>

        <div
          v-if="customScopeEntries.length === 0"
          class="rounded-[16px] card-surface p-6 text-center text-[14px] sm:text-xs text-ink-muted"
        >
          Aucun scope contextuel actif dans custom_scopes
        </div>

        <div
          v-for="[scopeName, rules] in customScopeEntries"
          :key="scopeName"
          class="rounded-[16px] card-surface p-5 sm:p-6 space-y-3.5"
        >
          <div class="flex items-center justify-between border-b hairline-border pb-3">
            <div class="flex items-center gap-2 min-w-0">
              <span class="rounded-[6px] bg-pastel-lavender-tint px-2.5 py-0.5 text-[13px] sm:text-xs font-mono meta-mono text-pastel-lavender truncate">
                {{ scopeName }}
              </span>
            </div>
            <span class="text-[13px] sm:text-xs font-mono meta-mono text-ink-muted shrink-0">
              {{ (rules || []).length }}
            </span>
          </div>

          <div
            v-if="!rules || rules.length === 0"
            class="text-[14px] sm:text-xs text-ink-muted py-1"
          >
            Aucune règle active dans ce scope
          </div>

          <ul v-else class="divide-y hairline-border">
            <li
              v-for="rule in rules"
              :key="rule.id"
              class="py-3.5 flex flex-col sm:flex-row sm:items-start justify-between gap-3 sm:gap-4"
            >
              <div class="space-y-1.5 min-w-0">
                <p class="text-[15px] sm:text-[14px] leading-[22px] sm:leading-[20px] text-ink-primary break-words">
                  {{ rule.instruction }}
                </p>
                <div class="flex flex-wrap items-center gap-2 text-[12px] font-mono meta-mono text-ink-muted">
                  <span class="truncate">{{ rule.id }}</span>
                  <span
                    v-if="rule.volatile"
                    class="rounded-[5px] bg-pastel-lavender-tint px-2 py-0.5 text-pastel-lavender"
                  >
                    volatile
                  </span>
                  <span
                    v-if="rule.expires_at"
                    class="rounded-[5px] bg-pastel-butter-tint px-2 py-0.5 text-pastel-butter"
                  >
                    expire {{ rule.expires_at }}
                  </span>
                </div>
              </div>

              <button
                type="button"
                :disabled="deletingRuleId === rule.id"
                class="pressable self-end sm:self-start min-h-[40px] sm:min-h-[32px] px-3 rounded-[8px] bg-pastel-rose-tint text-pastel-rose inline-flex items-center gap-1.5 text-[13px] sm:text-xs font-mono meta-mono shrink-0 disabled:opacity-40"
                @click="handleDeleteRule(rule.id)"
              >
                <AppIcon name="trash" />
                <span>{{ deletingRuleId === rule.id ? 'Suppression…' : 'Supprimer' }}</span>
              </button>
            </li>
          </ul>
        </div>
      </div>
    </template>
  </section>
</template>
