# Wei Alipay 开发指南

本指南适用于第一方插件代码、测试、发布候选和受控镜像维护。支付代码的本地通过不等于生产验收。

## 1. 仓库与变更边界

- FeiCode 精确仓库是代码上游；GitHub 是单向公开下游。
- 开工前记录 `git status --short --branch`、精确 HEAD、上游分支和共享 WIP。
- 共享工作树有改动时使用隔离 worktree/分支，不覆盖或清理他人内容。
- 未绑定 Board 审批的精确 SHA 不得 push；不得自行 merge、tag、release、同步 GitHub 或部署商城。
- 测试禁止真实支付宝生产请求、真实交易和真实退款。

## 2. 本地依赖

最低运行目标为 PHP 7.4、WordPress 6.6、WooCommerce 8.3。开发分析依赖通过 Composer 安装：

```bash
composer install --no-interaction --prefer-dist
composer validate --strict
```

`vendor/` 只服务开发检查，不进入发布 ZIP。内置支付宝 SDK 目前不是 Composer 依赖；它按独立的第三方来源门管理。

## 3. 代码地图

| 路径 | 责任 |
| --- | --- |
| `wei-alipay.php` | 插件头、版本、主入口、兼容声明 |
| `inc/class-wei-alipay.php` | 启动、网关/Blocks 注册、模板与生命周期 |
| `inc/class-wc-alipay.php` | 主网关、支付、通知、退款 |
| `inc/class-alipay-sdk-helper.php` | SDK 客户端、查询与关单边界 |
| `inc/class-wc-alipay-order-query.php` | 传统订单存储与 HPOS 精确查询 |
| `inc/class-wei-alipay-secret-store.php` | 密钥迁移、加密、轮换、回滚与失败关闭 |
| `inc/class-wei-alipay-features.php` | 可选功能白名单、兼容迁移、后台功能中心与停用调度清理 |
| `inc/modules/` | 可选支付与清结算模块 |
| `css/admin/features.css` / `js/admin/features.js` | 独立功能中心的命名空间样式和渐进增强交互 |
| `lib/alipay/` | 第三方支付宝 v2 SDK 与历史辅助文件，不属于第一方 PHPCS 范围 |
| `tests/` | 合约、静态分析引导和安全测试 |
| `scripts/` | 本地 CI、可复现构建和下游镜像脚本 |

后台页面的结构、通知归属、容器约束、响应式样本和防挤破门禁见 [`ADMIN-UI-ENGINEERING.md`](ADMIN-UI-ENGINEERING.md)。`DESIGN.md` 说明视觉与交互目标；工程手册规定开发时不可破坏的 DOM/CSS/测试契约。

## 4. 不可随意改名的运行身份

即使产品名、目录和 text domain 改为 Wei Alipay，以下身份仍与商城数据相连：

```text
gateway ID                 alipay
settings option            woocommerce_alipay_settings
order metadata             _alipay_*
callback                   wc-api/WC_Alipay/
schedules and hooks        woo_alipay_*
```

修改这些值前必须提供可重入迁移、克隆站数据样本、回滚和旧入口过渡方案。不得只做字符串替换。

## 5. 支付代码要求

### 入站通知

顺序必须是：读取有限字段 → 验签 → 校验编码与时间 → 绑定 `app_id`/卖家/商户订单/支付宝交易/金额/币种 → 获取并发锁 → 重新读取订单 → 执行允许的状态转换 → 持久化幂等结果 → 返回固定响应。

禁止在验签和商户绑定之前修改订单、写入交易号或触发退款。金额使用十进制最小单位比较，不使用二进制浮点相等判断。

### 出站请求

- 默认 RSA2，不允许算法自动降级。
- 设置连接与响应超时；重试只能用于可证明幂等的请求。
- URL 必须来自固定支付宝端点或经过允许列表验证的配置。
- 日志只写脱敏标识、状态和内部关联 ID。

### 订单状态

重复、并发和乱序通知必须安全。已完成订单不得被迟到的关闭通知回退；退款、关单和成功通知的竞态必须用原订单和交易绑定处理。

## 6. 自动检查

第一方基础检查：

```bash
./scripts/tests/run-tests.sh all
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=4G --no-progress
composer validate --strict
git diff --check
```

本地等价 CI 与可复现候选：

```bash
./scripts/ci-local.sh
SOURCE_DATE_EPOCH="$(git show -s --format=%ct HEAD)" \
  ./scripts/build-release-candidate.sh /tmp/wei-alipay-build-a
SOURCE_DATE_EPOCH="$(git show -s --format=%ct HEAD)" \
  ./scripts/build-release-candidate.sh /tmp/wei-alipay-build-b
sha256sum /tmp/wei-alipay-build-{a,b}/*.zip
```

两份 ZIP 必须逐字节一致。发布包还要运行 `unzip -t`、秘密模式扫描、manifest/SBOM 校验和干净 WordPress 安装。

### 4.0.1 支付边缘路径回归

4.0.1 的变更必须至少覆盖以下合约，不能只重跑主 `alipay` 网关：

