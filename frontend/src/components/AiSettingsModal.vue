<template>
  <div v-if="settingsStore.isModalOpen" class="modal-backdrop" @click.self="settingsStore.closeModal">
    <div class="modal-content">
      <div class="modal-header">
        <div class="modal-title-wrap">
          <span class="modal-icon">⚙️</span>
          <h3>AI Provider & Key Setup</h3>
        </div>
        <button class="close-btn" @click="settingsStore.closeModal">✕</button>
      </div>

      <p class="modal-desc">
        Bring Your Own Key (BYO Key) pattern: Your API key is stored strictly in browser <strong>sessionStorage</strong> and is never persisted on the server or database.
      </p>

      <form @submit.prevent="handleSave" class="settings-form">
        <div class="form-group">
          <label>Select AI Provider</label>
          <select v-model="selectedProvider" class="form-select">
            <option value="anthropic">Anthropic (Claude)</option>
            <option value="openai">OpenAI (GPT-4o / GPT-4o-mini)</option>
          </select>
        </div>

        <div class="form-group">
          <label>API Key</label>
          <input
            v-model="inputApiKey"
            type="password"
            :placeholder="selectedProvider === 'anthropic' ? 'sk-ant-api03-...' : 'sk-proj-...'"
            class="form-input"
            autocomplete="off"
            required
          />
          <span v-if="settingsStore.hasApiKey" class="key-status">
            Current session key: <code>{{ settingsStore.maskedApiKey }}</code>
          </span>
        </div>

        <div class="form-group">
          <label>Custom Model (Optional)</label>
          <input
            v-model="inputModel"
            type="text"
            :placeholder="selectedProvider === 'anthropic' ? 'claude-3-5-sonnet-20241022' : 'gpt-4o-mini'"
            class="form-input"
          />
        </div>

        <div class="modal-actions">
          <button
            v-if="settingsStore.hasApiKey"
            type="button"
            class="btn-clear"
            @click="handleClear"
          >
            Clear Stored Key
          </button>
          <div class="right-actions">
            <button type="button" class="btn-cancel" @click="settingsStore.closeModal">
              Cancel
            </button>
            <button type="submit" class="btn-save">
              Save to Session
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useSettingsStore } from '../stores/settings'

const settingsStore = useSettingsStore()

const selectedProvider = ref(settingsStore.provider)
const inputApiKey = ref(settingsStore.apiKey)
const inputModel = ref(settingsStore.model)

watch(() => settingsStore.isModalOpen, (isOpen) => {
  if (isOpen) {
    selectedProvider.value = settingsStore.provider
    inputApiKey.value = settingsStore.apiKey
    inputModel.value = settingsStore.model
  }
})

const handleSave = () => {
  settingsStore.saveSettings({
    provider: selectedProvider.value,
    apiKey: inputApiKey.value,
    model: inputModel.value,
  })
}

const handleClear = () => {
  settingsStore.clearSettings()
  inputApiKey.value = ''
  inputModel.value = ''
}
</script>

<style scoped>
.modal-backdrop {
  position: fixed;
  inset: 0;
  background-color: rgba(17, 24, 39, 0.6);
  backdrop-filter: blur(4px);
  display: flex;
  justify-content: center;
  align-items: center;
  z-index: 999;
  padding: 1rem;
}
.modal-content {
  background: #ffffff;
  border-radius: 12px;
  max-width: 520px;
  width: 100%;
  padding: 1.75rem;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
}
.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.5rem;
}
.modal-title-wrap {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.modal-icon {
  font-size: 1.25rem;
}
.modal-header h3 {
  font-size: 1.25rem;
  font-weight: 700;
  color: #111827;
  margin: 0;
}
.close-btn {
  background: none;
  border: none;
  font-size: 1.25rem;
  color: #9ca3af;
  cursor: pointer;
  padding: 0.25rem;
}
.close-btn:hover {
  color: #374151;
}
.modal-desc {
  font-size: 0.85rem;
  color: #6b7280;
  margin-bottom: 1.25rem;
  line-height: 1.5;
}
.settings-form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
.form-group {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.form-group label {
  font-size: 0.825rem;
  font-weight: 600;
  color: #374151;
}
.form-select, .form-input {
  padding: 0.65rem 0.85rem;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  font-size: 0.9rem;
  outline: none;
  transition: border-color 0.2s;
}
.form-select:focus, .form-input:focus {
  border-color: #4f46e5;
}
.key-status {
  font-size: 0.75rem;
  color: #059669;
  margin-top: 0.2rem;
}
.key-status code {
  font-family: monospace;
  background: #f3f4f6;
  padding: 0.1rem 0.3rem;
  border-radius: 4px;
}
.modal-actions {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 0.75rem;
  padding-top: 1rem;
  border-top: 1px solid #f3f4f6;
}
.right-actions {
  display: flex;
  gap: 0.5rem;
  margin-left: auto;
}
.btn-cancel {
  background: #f3f4f6;
  color: #374151;
  border: 1px solid #e5e7eb;
  padding: 0.6rem 1rem;
  border-radius: 8px;
  font-size: 0.875rem;
  font-weight: 600;
  cursor: pointer;
}
.btn-clear {
  background: #fee2e2;
  color: #991b1b;
  border: none;
  padding: 0.6rem 0.85rem;
  border-radius: 8px;
  font-size: 0.825rem;
  font-weight: 600;
  cursor: pointer;
}
.btn-save {
  background: #4f46e5;
  color: #ffffff;
  border: none;
  padding: 0.6rem 1.25rem;
  border-radius: 8px;
  font-size: 0.875rem;
  font-weight: 600;
  cursor: pointer;
}
.btn-save:hover {
  background: #4338ca;
}
</style>
