<template>
  <div class="app-layout">
    <header class="navbar">
      <div class="nav-container">
        <div class="brand">
          <span class="brand-icon">⚡</span>
          <span class="brand-title">Repo Analyzer</span>
          <span class="brand-version">v1.0</span>
        </div>

        <div class="nav-actions">
          <button class="btn-settings" @click="settingsStore.openModal">
            <span class="settings-icon">⚙️</span>
            <span>AI Settings</span>
            <span v-if="settingsStore.hasApiKey" class="badge-active">Active</span>
          </button>
        </div>
      </div>
    </header>

    <main class="main-content">
      <router-view />
    </main>

    <footer class="app-footer">
      <p>Built with Laravel 11 + Vue 3 • BYO Key AI Summarization & Repository Intelligence</p>
    </footer>

    <!-- Global AI Settings Modal -->
    <AiSettingsModal />
  </div>
</template>

<script setup>
import { useSettingsStore } from './stores/settings'
import AiSettingsModal from './components/AiSettingsModal.vue'

const settingsStore = useSettingsStore()
</script>

<style scoped>
.app-layout {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  background-color: #f8fafc;
  color: #1e293b;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}
.navbar {
  background: #ffffff;
  border-bottom: 1px solid #e2e8f0;
  position: sticky;
  top: 0;
  z-index: 50;
}
.nav-container {
  max-width: 1200px;
  margin: 0 auto;
  padding: 0.85rem 1.5rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.brand {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.brand-icon {
  font-size: 1.35rem;
}
.brand-title {
  font-size: 1.15rem;
  font-weight: 800;
  background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
}
.brand-version {
  font-size: 0.7rem;
  background: #f1f5f9;
  color: #64748b;
  padding: 0.15rem 0.4rem;
  border-radius: 4px;
  font-weight: 600;
}
.btn-settings {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  color: #334155;
  padding: 0.45rem 0.85rem;
  border-radius: 8px;
  font-size: 0.85rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}
.btn-settings:hover {
  background: #f1f5f9;
  border-color: #cbd5e1;
}
.badge-active {
  background: #dcfce7;
  color: #15803d;
  font-size: 0.7rem;
  padding: 0.1rem 0.4rem;
  border-radius: 9999px;
  font-weight: 700;
}
.main-content {
  flex: 1;
  max-width: 1200px;
  width: 100%;
  margin: 0 auto;
  padding: 2rem 1.5rem;
}
.app-footer {
  text-align: center;
  padding: 1.5rem;
  font-size: 0.8rem;
  color: #94a3b8;
  border-top: 1px solid #e2e8f0;
  background: #ffffff;
}
</style>
