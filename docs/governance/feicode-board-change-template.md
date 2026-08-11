# [CX] Woo Alipay PR #1 candidate change

## Allowed write

- Repository: `Weixiaoduo/woo-alipay` on FeiCode.
- Source: WenPai isolated branch `codex/woo-alipay-audit-20260809`.
- Operation: one ordinary push of the exact reviewed local HEAD to the existing PR #1 review ref.
- CI: approval must be bound to that exact commit SHA; no floating branch approval.

## Prohibited operations

No default-branch merge, release/tag creation, deployment, production configuration change, live Alipay request, real payment, real refund, or deletion of historical data. A changed HEAD invalidates the approval and requires a new Board record.

## Required Board fields

Paste the generated local artifact `build/board-change-<HEAD>.md`, including the full 40-character SHA, candidate ZIP SHA-256, validation commands and exit codes. The operator must compare `git rev-parse HEAD` immediately before the single push.
