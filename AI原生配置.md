# Wei Alipay AI 原生配置

本文件为在本仓库工作的 AI 编码代理提供最小事实、禁止项和验收格式。它不授予远端写入、发布或生产权限。

## 事实优先级

1. 项目 `AGENTS.md`、`CLAUDE.md`、状态和 `docs/ai-context/HANDOFF.md`（存在时）
2. WenPai VM 上的 canonical checkout 与 FeiCode 精确仓库
3. 当前隔离分支的代码、测试和构建结果
4. 官方 WooCommerce、WordPress、支付宝文档与官方 SDK 仓库
5. GitHub 公开仓库仅作下游镜像证据，不是开发真源

不得从 Mac 临时副本、目录名、组织名或公开镜像推断当前上游、发布或生产状态。

涉及后台页面、通知、表单或响应式布局时，还必须读取 `DESIGN.md` 与 `docs/ADMIN-UI-ENGINEERING.md`。不能只凭单张桌面截图写样式，也不能通过全局 CSS 或全局 notice hook 修补局部页面。

## 启动清单

```bash
git status --short --branch
git rev-parse HEAD
git remote -v
git worktree list
sed -n '1,240p' docs/ai-context/HANDOFF.md
```

发现共享 WIP 时建立隔离 worktree，不 reset、stash、clean、覆盖或合并他人改动。状态与交接内容标 `[CX]`。

## 任务边界

默认允许：读取、审计、在隔离分支修改、离线测试、沙箱桩测试、构建本地候选、生成 SBOM 和文档。

没有逐项授权时禁止：

- 连接支付宝生产网关或使用真实商户密钥
- 发起真实支付、退款、关单、分账、授权或签约
- 读取或修改 `mall.weixiaoduo.com` 生产数据
- push、批准 CI、merge、tag、Release、GitHub 同步或部署
- 删除订单、支付历史、共享 WIP 或 Multisite 数据
- 输出 token、私钥、完整回调报文、客户信息或凭据 URL

FeiCode 是 P0 且 `write_policy=board_required`。SSH 或读取成功不表示拥有写权限。

## 产品和兼容身份

```yaml
product_name: Wei Alipay Payment Gateway for WooCommerce
developer: Weixiaoduo.com
candidate_version: 4.0.1
package_slug: wei-alipay
text_domain: wei-alipay
canonical_upstream: FeiCode Weixiaoduo organization
public_downstream: GitHub Weixiaoduo/woo-alipay (planned rename to Weixiaoduo/wei-alipay)
minimum:
  php: "7.4"
  wordpress: "6.6"
  woocommerce: "8.3"
stable_financial_identities:
  gateway_id: alipay
  settings_option: woocommerce_alipay_settings
  order_meta: _alipay_*
  callback: wc-api/WC_Alipay/
  schedules_hooks: woo_alipay_*
feature_center:
  route: admin.php?page=wei-alipay
  allowlist_option: wei_alipay_features
  default: explicit_existing_enablement_only
  disable_policy: preserve_settings_orders_and_history
```

产品名和包名可以整理；稳定财务身份不能在没有迁移、克隆站验收和回滚时更改。

主 `alipay` 网关始终注册。可选支付方式和运营工具只有进入功能白名单后才允许加载；不得绕过功能中心批量启用。功能开关只决定模块注册，支付方式仍需在 WooCommerce 完成配置和第二步启用。后台设计依据及可复现 token 清单见 `docs/design/woopayments-admin-design-reference.md` 和 `docs/design/woopayments-admin-tokens.json`。

## 代码所有权边界

- 第一方：主文件、`inc/`、`includes/`、第一方 JS/CSS、`scripts/`、`tests/`
- 第三方：`lib/alipay/`、模块中的历史支付宝样例库、vendored QR 库
- 历史审计：`docs/audits/` 只追加后续事实，不重写旧结论
- 发布事实：源码、PR、精确 SHA 的 CI、tag、Release、公开 ZIP、商城运行必须分开报告

AI 不得用 blanket ignore 将 SDK 债务隐藏为第一方 PASS，也不得将第三方格式问题自动修复后混入支付代码提交。

## 支付安全不变量

- RSA2 为默认算法，旧 MD5 只在双重显式过渡开关下启用；禁止自动降级。
- 验签成功后才能进行商户、订单、交易、金额和时间绑定；所有绑定成功后才能改变订单。
- 金额按最小货币单位或规范十进制字符串比较。
- 重复、并发和乱序通知必须幂等；迟到关闭不能回退已支付订单。
- 退款请求和响应都要绑定订单、商户订单号、支付宝交易号、金额和请求 ID。
- 无法解密密钥时失败关闭；日志、导出和构建包不得出现明文私钥。
- 外部 URL 采用固定端点/允许列表、HTTPS、DNS/IP 复核和超时；不得形成 SSRF。

## 验证协议

基础命令：

```bash
./scripts/tests/run-tests.sh all
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=4G --no-progress
composer validate --strict
./scripts/ci-local.sh
```

每项结果使用 `PASS`、`FAIL`、`SKIP` 或 `BLOCKED`，并写样本数、版本、命令和退出码。零样本不能写 PASS；HTTP 200、候选 ZIP、旧 CI 或 PR 存在都不能替代业务验收。

最小支付测试集包括：签名有效/无效、RSA 降级、异常编码、过期/未来通知、金额精度、`app_id`/卖家/订单/交易绑定、跨订单伪造、精确/并发重放、成功/关闭乱序、退款幂等、HPOS on/off、Blocks、Multisite 生命周期。

## 支付切换与事故任务

任务命中“生产切换、SDK、密钥迁移、签名、missing-signature、回调、订单误改、退款”任一关键词时，先读：

1. `docs/governance/payment-gateway-production-cutover-sop.md`；
2. `docs/audits/wei-alipay-production-cutover-incident-2026-08-21.md`；
3. `docs/SDK-MAINTENANCE.md`；
4. 当前精确 SHA 的密钥存储、签名生成、回调和订单状态实现。

必须把“SDK 文件是否变化”“密钥能否解密”“密钥格式是否受支持”“密钥对是否匹配”“是否生成非空签名”“支付宝是否接受”“订单是否正确落账”分开取证。不得因其中一项通过推断其余项通过。用户报告的错误可以作为事故症状，但没有请求字段快照、OpenSSL 错误、活动 SHA 和支付宝响应证据时，不能写成已确认代码根因。

禁止输出或复制生产私钥。需要比对时只使用本地计算的公钥指纹；需要离线回归时使用临时密钥；需要真实生产 canary 时必须停在授权门前。回调缺少签名、`app_id`、订单号、交易号或金额时只应记录并返回失败，不能改变订单状态。

## SDK 决策

当前 SDK 固定与更新结论以 `docs/SDK-MAINTENANCE.md` 和 `docs/sbom/wei-alipay.spdx.json` 为准。没有固定官方提交、archive SHA-256、文件差异、许可证结论和离线/沙箱回归时，不得更新或裁剪 SDK，也不得把它标记为发布 PASS。

## 输出与交接

执行汇报先写结果事实，再写验证；未验证就写“已改，未验证”。交接至少包含：

```text
[CX] 日期与任务
worktree / branch / local HEAD
changed files and decisions
commands + exit codes + sample counts
candidate ZIP + SHA-256 (if built)
PASS / FAIL / SKIP / BLOCKED by layer
remaining risks and exact human approvals
explicitly: no production Alipay, no merge, no release, no deploy
```
