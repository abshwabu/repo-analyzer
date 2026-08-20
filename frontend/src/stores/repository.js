import { defineStore } from 'pinia'
import apiClient from '../api/client'

export const useRepositoryStore = defineStore('repository', {
  state: () => ({
    currentRepository: null,
    statusData: null,
    timelineData: null,
    contributorsData: null,
    summaryData: null,
    readmeData: null,
    contributingData: null,
    loading: false,
    timelineLoading: false,
    contributorsLoading: false,
    summaryLoading: false,
    readmeLoading: false,
    contributingLoading: false,
    polling: false,
    pollIntervalId: null,
    error: null,
    summaryError: null,
    readmeError: null,
  }),
  actions: {
    async fetchContributing(repositoryId) {
      this.contributingLoading = true
      try {
        const response = await apiClient.get(`/repositories/${repositoryId}/contributing`)
        this.contributingData = response.data.data
        return response.data.data
      } catch (err) {
        console.error('Failed to load contributing guide:', err)
      } finally {
        this.contributingLoading = false
      }
    },
    async generateReadme(repositoryId, options = {}) {
      this.readmeLoading = true
      this.readmeError = null
      try {
        const payload = {}
        const headers = {}

        if (options.provider) payload.provider = options.provider
        if (options.apiKey) {
          payload.api_key = options.apiKey
          headers['X-AI-API-Key'] = options.apiKey
        }
        if (options.model) payload.model = options.model

        const response = await apiClient.post(`/repositories/${repositoryId}/generate-readme`, payload, { headers })
        this.readmeData = response.data.data
        return response.data.data
      } catch (err) {
        this.readmeError = err.response?.data?.message || err.message || 'Failed to generate README'
        throw err
      } finally {
        this.readmeLoading = false
      }
    },

    async fetchReadme(repositoryId) {
      this.readmeLoading = true
      try {
        const response = await apiClient.get(`/repositories/${repositoryId}/readme`)
        this.readmeData = response.data.data
        return response.data.data
      } catch (err) {
        // May not be generated yet
        this.readmeData = null
      } finally {
        this.readmeLoading = false
      }
    },
    async generateSummary(repositoryId, { provider, apiKey, model }) {
      this.summaryLoading = true
      this.summaryError = null
      try {
        const response = await apiClient.post(`/repositories/${repositoryId}/summarize`, {
          provider,
          api_key: apiKey,
          model: model || undefined,
        }, {
          headers: {
            'X-AI-API-Key': apiKey,
          },
        })
        this.summaryData = response.data.data
        return response.data.data
      } catch (err) {
        this.summaryError = err.response?.data?.message || err.message || 'Failed to generate AI summary'
        throw err
      } finally {
        this.summaryLoading = false
      }
    },
    async analyzeRepository(githubUrl) {
      this.loading = true
      this.error = null
      this.statusData = null
      this.timelineData = null
      this.contributorsData = null
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
        if (this.statusData.status === 'completed') {
          this.stopPolling()
          this.fetchTimeline(repositoryId)
          this.fetchContributors(repositoryId)
          this.fetchReadme(repositoryId)
          this.fetchContributing(repositoryId)
        } else if (this.statusData.status === 'failed') {
          this.stopPolling()
        }
        return response.data.data
      } catch (err) {
        this.error = err.response?.data?.message || err.message || 'Failed to check status'
        this.stopPolling()
      }
    },

    async fetchTimeline(repositoryId) {
      this.timelineLoading = true
      try {
        const response = await apiClient.get(`/repositories/${repositoryId}/timeline`)
        this.timelineData = response.data.data
        return response.data.data
      } catch (err) {
        console.error('Failed to load timeline:', err)
      } finally {
        this.timelineLoading = false
      }
    },

    async fetchContributors(repositoryId) {
      this.contributorsLoading = true
      try {
        const response = await apiClient.get(`/repositories/${repositoryId}/contributors`)
        this.contributorsData = response.data.data
        return response.data.data
      } catch (err) {
        console.error('Failed to load contributors:', err)
      } finally {
        this.contributorsLoading = false
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
