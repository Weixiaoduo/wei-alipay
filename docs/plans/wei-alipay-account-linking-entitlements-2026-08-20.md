# [CX] Wei Alipay 账户绑定、功能授权与使用可见性方案（2026-08-20）

## 1. 产品结论

Wei Alipay 采用“插件免费、账户绑定免费、高级服务收费”的模式：

1. 插件代码继续按 GPL 提供，不收安装费。
2. 商户在启用生产支付前，明确同意数据说明并绑定 Weixiaoduo.com 账户；绑定后平台可以看到该插件实际使用在哪个站点。
3. 普通单商户功能在完成免费绑定、依赖检查和支付宝对应产品开通确认后免费启用。
4. WooCommerce Subscriptions 自动续费、多商户分账属于商业授权范围，并附带准入协助、配置验收、更新和支持服务。
5. 支付宝准入与 Wei Alipay 商业授权是两件事。购买服务不表示支付宝已批准商户使用对应产品。
6. 注册资本不能在插件里写死为统一 1000 万或 2000 万；不同扣款产品和行业规则不同，以支付宝当时的产品开通结果为准。

## 2. 功能分层

| 层级 | 功能 | 条件 | 收费策略 |
| --- | --- | --- | --- |
| 本地准备 | 配置页、密钥健康检查、沙箱/离线自检、回调地址、诊断导出 | 无需绑定；不得发送生产交易 | 免费 |
| 免费绑定 | 主电脑/手机网站支付、退款、查单、关单、回调验签、Webhook 日志与重试、对账 | 绑定 Weixiaoduo.com；支付宝对应收单产品已开通 | 免费 |
| 免费单商户扩展 | 当面付、条码付、花呗分期、小程序支付 | 绑定；支付宝对应产品/场景已开通；依赖满足 | 免费 |
| 商业自动续费 | WooCommerce Subscriptions + 支付宝周期/商家扣款 | 绑定；商业 entitlement；支付宝企业账号和对应扣款产品获批；Woo Subscriptions 已安装 | 收费 |
| 商业平台业务 | 多商户分账、接收方、规则、任务、补偿与审计 | 绑定；商业 entitlement；支付宝分账产品和商户/服务商关系获批 | 收费 |
| 专项服务 | 跨境支付、资金授权/预授权 | 绑定；支付宝/Alipay+ 准入；人工方案评审 | 功能授权可免费或收费，实施与支持服务收费，由产品另定 |

收费对象应写成“商业功能授权、准入协助、配置验收、持续更新和技术支持”，不要写成“购买后自动获得支付宝资格”。

## 3. 用户界面

### 3.1 页面结构与连接后导航

使用 WordPress/WooCommerce 原生结构：

- 页头只承载 `Wei Alipay` 本地 Logo、连接状态 chip、连接后头像和一句说明；不放远距离或重复的导航动作。主网关入口保留在收款状态卡片中。
- 未连接页签：`概览`、`支付方式`、`运营工具`、`账户与授权`。
- 已连接后在`运营工具`与`账户与授权`之间增加`增值服务`。连接状态只决定该目录是否相关，不代表已经获得任何 scope、支付宝准入或运行权限。
- `增值服务`汇总商业和专项能力的授权、准入与服务申请状态：自动续费、多商户分账、资金授权、跨境支付。不得命名为模糊的“高级功能”，也不把原配置从支付方式或运营工具迁走。
- 未连接时直接访问 `view=services` 必须规范化到账户页；不能只通过 CSS 隐藏标签。
- 免费单商户扩展仍位于`支付方式`；Webhook、对账与恢复能力仍位于`运营工具`。
- 不把账户绑定、十个功能开关、说明文字和销售链接塞在一个长页面里。
- 不重复在每一行同时放“支付宝要求”和“去购买”两个长链接；行内只显示状态和一个上下文动作，完整要求进入详情页或 disclosure。

### 3.2 未绑定

账户卡片：

