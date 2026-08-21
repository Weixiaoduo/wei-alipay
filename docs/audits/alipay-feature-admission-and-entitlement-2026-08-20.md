# [CX] Wei Alipay 功能准入与授权门控（2026-08-20）

## 决策

- 主支付宝网关、支付回调验签、查单、退款、关单、日志脱敏、失败通知重试等基础支付安全链路继续免费提供。
- 需要支付宝单独签约、行业/主体准入、定向开放或场景审核的支付产品，在插件中不得仅凭本地开关启用。
- 第一阶段不实现授权 Key 服务：受限模块在 UI、保存处理和运行时三处同时失败关闭，并引导至 Weixiaoduo.com。
- 后续授权 Key 只表示 Wei Alipay 功能授权，不表示支付宝已批准商户使用对应产品；支付宝准入和平台授权必须分别满足。
- 插件不得收集或上传商户支付宝私钥。未来许可证应使用本地公钥验签。

## 已核对的支付宝产品边界

支付宝准入规则按产品、主体、行业、渠道和风险审核变化。插件不能仅依据注册资本数值自动判断商户已获准入；最终以商户在支付宝开放平台实际签约/开通结果为准。

| 插件功能 | 支付宝侧公开要求摘要 | 本阶段策略 |
| --- | --- | --- |
| 主网关 | 电脑网站/手机网站支付需对应收单产品；网页、备案和主体一致性按支付宝当前规则审核 | 免费保留；商户自行配置已获批产品 |
| 当面付、条码付 | 对应线下收单产品、商户资料与场景审核；公开准入范围与订阅不同 | 锁定；未来需支付宝产品开通 + Wei Alipay 授权 |
| 花呗分期 | 需满足花呗准入，并先具备适用的收单产品；单通道可能需另行签约 | 锁定 |
| 资金授权/预授权 | 企业账号及产品/场景准入，部分方案定向开放 | 锁定 |
| 支付宝小程序 | 需获批小程序、关联 AppID/商户关系及支付产品 | 锁定 |
| 跨境支付 | 使用 Alipay+ / Ant International 独立开户、产品码与签约体系，不能与境内产品混用 | 锁定；未来应独立配置面 |
| 分账 | 需分账产品、收款方及商户/服务商授权关系 | 锁定 |
| 自动订阅/周期扣款/商家扣款 | 仅支持企业支付宝账号；需对应扣款产品开通，且须遵循扣费通知和用户解约要求；部分当前升级产品公开页列出更高经营门槛 | 锁定 |
| 对账 | 账单接口能力取决于商户签约，但本地对账工具不是独立支付产品 | 免费可选；接口无权限时明确报错 |
| Webhook 日志与重试 | 属于回调可靠性与运维能力，不是独立支付产品 | 免费可选 |

### 注册资本口径

“自动订阅统一要求注册资本 1000 万元”没有在本轮官方公开资料中得到支持，不得写成支付宝通用规则。支付宝当前“商家扣款升级版”公开介绍出现过企业账号、注册资本不低于 2000 万元、近半年月度主动支付用户数等条件；旧周期扣款和其他扣款/信用产品的公开条件并不相同。该门槛可能随产品、渠道与规则版本变化，UI 应链接当前官方规则，不在代码中硬编码 1000 万或 2000 万。

## 官方资料

- 周期扣款/商家扣款产品介绍：<https://opendocs.alipay.com/open/20190319114403226822>
- 商家扣款升级版：<https://opendocs.alipay.com/open/repo-0243dw>
- 商家扣款接入：<https://opendocs.alipay.com/open/00a05b>
- 商家扣款产品使用规范：<https://opendocs.alipay.com/b/06aql9>
- 当面付：<https://opendocs.alipay.com/open/194/105072>
- 花呗分期：<https://opendocs.alipay.com/open/repo-00phph>
- 预授权支付：<https://opendocs.alipay.com/open/repo-0243e2>
- 商家分账：<https://opendocs.alipay.com/open/009yja>
- 支付宝小程序支付：<https://opendocs.alipay.com/mini/08dcbo>
- Alipay+ 跨境开发帮助中心：<https://global.alipay.com/developer/helpcenter>
- WordPress.org 插件目录详细指南：<https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/>

## 门控模型

运行时有效状态必须同时满足：

1. 功能在当前发行版可开放；
2. 商户已获得支付宝对应产品准入/签约；
3. 所需 WooCommerce 依赖已安装；
4. 未来接入后，Wei Alipay 授权 Key 对该站点、App ID、Seller ID、环境和 feature scope 有效；
5. 管理员明确启用并完成配置。

当前版本没有可靠的支付宝产品开通状态接口，也没有 Wei Alipay 授权服务，所以所有受限模块统一保持 `restricted`，不得由 POST、直接改 option、后台任务或模块 bootstrap 绕过。

### 未来许可证最小字段

- `feature_scopes`
- `seller_id` / `app_id`
- `environment`
- 站点 URL 哈希
- `issued_at` / `not_before` / `expires_at`
- `policy_version`
- 平台签名

授权失效时禁止新签约和新业务，但已有订单的验签、查单、退款、解约与回调处理不能被切断。自动订阅需要单独的宽限期和降级为手动续费方案。

## WordPress.org 分发边界

如果未来提交 WordPress.org，单纯把随插件发布的本地代码作为付费试用功能锁住，可能触及目录对 trialware/locked functionality 的限制。届时应在“外部真实服务 + 免费基础客户端”或“免费核心插件 + 独立商业扩展”之间重新评审；本轮门控不等于已经通过 WordPress.org 合规审查。

## 本阶段实现范围

- 受限：`f2f`、`huabei`、`barcode`、`fund_auth`、`mini_program`、`cross_border`、`profitsharing`、`subscriptions`。
- 免费可选：`reconcile`、`webhook_retry`。
- 主网关不在功能中心被移除。
- 受限功能显示官方要求链接和 Weixiaoduo.com 申请入口，但开关禁用。
- 保存和版本迁移把受限功能写为关闭；运行时 `is_enabled()` 再次失败关闭。

## 未完成

- 未建立授权 Key 签发、撤销、轮换或离线验签服务。
- 未与支付宝 API 联机查询任何真实商户产品状态。
- 未对 mall.weixiaoduo.com 做生产配置修改或部署。
- 具体申请页、客服流程、价格和 SLA 尚未由产品/运营确定。