- 两套签约入口均要求显式启用、用户绑定 nonce 和本地返回地址；异步签约通知绑定 `app_id`、已配置的 `seller_id` 及签约状态。
- 订阅首扣使用数据库锁和持久幂等标记，代扣响应绑定原 `out_trade_no`。
- F2F/花呗退款使用 WooCommerce refund ID 生成稳定 `out_request_no`，并绑定原订单号、交易号及精确退款金额。
- F2F/花呗 Blocks 资源 URL 必须指向各自模块目录且文件真实存在。
- 多币种提示使用结账有效币种；密钥不可解密和未配置独立加密键必须向管理员失败关闭。
- 私有更新包仅允许 HTTPS 的 `updates.wenpai.net` 或 `feicode.com`；远端 Markdown 链接只允许 HTTPS。

定向安全检查示例：

```bash
PHPCS=/path/to/vendor/bin/phpcs
"$PHPCS" --standard=WordPress \
  --sniffs=WordPress.Security.EscapeOutput,WordPress.Security.NonceVerification,WordPress.Security.SafeRedirect,WordPress.Security.ValidatedSanitizedInput,WordPress.DB.PreparedSQL \
  <changed-first-party-runtime-files>
```

SDK 目录不得计入第一方零错误结论，也不得因为排除 SDK 就把整包写成 PASS。

### 后台功能中心

- 主 `alipay` 网关始终注册；其他支付方式和运营工具必须经过 `wei_alipay_features` 白名单。
- 第一次迁移只继承已有明确启用的网关或已有运营配置，不能因模块文件存在就默认开启。
- 关闭功能不得删除设置、订单元数据或历史记录，只能停止该模块注册并清理模块自有的待执行计划任务。
- 功能开关和 WooCommerce 网关启用是两个不同状态；后台必须明确解释两步启用，不能替商户静默启用支付能力。
- 设计 token、组件边界、证据 SHA 和可复现提取命令见 `docs/design/woopayments-admin-design-reference.md`。WooCommerce 支付列表的拖拽壳属于 WooCommerce core，不得错误归因或复制 WooPayments 私有组件。
- 页眉只显示产品身份、连接状态和说明；页面导航与任务动作进入标签、状态卡或对应内容区，不在页眉远端重复放置。

## 7. WordPress/WooCommerce 运行矩阵

至少记录以下每个样本的版本、命令、退出码和断言：

- PHP 7.4 与当前支持 PHP
- WordPress 6.6 与当前测试版本
- WooCommerce 8.3 与当前测试版本
- HPOS 关闭/开启
- Classic Checkout/Checkout Blocks
- 正常通知、精确重放、并发重放、跨订单伪造、金额不符、过期/未来通知、无效 UTF-8、RSA 降级
- 支付成功与关单乱序、退款重复和退款/关单竞态
- 单站点和 Multisite 的激活、升级、停用、默认保留卸载、显式清理

没有样本就写 `SKIP` 或 `BLOCKED`，不能写 PASS。

## 8. PHPCS、PHPStan 与 Plugin Check

- PHPStan 使用 `phpstan.neon.dist`、WordPress/WooCommerce stub 和明确的 SDK 边界；历史 baseline 不能吞掉新错误。
- PHPCS 优先检查支付回调、nonce/权限、SQL、输出、文件和网络安全；不为了数字归零机械格式化第三方 SDK。
- Plugin Check 对发布包执行，并将第一方、更新器政策和第三方 SDK 分开分类。
- 私有更新器的 `plugin_updater_detected` 对 WordPress.org 分发是政策错误，不能用 ignore 隐藏。

## 9. SDK 变更流程

SDK 结论、固定提交、哈希、许可缺口和升级步骤见 [SDK-MAINTENANCE.md](SDK-MAINTENANCE.md)。任何 SDK 更新必须单独提交，并至少完成：

1. 固定官方提交与下载摘要；
2. 比较插件实际引用的请求类；
3. 保留已记录的 PHP 兼容修补或给出替代；
4. 更新 SBOM、文件清单和许可证；
5. 离线签名/验签、查询、关单、退款和并发回调回归；
6. 沙箱验收；禁止用生产商户验证候选。

## 10. 支付网关生产切换 SOP

凡是出现插件目录/入口切换、SDK 更新或裁剪、密钥存储迁移、签名适配器变化、回调路由变化、网关 ID 或订单身份迁移，必须执行 `docs/governance/payment-gateway-production-cutover-sop.md`。事故背景和证据边界见 `docs/audits/wei-alipay-production-cutover-incident-2026-08-21.md`。

验收必须把 SDK tree、密钥容器、密钥格式、密钥对一致性、离线签名、沙箱、生产 canary 分开记录。`decryptable` 不是 `sign-ready`；后台可打开、支付方式可启用、候选 ZIP 可构建也都不是生产支付验收。真实生产 canary 必须另有精确 SHA、订单、金额、观察窗口和回滚审批；没有授权就标为 `BLOCKED`。

恢复旧私钥时只能从受控备份或原始密钥工具恢复。若原私钥不可恢复，必须生成新密钥对，并同步更新支付宝开放平台登记的应用公钥或证书；不能只在插件内替换私钥。日志、工单、HANDOFF 和测试输出只记录不可逆指纹，不记录密钥内容。

## 11. 提交与发布门

提交前更新 `[CX]` HANDOFF，记录精确 HEAD、测试命令/退出码、工件 SHA-256、未验证项。普通 push 也必须由 Board change 绑定精确 SHA；CI 审批、合并、Release、GitHub 同步和商城切换分别审批。
