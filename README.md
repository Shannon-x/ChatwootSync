# ChatwootSync · Xboard ↔ Chatwoot 双向同步插件

> 让 Chatwoot 客服侧栏自动展示客户的 Xboard 套餐/余额/流量/状态等数据；同时把 Chatwoot Web Widget 嵌入 Xboard 用户控制台（自动 Identity Validation，零摩擦）。

---

## 功能

- ✅ **Chatwoot → Xboard 拉取**：客服设置/修改 contact.email 时，自动同步该用户的 Xboard 资料到 custom_attributes
- ✅ **Xboard → Chatwoot 推送**：用户注册/下单/支付/流量重置/登录等事件，主动推送最新信息
- ✅ **Web Widget 自动嵌入**：用户登录 Xboard 控制台后，右下角自动出现 Chatwoot 浮窗；带 HMAC 身份验证
- ✅ **存量回填**：`php artisan chatwoot:backfill` 一键把现有 1000+ contact 全部同步
- ✅ **每日兜底**：自动注册 03:00 cron，扫描活跃 contact 保持新鲜
- ✅ **Telegram ID 关联**：客户没 email 但 sufegroup_bot 绑了 TG，也能反向匹配 Xboard 用户
- ✅ **登录节流**：高频登录事件 5 分钟内只触发一次同步

---

## 系统要求

| 项 | 版本 |
|---|---|
| Xboard | 当前 fork（支持 plugin 体系） |
| Chatwoot | v4.0+（已具备 Identity Validation + Contact webhook 事件） |
| PHP | 8.1+ |
| Queue Worker | 已配置并运行（Redis 或 database 驱动） |

---

## 安装

### 1. 部署文件

```bash
cd /var/www/xboard/plugins/
git clone https://github.com/your-org/chatwoot-sync.git ChatwootSync
# 或者直接 cp -r ChatwootSync /var/www/xboard/plugins/

cd /var/www/xboard
composer dump-autoload   # 让 Laravel 发现 Plugin\ChatwootSync 命名空间
```

### 2. Xboard 后台启用

打开 Xboard 后台 → **系统配置 → 插件管理** → 找到 `Chatwoot 用户信息同步` → 启用。

### 3. 填写配置

| 字段 | 从哪获取 |
|---|---|
| `chatwoot_base_url` | 你的 Chatwoot 域名，例 `https://chat.example.com` |
| `chatwoot_account_id` | Chatwoot URL 中 `/accounts/N/` 的 N，一般是 `1` |
| `chatwoot_api_token` | Chatwoot → Profile → Access Token |
| `widget_token` | Chatwoot → Inboxes → 你的 Website Widget → Website Token |
| `hmac_secret` | Chatwoot → Inboxes → 你的 Website Widget → Identity Validation 密钥（用于 Widget 身份签名） |
| `webhook_secret` | **不要自己生成** — 用 Chatwoot Webhook 后台生成的 HMAC Secret（见步骤 5） |
| `enable_widget` | 是否开启浮窗注入，建议开 |
| `enable_push_sync` | 是否开启 Xboard 主动推送，建议开 |
| `enable_telegram_id_match` | 是否开启 TG ID 回退匹配，建议开 |

### 4. Chatwoot 创建 13 个 Custom Attributes

> ⚠️ **必须在第一次同步之前完成**——否则 Chatwoot 会拒绝未定义的 custom_attribute key，导致同步失败。

进 Chatwoot → Settings → Custom Attributes，**Applies to: Contact**：

| Display Name | Key | Type | List Values |
|---|---|---|---|
| Xboard 套餐 | `xboard_plan` | Text | - |
| Xboard 状态 | `xboard_status` | List | `active, expired, banned, no_account` |
| 到期日期 | `xboard_expired_at` | Date | - |
| 账户余额 | `xboard_balance` | Number | - |
| 佣金余额 | `xboard_commission_balance` | Number | - |
| 流量使用率 | `xboard_used_percent` | Number | - |
| 已用流量(GB) | `xboard_used_gb` | Number | - |
| 总流量(GB) | `xboard_total_gb` | Number | - |
| 上级邀请人 | `xboard_inviter_email` | Text | - |
| Xboard 后台 | `xboard_admin_url` | Link | - |
| 订阅链接 | `xboard_subscribe_url` | Link | - |
| 最近登录 | `xboard_last_login_at` | Date | - |
| Xboard 同步时间 | `xboard_synced_at` | Date | - |

### 5. Chatwoot 配置 Webhook

Chatwoot → Settings → Integrations → Webhooks → **Add new**

- **End point URL**:（**不带任何 query 参数**）
  ```
  https://your-xboard.com/api/v1/plugin/chatwoot/sync
  ```
