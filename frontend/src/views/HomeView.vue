<template>
  <div class="home-view">
    <!-- Hero / Input Form -->
    <section class="hero-card">
      <div class="hero-header">
        <h2>Analyze Any GitHub Repository</h2>
        <p>Ingest commits, contributors, detect tech stack from manifests, generate AI summaries and full README.md files.</p>
      </div>

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
          <button type="submit" class="btn btn-primary" :disabled="repoStore.loading || repoStore.polling">
            <span v-if="repoStore.loading || repoStore.polling" class="btn-spinner"></span>
            {{ repoStore.loading ? 'Queuing...' : repoStore.polling ? 'Analyzing...' : 'Analyze Repo' }}
          </button>
        </div>

        <div class="examples-row">
          <span class="examples-label">Try example:</span>
          <button
            v-for="example in exampleRepos"
            :key="example"
            type="button"
            class="chip-btn"
            :disabled="repoStore.loading || repoStore.polling"
            @click="selectExample(example)"
          >
            {{ example }}
          </button>
        </div>
      </form>

      <!-- Polling Progress Indicator -->
      <ProgressIndicator
        v-if="repoStore.statusData && (repoStore.polling || repoStore.statusData.status === 'processing' || repoStore.statusData.status === 'pending' || repoStore.statusData.status === 'failed')"
        :status="repoStore.statusData.status"
        :stats="repoStore.statusData.stats"
        :error-message="repoStore.statusData.error_message || repoStore.error"
      />

      <div v-if="repoStore.error && !repoStore.statusData" class="error-banner">
        <strong>Error:</strong> {{ repoStore.error }}
      </div>
    </section>

    <!-- Repo Dashboard (Shown when repository status is loaded) -->
    <section v-if="repoStore.statusData" class="dashboard-section">
      <!-- Overview Card Header -->
      <div class="overview-card">
        <div class="overview-main">
          <div class="repo-title-wrap">
            <img
              :src="`https://github.com/${repoStore.statusData.owner}.png`"
              class="owner-avatar"
              alt="Owner"
              @error="(e) => e.target.style.display = 'none'"
            />
            <div>
              <div class="title-row">
                <a
                  :href="repoStore.statusData.github_url"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="repo-name"
                >
                  {{ repoStore.statusData.owner }} / {{ repoStore.statusData.name }} ↗
                </a>
                <span class="status-badge" :class="repoStore.statusData.status">
                  {{ repoStore.statusData.status.toUpperCase() }}
                </span>
              </div>
              <p v-if="repoStore.statusData.description" class="repo-desc">
                {{ repoStore.statusData.description }}
              </p>
            </div>
          </div>

          <div class="repo-badges">
            <span class="badge" v-if="repoStore.statusData.stars !== null">
              ⭐ {{ repoStore.statusData.stars }} stars
            </span>
            <span class="badge" v-if="repoStore.statusData.license">
              ⚖️ {{ repoStore.statusData.license }}
            </span>
            <span class="badge" v-if="repoStore.statusData.default_branch">
              🌿 {{ repoStore.statusData.default_branch }}
            </span>
            <span class="badge badge-lang" v-if="primaryLanguage">
              💻 {{ primaryLanguage }}
            </span>
          </div>
        </div>

        <div class="metrics-grid">
          <div class="metric-card">
            <span class="metric-val">{{ repoStore.statusData.stats?.commits_count ?? 0 }}</span>
            <span class="metric-lbl">Total Commits</span>
          </div>
          <div class="metric-card">
            <span class="metric-val">{{ repoStore.statusData.stats?.contributors_count ?? 0 }}</span>
            <span class="metric-lbl">Contributors</span>
          </div>
          <div class="metric-card">
            <span class="metric-val">{{ repoStore.statusData.stats?.tech_stack_count ?? 0 }}</span>
            <span class="metric-lbl">Technologies</span>
          </div>
        </div>
      </div>

      <!-- Main Navigation Tabs -->
      <div class="tabs-nav">
        <button
          class="nav-tab"
          :class="{ active: activeTab === 'dashboard' }"
          @click="activeTab = 'dashboard'"
        >
          📊 Dashboard & Activity
        </button>
        <button
          class="nav-tab"
          :class="{ active: activeTab === 'readme' }"
          @click="activeTab = 'readme'"
        >
          📄 README.md
        </button>
        <button
          class="nav-tab"
          :class="{ active: activeTab === 'contributing' }"
          @click="activeTab = 'contributing'"
        >
          🤝 Contributing Guide
        </button>
        <button
          class="nav-tab"
          :class="{ active: activeTab === 'ai' }"
          @click="activeTab = 'ai'"
        >
          ✨ AI Summary
        </button>
      </div>

      <!-- Tab 1: Dashboard & Activity -->
      <div v-if="activeTab === 'dashboard'" class="tab-content">
        <!-- Tech Stack Section -->
        <div class="card-panel">
          <div class="panel-header">
            <h3>Detected Tech Stack</h3>
            <span class="panel-meta">{{ repoStore.statusData.tech_stack?.length ?? 0 }} components detected</span>
          </div>

          <div v-if="groupedTechStack && Object.keys(groupedTechStack).length > 0" class="tech-groups-grid">
            <div
              v-for="(items, category) in groupedTechStack"
              :key="category"
              class="tech-group-card"
            >
              <h4 class="category-title">{{ formatCategory(category) }}</h4>
              <div class="tech-item-list">
                <div
                  v-for="item in items"
                  :key="item.id"
                  class="tech-item-row"
                >
                  <div class="tech-info">
                    <span class="tech-item-name">{{ item.name }}</span>
                    <span class="tech-conf">{{ Math.round(item.confidence) }}% confidence</span>
                  </div>
                  <div class="conf-bar-bg">
                    <div
                      class="conf-bar-fill"
                      :class="category"
                      :style="{ width: `${item.confidence}%` }"
                    ></div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div v-else class="empty-notice">No tech stack items detected yet.</div>
        </div>

        <!-- Commit Activity Chart & Significant Commits -->
        <div class="card-panel">
          <div class="panel-header">
            <h3>Commit Activity & History</h3>
            <span class="panel-meta">Monthly volume breakdown</span>
          </div>

          <div v-if="repoStore.timelineLoading" class="loading-box">Loading commit timeline...</div>
          <div v-else-if="repoStore.timelineData">
            <!-- Chart.js Activity Chart -->
            <CommitActivityChart :monthly-volume="repoStore.timelineData.monthly_volume" />

            <!-- Significant Commits Sub-section -->
            <div class="significant-commits-box">
              <div class="sub-header">
                <h4>Significant Commits ({{ repoStore.timelineData.significant_commits?.length ?? 0 }})</h4>
                <span class="sub-hint">Conventional commit features, breaking changes, & major updates</span>
              </div>

              <div v-if="repoStore.timelineData.significant_commits?.length" class="commits-table-wrap">
                <table class="commits-table">
                  <thead>
                    <tr>
                      <th>SHA</th>
                      <th>Message</th>
                      <th>Author</th>
                      <th>Date</th>
                      <th>Diff</th>
                      <th>Type</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="commit in repoStore.timelineData.significant_commits"
                      :key="commit.id"
                    >
                      <td><code class="sha-pill">{{ commit.short_sha }}</code></td>
                      <td class="msg-cell">{{ commit.message }}</td>
                      <td class="author-cell">{{ commit.author_name }}</td>
                      <td class="date-cell">{{ formatDate(commit.committed_at) }}</td>
                      <td class="diff-cell">
                        <span class="diff-add">+{{ commit.additions }}</span>
                        <span class="diff-del">-{{ commit.deletions }}</span>
                      </td>
                      <td>
                        <span class="reason-pill">{{ commit.reason }}</span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div v-else class="empty-notice">No significant conventional commits identified.</div>
            </div>
          </div>
        </div>

        <!-- Contributors Section -->
        <div class="card-panel">
          <div class="panel-header">
            <h3>Contributors Ranking</h3>
            <span class="panel-meta">{{ repoStore.contributorsData?.total_contributors ?? 0 }} active contributors</span>
          </div>

          <div v-if="repoStore.contributorsLoading" class="loading-box">Loading contributors...</div>
          <div v-else-if="repoStore.contributorsData && repoStore.contributorsData.contributors?.length" class="contributors-grid">
            <div
              v-for="contrib in repoStore.contributorsData.contributors"
              :key="contrib.id"
              class="contrib-card"
            >
              <img
                :src="`https://github.com/${contrib.github_username}.png`"
                class="contrib-avatar"
                alt="Avatar"
                @error="(e) => e.target.src = 'https://github.githubassets.com/images/modules/logos_page/GitHub-Mark.png'"
              />
              <div class="contrib-details">
                <a
                  :href="`https://github.com/${contrib.github_username}`"
                  target="_blank"
                  class="contrib-name"
                >
                  {{ contrib.github_username }} ↗
                </a>
                <span class="contrib-date-range" v-if="contrib.first_commit_at">
                  {{ formatDate(contrib.first_commit_at) }} – {{ formatDate(contrib.last_commit_at) }}
                </span>
                <div class="contrib-share-bar">
                  <div
                    class="contrib-share-fill"
                    :style="{ width: `${contrib.percentage_share}%` }"
                  ></div>
                </div>
              </div>
              <div class="contrib-stats-col">
                <span class="contrib-commits">{{ contrib.commit_count }} commits</span>
                <span class="contrib-pct">{{ contrib.percentage_share }}%</span>
              </div>
            </div>
          </div>
          <div v-else class="empty-notice">No contributor data recorded.</div>
        </div>
      </div>

      <!-- Tab 2: README.md -->
      <div v-if="activeTab === 'readme'" class="tab-content">
        <div class="card-panel">
          <div class="panel-header">
            <h3>Generated README.md</h3>
            <div class="header-actions">
              <button
                class="btn btn-secondary"
                :disabled="repoStore.readmeLoading"
                @click="handleGenerateReadme"
              >
                {{ repoStore.readmeLoading ? 'Generating...' : '🔄 Regenerate README' }}
              </button>
              <a
                v-if="repoStore.readmeData"
                :href="`/api/v1/repositories/${repoStore.statusData.id}/readme/download`"
                class="btn btn-primary"
                download="README.md"
              >
                ⬇ Download .md
              </a>
            </div>
          </div>

          <div v-if="repoStore.readmeLoading" class="loading-box">Generating comprehensive README.md...</div>
          <div v-else-if="repoStore.readmeData">
            <MarkdownRenderer :content="repoStore.readmeData.content" />
          </div>
          <div v-else class="empty-state-box">
            <p>No README generated yet for this repository.</p>
            <button class="btn btn-primary" @click="handleGenerateReadme">
              Generate Full README.md
            </button>
          </div>
        </div>
      </div>

      <!-- Tab 3: Contributing Guide -->
      <div v-if="activeTab === 'contributing'" class="tab-content">
        <div class="card-panel">
          <div class="panel-header">
            <h3>How to Contribute Guide</h3>
            <span class="panel-meta">Inferred setup, branch conventions, commit rules & PR checklist</span>
          </div>

          <div v-if="repoStore.contributingLoading" class="loading-box">Loading contributing guide...</div>
          <div v-else-if="repoStore.contributingData">
            <MarkdownRenderer :content="repoStore.contributingData.markdown" />
          </div>
          <div v-else class="empty-notice">Contributing guide not available.</div>
        </div>
      </div>

      <!-- Tab 4: AI Summary -->
      <div v-if="activeTab === 'ai'" class="tab-content">
        <div class="card-panel">
          <div class="panel-header">
            <h3>AI Repository Summarization</h3>
            <button class="btn btn-secondary" @click="settingsStore.openModal">
              ⚙️ {{ settingsStore.hasApiKey ? 'Change AI Key' : 'Setup AI Key' }}
            </button>
          </div>

          <div v-if="!settingsStore.hasApiKey" class="ai-banner">
            <div>
              <strong>No AI API Key Set in Session:</strong>
              <p>Configure your OpenAI or Anthropic API key in Settings to run LLM summarization. Keys are never saved server-side.</p>
            </div>
            <button class="btn btn-primary" @click="settingsStore.openModal">
              Configure Key
            </button>
          </div>

          <div v-else class="ai-action-bar">
            <span>Active Provider: <strong>{{ settingsStore.provider.toUpperCase() }}</strong></span>
            <button
              class="btn btn-primary"
              :disabled="repoStore.summaryLoading"
              @click="handleRunAiSummary"
            >
              {{ repoStore.summaryLoading ? 'Generating...' : '✨ Run AI Summary' }}
            </button>
          </div>

          <div v-if="repoStore.summaryLoading" class="loading-box">Analyzing repository with {{ settingsStore.provider }}...</div>
          <div v-else-if="repoStore.summaryData" class="summary-card">
            <div class="summary-item">
              <h4>What this project does</h4>
              <p>{{ repoStore.summaryData.summary.project_overview }}</p>
            </div>

            <div class="summary-item">
              <h4>Architecture & Structure</h4>
              <p>{{ repoStore.summaryData.summary.architecture }}</p>
            </div>

            <div class="summary-item">
              <h4>Getting Started</h4>
              <div v-if="repoStore.summaryData.summary.getting_started.prerequisites?.length" class="prereq-wrap">
                <strong>Prerequisites:</strong>
                <span v-for="p in repoStore.summaryData.summary.getting_started.prerequisites" :key="p" class="badge">
                  {{ p }}
                </span>
              </div>

              <div v-if="repoStore.summaryData.summary.getting_started.install_commands?.length" class="code-box">
                <span class="box-label">Install Commands:</span>
                <code>{{ repoStore.summaryData.summary.getting_started.install_commands.join('\n') }}</code>
              </div>

              <div v-if="repoStore.summaryData.summary.getting_started.run_commands?.length" class="code-box">
                <span class="box-label">Run Commands:</span>
                <code>{{ repoStore.summaryData.summary.getting_started.run_commands.join('\n') }}</code>
              </div>

              <p class="summary-instructions">{{ repoStore.summaryData.summary.getting_started.instructions }}</p>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { ref, computed, onUnmounted } from 'vue'
