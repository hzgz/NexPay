<script setup lang="ts">
import { paymentMethods } from '../content'

const heroPanels = [
  {
    title: '数据统计',
    text: '后台数据清晰直观，轻松查看订单、回调与资金变化。',
    tone: 'rose',
  },
  {
    title: '系统安全',
    text: '保留现有业务逻辑与支付链路，配置、回调和风控更稳。',
    tone: 'emerald',
  },
  {
    title: '快捷支付',
    text: '开发接入、支付测试与正式业务同源，联调路径更短。',
    tone: 'sky',
  },
  {
    title: '方便快捷',
    text: '首页、文档、商户入口统一整理，PC 与移动端都能直接使用。',
    tone: 'amber',
  },
]

const coreServices = [
  {
    title: '云支付',
    description: '支持支付宝、微信、QQ 钱包等主流支付方式，覆盖网页、扫码与移动端支付接入场景。',
    icon: 'payment-icons/alipay.png',
    tags: ['支付宝', '微信', 'QQ 钱包'],
    tone: 'primary',
  },
  {
    title: '云钱包',
    description: '商户可在统一后台中查看余额、订单、回调与通道信息，减少多套入口来回切换。',
    icon: 'payment-icons/unionpay.png',
    tags: ['资金统计', '订单明细', '通道明细'],
    tone: 'success',
  },
  {
    title: '云结算',
    description: '通过统一页面配置即可衔接支付、配置、结算和测试流程，降低维护与联调成本。',
    icon: 'payment-icons/wechat.png',
    tags: ['支付配置', '通知回调', '订单处理'],
    tone: 'warning',
  },
]

const walletHighlights = [
  { title: '长期稳定支付通道', icon: 'payment-icons/alipay.png' },
  { title: '便捷清晰账户管理', icon: 'payment-icons/unionpay.png' },
  { title: '高效实时监控订单', icon: 'payment-icons/wechat.png' },
]

defineProps<{
  legacyHeroVisual: string
  legacySceneOne: string
  legacySceneTwo: string
  legacySceneThree: string
  resolveAsset: (path: string) => string
}>()
</script>

<template>
  <main class="page-home page-home--legacy">
    <section class="legacy-hero">
      <div class="legacy-hero__bg">
        <img :src="legacyHeroVisual" alt="首页主题背景" />
      </div>

      <div class="legacy-hero__inner">
        <div class="legacy-hero__copy">
          <span class="legacy-hero__eyebrow">稳定支付接入</span>
          <h1>聚合支付平台</h1>
          <h2>我们专注的每一面，都是为了给你更稳的支付体验</h2>
          <p>
            稳定、安全、便捷的一站式聚合支付服务，支持前台展示、开发联调。
            商户入驻与后台统一配置，让支付链路更清晰。
          </p>

          <div class="legacy-hero__actions">
            <a class="primary-link primary-link--surface" href="/user/login">商户中心</a>
            <a class="secondary-link secondary-link--solid-dark" href="/doc">开发文档</a>
          </div>
        </div>

        <div class="legacy-hero__panels">
          <article
            v-for="item in heroPanels"
            :key="item.title"
            class="legacy-hero__panel"
            :class="`legacy-hero__panel--${item.tone}`"
          >
            <h3>{{ item.title }}</h3>
            <p>{{ item.text }}</p>
          </article>
        </div>
      </div>

      <div class="legacy-hero__divider" aria-hidden="true"></div>
    </section>

    <section class="legacy-strip">
      <div class="legacy-shell">
        <div class="legacy-strip__inner">
          <h2>你凭什么选择我们？</h2>
          <p>
            提供多种支付接入方式，方便、简单、快捷，前台展示、支付测试、商户入驻与正式业务保持统一，
            接入链路更清楚，联调上线更直接，也更适合后续继续接入真实接口。
          </p>
        </div>
      </div>
    </section>

    <section id="capability" class="legacy-services">
      <div class="legacy-shell">
        <div class="legacy-services__grid">
          <article
            v-for="item in coreServices"
            :key="item.title"
            class="legacy-service-card"
            :class="`legacy-service-card--${item.tone}`"
          >
            <div class="legacy-service-card__icon-shell">
              <div class="legacy-service-card__icon">
                <img :src="resolveAsset(item.icon)" :alt="item.title" />
              </div>
            </div>
            <h3>{{ item.title }}</h3>
            <p>{{ item.description }}</p>
            <div class="legacy-service-tags">
              <span v-for="tag in item.tags" :key="tag">{{ tag }}</span>
            </div>
          </article>
        </div>
      </div>
    </section>

    <section id="steps" class="legacy-media-section legacy-media-section--lead legacy-media-section--reverse">
      <div class="legacy-shell">
        <div class="legacy-media-section__grid">
          <div class="legacy-media-section__image">
            <img :src="legacySceneOne" alt="聚合支付展示图" />
          </div>
          <div class="legacy-media-section__copy legacy-media-section__copy--lead">
            <h2>聚合钱包，融合支付</h2>
            <p>
              统一承载商户注册、开发接入与支付测试能力，让用户在一个入口内完成从了解产品到发起支付的完整流程。
            </p>
            <ul class="legacy-highlight-list">
              <li v-for="item in walletHighlights" :key="item.title">
                <span class="legacy-highlight-list__icon">
                  <img :src="resolveAsset(item.icon)" :alt="item.title" />
                </span>
                <strong>{{ item.title }}</strong>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </section>

    <section class="legacy-media-section">
      <div class="legacy-shell">
        <div class="legacy-media-section__grid">
          <div class="legacy-media-section__image">
            <img :src="legacySceneTwo" alt="技术服务展示图" />
          </div>
          <div class="legacy-media-section__copy">
            <h2>高效技术服务</h2>
            <p>
              提供完整的支付接入文档、测试页面与商户入口，帮助开发者更快联调上线，
              减少重复沟通和配置成本，让前台展示与真实业务接入保持同一套表达。
            </p>
            <div class="legacy-inline-actions">
              <a href="/user/register">申请商户</a>
              <a href="/doc">查看文档</a>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="legacy-media-section legacy-media-section--reverse legacy-media-section--last">
      <div class="legacy-shell">
        <div class="legacy-media-section__grid">
          <div class="legacy-media-section__image">
            <img :src="legacySceneThree" alt="资金回笼展示图" />
          </div>
          <div class="legacy-media-section__copy">
            <h2>快速资金回笼</h2>
            <p>
              一次接入即可覆盖常用支付方式，结合商户中心和后台配置能力，
              帮助业务更快完成收款、通知与资金处理。
            </p>
            <a class="legacy-inline-link" href="/doc">了解更多...</a>
          </div>
        </div>
      </div>
    </section>

    <section id="support" class="legacy-finale">
      <div class="legacy-shell">
        <div class="legacy-finale__inner">
          <div class="section-head">
            <h2>展望未来</h2>
            <p>让首页、开发文档、支付测试与商户接入形成统一前台体验，便于展示，也便于后续继续接入真实接口。</p>
          </div>

          <div class="legacy-cta__actions">
            <a class="primary-link" href="/user/register">申请商户</a>
            <a class="secondary-link secondary-link--solid-dark" href="/doc">开发文档</a>
          </div>

          <div class="legacy-finale__icons" aria-hidden="true">
            <span v-for="item in paymentMethods" :key="item.name" class="legacy-finale__icon">
              <img :src="resolveAsset(item.icon)" :alt="item.name" />
            </span>
          </div>

          <p class="legacy-finale__closing">每一笔支付都承载着业务增长，感谢你选择我们。</p>
        </div>
      </div>
    </section>
  </main>
</template>
