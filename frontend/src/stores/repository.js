import { defineStore } from 'pinia'
import apiClient from '../api/client'

export const useRepositoryStore = defineStore('repository', {
  state: () => ({
    currentRepository: null,
    statusData: null,
    loading: false,
    polling: false,
    pollIntervalId: null,
    error: null,
  }),
  actions: {
    async analyzeRepository(githubUrl) {
      this.loading = true
      this.error = null
      this.statusData = null
      try {
        const response = await apiClient.post('/repositories/analyze', {
          github_url: githubUrl,
        })
        this.currentRepository = response.data.data
        this.startPolling(this.currentRepository.repository_id)
        return response.data
      } catch (err) {
        this.error = err.response?.data?.message || err.message || 'Failed to start repository analysis'
        throw err
      } finally {
        this.loading = false
      }
    },

    async fetchStatus(repositoryId) {
      try {
        const response = await apiClient.get(`/repositories/${repositoryId}/status`)
        this.statusData = response.data.data
        if (this.statusData.status === 'completed' || this.statusData.status === 'failed') {
          this.stopPolling()
        }
        return response.data.data
      } catch (err) {
        this.error = err.response?.data?.message || err.message || 'Failed to check status'
        this.stopPolling()
      }
    },

    startPolling(repositoryId, intervalMs = 2000) {
      this.stopPolling()
      this.polling = true
      this.fetchStatus(repositoryId)
      this.pollIntervalId = setInterval(() => {
        this.fetchStatus(repositoryId)
      }, intervalMs)
    },

    stopPolling() {
      if (this.pollIntervalId) {
        clearInterval(this.pollIntervalId)
        this.pollIntervalId = null
      }
      this.polling = false
    },
  },
})
