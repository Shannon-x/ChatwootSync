# 网站客服查询

网站继续使用已接入 Captain 的原收件箱。面板工单仍由客服在面板处理，下面的工具只读，接口不收发工单或消息。

| 接口 | 返回内容 | 身份 |
| --- | --- | --- |
| GET /api/v1/plugin/chatwoot/support/plans | 在售套餐与公开规格 | 配置的网站会话 |
| GET /api/v1/plugin/chatwoot/support/orders | 本人的套餐及充值订单。可传完整 trade_no，不传时查近期记录 | 当前面板登录会话 |
| GET /api/v1/plugin/chatwoot/support/connection | 本人账户资格、节点权益和上报状态。可用 node 缩小范围 | 当前面板登录会话 |
| GET /api/v1/plugin/chatwoot/support/offers | 可见的公告和公告披露的优惠 | 当前面板登录会话 |
| GET /api/v1/plugin/chatwoot/support/quote | 本人所选套餐、周期、可选流量及增值组的优惠试算 | 当前面板登录会话 |

在插件配置填写专用随机密钥、Chatwoot 账号 ID、网站收件箱 ID 和助手 ID。密钥通过服务端传递。保持原 Chatwoot API 令牌仅在 XBoard 服务端，用于核对真实会话。个人查询随面板登录。主题通过现有身份接口获取 Chatwoot 网站签名，再为该浏览器的 source_id 绑定当前面板登录；绑定每三分钟在可见页面刷新，十分钟到期。每次读取都重新检查发出当前消息的浏览器和同一枚面板登录令牌，退出、切号和令牌撤销后停止读取。用户输入的邮箱或订单号不能选择账户。游客可查询在售套餐。工具安装脚本同时关闭该助手的联系人属性提示，以免未经验证的历史快照混入答案。坐席仍可在原界面查看已有属性。

Chatwoot 须包含已签浏览器发信来源摘要与触发消息 ID 的支持，网站主题须同步浏览器绑定。随后在 Chatwoot 4.20 网站助手环境中，用该版本的 Rails runner 运行 `scripts/configure-website-tools.rb`。设置 `CW_ACCOUNT_ID`、`CW_ASSISTANT_ID`、`CW_WEBSITE_INBOX_ID` 和 HTTPS 地址 `XBOARD_SUPPORT_BASE`（完整路径以 `/api/v1/plugin/chatwoot/support` 结尾），从标准输入传入专用密钥。使用仅绑定目标网站收件箱的助手。加 `--check` 校验定义，加 `--stage` 保存为禁用工具，验证后省略参数启用。安装时按助手和固定工具名更新，遇到同名但指向不同地址的工具会中止。工具走 Chatwoot 的 SafeFetch，因此 HTTPS 入口需能从 Chatwoot 访问。

**停用**：关闭插件的“网站 Captain 只读查询”，并在该助手的工具页关闭上述五项。不要将旧联系人属性作为查询降级数据。节点的最近心跳反映面板上报，不能据此确认用户的实际线路畅通。

## FAQ 重复候选

在 Chatwoot 环境以 `CW_ACCOUNT_ID` 和 `CW_ASSISTANT_ID` 指定助手，使用 Rails runner 运行 `scripts/faq-overlap-audit.rb`。输出仅包含问答 ID、同题不同答数量和向量相似度。文本比较覆盖当前助手已批准的 FAQ，向量近邻查询每次取 64 条作为起点，可用 `FAQ_BATCH_SIZE`（1–128）及 `FAQ_AFTER_ID` 分批继续。在服务器运行同一命令时追加 `--all`，会在批次间暂停后继续，直到完成本轮全库。先审核同题多答，再对高相似候选检查套餐、平台、时间、数字、例外和来源。相似度是候选排序依据。工具使用已有向量，运行在只读事务内，保留原始答案及来源。

## 本地测试

在宿主 XBoard 项目目录，使用该项目锁定的 Composer 依赖及 phpunit.xml。将 PLUGIN_DIR 指向插件检出目录。

```sh
XBOARD_ROOT="$PWD" vendor/bin/phpunit -c phpunit.xml --bootstrap "$PLUGIN_DIR/tests/bootstrap.php" "$PLUGIN_DIR/tests"
```