- **Hmac Secret**：点 "Generate" Chatwoot 会生成一个 24 位左右的随机字符串（**示例值已隐藏 — 请使用 Chatwoot 实际生成的**）
  - **复制这个值**，回到 Xboard 插件配置页粘贴到 `webhook_secret` 字段，保存
  - Chatwoot 会用它对每条 webhook 做 HMAC-SHA256 签名，写入 `X-Chatwoot-Signature` Header，本插件验签后才接受请求
  - ⚠️ 两边的密钥**必须完全一致**（含末尾空格）
- **Subscribed events**: ✅ Contact Created, ✅ Contact Updated

> **关于安全性**：本插件使用 Chatwoot 官方 HMAC 签名（Stripe-style），含时间戳防重放（5 分钟容忍窗口）、签整个 body 防篡改。不再使用 URL `?token=` 参数（那种方式在日志/proxy/referrer 中容易泄露）。

### 6. Xboard 主题注入 Widget

Xboard 后台 → 主题配置 → 当前主题 → **自定义 HTML** 框。

插件内置 CORS 支持 + 自动用当前请求 host 生成 IDENTITY_URL，**以下三种部署形态都开箱可用**：

#### 形态 ①：用户前端和 Xboard 同域

```html
<script src="/api/v1/plugin/chatwoot/widget.js" defer></script>
```

✅ 最简单。浏览器无跨域、无 preflight。

#### 形态 ②：管理域和用户域分离 + nginx 反代（推荐安全场景）

用户前端 nginx 加：
```nginx
location /api/v1/plugin/chatwoot/ {
    proxy_pass https://admin.example.com/api/v1/plugin/chatwoot/;
    proxy_set_header Host admin.example.com;
    proxy_set_header Authorization $http_authorization;
    proxy_ssl_server_name on;
}
```
然后注入相对路径 `<script>`，同形态 ①。

✅ 不暴露管理员域名；零 CORS。

#### 形态 ③：直接嵌管理域绝对 URL（最省事）

```html
<script src="https://admin.example.com/api/v1/plugin/chatwoot/widget.js" defer></script>
```

✅ 不用配 nginx。
⚠️ 用户浏览器会知道管理域名；fetch 有 OPTIONS preflight（性能影响微乎其微）。
⚠️ 插件已自动加 CORS Header（`Allow-Origin: <Origin>`），无需额外配置。

> **三种都不需要改插件代码**——按你部署情况挑一个即可。

#### 与 Chatwoot 官方嵌入脚本共存

如果你已经在用户前端放了 Chatwoot 官方嵌入脚本（含 `window.chatwootSettings` + 加载 sdk.js + 调 `chatwootSDK.run`），**不用改它**，只需在它**之后**追加一行 widget.js：

```html
<!-- 你已有的官方嵌入脚本（保持不变）-->
<script>
  window.chatwootSettings = {position: "right", launcherTitle: "联系我们"};
  (function(d,t){
    var BASE_URL="https://your-chatwoot.com";
    var g=d.createElement(t), s=d.getElementsByTagName(t)[0];
    g.src=BASE_URL+"/packs/js/sdk.js"; g.async=true;
    s.parentNode.insertBefore(g,s);
    g.onload=function(){ window.chatwootSDK.run({websiteToken:"...", baseUrl:BASE_URL}); };
  })(document,"script");
</script>

<!-- 追加这一行就够，widget.js 会自动检测 SDK 已被加载，仅接管身份注入 -->
<script src="https://your-xboard.com/api/v1/plugin/chatwoot/widget.js" defer></script>
```

widget.js 内置 3 重 SDK 探测（`window.chatwootSDK` / `window.$chatwoot` / DOM 中已有 sdk.js 脚本）+ DOMContentLoaded 二次检测，避免重复加载。

#### CSP 注意事项

如果你的站点用了严格 CSP，需要允许：
- `script-src` 包含 Xboard 域名（widget.js）+ Chatwoot 域名（sdk.js）
- `connect-src` 包含 Xboard 域名（identity 端点 fetch）

例：
```
Content-Security-Policy:
  script-src 'self' https://your-xboard.com https://your-chatwoot.com;
  connect-src 'self' https://your-xboard.com https://your-chatwoot.com;
```

### 7. 存量回填（现有 contact）

```bash
cd /var/www/xboard

# 先演练看看（不写入）
php artisan chatwoot:backfill --dry-run

# 真跑（1000 个约 3-5 分钟）
php artisan chatwoot:backfill

# 只处理有 email 的（避免无效同步）
php artisan chatwoot:backfill --only-with-email
```

### 8. 启动 Queue Worker

