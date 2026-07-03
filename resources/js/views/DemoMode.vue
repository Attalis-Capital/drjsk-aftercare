<template>
  <div class="min-h-screen bg-gradient-to-b from-emerald-50 to-white">
    <!-- Header -->
    <header class="bg-white/80 backdrop-blur border-b border-gray-200 sticky top-0 z-40">
      <div class="max-w-4xl mx-auto px-4 h-16 flex items-center justify-between">
        <router-link to="/" class="flex items-center">
          <img src="/images/logo-full.png" alt="DrJSK AfterCare" class="h-7" />
        </router-link>
        <span class="text-xs font-medium bg-amber-100 text-amber-700 px-3 py-1 rounded-full">
          Demo Mode
        </span>
      </div>
    </header>

    <!-- Welcome -->
    <div v-if="step === 'welcome'" class="max-w-lg mx-auto px-4 py-16 text-center space-y-8">
      <h1 class="text-3xl font-bold text-gray-900">Experience DrJSK AfterCare</h1>
      <p class="text-gray-600">
        See how DrJSK AfterCare works with a simulated plastic surgery recovery.
      </p>

      <!-- S10 (#1718): surface demo-start failures inline (was console-only). -->
      <div v-if="demoError" role="alert" class="rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ demoError }}
      </div>

      <div class="space-y-4">
        <button
          class="w-full py-3 bg-emerald-600 text-white rounded-xl font-medium hover:bg-emerald-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="loading"
          @click="startDemo('voice')"
        >
          {{ loading ? 'Starting...' : 'Try Voice Recording' }}
        </button>
        <button
          class="w-full py-3 border-2 border-emerald-600 text-emerald-700 rounded-xl font-medium hover:bg-emerald-50 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="loading"
          @click="startDemo('skip')"
        >
          {{ loading ? 'Starting...' : 'Skip to Visit Summary' }}
        </button>
      </div>

      <button
        class="text-sm text-gray-400 hover:text-gray-600 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
        :disabled="loading"
        @click="switchToDoctor"
      >
        View Doctor Dashboard instead
      </button>
    </div>

    <!-- Demo visit loaded -->
    <div v-else-if="step === 'loaded'" class="max-w-lg mx-auto px-4 py-16 text-center space-y-4">
      <p class="text-emerald-600 font-medium">Demo visit ready</p>
      <h2 class="text-2xl font-bold text-gray-900">DIEP Flap Reconstruction Recovery</h2>
      <p class="text-gray-500">Post-operative recovery plan with full visit context</p>
      <router-link
        :to="`/visits/${demoVisitId}`"
        class="block w-full py-3 bg-indigo-600 text-white rounded-xl font-medium hover:bg-indigo-700 transition-colors"
      >
        Open Visit Summary
      </router-link>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useApi } from '@/composables/useApi';

const router = useRouter();
const auth = useAuthStore();
const step = ref('welcome');
const demoVisitId = ref(null);
const loading = ref(false);
const demoError = ref(null);

async function loginDemo(role) {
    const api = useApi();
    const { data } = await api.post('/demo/start', { role });
    const payload = data.data;
    auth.user = payload.user;
    auth.token = payload.token;
    auth.initialized = true;
    return payload.visit;
}

async function startDemo(mode) {
    loading.value = true;
    try {
        demoError.value = null;
        const visit = await loginDemo('patient');
        if (mode === 'skip') {
            demoVisitId.value = visit?.id;
            step.value = 'loaded';
        } else {
            router.push({ name: 'companion-scribe' });
        }
    } catch (err) {
        demoError.value = err.response?.data?.error?.message || 'Could not start the demo. Please try again.';
        console.error('Demo start failed:', err);
    } finally {
        loading.value = false;
    }
}

async function switchToDoctor() {
    loading.value = true;
    try {
        demoError.value = null;
        await loginDemo('doctor');
        router.push({ name: 'doctor-dashboard' });
    } catch (err) {
        demoError.value = err.response?.data?.error?.message || 'Could not start the demo. Please try again.';
        console.error('Demo start failed:', err);
    } finally {
        loading.value = false;
    }
}
</script>