- 标题：`连接薇晓朵账户`
- 状态：`未连接`
- 说明：`连接后可免费启用标准支付和单商户功能，并在账户中心管理站点、授权和更新。`
- 数据摘要：`将发送站点域名、安装标识、插件/WordPress/WooCommerce/PHP 版本、语言、币种、已启用功能和最近连接时间；不会发送订单、金额、客户资料或支付宝私钥。`
- 主动作：`连接账户`
- 次动作：`查看数据与隐私说明`

未绑定时：

- 可以完成本地配置和沙箱检查。
- 生产支付功能显示 `绑定后免费`，不是模糊的 `Authorization required`。
- 商业功能显示 `需要商业授权`。
- 禁用开关必须同时有文字原因，不能只靠灰色。

### 3.3 已绑定

账户卡片显示：

- 连接账户的掩码标识，不显示 Master API Key。
- 当前域名、安装 UUID 尾号、最近同步时间。
- `免费授权` / `商业授权` 状态。
- `在 Weixiaoduo.com 中管理`、`解除连接`。
- 商业功能临期或失效只在本插件页面显示一次上下文提示，不发全局广告 notice。

已绑定但没有商业授权时：

- 显示`增值服务`标签和服务目录，使用户能理解可申请的能力。
- 不显示可提交的功能开关；每项显示固定状态和准入 disclosure，页面只保留一个 Weixiaoduo.com 服务入口，避免连续重复申请按钮。
- 连接、商业 entitlement、支付宝/Alipay+ 准入、依赖安装和本地配置分别显示，不能合并成一个“已启用”。

有授权时后续版本才显示对应配置入口。授权到期时禁止新签约、新分账规则和新接收方，但回调、退款、解约、查询、补偿和审计继续可用。

### 3.4 功能行状态

状态使用固定词汇：

- `绑定后免费`
- `可免费启用`
- `需要支付宝开通`
- `需要商业授权`
- `依赖缺失`
- `需要配置`
- `已启用`

有效判定：

```text
can_boot = local_enabled
        && dependency_ready
        && site_link_active
        && entitlement_scope_present
        && provider_requirement_acknowledged
```

UI 灰掉不是安全边界。保存处理、模块 bootstrap、REST/AJAX、cron 和 Action Scheduler 都必须重复检查。

## 4. 账户和授权架构

### 4.1 现有事实

- `wei-account` 是账户中心的展示与代理层，不应成为第二套订单/订阅/授权真源。
- 商城当前使用 Kestrel API Manager；一个客户有账户级 Master API Key，产品×订单另有 product order key。
- 激活记录已有裸域名、实例 UUID、激活时间、插件版本和 IP，但没有最近校验时间。
- `wei-account` 已有授权详情形状：产品、掩码 Key、席位、激活域名、版本和状态。

### 4.2 不采用的方案

不让用户直接把账户级 Master API Key 填进 Wei Alipay：

- 权限过宽，一把 Key 可覆盖多个产品。
- Key 泄露影响整个账户。
- 旧 API Manager 客户端把 Key 放在查询参数中，不适合作为新设计基线。
- 插件不能把支付宝私钥或商户证书传给 Weixiaoduo.com。

### 4.3 推荐连接流程

1. 插件生成 `state`、PKCE challenge 和站点安装 UUID。
2. 浏览器跳转 `account.weixiaoduo.com/connect/wei-alipay`。
3. 用户登录/注册，在账户中心看到准确的数据清单和站点域名并确认连接。
4. 账户中心签发 5 分钟有效、单次使用的 authorization code。
5. 插件后台通过 HTTPS + PKCE 换取站点级 installation token 和签名 entitlement。
6. installation token 只允许当前产品、当前站点和固定 API 动作，不具备下载其他产品或查看账户的权限。
7. Wei Account 显示 Wei Alipay 服务卡、已连接域名、版本、功能 scopes、最近连接时间和解除连接入口。

