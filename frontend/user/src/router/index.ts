import { createRouter, createWebHistory } from 'vue-router'
import UserLayout from '../layouts/UserLayout.vue'
import MerchantDashboard from '../views/MerchantDashboard.vue'
import UserChannels from '../views/UserChannels.vue'
import UserOrders from '../views/UserOrders.vue'
import UserFunds from '../views/UserFunds.vue'
import UserPackages from '../views/UserPackages.vue'
import UserLogin from '../views/UserLogin.vue'
import UserRegister from '../views/UserRegister.vue'
import UserForgotPassword from '../views/UserForgotPassword.vue'
import UserOAuthResult from '../views/UserOAuthResult.vue'
import UserApiInfo from '../views/UserApiInfo.vue'
import UserProfile from '../views/UserProfile.vue'
import UserTickets from '../views/UserTickets.vue'
import UserFiles from '../views/UserFiles.vue'

const router = createRouter({
  history: createWebHistory('/user/'),
  routes: [
    { path: '/', redirect: '/login' },
    {
      path: '/login',
      name: 'user-login',
      component: UserLogin,
      meta: { title: '商户登录' },
    },
    {
      path: '/register',
      name: 'user-register',
      component: UserRegister,
      meta: { title: '商户注册' },
    },
    {
      path: '/forgot-password',
      name: 'user-forgot-password',
      component: UserForgotPassword,
      meta: { title: '找回密码' },
    },
    {
      path: '/oauth-result',
      name: 'user-oauth-result',
      component: UserOAuthResult,
      meta: { title: '聚合登录结果' },
    },
    {
      path: '/',
      component: UserLayout,
      children: [
        {
          path: 'dashboard',
          name: 'user-dashboard',
          component: MerchantDashboard,
          meta: { title: '仪表盘' },
        },
        { path: 'account', redirect: '/account/profile' },
        {
          path: 'account/profile',
          name: 'user-account-profile',
          component: UserProfile,
          meta: { title: '个人资料', section: 'profile' },
        },
        {
          path: 'account/realname',
          name: 'user-account-realname',
          component: UserProfile,
          meta: { title: '实名认证', section: 'realname' },
        },
        {
          path: 'account/security',
          name: 'user-account-security',
          component: UserProfile,
          meta: { title: '安全设置', section: 'security' },
        },
        {
          path: 'account/notifications',
          name: 'user-account-notifications',
          component: UserProfile,
          meta: { title: '通知设置', section: 'notifications' },
        },
        {
          path: 'account/bindings',
          name: 'user-account-bindings',
          component: UserProfile,
          meta: { title: '第三方绑定', section: 'bindings' },
        },
        {
          path: 'account/logins',
          name: 'user-account-logins',
          component: UserProfile,
          meta: { title: '登录日志', section: 'logins' },
        },
        { path: 'channels', redirect: '/channels/list' },
        {
          path: 'channels/list',
          name: 'user-channels-list',
          component: UserChannels,
          meta: { title: '通道列表', section: 'list' },
        },
        {
          path: 'channels/rotation',
          name: 'user-channels-rotation',
          component: UserChannels,
          meta: { title: '通道轮询', section: 'rotation' },
        },
        {
          path: 'channels/settings',
          name: 'user-channels-settings',
          component: UserChannels,
          meta: { title: '支付设置', section: 'settings' },
        },
        { path: 'orders', redirect: '/orders/list' },
        {
          path: 'orders/list',
          name: 'user-orders-list',
          component: UserOrders,
          meta: { title: '订单列表', section: 'list' },
        },
        {
          path: 'orders/callbacks',
          name: 'user-orders-callbacks',
          component: UserOrders,
          meta: { title: '回调日志', section: 'callbacks' },
        },
        { path: 'funds', redirect: '/funds/recharge' },
        {
          path: 'funds/recharge',
          name: 'user-funds-recharge',
          component: UserFunds,
          meta: { title: '在线充值', section: 'recharge' },
        },
        {
          path: 'funds/flows',
          name: 'user-funds-flows',
          component: UserFunds,
          meta: { title: '资金明细', section: 'flows' },
        },
        {
          path: 'funds/withdraw',
          name: 'user-funds-withdraw',
          component: UserFunds,
          meta: { title: '申请提现', section: 'withdraw' },
        },
        {
          path: 'funds/packages',
          name: 'user-funds-packages',
          component: UserPackages,
          meta: { title: '套餐购买', section: 'packages' },
        },
        { path: 'tickets', redirect: '/tickets/list' },
        {
          path: 'tickets/list',
          name: 'user-tickets-list',
          component: UserTickets,
          meta: { title: '我的工单', section: 'list' },
        },
        {
          path: 'tickets/create',
          name: 'user-tickets-create',
          component: UserTickets,
          meta: { title: '提交工单', section: 'create' },
        },
        {
          path: 'files',
          name: 'user-files',
          component: UserFiles,
          meta: { title: '文件管理' },
        },
        {
          path: 'api',
          name: 'user-api',
          component: UserApiInfo,
          meta: { title: 'API 接口' },
        },
      ],
    },
  ],
})

router.beforeEach((to) => {
  const token = sessionStorage.getItem('user:token')
  const publicPaths = ['/login', '/register', '/forgot-password', '/oauth-result']

  if (!publicPaths.includes(to.path) && !token) {
    return '/login'
  }

  if (publicPaths.includes(to.path) && token) {
    return '/dashboard'
  }

  return true
})

export default router

