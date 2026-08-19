import { defineStore } from 'pinia'
import apiClient from '../api/client'

export const useHealthStore = defineStore('health', {
  state: () => ({
    healthData: null,
    loading: false,
    error: null,
  }),
  actions: {
    async checkHealth() {
      this.loading = true
      this.error = null
      try {
        const response = await apiClient.get('/health')
        this.healthData = response.data
      } catch (err) {
        this.error = err.response?.data?.message || err.message || 'Failed to connect to backend'
      } finally {
        this.loading = false
      }
    },
  },
})
