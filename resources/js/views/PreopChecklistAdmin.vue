<template>
  <div class="min-h-screen bg-gray-50">
    <header class="bg-white border-b border-gray-200 sticky top-0 z-40">
      <div class="max-w-2xl mx-auto px-4 h-14 flex items-center justify-between">
        <router-link to="/doctor" class="text-sm text-emerald-600 font-medium">Back</router-link>
        <span class="text-sm font-semibold text-gray-900">Edit Pre-op Checklists</span>
        <span class="w-10"></span>
      </div>
    </header>

    <div class="max-w-2xl mx-auto px-4 py-6 space-y-6">
      <p v-if="loading" class="text-center text-gray-500 py-10">Loading templates...</p>
      <p v-else-if="error" class="text-center text-red-600 py-10">{{ error }}</p>

      <template v-else>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1">Procedure</label>
          <select v-model="activeKey" class="w-full rounded-xl border border-gray-300 px-3 py-3 text-base bg-white">
            <option v-for="t in templates" :key="t.key" :value="t.key">{{ t.name }}</option>
          </select>
        </div>

        <div v-if="active" class="space-y-5">
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Title</label>
            <input v-model="active.name" type="text" class="w-full rounded-xl border border-gray-300 px-3 py-2 text-base" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Summary</label>
            <textarea v-model="active.summary" rows="2" class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm"></textarea>
          </div>

          <div v-for="(section, si) in active.sections" :key="si" class="bg-white rounded-xl border border-gray-200 p-4 space-y-3">
            <div class="flex items-center gap-2">
              <input v-model="section.title" type="text" class="flex-1 rounded-lg border border-gray-300 px-2 py-1 text-sm font-semibold" />
              <button type="button" class="text-xs text-red-500" @click="removeSection(si)">Remove</button>
            </div>

            <div v-for="(item, ii) in section.items" :key="ii" class="space-y-1 border-t border-gray-100 pt-2">
              <textarea v-model="item.label" rows="2" class="w-full rounded-lg border border-gray-300 px-2 py-1 text-sm"></textarea>
              <div class="flex items-center gap-2">
                <input v-model="item.link" type="text" placeholder="Optional link (drjsk.com.au or YouTube)" class="flex-1 rounded-lg border border-gray-300 px-2 py-1 text-xs" />
                <button type="button" class="text-xs text-red-500" @click="removeItem(si, ii)">Delete</button>
              </div>
            </div>
            <button type="button" class="text-xs text-emerald-600" @click="addItem(si)">+ Add item</button>
          </div>

          <button type="button" class="text-sm text-emerald-600" @click="addSection">+ Add section</button>

          <div class="pt-2">
            <button
              type="button"
              class="w-full py-3 bg-emerald-600 text-white rounded-xl font-medium disabled:opacity-50"
              :disabled="saving"
              @click="save"
            >
              {{ saving ? 'Saving...' : 'Save template' }}
            </button>
            <p v-if="savedMessage" class="text-center text-emerald-600 text-sm mt-2">{{ savedMessage }}</p>
            <p v-if="saveError" class="text-center text-red-600 text-sm mt-2">{{ saveError }}</p>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useApi } from '@/composables/useApi';

const api = useApi();

const loading = ref(true);
const error = ref('');
const saving = ref(false);
const savedMessage = ref('');
const saveError = ref('');
const templates = ref([]);
const activeKey = ref(null);

const active = computed(() => templates.value.find(t => t.key === activeKey.value) || null);

function addSection() {
  if (!active.value) return;
  active.value.sections.push({ title: 'New section', items: [{ label: '', link: null }] });
}

function removeSection(si) {
  if (!active.value) return;
  active.value.sections.splice(si, 1);
}

function addItem(si) {
  active.value.sections[si].items.push({ label: '', link: null });
}

function removeItem(si, ii) {
  active.value.sections[si].items.splice(ii, 1);
}

async function save() {
  if (!active.value) return;
  saving.value = true;
  savedMessage.value = '';
  saveError.value = '';
  try {
    await api.put(`/doctor/preop-checklists/${active.value.key}`, {
      name: active.value.name,
      summary: active.value.summary,
      sections: active.value.sections,
    });
    savedMessage.value = 'Template saved.';
  } catch (e) {
    saveError.value = 'Unable to save the template. Please check the fields and try again.';
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  try {
    const { data } = await api.get('/preop-checklists');
    templates.value = data.data.templates || [];
    if (templates.value.length) {
      activeKey.value = templates.value[0].key;
    }
  } catch (e) {
    error.value = 'Unable to load the checklist templates.';
  } finally {
    loading.value = false;
  }
});
</script>
