<script setup lang="ts">
import type { PageKind } from '../content'

defineProps<{
  pageKind: PageKind
  logoSrc: string
  homeAnchor: (anchor: string) => string
}>()
</script>

<template>
  <header :class="['site-header', pageKind === 'home' ? 'site-header--home' : 'site-header--legacy']">
    <a class="brand-link" href="/">
      <template v-if="pageKind === 'home'">
        <span class="brand-wordmark">NexPay 支付</span>
      </template>
      <img v-else :src="logoSrc" alt="NexPay" />
    </a>

    <nav class="site-nav" aria-label="前台导航">
      <a href="/">首页</a>
      <a href="/doc">开发文档</a>
      <a href="/demo">支付测试</a>
      <a :href="homeAnchor('capability')">支付方式</a>
      <a :href="homeAnchor('steps')">接入流程</a>
      <a :href="homeAnchor('support')">平台优势</a>
    </nav>

    <div class="site-actions">
      <template v-if="pageKind === 'home'">
        <a class="text-link" href="/user/login">商户登录</a>
        <a class="text-link text-link--strong" href="/user/register">商户注册</a>
      </template>
      <template v-else>
        <a class="text-link" href="/">返回首页</a>
        <a class="primary-link primary-link--ghost" href="/user/login">商户登录</a>
      </template>
    </div>
  </header>
</template>