import { useRepositoryStore } from '../stores/repository'
import { useSettingsStore } from '../stores/settings'
import ProgressIndicator from '../components/ProgressIndicator.vue'
import CommitActivityChart from '../components/CommitActivityChart.vue'
import MarkdownRenderer from '../components/MarkdownRenderer.vue'

const repoStore = useRepositoryStore()
const settingsStore = useSettingsStore()

const githubUrl = ref('')
const activeTab = ref('dashboard')

const exampleRepos = [
  'https://github.com/laravel/laravel',
  'https://github.com/vuejs/core',
  'https://github.com/facebook/react',
]

const selectExample = (url) => {
  githubUrl.value = url
  handleSubmit()
}

const handleSubmit = async () => {
  if (!githubUrl.value) return
  try {
    await repoStore.analyzeRepository(githubUrl.value)
  } catch (e) {
    // Handled in store
  }
}

const handleGenerateReadme = async () => {
  if (!repoStore.statusData?.id) return
  try {
    await repoStore.generateReadme(repoStore.statusData.id, {
      provider: settingsStore.hasApiKey ? settingsStore.provider : undefined,
      apiKey: settingsStore.hasApiKey ? settingsStore.apiKey : undefined,
      model: settingsStore.model || undefined,
    })
  } catch (e) {
    // Handled in store
  }
}

