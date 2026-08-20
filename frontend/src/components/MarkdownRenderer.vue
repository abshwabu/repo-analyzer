<template>
  <div class="markdown-container">
    <div class="markdown-header">
      <div class="view-toggle">
        <button
          class="toggle-btn"
          :class="{ active: viewMode === 'rendered' }"
          @click="viewMode = 'rendered'"
        >
          👁 Preview
        </button>
        <button
          class="toggle-btn"
          :class="{ active: viewMode === 'raw' }"
          @click="viewMode = 'raw'"
        >
          📝 Raw Markdown
        </button>
      </div>

      <div class="actions">
        <button class="btn-copy" @click="handleCopy">
          {{ copied ? '✓ Copied!' : '📋 Copy' }}
        </button>
      </div>
    </div>

    <div v-if="viewMode === 'rendered'" class="rendered-content" v-html="renderedHtml"></div>
    <pre v-else class="raw-content"><code>{{ content }}</code></pre>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { marked } from 'marked'

marked.setOptions({
  gfm: true,
  breaks: true,
})

const props = defineProps({
  content: {
    type: String,
    default: '',
  },
})

const viewMode = ref('rendered')
const copied = ref(false)

const renderedHtml = computed(() => {
  if (!props.content) return ''
  try {
    return marked.parse(props.content)
  } catch (e) {
    return `<pre>${props.content}</pre>`
  }
})

const handleCopy = async () => {
  if (!props.content) return
  try {
    await navigator.clipboard.writeText(props.content)
    copied.value = true
    setTimeout(() => {
      copied.value = false
    }, 2000)
  } catch (e) {
    console.error('Failed to copy', e)
  }
}
</script>

<style scoped>
.markdown-container {
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  overflow: hidden;
}
.markdown-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.5rem 0.75rem;
  background: #f9fafb;
  border-bottom: 1px solid #e5e7eb;
}
.view-toggle {
  display: flex;
  gap: 0.25rem;
}
.toggle-btn {
  background: none;
  border: none;
  padding: 0.35rem 0.65rem;
  font-size: 0.8rem;
  font-weight: 600;
  color: #6b7280;
  border-radius: 4px;
  cursor: pointer;
}
.toggle-btn.active {
  background: #ffffff;
  color: #4f46e5;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}
.btn-copy {
  background: #ffffff;
  border: 1px solid #d1d5db;
  padding: 0.35rem 0.65rem;
  font-size: 0.8rem;
  border-radius: 4px;
  cursor: pointer;
  color: #374151;
  font-weight: 600;
}
.btn-copy:hover {
  background: #f3f4f6;
}
.rendered-content {
  padding: 1.5rem;
  color: #1f2937;
  line-height: 1.6;
  max-height: 550px;
  overflow-y: auto;
}
.rendered-content :deep(h1) {
  font-size: 1.6rem;
  font-weight: 800;
  border-bottom: 1px solid #e5e7eb;
  padding-bottom: 0.4rem;
  margin-top: 0;
  margin-bottom: 1rem;
}
.rendered-content :deep(h2) {
  font-size: 1.3rem;
  font-weight: 700;
  border-bottom: 1px solid #f3f4f6;
  padding-bottom: 0.3rem;
  margin-top: 1.5rem;
  margin-bottom: 0.75rem;
}
.rendered-content :deep(h3) {
  font-size: 1.1rem;
  font-weight: 600;
  margin-top: 1.25rem;
  margin-bottom: 0.5rem;
}
.rendered-content :deep(pre) {
  background: #1f2937;
  color: #f3f4f6;
  padding: 1rem;
  border-radius: 6px;
  overflow-x: auto;
  font-size: 0.85rem;
}
.rendered-content :deep(code) {
  font-family: monospace;
  background: #f3f4f6;
  padding: 0.15rem 0.35rem;
  border-radius: 4px;
  font-size: 0.85rem;
}
.rendered-content :deep(pre code) {
  background: none;
  padding: 0;
  color: inherit;
}
.rendered-content :deep(table) {
  width: 100%;
  border-collapse: collapse;
  margin: 1rem 0;
}
.rendered-content :deep(th),
.rendered-content :deep(td) {
  border: 1px solid #e5e7eb;
  padding: 0.5rem 0.75rem;
  text-align: left;
  font-size: 0.875rem;
}
.rendered-content :deep(th) {
  background: #f9fafb;
}
.rendered-content :deep(blockquote) {
  border-left: 4px solid #6366f1;
  padding-left: 1rem;
  margin: 1rem 0;
  color: #4b5563;
}
.raw-content {
  margin: 0;
  padding: 1.25rem;
  background: #1e1e1e;
  color: #d4d4d4;
  font-family: monospace;
  font-size: 0.85rem;
  line-height: 1.5;
  max-height: 550px;
  overflow-y: auto;
  white-space: pre-wrap;
}
</style>
