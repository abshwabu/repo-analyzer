<template>
  <div class="health-view">
    <div class="card">
      <h2>Backend Health Status</h2>
      <p class="description">Querying <code>GET /api/v1/health</code></p>

      <div class="status-container">
        <button class="btn" :disabled="healthStore.loading" @click="healthStore.checkHealth()">
          {{ healthStore.loading ? 'Checking...' : 'Refresh Health Status' }}
        </button>

        <div v-if="healthStore.loading" class="state-message">
          Checking backend connection...
        </div>

        <div v-else-if="healthStore.error" class="error-box">
          <strong>Error:</strong> {{ healthStore.error }}
        </div>

        <div v-else-if="healthStore.healthData" class="success-box">
          <div class="status-badge" :class="healthStore.healthData.status">
            Status: {{ healthStore.healthData.status.toUpperCase() }}
          </div>
          <pre class="json-dump">{{ JSON.stringify(healthStore.healthData, null, 2) }}</pre>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import { useHealthStore } from '../stores/health'

const healthStore = useHealthStore()

onMounted(() => {
  if (!healthStore.healthData) {
    healthStore.checkHealth()
  }
})
</script>

<style scoped>
.health-view {
  display: flex;
  justify-content: center;
  padding: 2rem 0;
}
.card {
  background: #ffffff;
  padding: 2rem;
  border-radius: 12px;
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
  width: 100%;
  max-width: 650px;
}
h2 {
  margin-top: 0;
  color: #111827;
}
.description {
  color: #6b7280;
  font-size: 0.95rem;
}
.status-container {
  margin-top: 1.5rem;
}
.btn {
  background-color: #2563eb;
  color: white;
  border: none;
  padding: 0.6rem 1.2rem;
  border-radius: 6px;
  cursor: pointer;
  font-weight: 500;
}
.btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.state-message {
  margin-top: 1rem;
  color: #6b7280;
}
.error-box {
  margin-top: 1rem;
  padding: 1rem;
  background-color: #fef2f2;
  border: 1px solid #f87171;
  border-radius: 6px;
  color: #991b1b;
}
.success-box {
  margin-top: 1rem;
}
.status-badge {
  display: inline-block;
  padding: 0.35rem 0.75rem;
  border-radius: 9999px;
  font-size: 0.875rem;
  font-weight: 700;
  margin-bottom: 1rem;
}
.status-badge.ok {
  background-color: #dcfce7;
  color: #166534;
}
.status-badge.degraded {
  background-color: #fef9c3;
  color: #854d0e;
}
.json-dump {
  background: #f3f4f6;
  padding: 1rem;
  border-radius: 6px;
  overflow-x: auto;
  font-size: 0.875rem;
  color: #1f2937;
}
</style>