const handleRunAiSummary = async () => {
  if (!repoStore.statusData?.id || !settingsStore.hasApiKey) return
  try {
    await repoStore.generateSummary(repoStore.statusData.id, {
      provider: settingsStore.provider,
      apiKey: settingsStore.apiKey,
      model: settingsStore.model || undefined,
    })
  } catch (e) {
    // Handled in store
  }
}

const primaryLanguage = computed(() => {
  const languages = repoStore.statusData?.tech_stack?.filter((t) => t.category === 'language')
  if (!languages || !languages.length) return null
  return languages[0]?.name
})

const groupedTechStack = computed(() => {
  const stack = repoStore.statusData?.tech_stack
  if (!stack || !stack.length) return {}
  return stack.reduce((acc, item) => {
    const cat = item.category || 'other'
    if (!acc[cat]) acc[cat] = []
    acc[cat].push(item)
    return acc
  }, {})
})

const formatCategory = (cat) => {
  const map = {
    language: 'Languages',
    framework: 'Frameworks & Libraries',
    database: 'Databases & ORMs',
    devops: 'DevOps & Tooling',
    testing: 'Testing Frameworks',
  }
  return map[cat] || cat.toUpperCase()
}

const formatDate = (dateStr) => {
  if (!dateStr) return 'N/A'
  try {
    return new Date(dateStr).toLocaleDateString()
  } catch {
    return dateStr
  }
}

