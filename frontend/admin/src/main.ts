import { createApp } from 'vue'
import ElementPlus from 'element-plus'
import 'element-plus/dist/index.css'
import App from './App.vue'
import router from './router'
import './style.css'

router.afterEach((to) => {
  const pageTitle = typeof to.meta.title === 'string' ? to.meta.title : '管理员后台'
  document.title = `${pageTitle} - NexPay 聚合支付系统`
})

createApp(App).use(router).use(ElementPlus).mount('#app')