```bash
# 本插件 Job 走 default queue，所以标准命令即可
php artisan queue:work --tries=3 --backoff=30
```

建议用 Supervisor / systemd 守护。

> ⚠️ 如果 `QUEUE_CONNECTION=sync`（Xboard 默认），Job 会**同步执行**，无需启动 worker，但 webhook 响应会被同步等待。生产环境务必切到 `redis` 或 `database`。

---

## 自检

```bash
# 1. 检查路由是否注册
php artisan route:list | grep chatwoot

# 期望看到：
#   POST  /api/v1/plugin/chatwoot/sync
#   GET   /api/v1/plugin/chatwoot/widget.js
#   GET   /api/v1/plugin/chatwoot/widget-identity
#   GET   /api/v1/plugin/chatwoot/health

# 2. 配置健康检查
curl https://your-xboard.com/api/v1/plugin/chatwoot/health
# 期望返回 9 个布尔值，全 true 表示配置齐全

# 3. 测试 webhook（需要计算 HMAC 签名）
BODY='{"event":"contact_updated","id":1,"email":"test@example.com","account":{"id":1}}'
TS=$(date +%s)
SECRET="YOUR_WEBHOOK_HMAC_SECRET"   # 等于 Chatwoot 后台填的 Hmac Secret
SIG="sha256=$(echo -n "${TS}.${BODY}" | openssl dgst -sha256 -hmac "$SECRET" -hex | sed 's/^.*= //')"

curl -X POST "https://your-xboard.com/api/v1/plugin/chatwoot/sync" \
  -H "Content-Type: application/json" \
  -H "X-Chatwoot-Timestamp: $TS" \
  -H "X-Chatwoot-Signature: $SIG" \
  -d "$BODY"

# 4. 测试 widget.js 渲染
curl https://your-xboard.com/api/v1/plugin/chatwoot/widget.js
# 期望返回 JS 内容，含 chatwootSDK.run
```

---

## 工作流速查

| 触发场景 | 数据流向 |
|---|---|
| 客服在 Chatwoot 改了 contact.email | Chatwoot → `webhook` → Plugin SyncController → Job → ChatwootClient → Chatwoot Contact 更新 |
| Xboard 用户注册/下单/支付/流量重置 | Xboard `HookManager::call` → Plugin listen → Job → Chatwoot Contact 更新 |
| 用户登录 Xboard 控制台并打开浮窗 | 浏览器加载 widget.js（仅 SDK） → 客户端 JS 从 localStorage 取 token → fetch `/widget-identity` (Bearer) → 拿到 `{email, identifier_hash}` → setUser → Chatwoot 自动创建/匹配 Contact → contact.created webhook → Plugin sync |
| 存量回填 | Admin → `php artisan chatwoot:backfill` → 遍历 Chatwoot contact → 按 email 查 Xboard → 回写 |
| 每日 03:00 兜底 | Laravel Scheduler → `chatwoot:backfill --only-with-email`（受 `enable_push_sync` 总开关控制） |

---

## 字段映射表

详见 `Services/AttributeMapper.php`。

| Xboard 字段 | 转换 | Chatwoot Key |
|---|---|---|
| `plan.name` | 直接 | `xboard_plan` |
| `banned + expired_at + time()` | 综合判定 | `xboard_status` |
| `expired_at` | 时间戳 → `Y-m-d` | `xboard_expired_at` |
| `balance` | ÷ 100 | `xboard_balance` |
| `commission_balance` | ÷ 100 | `xboard_commission_balance` |
| `u + d` | ÷ 1073741824 | `xboard_used_gb` |
| `transfer_enable` | ÷ 1073741824 | `xboard_total_gb` |
| `(u+d) / transfer_enable` | × 100 | `xboard_used_percent` |
| `invite_user.email` | 直接 | `xboard_inviter_email` |
| Xboard URL + id | 拼接 | `xboard_admin_url` |
| `Helper::getSubscribeUrl(token)` | 直接 | `xboard_subscribe_url` |
| `last_login_at` | 时间戳 → `Y-m-d` | `xboard_last_login_at` |
| - | `now()` | `xboard_synced_at` |

---

## 排错指南

