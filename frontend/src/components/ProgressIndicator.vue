<template>
  <div class="progress-box">
    <div class="progress-header">
      <span class="pulse-dot" :class="status"></span>
      <span class="status-title">{{ getStatusTitle() }}</span>
      <span class="poll-text">Polling status every 2s</span>
    </div>

    <div class="stepper">
      <!-- Step 1: Ingestion -->
      <div class="step" :class="getStepClass(1)">
        <div class="step-icon">
          <span v-if="currentStep > 1">✓</span>
          <span v-else-if="currentStep === 1 && status !== 'failed'" class="step-spinner"></span>
          <span v-else-if="status === 'failed' && currentStep === 1">✕</span>
          <span v-else>1</span>
        </div>
        <div class="step-label">
          <strong>GitHub Ingestion</strong>
          <span>Commits, metadata & contributors</span>
        </div>
      </div>

      <div class="step-connector" :class="{ active: currentStep > 1 }"></div>

      <!-- Step 2: Tech Stack -->
      <div class="step" :class="getStepClass(2)">
        <div class="step-icon">
          <span v-if="currentStep > 2">✓</span>
          <span v-else-if="currentStep === 2 && status !== 'failed'" class="step-spinner"></span>
          <span v-else-if="status === 'failed' && currentStep === 2">✕</span>
          <span v-else>2</span>
        </div>
        <div class="step-label">
          <strong>Tech Stack Detection</strong>
          <span>Shallow clone & manifest analysis</span>
        </div>
      </div>

      <div class="step-connector" :class="{ active: currentStep > 2 }"></div>

      <!-- Step 3: Complete -->
      <div class="step" :class="getStepClass(3)">
        <div class="step-icon">
          <span v-if="status === 'completed'">✓</span>
          <span v-else>3</span>
        </div>
        <div class="step-label">
          <strong>Ready</strong>
          <span>Metrics & README ready</span>
        </div>
      </div>
    </div>

    <div v-if="errorMessage" class="error-banner">
      <strong>Ingestion Failed:</strong> {{ errorMessage }}
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  status: {
    type: String,
    default: 'pending',
  },
  stats: {
    type: Object,
    default: () => ({}),
  },
  errorMessage: {
    type: String,
    default: null,
  },
})

const currentStep = computed(() => {
  if (props.status === 'completed') return 3
  if (props.status === 'processing') {
    // If commits are ingested, we are at step 2 (tech stack)
    if ((props.stats?.commits_count ?? 0) > 0) return 2
    return 1
  }
  return 1
})

const getStepClass = (stepNum) => {
  if (props.status === 'failed' && currentStep.value === stepNum) return 'failed'
  if (currentStep.value > stepNum || props.status === 'completed') return 'completed'
  if (currentStep.value === stepNum) return 'active'
  return 'pending'
}

const getStatusTitle = () => {
  if (props.status === 'completed') return 'Analysis Completed'
  if (props.status === 'failed') return 'Analysis Failed'
  if (currentStep.value === 2) return 'Detecting Tech Stack & Manifests...'
  return 'Ingesting GitHub Repository...'
}
</script>

<style scoped>
.progress-box {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 1.25rem;
  margin: 1.25rem 0;
}
.progress-header {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 1.25rem;
}
.pulse-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: #3b82f6;
  animation: pulse 1.5s infinite;
}
.pulse-dot.completed {
  background: #10b981;
  animation: none;
}
.pulse-dot.failed {
  background: #ef4444;
  animation: none;
}
.status-title {
  font-weight: 700;
  color: #1e293b;
  font-size: 0.95rem;
}
.poll-text {
  font-size: 0.75rem;
  color: #64748b;
  margin-left: auto;
}
.stepper {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}
.step {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  flex: 1;
}
.step-icon {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.8rem;
  font-weight: 700;
  background: #e2e8f0;
  color: #64748b;
}
.step.active .step-icon {
  background: #e0e7ff;
  color: #4338ca;
  border: 2px solid #6366f1;
}
.step.completed .step-icon {
  background: #dcfce7;
  color: #15803d;
}
.step.failed .step-icon {
  background: #fee2e2;
  color: #b91c1c;
}
.step-label {
  display: flex;
  flex-direction: column;
}
.step-label strong {
  font-size: 0.825rem;
  color: #1e293b;
}
.step-label span {
  font-size: 0.7rem;
  color: #64748b;
}
.step-connector {
  flex: 0.5;
  height: 2px;
  background: #e2e8f0;
}
.step-connector.active {
  background: #10b981;
}
.step-spinner {
  width: 12px;
  height: 12px;
  border: 2px solid #4338ca;
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 1s linear infinite;
}
.error-banner {
  margin-top: 1rem;
  padding: 0.75rem;
  background: #fee2e2;
  color: #991b1b;
  border-radius: 6px;
  font-size: 0.85rem;
}
@keyframes pulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: 0.5; transform: scale(1.2); }
}
@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>