onUnmounted(() => {
  repoStore.stopPolling()
})
</script>

<style scoped>
.home-view {
  display: flex;
  flex-direction: column;
  gap: 1.75rem;
}

/* Hero Section */
.hero-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 2rem;
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
}
.hero-header h2 {
  font-size: 1.6rem;
  font-weight: 800;
  color: #0f172a;
  margin: 0 0 0.4rem 0;
}
.hero-header p {
  color: #64748b;
  font-size: 0.95rem;
  margin: 0 0 1.5rem 0;
}
.analyze-form {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.input-group {
  display: flex;
  gap: 0.75rem;
}
.input-field {
  flex: 1;
  padding: 0.85rem 1.15rem;
  border: 1.5px solid #cbd5e1;
  border-radius: 8px;
  font-size: 0.95rem;
  outline: none;
  transition: border-color 0.2s;
}
.input-field:focus {
  border-color: #4f46e5;
}
.btn {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.85rem 1.5rem;
  border-radius: 8px;
  font-size: 0.95rem;
  font-weight: 700;
  border: none;
  cursor: pointer;
  transition: all 0.2s;
  text-decoration: none;
}
.btn-primary {
  background: #4f46e5;
  color: #ffffff;
}
.btn-primary:hover:not(:disabled) {
  background: #4338ca;
}
.btn-secondary {
  background: #f1f5f9;
  color: #334155;
  border: 1px solid #cbd5e1;
}
.btn-secondary:hover:not(:disabled) {
  background: #e2e8f0;
}
.btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.btn-spinner {
  width: 14px;
  height: 14px;
  border: 2px solid #ffffff;
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 1s linear infinite;
}
.examples-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-top: 0.25rem;
}
.examples-label {
  font-size: 0.8rem;
  color: #64748b;
}
.chip-btn {
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  color: #475569;
  padding: 0.2rem 0.55rem;
  border-radius: 9999px;
  font-size: 0.75rem;
  cursor: pointer;
  transition: all 0.2s;
}
.chip-btn:hover:not(:disabled) {
  background: #e0e7ff;
  color: #4338ca;
  border-color: #c7d2fe;
}
.error-banner {
  margin-top: 1rem;
  padding: 0.75rem 1rem;
  background: #fef2f2;
  border: 1px solid #f87171;
  border-radius: 8px;
  color: #991b1b;
  font-size: 0.9rem;
}

