import { createRouter, createWebHistory } from 'vue-router'
import AdminLayout from '../layouts/AdminLayout.vue'
import AdminDashboard from '../views/AdminDashboard.vue'
import AdminLogin from '../views/AdminLogin.vue'
import AdminMerchants from '../views/AdminMerchants.vue'
import AdminTrades from '../views/AdminTrades.vue'
import AdminPackages from '../views/AdminPackages.vue'
import AdminPlugins from '../views/AdminPlugins.vue'
import AdminTickets from '../views/AdminTickets.vue'
import AdminFiles from '../views/AdminFiles.vue'
import AdminSettings from '../views/AdminSettings.vue'
import AdminTasks from '../views/AdminTasks.vue'
import AdminLogs from '../views/AdminLogs.vue'
import AdminProfile from '../views/AdminProfile.vue'

const router = createRouter({
  history: createWebHistory('/admin/'),
  routes: [
    { path: '/', redirect: '/login' },
    {
      path: '/login',
      name: 'admin-login',
      component: AdminLogin,
      meta: { title: '管理员登录' },
    },
    {
      path: '/',
      component: AdminLayout,
      children: [
        {
          path: 'dashboard',
          name: 'admin-dashboard',
          component: AdminDashboard,
          meta: { title: '仪表盘' },
        },
        { path: 'merchants', redirect: '/merchants/list' },
        {
          path: 'merchants/list',
          name: 'admin-merchants-list',
          component: AdminMerchants,
          meta: { title: '商户列表', section: 'merchants' },
        },
        {
          path: 'merchants/groups',
          name: 'admin-merchants-groups',
          component: AdminMerchants,
          meta: { title: '用户组设置', section: 'groups' },
        },
        { path: 'trades', redirect: '/trades/orders' },
        {
          path: 'trades/orders',
          name: 'admin-trades-orders',
          component: AdminTrades,
          meta: { title: '订单列表', section: 'orders' },
        },
        {
          path: 'trades/refunds',
          name: 'admin-trades-refunds',
          component: AdminTrades,
          meta: { title: '退款列表', section: 'refunds' },
        },
        {
          path: 'trades/transfers',
          name: 'admin-trades-transfers',
          component: AdminTrades,
          meta: { title: '代付审核', section: 'transfers' },
        },
        {
          path: 'trades/earnings',
          name: 'admin-trades-earnings',
          component: AdminTrades,
          meta: { title: '收益列表', section: 'earnings' },
        },
        {
          path: 'trades/settlements',
          name: 'admin-trades-settlements',
          component: AdminTrades,
          meta: { title: '结算审核', section: 'settlements' },
        },
        { path: 'packages', redirect: '/packages/list' },
        {
          path: 'packages/list',
          name: 'admin-packages-list',
          component: AdminPackages,
          meta: { title: '套餐列表' },
        },
        { path: 'plugins', redirect: '/plugins/methods' },
        {
          path: 'plugins/methods',
          name: 'admin-plugins-methods',
          component: AdminPlugins,
          meta: { title: '支付方式', section: 'methods' },
        },
        {
          path: 'plugins/list',
          name: 'admin-plugins-list',
          component: AdminPlugins,
          meta: { title: '插件列表', section: 'plugins' },
        },
        { path: 'tickets', redirect: '/tickets/list' },
        {
          path: 'tickets/list',
          name: 'admin-tickets-list',
          component: AdminTickets,
          meta: { title: '工单列表', section: 'tickets' },
        },
        {
          path: 'tickets/categories',
          name: 'admin-tickets-categories',
          component: AdminTickets,
          meta: { title: '工单分类', section: 'categories' },
        },
        { path: 'files', redirect: '/files/list' },
        {
          path: 'files/list',
          name: 'admin-files-list',
          component: AdminFiles,
          meta: { title: '文件列表', section: 'files' },
        },
        { path: 'settings', redirect: '/settings/basic' },
        {
          path: 'settings/basic',
          name: 'admin-settings-basic',
          component: AdminSettings,
          meta: { title: '基本设置', section: 'basic' },
        },
        {
          path: 'settings/announcements',
          name: 'admin-settings-announcements',
          component: AdminSettings,
          meta: { title: '公告配置', section: 'announcements' },
        },
        {
          path: 'settings/payment',
          name: 'admin-settings-payment',
          component: AdminSettings,
          meta: { title: '支付配置', section: 'payment' },
        },
        {
          path: 'settings/oauth',
          name: 'admin-settings-oauth',
          component: AdminSettings,
          meta: { title: '聚合登录', section: 'oauth' },
        },
        {
          path: 'settings/verify',
          name: 'admin-settings-verify',
          component: AdminSettings,
          meta: { title: '验证安全', section: 'verify' },
        },
        {
          path: 'settings/merchant',
          name: 'admin-settings-merchant',
          component: AdminSettings,
          meta: { title: '商户设置', section: 'merchant' },
        },
        {
          path: 'settings/mail',
          name: 'admin-settings-mail',
          component: AdminSettings,
          meta: { title: '邮件配置', section: 'mail' },
        },
        {
          path: 'settings/telegram',
          name: 'admin-settings-telegram',
          component: AdminSettings,
          meta: { title: 'TG 配置', section: 'telegram' },
        },
        {
          path: 'settings/sms',
          name: 'admin-settings-sms',
          component: AdminSettings,
          meta: { title: '短信配置', section: 'sms' },
        },
        {
          path: 'settings/realname',
          name: 'admin-settings-realname',
          component: AdminSettings,
          meta: { title: '实名认证', section: 'realname' },
        },
        {
          path: 'settings/upload',
          name: 'admin-settings-upload',
          component: AdminSettings,
          meta: { title: '上传设置', section: 'upload' },
        },
        {
          path: 'settings/api',
          name: 'admin-settings-api',
          component: AdminSettings,
          meta: { title: '接口设置', section: 'api' },
        },
        {
          path: 'settings/auth',
          name: 'admin-settings-auth',
          component: AdminSettings,
          meta: { title: '认证设置', section: 'auth' },
        },
        {
          path: 'settings/cache',
          name: 'admin-settings-cache',
          component: AdminSettings,
          meta: { title: '缓存清理', section: 'cache' },
        },
        { path: 'tasks', redirect: '/tasks/list' },
        {
          path: 'tasks/list',
          name: 'admin-tasks-list',
          component: AdminTasks,
          meta: { title: '定时任务' },
        },
        { path: 'logs', redirect: '/logs/admin' },
        {
          path: 'logs/admin',
          name: 'admin-logs-admin',
          component: AdminLogs,
          meta: { title: '管理员日志', section: 'admin' },
        },
        {
          path: 'logs/merchant',
          name: 'admin-logs-merchant',
          component: AdminLogs,
          meta: { title: '用户日志', section: 'merchant' },
        },
        {
          path: 'logs/callback',
          name: 'admin-logs-callback',
          component: AdminLogs,
          meta: { title: '回调日志', section: 'callback' },
        },
        {
          path: 'logs/plugin-notify',
          name: 'admin-logs-plugin-notify',
          component: AdminLogs,
          meta: { title: '插件通知', section: 'plugin-notify' },
        },
        {
          path: 'logs/provider',
          name: 'admin-logs-provider',
          component: AdminLogs,
          meta: { title: '服务商日志', section: 'provider' },
        },
        {
          path: 'logs/realname',
          name: 'admin-logs-realname',
          component: AdminLogs,
          meta: { title: '实名日志', section: 'realname' },
        },
        {
          path: 'profile',
          name: 'admin-profile',
          component: AdminProfile,
          meta: { title: '个人资料' },
        },
      ],
    },
  ],
})

router.beforeEach((to) => {
  const token = sessionStorage.getItem('admin:token')
  if (to.path !== '/login' && !token) return '/login'
  if (to.path === '/login' && token) return '/dashboard'
  return true
})

export default router

