<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <header class="bg-white border-b border-gray-200 sticky top-0 z-40">
      <div class="max-w-lg mx-auto px-4 h-14 flex items-center justify-between">
        <router-link to="/health" class="text-sm text-emerald-600 font-medium">Back</router-link>
        <span class="text-sm font-semibold text-gray-900">Pre-op Checklist</span>
        <span class="w-10"></span>
      </div>
    </header>

    <div class="max-w-lg mx-auto px-4 py-6 space-y-6">
      <p v-if="loading" class="text-center text-gray-500 py-10">Loading checklist...</p>
      <p v-else-if="error" class="text-center text-red-600 py-10">{{ error }}</p>

      <template v-else>
        <!-- Procedure selector -->
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1">Your procedure</label>
          <select
            v-model="activeKey"
            class="w-full rounded-xl border border-gray-300 px-3 py-3 text-base bg-white"
          >
            <option v-for="t in templates" :key="t.key" :value="t.key">{{ t.name }}</option>
          </select>
        </div>

        <div v-if="active">
          <h1 class="text-xl font-bold text-gray-900">{{ active.name }}</h1>
          <p class="text-gray-600 text-sm mt-1">{{ active.summary }}</p>

          <!-- Progress -->
          <div class="mt-4">
            <div class="flex justify-between text-xs text-gray-500 mb-1">
              <span>{{ checkedCount }} of {{ totalCount }} done</span>
              <span>{{ percent }}%</span>
            </div>
            <div class="h-2 bg-gray-200 rounded-full overflow-hidden">
              <div class="h-full bg-emerald-500 transition-all" :style="{ width: percent + '%' }"></div>
            </div>
          </div>

          <!-- Sections -->
          <section v-for="(section, si) in active.sections" :key="si" class="mt-6">
            <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">{{ section.title }}</h2>
            <ul class="mt-2 space-y-2">
              <li
                v-for="(item, ii) in section.items"
                :key="ii"
                class="bg-white rounded-xl border border-gray-200 p-3 flex items-start gap-3"
              >
                <button
                  type="button"
                  class="mt-0.5 shrink-0 w-6 h-6 rounded-md border-2 flex items-center justify-center transition-colors"
                  :class="isChecked(si, ii) ? 'bg-emerald-500 border-emerald-500' : 'border-gray-300 bg-white'"
                  :aria-pressed="isChecked(si, ii)"
                  @click="toggle(si, ii)"
                >
                  <svg v-if="isChecked(si, ii)" class="w-4 h-4 text-white" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7 7a1 1 0 01-1.4 0l-3-3a1 1 0 011.4-1.4L9 11.6l6.3-6.3a1 1 0 011.4 0z" clip-rule="evenodd" />
                  </svg>
                </button>
                <div class="text-sm text-gray-800">
                  <p :class="{ 'line-through text-gray-400': isChecked(si, ii) }">{{ item.label }}</p>
                  <a
                    v-if="item.link"
                    :href="item.link"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-emerald-600 text-xs underline mt-1 inline-block"
                  >More information</a>
                </div>
              </li>
            </ul>
          </section>

          <!-- Practice contact -->
          <div v-if="practice" class="mt-8 bg-emerald-50 rounded-xl p-4 text-sm text-gray-700">
            <p class="font-semibold text-gray-900">{{ practice.name }}</p>
            <p class="mt-1">If you have any questions, call the practice on {{ practice.phone }}.</p>
            <a :href="practice.website" target="_blank" rel="noopener noreferrer" class="text-emerald-600 underline mt-1 inline-block">{{ practice.website }}</a>
          </div>

          <button
            type="button"
            class="mt-6 w-full py-2 text-sm text-gray-400 hover:text-gray-600"
            @click="resetChecks"
          >
            Reset my ticks
          </button>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useApi } from '@/composables/useApi';

const api = useApi();

const loading = ref(true);
const error = ref('');
const templates = ref([]);
const practice = ref(null);
const activeKey = ref(null);
const checks = ref({});

const active = computed(() => templates.value.find(t => t.key === activeKey.value) || null);

const totalCount = computed(() => {
  if (!active.value) return 0;
  return active.value.sections.reduce((n, s) => n + s.items.length, 0);
});

const checkedCount = computed(() => {
  const map = checks.value[activeKey.value] || {};
  return Object.values(map).filter(Boolean).length;
});

const percent = computed(() => {
  if (!totalCount.value) return 0;
  return Math.round((checkedCount.value / totalCount.value) * 100);
});

function storageKey() {
  return 'drjsk_preop_checks';
}

function loadChecks() {
  try {
    const raw = localStorage.getItem(storageKey());
    checks.value = raw ? JSON.parse(raw) : {};
  } catch (e) {
    checks.value = {};
  }
}

function saveChecks() {
  try {
    localStorage.setItem(storageKey(), JSON.stringify(checks.value));
  } catch (e) {
    // Ignore storage errors (e.g. private mode)
  }
}

function itemId(si, ii) {
  return `${si}:${ii}`;
}

function isChecked(si, ii) {
  const map = checks.value[activeKey.value] || {};
  return !!map[itemId(si, ii)];
}

function toggle(si, ii) {
  const key = activeKey.value;
  if (!checks.value[key]) checks.value[key] = {};
  checks.value[key][itemId(si, ii)] = !checks.value[key][itemId(si, ii)];
  saveChecks();
}

function resetChecks() {
  checks.value[activeKey.value] = {};
  saveChecks();
}

watch(checks, saveChecks, { deep: true });

onMounted(async () => {
  loadChecks();
  try {
    const { data } = await api.get('/preop-checklists');
    templates.value = data.data.templates || [];
    practice.value = data.data.practice || null;
    if (templates.value.length) {
      activeKey.value = templates.value[0].key;
    }
  } catch (e) {
    error.value = 'Unable to load your pre-operative checklist. Please try again later.';
  } finally {
    loading.value = false;
  }
});
</script>
