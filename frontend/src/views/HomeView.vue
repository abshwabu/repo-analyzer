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
          <div>
            <h3>{{ repoStore.statusData.owner }}/{{ repoStore.statusData.name }}</h3>
            <p v-if="repoStore.statusData.description" class="repo-desc">
              {{ repoStore.statusData.description }}
            </p>
          </div>
          <span class="status-pill" :class="repoStore.statusData.status">
            {{ repoStore.statusData.status.toUpperCase() }}
          </span>
        </div>

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
            <span class="stat-label">Technologies</span>
          </div>
          <div class="stat-item">
            <span class="stat-value">{{ repoStore.statusData.stars ?? 0 }}</span>
            <span class="stat-label">Stars</span>
          </div>
        </div>

        <div v-if="repoStore.statusData.status === 'processing' || repoStore.statusData.status === 'pending'" class="polling-indicator">
          <span class="spinner"></span> Analyzing repository in background...
        </div>

        <div v-if="repoStore.statusData.status === 'completed'" class="tabs-container">
          <div class="tabs-header">
            <button
              class="tab-btn"
              :class="{ active: activeTab === 'stack' }"
              @click="activeTab = 'stack'"
            >
              Tech Stack ({{ repoStore.statusData.tech_stack?.length ?? 0 }})
            </button>
            <button
              class="tab-btn"
              :class="{ active: activeTab === 'timeline' }"
              @click="activeTab = 'timeline'"
            >
              Commit Timeline
            </button>
            <button
              class="tab-btn"
              :class="{ active: activeTab === 'contributors' }"
              @click="activeTab = 'contributors'"
            >
              Contributors
            </button>
            <button
              class="tab-btn"
              :class="{ active: activeTab === 'ai' }"
              @click="activeTab = 'ai'"
            >
              ✨ AI Summary
            </button>
            <button
              class="tab-btn"
              :class="{ active: activeTab === 'readme' }"
              @click="activeTab = 'readme'"
            >
              📄 README.md
            </button>
          </div>

          <!-- Tech Stack Tab -->
          <div v-if="activeTab === 'stack'" class="tab-pane">
            <div v-if="repoStore.statusData.tech_stack && repoStore.statusData.tech_stack.length > 0" class="tech-tags">
              <span
                v-for="tech in repoStore.statusData.tech_stack"
                :key="tech.id"
                class="tech-tag"
                :class="tech.category"
              >
                <span class="tech-name">{{ tech.name }}</span>
                <span class="tech-meta">{{ tech.category }} • {{ Math.round(tech.confidence) }}%</span>
              </span>
            </div>
            <p v-else class="empty-state">No tech stack items detected.</p>
          </div>

          <!-- Timeline Tab -->
          <div v-if="activeTab === 'timeline'" class="tab-pane">
            <div v-if="repoStore.timelineLoading" class="loading-state">Loading timeline data...</div>
            <div v-else-if="repoStore.timelineData">
              <h4 class="section-title">Monthly Commit Volume</h4>
              <div v-if="repoStore.timelineData.monthly_volume?.length" class="volume-chart">
                <div
                  v-for="vol in repoStore.timelineData.monthly_volume"
                  :key="vol.period"
                  class="volume-bar-wrap"
                >
                  <div class="volume-bar-container">
                    <div
                      class="volume-bar"
                      :style="{ height: Math.max(10, Math.min(100, (vol.count / getMaxVolume(repoStore.timelineData.monthly_volume)) * 100)) + '%' }"
                    ></div>
                  </div>
                  <span class="vol-count">{{ vol.count }}</span>
                  <span class="vol-label">{{ vol.period }}</span>
                </div>
              </div>
              <p v-else class="empty-state">No commit volume records available.</p>

              <h4 class="section-title" style="margin-top: 1.5rem;">Significant Commits ({{ repoStore.timelineData.significant_commits?.length ?? 0 }})</h4>
              <div v-if="repoStore.timelineData.significant_commits?.length" class="commits-list">
                <div
                  v-for="commit in repoStore.timelineData.significant_commits"
                  :key="commit.id"
                  class="commit-item"
                >
                  <div class="commit-header">
                    <span class="commit-sha">{{ commit.short_sha }}</span>
                    <span class="commit-reason">{{ commit.reason }}</span>
                    <span class="commit-stats">+{{ commit.additions }} / -{{ commit.deletions }}</span>
                  </div>
                  <div class="commit-msg">{{ commit.message }}</div>
                  <div class="commit-meta">By {{ commit.author_name }} on {{ formatDate(commit.committed_at) }}</div>
                </div>
              </div>
              <p v-else class="empty-state">No significant commits detected.</p>
            </div>
          </div>

          <!-- Contributors Tab -->
          <div v-if="activeTab === 'contributors'" class="tab-pane">
            <div v-if="repoStore.contributorsLoading" class="loading-state">Loading contributors...</div>
            <div v-else-if="repoStore.contributorsData">
              <div v-if="repoStore.contributorsData.contributors?.length" class="contributors-list">
                <div
                  v-for="contrib in repoStore.contributorsData.contributors"
                  :key="contrib.id"
                  class="contributor-card"
                >
                  <div class="contrib-info">
                    <strong>{{ contrib.github_username }}</strong>
                    <span class="contrib-dates" v-if="contrib.first_commit_at">
                      {{ formatDate(contrib.first_commit_at) }} – {{ formatDate(contrib.last_commit_at) }}
                    </span>
                  </div>
                  <div class="contrib-stats">
                    <span class="contrib-count">{{ contrib.commit_count }} commits</span>
                    <span class="contrib-share">{{ contrib.percentage_share }}%</span>
                  </div>
                </div>
              </div>
              <p v-else class="empty-state">No contributors data recorded.</p>
            </div>
          </div>

          <!-- AI Summary Tab -->
          <div v-if="activeTab === 'ai'" class="tab-pane">
            <div class="ai-config-card">
              <h4 class="section-title">AI Summary (BYO Key)</h4>
              <p class="ai-desc">Select an AI provider and enter your API key to generate a structured architecture and getting-started analysis. Your key is never persisted.</p>

              <form @submit.prevent="handleGenerateSummary" class="ai-form">
                <div class="form-row">
                  <div class="form-group">
                    <label>Provider</label>
                    <select v-model="aiProvider" class="form-select">
                      <option value="anthropic">Anthropic (Claude)</option>
                      <option value="openai">OpenAI</option>
                    </select>
                  </div>
                  <div class="form-group" style="flex: 2;">
                    <label>API Key</label>
                    <input
                      v-model="aiApiKey"
                      type="password"
                      placeholder="sk-..."
                      class="input-field"
                      required
                    />
                  </div>
                </div>

                <button type="submit" class="btn" :disabled="repoStore.summaryLoading || !aiApiKey">
                  {{ repoStore.summaryLoading ? 'Generating Summary...' : 'Generate AI Summary' }}
                </button>
              </form>

              <div v-if="repoStore.summaryError" class="error-box">
                <strong>Error:</strong> {{ repoStore.summaryError }}
              </div>
            </div>

            <div v-if="repoStore.summaryData" class="summary-results">
              <div class="summary-card">
                <div class="summary-badge">
                  Generated with {{ repoStore.summaryData.provider.toUpperCase() }} ({{ repoStore.summaryData.model }})
                </div>

                <div class="summary-block">
                  <h5>What this project does</h5>
                  <p>{{ repoStore.summaryData.summary.project_overview }}</p>
                </div>

                <div class="summary-block">
                  <h5>Architecture & Structure</h5>
                  <p>{{ repoStore.summaryData.summary.architecture }}</p>
                </div>

                <div class="summary-block">
                  <h5>Getting Started</h5>
                  <div v-if="repoStore.summaryData.summary.getting_started.prerequisites?.length" class="prereq-list">
                    <strong>Prerequisites:</strong>
                    <span v-for="p in repoStore.summaryData.summary.getting_started.prerequisites" :key="p" class="prereq-badge">
                      {{ p }}
                    </span>
                  </div>

                  <div v-if="repoStore.summaryData.summary.getting_started.install_commands?.length" class="command-box">
                    <span class="cmd-label">Installation:</span>
                    <code>{{ repoStore.summaryData.summary.getting_started.install_commands.join('\n') }}</code>
                  </div>

                  <div v-if="repoStore.summaryData.summary.getting_started.run_commands?.length" class="command-box">
                    <span class="cmd-label">Run Application:</span>
                    <code>{{ repoStore.summaryData.summary.getting_started.run_commands.join('\n') }}</code>
                  </div>

                  <p class="getting-started-instructions">{{ repoStore.summaryData.summary.getting_started.instructions }}</p>
                </div>
              </div>
            </div>
          </div>

          <!-- README Generator Tab -->
          <div v-if="activeTab === 'readme'" class="tab-pane">
            <div class="readme-toolbar">
              <button
                class="btn"
                @click="handleGenerateReadme"
                :disabled="repoStore.readmeLoading"
              >
                {{ repoStore.readmeLoading ? 'Generating README...' : repoStore.readmeData ? 'Regenerate README' : 'Generate Full README.md' }}
              </button>

              <div v-if="repoStore.readmeData" class="readme-actions">
                <button class="btn btn-secondary" @click="handleCopyReadme">
                  {{ copied ? '✓ Copied!' : 'Copy Markdown' }}
                </button>
                <a
                  :href="`/api/v1/repositories/${repoStore.statusData.id}/readme/download`"
                  class="btn btn-download"
                  download="README.md"
                >
                  ⬇ Download README.md
                </a>
              </div>
            </div>

            <div v-if="repoStore.readmeError" class="error-box" style="margin-top: 1rem;">
              <strong>Error:</strong> {{ repoStore.readmeError }}
            </div>

            <div v-if="repoStore.readmeData" class="readme-viewer">
              <div class="readme-meta">
                Generated at {{ formatDate(repoStore.readmeData.generated_at) }}
              </div>
              <pre class="markdown-preview"><code>{{ repoStore.readmeData.content }}</code></pre>
            </div>
            <div v-else-if="!repoStore.readmeLoading" class="empty-state">
              No README generated yet. Click the button above to generate a comprehensive, standard markdown README.md!
            </div>
          </div>
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
const activeTab = ref('stack')

