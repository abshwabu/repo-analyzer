import { defineStore } from 'pinia'

const STORAGE_KEY = 'ai_settings_session'

export const useSettingsStore = defineStore('settings', {
  state: () => {
    let saved = null
    try {
      const item = sessionStorage.getItem(STORAGE_KEY)
      if (item) {
        saved = JSON.parse(item)
      }
    } catch (e) {
      console.warn('Could not read sessionStorage', e)
    }

    return {
      provider: saved?.provider || 'anthropic',
      apiKey: saved?.apiKey || '',
      model: saved?.model || '',
      isModalOpen: false,
    }
  },
  getters: {
    hasApiKey: (state) => Boolean(state.apiKey && state.apiKey.trim().length > 0),
    maskedApiKey: (state) => {
      if (!state.apiKey) return ''
      const key = state.apiKey.trim()
      if (key.length <= 8) return '••••••••'
      return key.slice(0, 4) + '••••••••' + key.slice(-4)
    },
  },
  actions: {
    openModal() {
      this.isModalOpen = true
    },
    closeModal() {
      this.isModalOpen = false
    },
    saveSettings({ provider, apiKey, model }) {
      this.provider = provider || 'anthropic'
      this.apiKey = apiKey ? apiKey.trim() : ''
      this.model = model ? model.trim() : ''

      try {
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
          provider: this.provider,
          apiKey: this.apiKey,
          model: this.model,
        }))
      } catch (e) {
        console.warn('Could not persist to sessionStorage', e)
      }

      this.closeModal()
    },
    clearSettings() {
      this.apiKey = ''
      this.model = ''
      try {
        sessionStorage.removeItem(STORAGE_KEY)
      } catch (e) {
        console.warn('Could not remove from sessionStorage', e)
      }
    },
  },
})
