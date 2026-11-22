# Wei Alipay 后台 UI 工程原则

本手册规定后台页面开发时不可破坏的结构和验证契约。视觉 token、组件层级与交互目标以根目录 `DESIGN.md` 为准；本文件解决“代码怎样写才不会被通知、长文案、第三方 Hook 或窄屏挤破”。

## 1. 原生优先与所有权

- 控件优先使用 WordPress/WooCommerce 的 `button`、`notice`、`form-table`、`nav-tab-wrapper`、`wp-list-table`、`details/summary` 和设置 API。
- Wei CSS 只控制自己的容器、间距、密度和 `--wei-*` token，不全局覆盖 `.notice`、`.button`、`.form-table`、`.nav-tab`、`.wrap` 或 `#wpcontent` 的组件外观。
- WooCommerce 设置页由 WooCommerce 拥有；Wei Alipay 只能在精确 gateway section 内增加自己的结构和资源。
- 第三方通知、按钮、表格和 React 根节点不得被移动、复制、包裹或重新挂载。

## 2. 页面骨架

独立 Wei Alipay 页面固定顺序：

```text
page identity header
wp-header-end boundary
URL-addressable tabs
page-scoped notice region
page content
page actions
```

- 每页一个 `h1`；页眉只放产品 Logo、名称、连接状态、连接后头像和一句说明。
- 页眉不放与卡片、标签或表单重复的导航动作。动作必须靠近它解决的问题。
- 页眉、标签、通知和主内容使用同一个 shell 宽度与左边界。
- PHP Hook 不得在页眉内部或标签与通知区域之间输出未声明结构。

## 3. 通知归属

| 通知类型 | 所有者 | 唯一位置 |
| --- | --- | --- |
| WordPress/WooCommerce 系统通知 | WordPress/WooCommerce | 原生全局 notice 区 |
| Wei 页面保存、同步、连接反馈 | 当前 Wei 页面 | 标签之后的 `.wei-alipay-notice-region` |
| 网关密钥、币种、依赖和设置校验 | 当前 gateway | 标题/分区导航之后、字段之前的 `.wei-alipay-settings-notices` |
| 字段错误 | 对应输入 | 输入附近，通过 `aria-describedby` 关联 |

- 页面级或网关级问题禁止注册全局 `admin_notices`。
- 同一条件只能出现一次；不能同时显示在系统区、插件页和字段旁。
- 保留原生 `notice notice-* inline`、dismiss、`role="status"`/`role="alert"` 语义，Wei CSS 只调整容器间距。
- 禁用或未注册的网关不得在其他后台页面输出要求或销售提醒。

## 4. 容器与防溢出

- 所有 flex/grid 子项在可能承载动态内容时设置可收缩边界，例如 `min-width: 0`。
- URL、代码、哈希和错误文本使用局部 `overflow-wrap: anywhere`、代码容器滚动或截断；不得让 `html/body/#wpcontent` 横向滚动。
- 表格过宽时只允许 `.wei-alipay-table-wrap` 局部滚动。
- 图片声明宽高和最大宽度；头像与产品 Logo 使用不同 class、尺寸与语义。
- 不用固定高度容纳翻译、通知或验证错误；使用内容驱动高度和有界 padding。
- 不通过超大左右 `justify-content: space-between` 把相关元素推到页面两端。

## 5. 响应式与本地化

每个改动页面至少验证 1280、960、782、600、400px。断言包括：

- 页面横向溢出为 0；
- 一个 `h1`、一个当前标签、至多一个页面通知区；
- 页眉、标签、通知和内容左边界一致；
- 中文和英文长文案不遮挡按钮、状态或输入；
- 400px 下次要动作换行但不变成不可读的满屏横幅；
- 键盘焦点可见，缩放 200% 后主要任务仍可完成。

源 msgid 使用英文，变更界面同步 zh_CN。协议名、产品名、错误码和代码标识不做品牌式翻译。

## 6. 必测状态

页面级视觉样本必须覆盖与本次改动相关的组合：

1. 无通知；
2. 一个系统通知；
3. 多个系统通知；
4. 页面 success/warning/error；
5. 系统通知与页面通知同时存在；
6. 网关信息、警告和错误并列；
7. 超长中英文、URL、代码和不可解密错误；
8. 通知关闭前后；
9. 未连接、已连接、授权临期和远端不可用；
10. 空、加载、成功、失败、部分降级和重试。

零运行样本不能写视觉 PASS。第三方控制台错误必须按来源分类，不能归到 Wei，也不能用它掩盖 Wei 自身错误。

## 7. 外联与账户连接界面

- “连接账户”必须先展示目标域名、发送字段、用途和隐私说明，再离开 WordPress。
- 回调页只能显示一次性授权结果，不渲染 access token、refresh token、authorization code、完整站点凭据或账户 PII。
- 连接、entitlement、支付宝准入、依赖和本地配置是独立状态，不能合成一个模糊的“已启用”。
- 远端不可用时保留本地支付恢复、回调、退款和解约路径；后台只显示有界上下文提示，不发全局广告 notice。

## 8. 自动门禁

提交前至少执行：

```bash
./scripts/tests/run-tests.sh all
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=4G --debug
git diff --check
```

UI 合约还必须检查：

- 页面/网关代码没有新增局部问题的 `admin_notices` Hook；
- DOM 顺序、区域数量、ARIA 语义和动作不重复；
- CSS 没有新增未限定范围的 WordPress/WooCommerce 核心选择器；
- Playwright 对要求的视口和状态组合记录 URL、版本、样本数、控制台和溢出断言；
- 发布包加载的 CSS/JS 与源码验收版本一致。

静态 token 检查、HTTP 200、单张截图或候选 ZIP 都不能替代页面级运行验收。
