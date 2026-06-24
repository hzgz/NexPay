<script setup lang="ts">
import {
  ArrowDown,
  ChatDotRound,
  Coin,
  Connection,
  DataBoard,
  DocumentCopy,
  Expand,
  Files,
  Fold,
  Operation,
  Tickets,
  UserFilled,
} from '@element-plus/icons-vue'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import MerchantAnnouncementBell from '../components/MerchantAnnouncementBell.vue'
import { getUserSessionUser, resolveUserAvatarUrl, USER_SESSION_UPDATED_EVENT } from '../lib/api'
import { useSidebarAccordion, type MenuItem } from '../lib/sidebar'

const route = useRoute()
const router = useRouter()
const isMobile = ref(false)
const menuOpen = ref(false)
const sidebarCollapsed = ref(false)
const sessionUser = ref<Record<string, any>>({})

const menus: MenuItem[] = [
  { key: 'dashboard', path: '/dashboard', label: '仪表盘', icon: DataBoard },
  {
    key: 'account',
    label: '账户管理',
    icon: UserFilled,
    children: [
      { label: '个人资料', path: '/account/profile' },
      { label: '实名认证', path: '/account/realname' },
      { label: '安全设置', path: '/account/security' },
      { label: '通知设置', path: '/account/notifications' },
      { label: '第三方绑定', path: '/account/bindings' },
      { label: '登录日志', path: '/account/logins' },
    ],
  },
  {
    key: 'channels',
    label: '通道管理',
    icon: Connection,
    children: [
      { label: '通道列表', path: '/channels/list' },
      { label: '通道轮询', path: '/channels/rotation' },
      { label: '支付设置', path: '/channels/settings' },
    ],
  },
  {
    key: 'orders',
    label: '订单管理',
    icon: Tickets,
    children: [
      { label: '订单列表', path: '/orders/list' },
      { label: '回调日志', path: '/orders/callbacks' },
    ],
  },
  {
    key: 'funds',
    label: '资金管理',
    icon: Coin,
    children: [
      { label: '在线充值', path: '/funds/recharge' },
      { label: '资金明细', path: '/funds/flows' },
      { label: '申请提现', path: '/funds/withdraw' },
      { label: '套餐购买', path: '/funds/packages' },
    ],
  },
  {
    key: 'tickets',
    label: '工单系统',
    icon: ChatDotRound,
    children: [
      { label: '我的工单', path: '/tickets/list' },
      { label: '提交工单', path: '/tickets/create' },
    ],
  },
  { key: 'files', path: '/files', label: '文件管理', icon: Files },
  { key: 'api', path: '/api', label: 'API 接口', icon: DocumentCopy },
]

function createFallbackAvatar(letter: string) {
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96"><rect width="96" height="96" fill="#0d66ff"/><text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="Arial" font-size="42" font-weight="700">${letter}</text></svg>`
  return `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`
}

const {
  collapseAllMenus,
  syncExpandedByRoute,
  handleMenuClick,
  handleChildClick,
  isExpanded,
  isChildActive,
  isGroupActive,
} = useSidebarAccordion(
  menus,
  computed(() => route.path),
  sidebarCollapsed,
  isMobile,
  menuOpen,
)

const defaultAvatar = createFallbackAvatar('M')

function loadUser() {
  sessionUser.value = getUserSessionUser()
}

function loadSidebarState() {
  sidebarCollapsed.value = localStorage.getItem('user:sidebar-collapsed') === '1'
}

function saveSidebarState() {
  localStorage.setItem('user:sidebar-collapsed', sidebarCollapsed.value ? '1' : '0')
}

function expandSidebar() {
  sidebarCollapsed.value = false
  saveSidebarState()
}

function closeMenu() {
  menuOpen.value = false
}

function logout() {
  sessionStorage.removeItem('user:token')
  sessionStorage.removeItem('user:user')
  router.push('/login')
}

function goProfile() {
  router.push('/account/profile')
}

function handleCommand(command: string) {
  if (command === 'profile') goProfile()
  if (command === 'logout') logout()
}

function syncViewport() {
  isMobile.value = window.innerWidth <= 900
  if (isMobile.value) {
    menuOpen.value = false
    sidebarCollapsed.value = false
  }
}

