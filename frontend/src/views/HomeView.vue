<template>
  <div class="home">
    <div class="card">
      <h1>Welcome to Repo Analyzer</h1>
      <p class="subtitle">A full-stack repository analysis platform built with Laravel 11 and Vue 3.</p>
      <div class="actions">
        <router-link to="/health" class="btn">Check API Health</router-link>
      </div>
    </div>
  </div>
</template>

<style scoped>
.home {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 60vh;
}
.card {
  background: #ffffff;
  padding: 2.5rem;
  border-radius: 12px;
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
  max-width: 600px;
  text-align: center;
}
h1 {
  font-size: 2rem;
  color: #1f2937;
  margin-bottom: 0.75rem;
}
.subtitle {
  color: #4b5563;
  margin-bottom: 1.5rem;
  line-height: 1.5;
}
.btn {
  display: inline-block;
  background-color: #4f46e5;
  color: white;
  padding: 0.75rem 1.5rem;
  border-radius: 8px;
  text-decoration: none;
  font-weight: 600;
  transition: background-color 0.2s ease;
}
.btn:hover {
  background-color: #4338ca;
}
</style>
