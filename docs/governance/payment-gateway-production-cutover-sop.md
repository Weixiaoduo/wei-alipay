# SOP：支付网关插件生产切换与 SDK/密钥变更

版本：1.0
建立原因：2026-08-20/21 Wei Alipay 生产切换与订单状态事故
适用范围：Wei Alipay、Wei WeChat Pay 及任何会创建、查询、关闭、退款、签约或处理支付回调的 WordPress/WooCommerce 插件。

## 0. 何时强制使用

命中任一项就必须使用本 SOP，普通 WordPress 插件升级流程不够：

- 激活的支付插件 basename、入口文件或插件 slug 变化；
- SDK 版本、SDK tree、签名算法、证书模式或 HTTP 客户端变化；
- App ID、商户 ID、私钥、公钥、证书、密钥 envelope/KDF/keyring 变化；
- gateway ID、option、订单 meta、callback route、cron/action scheduler hook 变化；
- 支付表单、跳转、Blocks、HPOS、退款、关单、签约或通知状态机变化；
- 把多个旧支付插件聚合为一个新插件，或反向拆分；
- 回退支付插件。

## 1. 不可跳过的规则

1. **支付可用证据必须走到服务商受理和最终订单状态**。PHP lint、HTTP 200、插件 active、option 存在、密钥可解密都不是支付 PASS。
2. **零样本不能写 PASS**。沙箱支付、回调、查询、退款、并发、乱序、回退各自记录样本数。
3. **私钥永不进入工单、日志、截图、Git、聊天或普通证据包**。只记录格式类别、长度区间、`kid` 和由私钥导出的公钥 SHA-256 指纹。
4. **密钥恢复与密钥轮换是两件事**。恢复原私钥不改支付宝端公钥；轮换必须同步更新插件私钥与支付宝应用公钥。
5. **SDK 更新、密钥轮换、插件改名/聚合默认拆成不同变更窗口**。确需合并，风险提高一级并要求额外评审。
6. **失败关闭**：无法解密、无法解析、无法签名、指纹不匹配、缺少 `sign`、回调验签失败时不得发起支付或改变订单状态。
7. **不自动纠正资金状态**：事故中不得自动把订单改成已付款、不得自动退款；先查服务商账单、交易号、金额和收款账户。
8. **切换和回退都需要相同等级的支付验收**。旧版本不是天然安全。
9. **生产资金请求必须另有明确审批和人在环**。没有审批时，生产 canary 为 BLOCKED，不得用合成订单替代。
10. **一个窗口只允许一个支付变更 owner**，一个独立 verifier；执行人与验收人不得只看同一份脚本输出。

## 2. 角色

| 角色 | 职责 |
| --- | --- |
| Change owner | 候选 SHA、差分、测试、回退包和操作清单 |
| Production operator | 按 Board 精确审批执行，不临场扩展范围 |
| Payment verifier | 独立核对密钥指纹、请求字段、回调和订单状态 |
| Finance/operator | 生产 canary 收款与账单对账；决定人工退款/保留 |
| Incident commander | 出现资金/订单不一致时冻结变更、止损和记录时间线 |

## 3. Phase A：变更分解

先生成变更清单，逐项写 old/new：

```text
plugin basename / entry file / exact SHA
SDK source commit / tree hash / local patch hash
sign mode / sign_type / key format category
App ID / seller ID presence and masked fingerprint
option keys / gateway IDs / callback routes / cron hooks
HPOS / Blocks / classic checkout
payment / query / close / refund / agreement / notify
```

阻断条件：

- 说不清 SDK 是否变化；
- 无法确定生产 active plugin 精确文件 SHA；
- 同一窗口存在未记录的插件副本或重复 basename；
- 回退包与设置备份未生成；
- 候选包含未评审的真实生产密钥。

## 4. Phase B：SDK 与供应链

1. 固定 SDK 官方仓库、commit、tree hash、许可证和本地补丁。
2. 对 old/new SDK 做文件和 API 差分，不以版本字符串代替代码差分。
3. 生成使用类 allowlist 和传递依赖。
4. SDK 更新必须独立运行：
   - 临时 RSA2 密钥 sign→verify；
   - 页面/手机/查询/关闭/退款请求生成；
   - 超时、TLS、异常和空响应；
   - PHP 最低/最高支持版本；
   - 发布包 SBOM、manifest 和可复现 ZIP。
