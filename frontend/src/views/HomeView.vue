<template>
  <div class="home">
    <div class="card">
      <h1>Repository Analyzer</h1>
      <p class="subtitle">Enter a public GitHub repository URL to ingest commits, contributors, and tech stack.</p>

      <form @submit.prevent="handleSubmit" class="analyze-form">
        <div class="input-group">
          <input
            v-model="githubUrl"
            type="text"
            placeholder="https://github.com/owner/repo or git@github.com:owner/repo.git"
            class="input-field"
            :disabled="repoStore.loading || repoStore.polling"
            required
          />
          <button type="submit" class="btn" :disabled="repoStore.loading || repoStore.polling">
            {{ repoStore.loading ? 'Submitting...' : repoStore.polling ? 'Analyzing...' : 'Analyze' }}
          </button>
        </div>
      </form>

      <div v-if="repoStore.error" class="error-box">
        <strong>Error:</strong> {{ repoStore.error }}
      </div>

      <div v-if="repoStore.statusData" class="status-card">
        <div class="status-header">
          <h3>{{ repoStore.statusData.owner }}/{{ repoStore.statusData.name }}</h3>
          <span class="status-pill" :class="repoStore.statusData.status">
            {{ repoStore.statusData.status.toUpperCase() }}
          </span>
        </div>

        <p v-if="repoStore.statusData.description" class="repo-desc">
          {{ repoStore.statusData.description }}
        </p>

        <div class="stats-grid">
          <div class="stat-item">
            <span class="stat-value">{{ repoStore.statusData.stats?.commits_count ?? 0 }}</span>
            <span class="stat-label">Commits</span>
          </div>
          <div class="stat-item">
            <span class="stat-value">{{ repoStore.statusData.stats?.contributors_count ?? 0 }}</span>
            <span class="stat-label">Contributors</span>
          </div>
          <div class="stat-item">
            <span class="stat-value">{{ repoStore.statusData.stats?.tech_stack_count ?? 0 }}</span>
            <span class="stat-label">Languages</span>
          </div>
          <div class="stat-item">
            <span class="stat-value">{{ repoStore.statusData.stars ?? 0 }}</span>
            <span class="stat-label">Stars</span>
          </div>
        </div>

        <div v-if="repoStore.statusData.status === 'processing' || repoStore.statusData.status === 'pending'" class="polling-indicator">
          <span class="spinner"></span> Polling progress every 2 seconds...
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onUnmounted } from 'vue'
import { useRepositoryStore } from '../stores/repository'

const repoStore = useRepositoryStore()
const githubUrl = ref('')

const handleSubmit = async () => {
  if (!githubUrl.value) return
  try {
    await repoStore.analyzeRepository(githubUrl.value)
  } catch (e) {
    // Handled in store
  }
}

onUnmounted(() => {
  repoStore.stopPolling()
})
</script>

<style scoped>
.home {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 70vh;
}
.card {
  background: #ffffff;
  padding: 2.5rem;
  border-radius: 12px;
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
  width: 100%;
  max-width: 700px;
}
h1 {
  font-size: 1.875rem;
  color: #1f2937;
  margin-bottom: 0.5rem;
}
.subtitle {
  color: #4b5563;
  margin-bottom: 1.5rem;
  line-height: 1.5;
}
.analyze-form {
  margin-bottom: 1.5rem;
}
.input-group {
  display: flex;
  gap: 0.75rem;
}
.input-field {
  flex: 1;
  padding: 0.75rem 1rem;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  font-size: 0.95rem;
  outline: none;
  transition: border-color 0.2s;
}
.input-field:focus {
  border-color: #4f46e5;
}
.btn {
  background-color: #4f46e5;
  color: white;
  padding: 0.75rem 1.5rem;
  border-radius: 8px;
  border: none;
  cursor: pointer;
  font-weight: 600;
  transition: background-color 0.2s ease;
}
.btn:hover:not(:disabled) {
  background-color: #4338ca;
}
.btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.error-box {
  margin-top: 1rem;
  padding: 0.875rem 1rem;
  background-color: #fef2f2;
  border: 1px solid #f87171;
  border-radius: 8px;
  color: #991b1b;
  font-size: 0.9rem;
}
.status-card {
  margin-top: 1.5rem;
  padding: 1.5rem;
  background-color: #f9fafb;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}
.status-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.75rem;
}
.status-pill {
  padding: 0.25rem 0.6rem;
  border-radius: 9999px;
  font-size: 0.75rem;
  font-weight: 700;
}
.status-pill.pending {
  background: #fef3c7;
  color: #92400e;
}
.status-pill.processing {
  background: #dbeafe;
  color: #1e40af;
}
.status-pill.completed {
  background: #dcfce7;
  color: #166534;
}
.status-pill.failed {
  background: #fee2e2;
  color: #991b1b;
}
.repo-desc {
  color: #4b5563;
  font-size: 0.9rem;
  margin-bottom: 1rem;
}
.stats-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 0.75rem;
  margin-top: 1rem;
}
.stat-item {
  background: #ffffff;
  padding: 0.75rem;
  border-radius: 6px;
  text-align: center;
  border: 1px solid #e5e7eb;
}
.stat-value {
  display: block;
  font-size: 1.25rem;
  font-weight: 700;
  color: #111827;
}
.stat-label {
  font-size: 0.75rem;
  color: #6b7280;
}
.polling-indicator {
  margin-top: 1rem;
  font-size: 0.85rem;
  color: #6366f1;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.spinner {
  width: 12px;
  height: 12px;
  border: 2px solid #6366f1;
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 1s linear infinite;
  display: inline-block;
}
@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
