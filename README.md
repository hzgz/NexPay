# NexPay

NexPay 是一个基于 `Webman + Vue 3` 的聚合支付系统，提供：

- 管理员后台
- 商户中心
- 首页与演示页
- 易支付 V1 / V2 兼容接口
- 插件化支付扩展能力

## 系统优势

- 单端口部署  
  首页、商户中心、管理后台、支付接口可统一由 Webman 单端口对外提供，部署链路更简单。

- 易支付兼容接入  
  同时保留 V1 / V2 兼容入口，便于对接已有商户系统与历史接入程序。

- 插件化支付架构  
  支付能力按插件组织，便于扩展不同通道、不同支付方式与差异化配置。

- 双角色后台分离  
  管理员后台与商户中心职责分开，方便平台运营、商户管理和渠道配置。

- 前后端分离但可统一发布  
  `frontend/home`、`frontend/admin`、`frontend/user` 构建后可统一发布到 `backend/public*`，兼顾开发效率与上线部署。

- 业务服务层拆分清晰  
  路由、控制器、支付服务、商户服务、系统服务、插件层分层处理，便于后续维护与扩展。

## 架构图

```mermaid
flowchart LR
    Home["首页 / 演示页"] --> Webman["Webman HTTP 入口"]
    Merchant["商户中心"] --> Webman
    Admin["管理员后台"] --> Webman
    Compat["易支付 V1 / V2 请求"] --> Webman
    Callback["支付回调 / 查询请求"] --> Webman

    Webman --> Router["路由与控制器层"]
    Router --> Static["StaticAppController / 静态发布资源"]
    Router --> Api["API Controllers"]

    Api --> Payment["支付服务层"]
    Api --> MerchantSvc["商户服务层"]
    Api --> SystemSvc["系统服务层"]
    Api --> CallbackSvc["回调处理层"]

    Payment --> Plugins["支付插件层"]
    CallbackSvc --> Plugins
    MerchantSvc --> Plugins
    SystemSvc --> Plugins

    Payment --> MySQL["MySQL"]
    MerchantSvc --> MySQL
    SystemSvc --> MySQL
    CallbackSvc --> Redis["Redis / Queue"]
    Payment --> Redis
```

### 发布结构

```mermaid
flowchart TD
    HomeSrc["frontend/home"] --> Build["scripts/build-release.ps1"]
    AdminSrc["frontend/admin"] --> Build
    UserSrc["frontend/user"] --> Build
    Build --> PublicRoot["backend/public"]
    Build --> PublicAdmin["backend/public/admin"]
    Build --> PublicUser["backend/public/user"]
    PublicRoot --> Runtime["Webman 单端口运行"]
    PublicAdmin --> Runtime
    PublicUser --> Runtime
```

## 技术栈

### 后端

- PHP `8.1+`
- [Webman 2.x](https://www.workerman.net/webman)
- Think ORM
- Redis Queue
- Composer

### 前端

- Vue 3
- Vite
- TypeScript
- Element Plus
- Vue Router
- ECharts

## 运行环境要求

建议环境：

- Windows / Linux
- PHP `8.1+`
- Composer `2.x`
- Node.js `20+`
- npm `10+`
- MySQL `5.7+` / `8.0+`
- Redis `6+`

建议 PHP 扩展：

- `openssl`
- `pdo_mysql`
- `redis`
- `mbstring`
- `curl`
- `json`
- `fileinfo`

## 目录结构

```text
backend/                 Webman 后端主程序
  app/                   控制器、服务、模型、支付流程
  config/                路由、进程、数据库、会话等配置
  database/              数据库结构
  plugins/               支付及扩展插件
  public/                单端口静态发布目录
  support/               启动与基础支持代码

frontend/
  home/                  首页与演示页前端源码
  admin/                 管理员后台前端源码
  user/                  商户中心前端源码

scripts/                 构建与辅助脚本
```

## 接口入口

默认访问入口：

- 首页：`/`
- 演示：`/demo`
- 管理后台：`/admin`
- 商户中心：`/user`

后端接口分组：

- 管理后台：`/api/admin/*`
- 商户中心：`/api/merchant/*`
- 首页：`/api/home/*`

### 易支付 V1

- `POST /mapi.php`
- `POST /api.php`
- 页面兼容入口：
  - `/submit.php`
  - `/submit2.php`

### 易支付 V2

- `POST /api/pay/create`
- `POST /api/pay/query`
- `POST /api/pay/refund`
- `POST /api/pay/refundquery`
- `POST /api/pay/close`
- `POST /api/transfer/submit`
- `POST /api/transfer/query`
- `POST /api/transfer/balance`

## 安装与启动

### 1. 安装后端依赖

```powershell
cd backend
composer install
```

### 2. 配置环境变量

```powershell
Copy-Item .env.example .env
```

按实际环境修改：

- `APP_URL`
- `HTTP_PORT`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `REDIS_HOST`
- `REDIS_PORT`
- `TOKEN_SECRET`
- `PLATFORM_PUBLIC_KEY`
- `PLATFORM_PRIVATE_KEY`

### 3. 安装前端依赖

```powershell
cd frontend/home
npm install

cd ../admin
npm install

cd ../user
npm install
```

### 4. 启动后端

```powershell
cd backend
php windows.php
```

## 单端口部署

执行：

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\build-release.ps1
```

该脚本会构建：

- `frontend/home`
- `frontend/admin`
- `frontend/user`

并发布到：

- `backend/public`
- `backend/public/admin`
- `backend/public/user`

部署后启动：

```powershell
cd backend
php windows.php
```

## 说明

- 本项目包含大量插件与兼容接口，实际投产前请按所需链路完成单独验收。
- 请勿将本地密钥、运行日志、上传文件、依赖目录直接纳入公开仓库。

## 仓库

- [hzgz/NexPay](https://github.com/hzgz/NexPay)