授权真源应落在 mall/API Manager 的受控扩展或独立 entitlement engine；`wei-account` 只代理读写，不双写业务状态。

### 4.4 entitlement 最小字段

```json
{
  "product": "wei-alipay",
  "installation_id": "uuid",
  "site_host": "example.com",
  "environment": "production",
  "scopes": ["core", "f2f", "subscriptions"],
  "issued_at": 0,
  "not_before": 0,
  "expires_at": 0,
  "policy_version": 1,
  "signature": "base64url"
}
```

插件只保存加密 installation token 和可公开验证的签名 entitlement；平台私钥不进入插件。

### 4.5 SSO、Wei Account 与 Wei Payments 的责任边界

这不是三选一。目标结构是：

- **SSO 是用户在 Weixiaoduo.com 自有域名内的登录体验**。`account.weixiaoduo.com` 负责登录、注册、Passkey、会话和确认页；共享 `.weixiaoduo.com` cookie 只能覆盖自有子域，不能作为外部 WordPress 商户站的插件凭据。
- **Wei Account 是账户连接和授权入口**。Wei Alipay 作为安装在任意域名上的公开 OAuth 客户端，使用 authorization code + PKCE S256、一次性 `state`、固定 HTTPS 回调和明确 consent，把当前站点连接到账户。插件获得的是站点级 installation credential，不是用户密码、WordPress 登录 cookie 或账户级 Master API Key。
- **Wei Payments 是订单、统一收银台、续费和付款状态层**，不签发登录身份，也不被插件当作认证服务器。商业服务购买完成后，由 Wei Payments 通过受控事件/适配器更新 entitlement 真源；Wei Account 展示结果，Wei Alipay 只同步自己被授权的 scopes。

现有 `wei-account` 代码提供的是自有站点 cookie + `X-WP-Nonce` 会话 API，还没有 OAuth/OIDC authorization server、token endpoint、discovery/JWKS 或 installation-token 端点。因此 Phase B 实施前只能保留 presentation-only account state，不能把现有 SSO cookie、分享 token、WooCommerce order key 或 Wei Payments 回跳参数冒充插件授权。

首版不必宣称 OIDC：插件并不需要消费用户身份 claims。先实现符合 OAuth 安全实践的站点授权协议；只有当平台提供规范的 issuer、discovery、JWKS、ID Token、`nonce` 校验和客户端注册模型后，才把认证层命名为 OIDC。

## 5. 使用数据与隐私边界

### 5.1 绑定服务必需数据

- 规范化站点 host，不发送路径、查询参数或后台 URL。
- 随机 installation UUID；多站点按 network + blog 明确建模。
- Wei Alipay、WordPress、WooCommerce、PHP 版本。
- 站点语言、商店币种、环境类型。
- 已启用/申请的 Wei Alipay feature scopes。
- 首次绑定、最近连接和解除连接时间。
- 数据说明/隐私政策版本及用户确认时间。

### 5.2 默认不收集

- 订单号、订单金额、交易量、退款金额。
- 客户姓名、邮箱、手机号、地址、支付宝 buyer ID。
- 支付宝应用私钥、公钥正文、证书、通知正文。
- 完整 App ID / Seller ID；反滥用确需绑定时只发送带服务端 pepper 的不可逆标识或由 entitlement engine 单独保护。
- 任意页面访问、文章、商品或管理员行为分析。

“功能兴趣”只在管理员主动点击申请/查看商业功能时记录，不能根据后台浏览轨迹偷偷推断。

### 5.3 同步策略

- 首次绑定立即同步；之后每 24 小时一次并加随机抖动。
- 使用 Action Scheduler/WP-Cron，非阻塞请求，短超时，指数退避。
- 后台明确显示最近成功同步时间和本次会发送的数据。
- 提供解除连接、数据导出和删除申请入口。
- 可选诊断数据单独 opt-in，不能与必需授权数据捆绑。

隐私政策必须先确定保留期限、解除连接后的删除时限和安全日志 IP 的用途；本方案不替代法律审查。

