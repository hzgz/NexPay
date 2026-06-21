<script setup lang="ts">
import type { PageKind } from '../content'

defineProps<{
  pageKind: PageKind
  logoSrc: string
  homeAnchor: (anchor: string) => string
}>()
</script>

<template>
  <header class="site-header">
    <a class="brand-link" href="/">
      <img :src="logoSrc" alt="NexPay" />
    </a>

    <nav class="site-nav" aria-label="前台导航">
      <a href="/">产品</a>
      <a href="/">解决方案</a>
      <a href="/doc">开发者</a>
      <a :href="homeAnchor('steps')">费率</a>
      <a :href="homeAnchor('support')">帮助中心</a>
    </nav>

    <div class="site-actions">
      <template v-if="pageKind === 'home'">
        <a class="text-link" href="/user/login">登录</a>
        <a class="primary-link" href="/user/register">立即接入</a>
      </template>
      <template v-else>
        <a class="text-link" href="/">返回首页</a>
        <a class="primary-link primary-link--ghost" href="/user/login">商户登录</a>
      </template>
    </div>
  </header>
</template>
