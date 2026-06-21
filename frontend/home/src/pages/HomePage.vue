<script setup lang="ts">
import { accessSteps, heroStats, paymentMethods, trustBrands } from '../content'

defineProps<{
  heroVisual: string
  resolveAsset: (path: string) => string
}>()
</script>

<template>
  <main class="page-home">
    <section class="hero-grid">
      <div class="hero-copy">
        <div class="hero-line"></div>
        <div class="hero-copy__body">
          <h1>
            让每一次支付，
            <br />
            都成为<span>增长的力量</span>
          </h1>
          <p>
            NexPay 为全球商户提供安全、稳定、合规的支付解决方案，
            支持多种支付方式，帮助业务更顺滑地完成接入、联调与收款。
          </p>
        </div>

        <div class="hero-actions">
          <a class="primary-link" href="/user/register">立即接入</a>
          <a class="secondary-link" href="/doc">联系销售</a>
        </div>

        <div class="hero-highlights">
          <span>PCI DSS 认证</span>
          <span>数据安全等级保护</span>
          <span>7×24 小时风险监控</span>
        </div>
      </div>

      <div class="hero-visual">
        <img :src="heroVisual" alt="NexPay 品牌主视觉" />
        <div class="hero-stat-strip">
          <div v-for="item in heroStats" :key="item.label" class="hero-stat">
            <strong>{{ item.value }}</strong>
            <span>{{ item.label }}</span>
          </div>
        </div>
      </div>
    </section>

    <section id="capability" class="content-section">
      <div class="section-head">
        <h2>多种支付方式，全球覆盖</h2>
        <p>接入一次，连接主流支付网络与全球收款能力。</p>
      </div>

      <div class="method-row">
        <div v-for="item in paymentMethods" :key="item.name" class="method-item">
          <img :src="resolveAsset(item.icon)" :alt="item.name" />
          <span>{{ item.name }}</span>
        </div>
        <div class="method-item method-item--more">
          <span>...</span>
          <small>更多方式</small>
        </div>
      </div>
    </section>

    <section id="steps" class="content-section">
      <div class="section-head">
        <h2>三步接入，快速上线</h2>
        <p>从账户创建到联调上线，前后台结构统一，路径更短。</p>
      </div>

      <div class="step-row">
        <article v-for="item in accessSteps" :key="item.index" class="step-card">
          <div class="step-index">{{ item.index }}</div>
          <div>
            <h3>{{ item.title }}</h3>
            <p>{{ item.description }}</p>
          </div>
        </article>
      </div>
    </section>

    <section id="support" class="content-section content-section--brands">
      <p class="section-caption">深受全球企业信赖</p>
      <div class="brand-row">
        <span v-for="item in trustBrands" :key="item">{{ item }}</span>
      </div>
    </section>
  </main>
</template>
