<script setup>
import { RouterView } from 'vue-router';
import { onBeforeMount } from 'vue';
import { useTheme } from './composables/useTheme';

const { initTheme } = useTheme();

// Apply theme preference immediately before mounting
onBeforeMount(() => {
  initTheme();
});
</script>

<template>
  <RouterView />
</template>