5. SDK tree 未变化时要明确写“SDK 未变”；不能把产品升级笼统写成 SDK 升级。

## 5. Phase C：密钥预检

仅在受限运行环境执行，不回显私钥。

### C1. 格式

记录类别而不是内容：

- raw PKCS#1 body；
- `BEGIN RSA PRIVATE KEY` PKCS#1 PEM；
- `BEGIN PRIVATE KEY` PKCS#8 PEM；
- 证书模式文件；
- 外部 secret provider。

候选代码必须明确接受哪些格式，拒绝哪些格式，并给出可操作错误；不得 `die()` 输出模糊页面。

### C2. 密钥对

1. 从候选实际读取路径取得解密后的私钥；
2. 本地导出应用公钥；
3. 计算应用公钥 SHA-256 指纹；
4. 与迁移前指纹和支付宝控制台登记指纹人工双人核对；
5. 使用候选 SDK 对固定测试串做 RSA2 签名，再用导出的应用公钥验签；
6. 立即清理临时明文和 shell history 风险。

结果只能是：

- `same-keypair`；
- `planned-rotation`；
- `mismatch`；
- `unreadable`。

`mismatch/unreadable` 必须阻断启用。

### C3. 存储迁移

- 迁移前备份原 option 和 keyring 配置，权限 0600/目录 0700；
- 迁移先验证再写；
- 保留一代可回滚 envelope；
- 切换后确认候选运行时解密，但后台表单不回显；
- 回退时保证旧插件能读取其所需形态，不能只恢复插件文件不恢复设置。

## 6. Phase D：无网络出站合同

候选代码生成完整支付表单/请求，但不发送网络：

必填并非空：

```text
app_id
method
format
charset
sign_type=RSA2
timestamp
version
biz_content
notify_url
return_url
sign
```

验证：

- `sign` base64 可解码；
- 用配对应用公钥验签通过；
- 表单净化前后字段集合一致；
- `out_trade_no`、金额、币种、subject 与订单一致；
- 页面输出不含私钥、公钥正文、证书或调试栈；
- 缺签名时插件本地失败，不跳转支付宝。

## 7. Phase E：隔离 WordPress 验收

环境必须记录 WordPress、WooCommerce、PHP、OpenSSL、插件 exact SHA。

| 场景 | 最少样本 |
| --- | ---: |
| Classic checkout 桌面/移动 | 各 1 |
| Checkout Blocks | 1 |
| HPOS on/off | 各 1 |
| 支付表单非空签名 | 每路径 1 |
| 正常异步通知 | 1 |
| 重复通知 | 2 次同通知 |
| 并发通知 | 至少 2 worker |
| 乱序 success/close | 每种顺序 1 |
| 错误 app_id/seller_id/金额/订单/交易号 | 各 1 |
| 空 GET/缺签名/错误签名 | 各 1，订单变化必须为 0 |
| 查询/关闭/退款 | 启用的能力各 1 |
| 插件回退 | 1 次完整回退 |

使用生产形状克隆时，凭据必须替换为测试密钥；真实订单和客户数据脱敏。

## 8. Phase F：支付宝沙箱

使用独立沙箱应用和密钥，不使用生产凭据：

1. 支付宝接受请求，不出现 `missing-signature`/`invalid-signature`；
2. 完成支付并收到异步通知；
3. 订单只完成一次；
4. 主动查询与通知结果一致；
5. 退款/关闭仅在本次变更涉及时执行；
6. 保存脱敏字段名、错误码、时间和状态，不保存签名/密钥/客户 PII。

沙箱未执行时，本行是 BLOCKED，不能用 HTTP 200 或离线签名改写为 PASS。

## 9. Phase G：生产变更审批包

Board change 必须绑定：

- 候选 exact SHA、ZIP SHA-256、manifest/SBOM；
- active plugin old/new 集合；
- 设置/密钥迁移摘要和公钥指纹；
- A–F 阶段结果与样本数；
- 备份路径、回退包 SHA、回退命令；
- 生产 canary 方案、金额、执行人、财务确认人；
- 观察窗口和自动/人工停止条件。

审批只允许精确操作，不自动包含 merge、Release、真实退款或再次部署。

## 10. Phase H：生产切换

### H1. 切换前