/* Dashboard Section */
.dashboard-section {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}
.overview-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 1.5rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1.5rem;
  flex-wrap: wrap;
}
.overview-main {
  flex: 1;
  min-width: 320px;
}
.repo-title-wrap {
  display: flex;
  align-items: flex-start;
  gap: 1rem;
  margin-bottom: 0.75rem;
}
.owner-avatar {
  width: 44px;
  height: 44px;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
}
.title-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
}
.repo-name {
  font-size: 1.35rem;
  font-weight: 800;
  color: #0f172a;
  text-decoration: none;
}
.repo-name:hover {
  color: #4f46e5;
}
.status-badge {
  font-size: 0.7rem;
  font-weight: 800;
  padding: 0.2rem 0.55rem;
  border-radius: 9999px;
}
.status-badge.completed { background: #dcfce7; color: #166534; }
.status-badge.processing { background: #dbeafe; color: #1e40af; }
.status-badge.pending { background: #fef3c7; color: #92400e; }
.status-badge.failed { background: #fee2e2; color: #991b1b; }

.repo-desc {
  font-size: 0.9rem;
  color: #475569;
  margin: 0.35rem 0 0 0;
  line-height: 1.4;
}
.repo-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-top: 0.75rem;
}
.badge {
  background: #f1f5f9;
  color: #475569;
  padding: 0.25rem 0.6rem;
  border-radius: 6px;
  font-size: 0.75rem;
  font-weight: 600;
}
.badge-lang {
  background: #eef2ff;
  color: #4338ca;
}

.metrics-grid {
  display: flex;
  gap: 1rem;
}
.metric-card {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  padding: 0.85rem 1.25rem;
  border-radius: 8px;
  text-align: center;
  min-width: 100px;
}
.metric-val {
  display: block;
  font-size: 1.4rem;
  font-weight: 800;
  color: #0f172a;
}
.metric-lbl {
  font-size: 0.7rem;
  color: #64748b;
  text-transform: uppercase;
  font-weight: 600;
}

/* Tabs Navigation */
.tabs-nav {
  display: flex;
  gap: 0.5rem;
  border-bottom: 2px solid #e2e8f0;
  padding-bottom: 0.25rem;
}
.nav-tab {
  background: none;
  border: none;
  padding: 0.65rem 1.15rem;
  font-size: 0.95rem;
  font-weight: 700;
  color: #64748b;
  cursor: pointer;
  border-radius: 8px;
  transition: all 0.2s;
}
.nav-tab:hover {
  color: #4f46e5;
  background: #f1f5f9;
}
.nav-tab.active {
  color: #4f46e5;
  background: #eef2ff;
}

/* Panels */
.tab-content {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}
.card-panel {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 1.5rem;
}
.panel-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.25rem;
  border-bottom: 1px solid #f1f5f9;
  padding-bottom: 0.75rem;
}
.panel-header h3 {
  font-size: 1.15rem;
  font-weight: 800;
  color: #0f172a;
  margin: 0;
}
.panel-meta {
  font-size: 0.8rem;
  color: #64748b;
}
.header-actions {
  display: flex;
  gap: 0.5rem;
}

/* Tech Stack Groups */
.tech-groups-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 1rem;
}
.tech-group-card {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 1rem;
}
.category-title {
  font-size: 0.85rem;
  color: #475569;
  margin: 0 0 0.75rem 0;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.tech-item-list {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}
.tech-item-row {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}
.tech-info {
  display: flex;
  justify-content: space-between;
  font-size: 0.825rem;
}
.tech-item-name {
  font-weight: 700;
  color: #1e293b;
}
.tech-conf {
  color: #64748b;
  font-size: 0.75rem;
}
.conf-bar-bg {
  height: 5px;
  background: #e2e8f0;
  border-radius: 9999px;
  overflow: hidden;
}
.conf-bar-fill {
  height: 100%;
  border-radius: 9999px;
}
.conf-bar-fill.language { background: #3b82f6; }
.conf-bar-fill.framework { background: #10b981; }
.conf-bar-fill.database { background: #f59e0b; }
.conf-bar-fill.devops { background: #8b5cf6; }
.conf-bar-fill.testing { background: #ec4899; }

/* Significant Commits Table */
.significant-commits-box {
  margin-top: 1.75rem;
}
.sub-header {
  margin-bottom: 0.75rem;
}
.sub-header h4 {
  font-size: 0.95rem;
  font-weight: 700;
  color: #1e293b;
  margin: 0;
}
.sub-hint {
  font-size: 0.75rem;
  color: #64748b;
}
.commits-table-wrap {
  overflow-x: auto;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
}
.commits-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.85rem;
}
.commits-table th, .commits-table td {
  padding: 0.65rem 0.85rem;
  border-bottom: 1px solid #f1f5f9;
  text-align: left;
}
.commits-table th {
  background: #f8fafc;
  color: #475569;
  font-weight: 700;
}
.sha-pill {
  background: #eef2ff;
  color: #4338ca;
  padding: 0.15rem 0.4rem;
  border-radius: 4px;
  font-weight: 700;
}
.msg-cell {
  color: #0f172a;
  font-weight: 500;
  max-width: 320px;
}
.diff-cell {
  display: flex;
  gap: 0.35rem;
  font-weight: 700;
  font-size: 0.75rem;
}
.diff-add { color: #16a34a; }
.diff-del { color: #dc2626; }
.reason-pill {
  background: #f1f5f9;
  color: #475569;
  padding: 0.15rem 0.45rem;
  border-radius: 9999px;
  font-size: 0.7rem;
  text-transform: capitalize;
}

/* Contributors Grid */
.contributors-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 0.85rem;
}
.contrib-card {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 0.85rem 1rem;
  display: flex;
  align-items: center;
  gap: 0.85rem;
}
.contrib-avatar {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  border: 1px solid #cbd5e1;
}
.contrib-details {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}
.contrib-name {
  font-weight: 700;
  color: #0f172a;
  text-decoration: none;
  font-size: 0.875rem;
}
.contrib-name:hover {
  color: #4f46e5;
}
.contrib-date-range {
  font-size: 0.7rem;
  color: #64748b;
}
.contrib-share-bar {
  height: 4px;
  background: #e2e8f0;
  border-radius: 9999px;
  overflow: hidden;
  margin-top: 0.2rem;
}
.contrib-share-fill {
  height: 100%;
  background: #4f46e5;
}
.contrib-stats-col {
  text-align: right;
}
.contrib-commits {
  display: block;
  font-size: 0.825rem;
  font-weight: 700;
  color: #1e293b;
}
.contrib-pct {
  font-size: 0.7rem;
  color: #64748b;
}

/* AI Summary Styles */
.ai-banner {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 8px;
  padding: 1rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.25rem;
}
.ai-banner p {
  margin: 0.2rem 0 0 0;
  font-size: 0.825rem;
  color: #166534;
}
.ai-action-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
  font-size: 0.875rem;
}
.summary-card {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}
.summary-item h4 {
  font-size: 0.95rem;
  color: #0f172a;
  margin: 0 0 0.35rem 0;
}
.summary-item p {
  color: #334155;
  line-height: 1.5;
  margin: 0;
  font-size: 0.9rem;
}
.prereq-wrap {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
  margin: 0.5rem 0;
}
.code-box {
  background: #0f172a;
  color: #f8fafc;
  padding: 0.75rem 1rem;
  border-radius: 6px;
  margin: 0.5rem 0;
}
.box-label {
  display: block;
  font-size: 0.7rem;
  color: #94a3b8;
  text-transform: uppercase;
  margin-bottom: 0.25rem;
}
.code-box code {
  font-family: monospace;
  font-size: 0.85rem;
  color: #a7f3d0;
  white-space: pre-wrap;
}

/* Empty / Loading States */
.empty-state-box, .loading-box, .empty-notice {
  text-align: center;
  padding: 2rem;
  color: #64748b;
  font-size: 0.9rem;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>