const aiProvider = ref('anthropic')
const aiApiKey = ref('')
const copied = ref(false)

const handleSubmit = async () => {
  if (!githubUrl.value) return
  try {
    await repoStore.analyzeRepository(githubUrl.value)
  } catch (e) {
    // Handled in store
  }
}

const handleGenerateSummary = async () => {
  if (!repoStore.statusData?.id || !aiApiKey.value) return
  try {
    await repoStore.generateSummary(repoStore.statusData.id, {
      provider: aiProvider.value,
      apiKey: aiApiKey.value,
    })
  } catch (e) {
    // Handled in store
  }
}

const handleGenerateReadme = async () => {
  if (!repoStore.statusData?.id) return
  try {
    await repoStore.generateReadme(repoStore.statusData.id, {
      provider: aiApiKey.value ? aiProvider.value : undefined,
      apiKey: aiApiKey.value || undefined,
    })
  } catch (e) {
    // Handled in store
  }
}

const handleCopyReadme = async () => {
  if (!repoStore.readmeData?.content) return
  try {
    await navigator.clipboard.writeText(repoStore.readmeData.content)
    copied.value = true
    setTimeout(() => {
      copied.value = false
    }, 2000)
  } catch (err) {
    console.error('Failed to copy to clipboard', err)
  }
}