| 症状 | 可能原因 | 排查 |
|---|---|---|
| 浮窗不出现 | custom_html 没生效 | 查看页面源码末尾是否含 `<script src=".../widget.js">`；清浏览器缓存 |
| widget.js 返回 404 | 插件没启用或没 `composer dump-autoload` | `php artisan route:list \| grep chatwoot` |
| widget.js 返回 "widget disabled" | 配置里 `enable_widget=false` | 后台开启 |
| widget.js 返回 "missing config" | base_url 或 widget_token 没填 | 检查配置 |
| 浮窗显示但永远是匿名 | hmac_secret 没配 → widget.js 不会去拉身份 | 健康检查 `derived.widget_identity_ready` 应为 true；浏览器 console 会有 `hmac_secret not configured` warning |
| 浮窗显示但 setUser 失败 | identifier_hash 不匹配 | 看 Chatwoot 日志 `Invalid identifier_hash`；确认 Xboard 插件配的 hmac_secret 和 Chatwoot 后台的完全一致（无空格） |
| widget-identity 总是返回 `{authenticated: false}` | localStorage token key 名不一致 | 浏览器 DevTools → Application → Local Storage 看 key 名；若不是 `auth_data/token/access_token`，需在 widget.blade.php 加 |
| webhook 报 `account_mismatch` | 别的 Chatwoot 账号误推到这个 URL | 检查 Chatwoot Webhook URL 配置；或确认 `chatwoot_account_id` 填对了 |
| webhook 没触发 | URL 错或 secret 不匹配 | Chatwoot Webhook 详情页 "Test webhook" 试一下 |
| webhook 返回 `missing_signature` | Chatwoot Webhook 后台没填 Hmac Secret | 在 Chatwoot Webhook 编辑页 Generate 一个，复制到插件 `webhook_secret` |
| webhook 返回 `invalid_signature` | 两边 secret 不一致 | 重新复制 Chatwoot 那边的值，注意首尾空格 |
| webhook 返回 `stale_timestamp` | Xboard 服务器时钟与 Chatwoot 偏差 > 5 分钟 | `timedatectl status` / 校时 (`sudo timedatectl set-ntp true`) |
| webhook 触发但 contact 没更新 | Queue 没起 / API token 权限不足 | `ps aux \| grep queue:work`；Chatwoot 用 Administrator Bot |
| 客服看不到字段 | 13 个 Custom Attribute 没创建 | 见安装步骤 4 |
| 回填很慢 | rate 默认 200ms 慢 | 加 `--rate=100`（注意 Chatwoot 限流） |

---

## 安全考量

1. **webhook_secret** = Chatwoot 自动生成的 Hmac Secret，校验使用 Stripe-style 签名（含时间戳防重放 + HMAC-SHA256 防篡改）。`hash_equals()` 比较防 timing attack。绝不进 git。
2. **chatwoot_api_token** 用专用 Bot 账号 token，便于撤销
3. **hmac_secret** （Widget Identity Validation 用）仅放后端（插件配置 + Chatwoot 后端），绝对不进前端 JS
4. **widget.js** 已设 `Cache-Control: no-store`，CDN 不会缓存
5. **回填命令** 限速 200ms 默认值保护 Chatwoot 不被打爆
6. **account_id 校验** 阻断别的 Chatwoot 账号误推的 webhook
7. **localStorage token 探测** 对通用 key（`token` / `access_token`）要求 `Bearer ` 前缀，防止其他 SDK 写入的无关字符串被误用为 auth header
8. **其他客服 widget 共存检测** 自动检测页面是否已加载 Crisp / Intercom / Tawk / Zendesk / Freshchat / LiveChat，若有则退出避免双气泡冲突
9. **XSS 风险**：widget.js 从 localStorage 读 token 后通过 Authorization Header 发到 identity 端点。如果站点有 XSS 漏洞，攻击者可直接读 localStorage 拿到 token——widget 不增加新风险，但提供了便利的探测路径。**建议**：含 UGC 的站点应改用 httpOnly cookie + 服务端 widget-identity 代理

## 多站点部署

一个 ChatwootSync 实例可以服务多个前端站点，前提：

- **所有站点共用同一个 Xboard 后端**（token 在哪个 Xboard 签发就只在哪个 Xboard 能验证）
- **Chatwoot Inbox 的 Allowed Domains 包含所有前端域名**（用逗号分隔）
- 每个站点 `<script src>` 都指向同一个 `/api/v1/plugin/chatwoot/widget.js`

如果各站点用**不同的 Chatwoot Inbox**：需要在每个 Xboard 实例上**单独装一份 ChatwootSync 插件**，配各自的 `widget_token` / `hmac_secret`。

如果各站点有**别家客服 widget**（Crisp/Intercom 等）：本插件检测到会自动退出。需要先**移除别家 widget** 才能使用。

---

## 卸载

```bash
# Xboard 后台 → 插件管理 → ChatwootSync → 禁用
# 然后删目录
rm -rf /var/www/xboard/plugins/ChatwootSync
composer dump-autoload
```

> Chatwoot 端的 13 个 Custom Attribute Definition 和 Webhook 配置需要手动删除。

---

## 版本

- v1.0.0（2026-05-20）：初版

---

## 许可

MIT
