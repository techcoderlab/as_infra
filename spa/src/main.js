import './assets/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { vCan } from './directives/v-can'
import { vModule } from './directives/v-module'

const app = createApp(App)

app.use(createPinia())
app.use(router)

// Register custom directives
app.directive('can', vCan)
app.directive('module', vModule)

// Mount immediately.
// The Router's beforeEach hook will pause navigation until bootstrap completes.
app.mount('#app')
