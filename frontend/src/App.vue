<template>
  <div class="app-layout">
    <header class="navbar">
      <div class="container nav-content">
        <router-link to="/" class="brand">Repo Analyzer</router-link>
        <nav class="nav-links">
          <router-link to="/" class="nav-link">Home</router-link>
          <router-link to="/health" class="nav-link">Health</router-link>
        </nav>
      </div>
    </header>

    <main class="container main-content">
      <router-view />
    </main>
  </div>
</template>

<style>
* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}
body {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
  background-color: #f9fafb;
  color: #111827;
}
.container {
  max-width: 1024px;
  margin: 0 auto;
  padding: 0 1.5rem;
}
.navbar {
  background-color: #ffffff;
  border-bottom: 1px solid #e5e7eb;
  padding: 1rem 0;
}
.nav-content {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.brand {
  font-size: 1.25rem;
  font-weight: 700;
  color: #4f46e5;
  text-decoration: none;
}
.nav-links {
  display: flex;
  gap: 1.5rem;
}
.nav-link {
  text-decoration: none;
  color: #4b5563;
  font-weight: 500;
  transition: color 0.2s ease;
}
.nav-link:hover,
.nav-link.router-link-active {
  color: #4f46e5;
}
.main-content {
  padding-top: 2rem;
  padding-bottom: 2rem;
}
</style>