const getMaxVolume = (volumes) => {
  if (!volumes || !volumes.length) return 1
  return Math.max(...volumes.map(v => v.count), 1)
}

const formatDate = (dateStr) => {
  if (!dateStr) return 'N/A'
  try {
    return new Date(dateStr).toLocaleString()
  } catch {
    return dateStr
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
.tech-stack-section {
  margin-top: 1.5rem;
}
.tech-stack-section h4 {
  font-size: 0.95rem;
  color: #374151;
  margin-bottom: 0.75rem;
}
.tech-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.tech-tag {
  display: inline-flex;
  flex-direction: column;
  padding: 0.4rem 0.75rem;
  border-radius: 6px;
  background: #ffffff;
  border: 1px solid #e5e7eb;
}
.tech-tag.language {
  border-left: 3px solid #3b82f6;
}
.tech-tag.framework {
  border-left: 3px solid #10b981;
}
.tech-tag.database {
  border-left: 3px solid #f59e0b;
}
.tech-tag.devops {
  border-left: 3px solid #8b5cf6;
}
.tech-tag.testing {
  border-left: 3px solid #ec4899;
}
.tech-name {
  font-size: 0.875rem;
  font-weight: 600;
  color: #111827;
}
.tech-meta {
  font-size: 0.7rem;
  color: #6b7280;
  text-transform: capitalize;
}
.polling-indicator {
  margin-top: 1rem;
  font-size: 0.85rem;
  color: #6366f1;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.tabs-container {
  margin-top: 1.5rem;
}
.tabs-header {
  display: flex;
  gap: 0.5rem;
  border-bottom: 2px solid #e5e7eb;
  padding-bottom: 0.5rem;
  margin-bottom: 1.25rem;
}
.tab-btn {
  background: none;
  border: none;
  padding: 0.5rem 1rem;
  font-size: 0.9rem;
  font-weight: 600;
  color: #6b7280;
  cursor: pointer;
  border-radius: 6px;
  transition: all 0.2s;
}
.tab-btn:hover {
  color: #4f46e5;
  background-color: #f3f4f6;
}
.tab-btn.active {
  color: #4f46e5;
  background-color: #eef2ff;
}
.tab-pane {
  padding-top: 0.5rem;
}
.section-title {
  font-size: 0.95rem;
  color: #374151;
  margin-bottom: 0.75rem;
}
.volume-chart {
  display: flex;
  align-items: flex-end;
  gap: 0.75rem;
  height: 140px;
  background: #ffffff;
  padding: 1rem 0.5rem;
  border-radius: 8px;
  border: 1px solid #e5e7eb;
  overflow-x: auto;
}
.volume-bar-wrap {
  display: flex;
  flex-direction: column;
  align-items: center;
  flex: 1;
  min-width: 45px;
  height: 100%;
}
.volume-bar-container {
  flex: 1;
  width: 100%;
  display: flex;
  align-items: flex-end;
  justify-content: center;
}
.volume-bar {
  width: 24px;
  background: linear-gradient(180deg, #6366f1 0%, #4f46e5 100%);
  border-radius: 4px 4px 0 0;
  transition: height 0.3s ease;
}
.vol-count {
  font-size: 0.75rem;
  font-weight: 700;
  color: #1f2937;
  margin-top: 0.25rem;
}
.vol-label {
  font-size: 0.65rem;
  color: #6b7280;
  margin-top: 0.1rem;
}
.commits-list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.commit-item {
  background: #ffffff;
  padding: 0.75rem 1rem;
  border-radius: 8px;
  border: 1px solid #e5e7eb;
}
.commit-header {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.35rem;
}
.commit-sha {
  font-family: monospace;
  font-weight: 700;
  background: #f3f4f6;
  padding: 0.15rem 0.4rem;
  border-radius: 4px;
  font-size: 0.8rem;
  color: #4f46e5;
}
.commit-reason {
  background: #eef2ff;
  color: #4338ca;
  font-size: 0.75rem;
  padding: 0.15rem 0.5rem;
  border-radius: 9999px;
  text-transform: capitalize;
}
.commit-stats {
  font-size: 0.75rem;
  color: #166534;
  margin-left: auto;
  font-weight: 600;
}
.commit-msg {
  font-size: 0.875rem;
  color: #1f2937;
  font-weight: 500;
  margin-bottom: 0.25rem;
}
.commit-meta {
  font-size: 0.75rem;
  color: #6b7280;
}
.contributors-list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.contributor-card {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #ffffff;
  padding: 0.75rem 1rem;
  border-radius: 8px;
  border: 1px solid #e5e7eb;
}
.contrib-info {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}
.contrib-dates {
  font-size: 0.75rem;
  color: #6b7280;
}
.contrib-stats {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}
.contrib-count {
  font-size: 0.85rem;
  color: #4b5563;
}
.contrib-share {
  font-weight: 700;
  color: #4f46e5;
  background: #eef2ff;
  padding: 0.2rem 0.5rem;
  border-radius: 6px;
  font-size: 0.8rem;
}
.empty-state, .loading-state {
  color: #6b7280;
  font-size: 0.9rem;
  padding: 1rem 0;
}
.ai-config-card {
  background: #f9fafb;
  border: 1px solid #e5e7eb;
  padding: 1.25rem;
  border-radius: 8px;
  margin-bottom: 1.25rem;
}
.ai-desc {
  font-size: 0.85rem;
  color: #6b7280;
  margin-bottom: 1rem;
}
.ai-form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
.form-row {
  display: flex;
  gap: 1rem;
}
.form-group {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.form-group label {
  font-size: 0.8rem;
  font-weight: 600;
  color: #374151;
}
.form-select {
  padding: 0.65rem 0.85rem;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  background-color: #ffffff;
  font-size: 0.9rem;
  outline: none;
}
.summary-results {
  margin-top: 1.25rem;
}
.summary-card {
  background: #ffffff;
  border: 1px solid #e0e7ff;
  border-radius: 8px;
  padding: 1.5rem;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}
.summary-badge {
  display: inline-block;
  background: #eef2ff;
  color: #4338ca;
  padding: 0.25rem 0.65rem;
  border-radius: 9999px;
  font-size: 0.75rem;
  font-weight: 700;
  margin-bottom: 1rem;
}
.summary-block {
  margin-bottom: 1.25rem;
}
.summary-block h5 {
  font-size: 0.95rem;
  color: #1f2937;
  margin-bottom: 0.4rem;
  font-weight: 700;
}
.summary-block p {
  font-size: 0.9rem;
  color: #4b5563;
  line-height: 1.5;
}
.prereq-list {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-bottom: 0.75rem;
  font-size: 0.85rem;
  color: #374151;
}
.prereq-badge {
  background: #f3f4f6;
  border: 1px solid #e5e7eb;
  padding: 0.15rem 0.5rem;
  border-radius: 4px;
  font-size: 0.8rem;
  font-weight: 600;
}
.command-box {
  background: #1f2937;
  color: #f9fafb;
  padding: 0.75rem 1rem;
  border-radius: 6px;
  margin-bottom: 0.5rem;
}
.command-box .cmd-label {
  display: block;
  font-size: 0.7rem;
  color: #9ca3af;
  margin-bottom: 0.25rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.command-box code {
  font-family: monospace;
  font-size: 0.85rem;
  white-space: pre-wrap;
  color: #a7f3d0;
}
.getting-started-instructions {
  margin-top: 0.5rem;
  font-style: italic;
}
.readme-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.75rem;
  margin-bottom: 1rem;
}
.readme-actions {
  display: flex;
  gap: 0.5rem;
}
.btn-secondary {
  background-color: #f3f4f6;
  color: #374151;
  border: 1px solid #d1d5db;
}
.btn-secondary:hover {
  background-color: #e5e7eb;
}
.btn-download {
  background-color: #10b981;
  color: #ffffff;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  padding: 0.75rem 1.25rem;
  border-radius: 8px;
  font-weight: 600;
  font-size: 0.9rem;
  transition: background-color 0.2s;
}
.btn-download:hover {
  background-color: #059669;
}
.readme-viewer {
  background: #1e1e1e;
  border-radius: 8px;
  overflow: hidden;
  border: 1px solid #374151;
}
.readme-meta {
  background: #2d2d2d;
  color: #9ca3af;
  font-size: 0.75rem;
  padding: 0.5rem 1rem;
  border-bottom: 1px solid #374151;
}
.markdown-preview {
  margin: 0;
  padding: 1.25rem;
  max-height: 500px;
  overflow-y: auto;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 0.85rem;
  line-height: 1.6;
  color: #e5e7eb;
  white-space: pre-wrap;
}
</style>