## 6. 支付连续性

授权服务不可用时不能让支付现场故障：

- entitlement 本地验签，不在结账请求中实时访问 Weixiaoduo.com。
- 主免费网关首次绑定后，平台短暂故障不应停止收款。
- 商业授权失效：禁止新订阅签约、新分账规则和新接收方；已有支付回调、查单、退款、解约、既有分账补偿继续运行。
- 自动续费需独立宽限期和人工降级方案，不能因授权服务器短暂失败直接漏扣或破坏用户协议。
- 远端撤销在下一次后台刷新/定时同步生效，并保留审计原因。

## 7. 与 Wei Account 的结合

Wei Account 增加 `Wei Alipay` 服务卡和详情页：

- 免费/商业授权状态。
- 已连接域名、插件版本、首次绑定和最近连接。
- 已批准 scopes 与正在申请的商业功能。
- `升级自动续费`、`申请多商户分账`、`预约配置验收`等上下文动作。
- 域名解绑、令牌撤销、数据导出/删除申请。

销售线索只来自用户主动连接、主动申请和站点技术兼容数据，不采集支付交易或客户数据。这样可以判断用户需要版本升级、配置、订阅接入或分账实施服务，而不越过支付数据边界。

## 8. 近期实施顺序

### Phase A：先改界面和状态模型

- [CX] 已把重复的 `Authorization required` 行拆成固定产品状态，并把完整要求移入原生 disclosure。
- [CX] 已把根页面拆成 `概览`、`支付方式`、`运营工具`、`账户与授权` 四个 URL 可寻址页签。
- [CX] 已为 registry 增加 `tier`、`required_scope`、`provider_requirement`；现阶段 `access` 继续作为 fail-closed availability 边界。
- [CX] 账户页按“结论 → 权益 → 数据披露 → 连接详情 → 危险操作”渐进显示；连接后端未完成前不发送外部请求，也不提供伪连接按钮。
- [CX] 导航采用连接感知：未连接保持四个基础标签，connected 状态才显示`增值服务`；服务页仅汇总授权/准入，不迁移支付方式和运营工具原有配置，目录可见不改变 runtime fail-closed 状态。
- [CX] 不为每个已启用网关创建动态顶级标签。支付方式页保留原列表，并在存在有效启用的可选支付方式时显示一个紧凑状态块。

### Phase B：服务端最小闭环

- 在 mall entitlement engine 建站点连接、令牌、scopes、last_seen 和撤销模型。
- Wei Account 增加连接确认页、站点列表和代理接口。
- 插件实现 authorization-code + PKCE、加密 token、本地验签和定时同步。
- mall.weixiaoduo.com 克隆站做绑定、解绑、换域、multisite、离线和回滚测试。

### Phase C：商业功能

- Woo Subscriptions scope：申请、审批、购买/续费、到期宽限、已有协议连续性。
- Profit Sharing scope：商户关系证明、接收方/规则/任务/补偿权限与审计。
- 接入 Weixiaoduo.com 服务页、工单和销售线索，但不自动发送营销信息。

## 9. 发布边界

本产品按 FeiCode 自有渠道分发，不计划提交 WordPress.org。外联、必要使用遥测和站点授权是已确定的产品能力，不再按 WordPress.org 目录政策设计删减；仍必须明确披露字段、目的、保留期、失败行为与数据排除项，并遵守 GPL、隐私、安全和支付连续性边界。

## 10. 未决产品项

1. 主生产网关是否强制完成免费绑定，还是仅高级/扩展功能要求绑定。若目标是知道所有真实使用站点，必须选择前者并明确披露。
2. 跨境支付和资金授权属于免费绑定功能，还是收费实施服务。
3. 免费授权允许多少个站点；开发/测试域名是否占席位。
4. 域名和 last_seen 的保留期限，以及解绑后多久删除明细。
5. 商业授权价格、服务内容、退款和到期宽限。
