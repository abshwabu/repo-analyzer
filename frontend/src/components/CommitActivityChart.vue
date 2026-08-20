<template>
  <div class="chart-wrapper">
    <div v-if="!hasData" class="empty-chart">
      No commit activity recorded yet.
    </div>
    <div v-show="hasData" class="canvas-container">
      <canvas ref="chartCanvas"></canvas>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onMounted, onBeforeUnmount, computed } from 'vue'
import {
  Chart,
  LineController,
  LineElement,
  PointElement,
  LinearScale,
  CategoryScale,
  Title,
  Tooltip,
  Legend,
  Filler,
} from 'chart.js'

Chart.register(
  LineController,
  LineElement,
  PointElement,
  LinearScale,
  CategoryScale,
  Title,
  Tooltip,
  Legend,
  Filler
)

const props = defineProps({
  monthlyVolume: {
    type: Array,
    default: () => [],
  },
})

const chartCanvas = ref(null)
let chartInstance = null

const hasData = computed(() => props.monthlyVolume && props.monthlyVolume.length > 0)

const renderChart = () => {
  if (!chartCanvas.value || !hasData.value) return

  if (chartInstance) {
    chartInstance.destroy()
    chartInstance = null
  }

  const labels = props.monthlyVolume.map((item) => item.period)
  const dataPoints = props.monthlyVolume.map((item) => item.count)

  const ctx = chartCanvas.value.getContext('2d')
  const gradient = ctx.createLinearGradient(0, 0, 0, 240)
  gradient.addColorStop(0, 'rgba(99, 102, 241, 0.4)')
  gradient.addColorStop(1, 'rgba(99, 102, 241, 0.0)')

  chartInstance = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [
        {
          label: 'Commits',
          data: dataPoints,
          borderColor: '#4f46e5',
          backgroundColor: gradient,
          borderWidth: 2.5,
          fill: true,
          tension: 0.35,
          pointBackgroundColor: '#ffffff',
          pointBorderColor: '#4f46e5',
          pointBorderWidth: 2,
          pointRadius: 4,
          pointHoverRadius: 6,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: false,
        },
        tooltip: {
          backgroundColor: '#1f2937',
          titleFont: { size: 12, weight: 'bold' },
          bodyFont: { size: 13 },
          padding: 10,
          cornerRadius: 6,
          displayColors: false,
          callbacks: {
            label: (context) => `${context.parsed.y} commit${context.parsed.y === 1 ? '' : 's'}`,
          },
        },
      },
      scales: {
        x: {
          grid: {
            display: false,
          },
          ticks: {
            font: { size: 11 },
            color: '#6b7280',
          },
        },
        y: {
          beginAtZero: true,
          grid: {
            color: '#f3f4f6',
          },
          ticks: {
            precision: 0,
            font: { size: 11 },
            color: '#6b7280',
          },
        },
      },
    },
  })
}

watch(() => props.monthlyVolume, () => {
  renderChart()
}, { deep: true })

onMounted(() => {
  renderChart()
})

onBeforeUnmount(() => {
  if (chartInstance) {
    chartInstance.destroy()
  }
})
</script>

<style scoped>
.chart-wrapper {
  position: relative;
  width: 100%;
  height: 220px;
  background: #ffffff;
  border-radius: 8px;
}
.canvas-container {
  width: 100%;
  height: 100%;
}
.empty-chart {
  display: flex;
  justify-content: center;
  align-items: center;
  height: 100%;
  color: #9ca3af;
  font-size: 0.9rem;
}
</style>