function toggleMenu() {
  if (isMobile.value) {
    menuOpen.value = !menuOpen.value
    return
  }

  sidebarCollapsed.value = !sidebarCollapsed.value
  if (sidebarCollapsed.value) {
    collapseAllMenus()
  } else {
    syncExpandedByRoute()
  }
  saveSidebarState()
}
const shellClass = computed(() => ({
  'is-mobile': isMobile.value,
  'is-menu-open': menuOpen.value,
  'is-collapsed': sidebarCollapsed.value && !isMobile.value,
}))
const sidebarSwitchIcon = computed(() => (sidebarCollapsed.value ? Expand : Fold))
const avatarSrc = computed(() => {
  const avatar = resolveUserAvatarUrl(sessionUser.value.avatar, sessionUser.value.avatar_version)
  return avatar || defaultAvatar
})

watch(
  () => route.path,
  () => {
    loadUser()
  },
)

onMounted(() => {
  loadUser()
  loadSidebarState()
  syncViewport()
  syncExpandedByRoute()
  window.addEventListener('resize', syncViewport)
  window.addEventListener(USER_SESSION_UPDATED_EVENT, loadUser as EventListener)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', syncViewport)
  window.removeEventListener(USER_SESSION_UPDATED_EVENT, loadUser as EventListener)
})
</script>

<template>
  <div class="user-shell" :class="shellClass">
    <aside class="art-sidebar">
      <div class="art-brand" :class="{ compact: sidebarCollapsed && !isMobile }" @click="router.push('/dashboard')">
        <span class="art-brand__mark">N</span>
        <div v-if="!(sidebarCollapsed && !isMobile)" class="art-brand__copy">
          <strong>NexPay</strong>
          <span>商户中心</span>
        </div>
      </div>

      <nav class="art-menu">
        <div
          v-for="item in menus"
          :key="item.key"
          class="art-menu__group"
          :class="{ active: isGroupActive(item), expanded: isExpanded(item.key) }"
        >
          <button
            class="art-menu__item"
            type="button"
            @click="handleMenuClick(item, (path) => router.push(path), expandSidebar)"
          >
            <span class="art-menu__item-main">
              <component :is="item.icon" class="art-menu__icon" />
              <span v-if="!(sidebarCollapsed && !isMobile)" class="art-menu__label">{{ item.label }}</span>
            </span>
            <ArrowDown
              v-if="item.children?.length && !(sidebarCollapsed && !isMobile)"
              class="art-menu__arrow"
            />
          </button>

          <div
            v-if="item.children?.length && !(sidebarCollapsed && !isMobile)"
            class="art-submenu"
            :class="{ visible: isExpanded(item.key) }"
          >
            <button
              v-for="child in item.children"
              :key="child.path"
              class="art-submenu__item"
              :class="{ active: isChildActive(child.path) }"
              type="button"
              @click="handleChildClick(item.key, child.path, (path) => router.push(path))"
            >
              {{ child.label }}
            </button>
          </div>
        </div>
      </nav>
    </aside>

    <div v-if="isMobile" class="art-overlay" :class="{ active: menuOpen }" @click="closeMenu" />

    <main class="art-main">
      <header class="art-topbar">
        <div class="art-topbar__left">
          <button class="art-topbar__toggle" type="button" @click="toggleMenu">
            <component :is="isMobile ? Operation : sidebarSwitchIcon" class="art-topbar__toggle-icon" />
          </button>
        </div>

        <div class="art-topbar__right">
          <MerchantAnnouncementBell :merchant-id="sessionUser.merchant_id" :mobile="isMobile" />

          <el-dropdown trigger="click" @command="handleCommand">
            <button class="art-profile" type="button">
              <img class="art-profile__avatar" :src="avatarSrc" alt="merchant avatar" />
              <span class="art-profile__text">
                <strong>{{ sessionUser.nickname || sessionUser.username || '商户' }}</strong>
                <small>ID {{ sessionUser.merchant_id || '--' }}</small>
              </span>
              <ArrowDown class="art-profile__arrow" />
            </button>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item command="profile">个人资料</el-dropdown-item>
                <el-dropdown-item command="logout">退出登录</el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </div>
      </header>

      <section class="view-host">
        <router-view />
      </section>
    </main>
  </div>
</template>

<style scoped>
.user-shell {
  display: grid;
  grid-template-columns: 248px minmax(0, 1fr);
  min-height: 100vh;
  background: #f6f8fc;
  transition: grid-template-columns 0.22s ease;
}

.user-shell.is-collapsed {
  grid-template-columns: 78px minmax(0, 1fr);
}

.art-sidebar {
  position: sticky;
  top: 0;
  height: 100vh;
  overflow-y: auto;
  border-right: 1px solid #e8edf5;
  background: #fff;
}

.art-brand {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 58px;
  padding: 0 16px;
  border-bottom: 1px solid #eef2f7;
  cursor: pointer;
}