- 冻结支付相关插件、设置、缓存和计划任务变更；
- 导出 `active_plugins`、gateway 状态和回调路由；
- 备份插件目录、options、keyring/wp-config 相关非公开配置；
- 确认没有重复 basename、旧聚合插件和独立模块同时 active；
- 确认回退包可读、owner/mode 正确；
- 打开有界支付日志，确认会脱敏。

### H2. 切换

1. 只执行审批脚本；
2. 每一步立即核对退出码；
3. 第一次出现函数重声明、权限错误、密钥不可读或签名失败就停止，不在同一窗口继续修补后强行上线；
4. 完成后记录 active plugin basename、版本、主文件 SHA 和时间。

### H3. 切换后无资金探针

- 首页、购物车、结账、order-pay HTTP 200；
- gateway 可用性与币种正确；
- 候选使用实际迁移密钥完成离线 sign→verify；
- 生成最终支付表单并确认非空 `sign`，但不提交到支付宝；
- 回调空 GET/伪造 POST 不改变测试订单。

任一失败立即回退。

## 11. Phase I：生产最小 canary

只有 Board 明确授权真实支付后执行：

1. 人工创建可识别的最小金额订单；
2. 付款前记录订单状态、金额、币种、merchant order number；
3. 在真实浏览器完成一次支付；
4. 核对支付宝受理、交易号、异步通知、WooCommerce 最终状态；
5. 重放一次已收到通知，订单不得重复完成；
6. 财务在支付宝账单确认实际到账；
7. 是否退款由财务人工决定，退款需要独立授权。

没有 canary，不得宣布切换完成。若业务不能容忍 canary，则保持旧插件并安排可验收窗口。

## 12. 观察与停止条件

至少观察一个完整业务高峰或 24 小时，记录：

- 发起支付数、支付宝受理数、成功通知数、最终已付款订单数；
- `missing-signature`、`invalid-signature`、app/seller/amount/order/trade mismatch；
- 重复和乱序通知；
- pending 超时、主动查询、退款；
- 旧/新 callback route 命中。

立即停止并回退：

- 任一 `missing-signature`；
- 私钥不可解密或公钥指纹不匹配；
- 已付款订单被降级；
- 支付宝到账与 WooCommerce 状态不一致；
- 重复扣款、错误退款或跨订单绑定；
- active plugin 集合/主文件 SHA 与审批不一致。

## 13. 回退

1. 冻结新支付发起；回调接收保持可用；
2. 保存时间线和脱敏错误证据；
3. 恢复旧插件文件、active plugin 集合和与之匹配的设置/keyring；
4. 不删除新插件文件、订单 meta 或历史数据；
5. 运行 D、E 的关键子集和一次经批准 canary；
6. 对切换窗口内订单逐笔对账；
7. 不自动改变已付款/失败状态，不自动退款。

## 14. 事故响应

发现支付/订单不一致：

1. 5 分钟内冻结新的支付变更和自动部署；
2. 区分“未签名请求”“签名不匹配”“回调无效”“支付到账但订单错误”；
3. 记录精确时间、订单、交易号、金额、active SHA、请求字段名和错误码；
4. 不记录签名值、私钥、公钥正文、Cookie 或客户资料；
5. 优先恢复安全收款或关闭支付入口，不边排查边继续发布；
6. 逐笔财务对账；
7. 止损后再修代码、补测试、更新本 SOP；
8. 24 小时内完成复盘，期间不混入 UI 或新功能部署。

## 15. 证据模板

```text
Candidate SHA:
ZIP SHA-256:
SDK source/tree/local patches:
Old/new active plugins:
Gateway IDs/options/callbacks/crons:
Key format category:
Application public-key fingerprint before/after/platform:
Offline sign/verify samples:
Generated form samples and required fields:
Classic/Blocks/HPOS samples:
Invalid callback mutation samples:
Sandbox samples:
Production canary approval/sample:
Rollback execution/sample:
Unresolved evidence gaps:
Final status: PASS / FAIL / BLOCKED / SKIP
```

## 16. 参考

- 官方 Alipay PHP SDK v2 配置要求：<https://github.com/alipay/alipay-sdk-php-all/blob/master/v2/README.md>
- WooCommerce HPOS recipe book：<https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/>
- 本次复盘：`docs/audits/wei-alipay-production-cutover-incident-2026-08-21.md`