.art-brand.compact {
  justify-content: center;
  padding-inline: 0;
}

.art-brand__mark {
  width: 32px;
  height: 32px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #0d66ff, #0b55d7);
  color: #fff;
  font-size: 13px;
  font-weight: 800;
  border-radius: 8px;
}

.art-brand__copy {
  display: grid;
  gap: 2px;
}

.art-brand__copy strong {
  font-size: 14px;
  line-height: 1.1;
}

.art-brand__copy span {
  color: #7a879c;
  font-size: 10px;
}

.art-menu {
  display: grid;
  gap: 2px;
  padding: 12px 8px 16px;
}

.art-menu__group {
  display: grid;
  gap: 4px;
}

.art-menu__item {
  width: 100%;
  min-height: 40px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 10px;
  border: 0;
  background: transparent;
  color: #41536d;
  text-align: left;
  border-radius: 10px;
}

.art-menu__item-main {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}

.art-menu__icon {
  width: 16px;
  height: 16px;
  color: #7a879c;
  flex: none;
}

.art-menu__label {
  white-space: nowrap;
  font-size: 14px;
  font-weight: 600;
}

.art-menu__arrow {
  width: 13px;
  height: 13px;
  color: #8fa0b8;
  transition: transform 0.2s ease;
}

.art-menu__group.active > .art-menu__item,
.art-menu__group.expanded > .art-menu__item {
  background: #f3f7ff;
  color: #0d66ff;
}

.art-menu__group.active > .art-menu__item .art-menu__icon,
.art-menu__group.expanded > .art-menu__item .art-menu__icon {
  color: #0d66ff;
}

.art-menu__group.expanded .art-menu__arrow {
  transform: rotate(180deg);
}

.user-shell.is-collapsed .art-menu__item {
  justify-content: center;
  padding-inline: 0;
}

.art-submenu {
  display: none;
  gap: 2px;
  padding: 0 0 4px 34px;
}

.art-submenu.visible {
  display: grid;
}

.art-submenu__item {
  min-height: 30px;
  padding: 0 10px;
  border: 0;
  background: transparent;
  color: #73839a;
  text-align: left;
  font-size: 13px;
  border-radius: 8px;
}

.art-submenu__item.active {
  color: #0d66ff;
  background: #f5f8ff;
  font-weight: 700;
}

.art-main {
  min-width: 0;
}

.art-topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  min-height: 56px;
  padding: 0 16px;
  border-bottom: 1px solid #e8edf5;
  background: #fff;
}

.art-topbar__left,
.art-topbar__right {
  display: flex;
  align-items: center;
  gap: 14px;
  min-width: 0;
}

.art-topbar__right {
  margin-left: auto;
}

.art-topbar__toggle {
  width: 34px;
  height: 34px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid #e6ebf3;
  background: #fff;
  border-radius: 10px;
}

.art-topbar__toggle-icon {
  width: 18px;
  height: 18px;
  color: #2c4d78;
}

.art-profile {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  min-height: 38px;
  padding: 0 0 0 2px;
  border: 0;
  background: transparent;
  color: #24436c;
}

.art-profile__avatar {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  object-fit: cover;
  flex: none;
}

.art-profile__text {
  display: grid;
  text-align: left;
}

.art-profile__text strong {
  font-size: 12px;
  line-height: 1.2;
}

.art-profile__text small {
  color: #7d8da4;
  font-size: 11px;
  line-height: 1.2;
}

.art-profile__arrow {
  width: 14px;
  height: 14px;
  color: #8fa0b8;
  margin-left: 2px;
}

.view-host {
  padding: 14px 16px 18px;
}

.art-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.2);
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.2s ease;
  z-index: 20;
}

.art-overlay.active {
  opacity: 1;
  pointer-events: auto;
}

@media (max-width: 900px) {
  .user-shell,
  .user-shell.is-collapsed {
    grid-template-columns: 1fr;
  }

  .art-sidebar {
    position: fixed;
    left: 0;
    top: 0;
    bottom: 0;
    width: min(272px, 82vw);
    transform: translateX(-104%);
    transition: transform 0.24s ease;
    z-index: 30;
  }

  .user-shell.is-menu-open .art-sidebar {
    transform: translateX(0);
  }

  .art-topbar {
    padding-inline: 16px;
  }

  .art-topbar__right {
    gap: 10px;
    flex-wrap: wrap;
    justify-content: flex-end;
  }

  .art-profile__text small {
    display: none;
  }

  .view-host {
    padding: 12px 12px 16px;
  }
}
</style>
