# [CX] Wei Alipay candidate change template

## Requested writes

Each write requires a Board record bound to the exact reviewed SHA.

1. Push the exact local HEAD from an isolated `wei-alipay` worktree to a new FeiCode review branch in the canonical repository `Weixiaoduo/wei-alipay`.
2. Run candidate CI for that exact SHA and review the resulting pull request.
3. Only after an approved merge to FeiCode master, manually run `sync-github-downstream` to GitHub `Weixiaoduo/wei-alipay` with the approved master SHA and Board change ID.
4. Keep repository rename, branch push, CI approval, merge, tag, release, public mirror synchronization, and mall deployment as separate writes. Approval of one does not authorize another.

The former repository name `Weixiaoduo/woo-alipay` is historical evidence only. Do not recreate it as a second repository or use it as a current release target.

## Prohibited operations

No force push, full-ref mirror, unreviewed default-branch merge, release/tag creation, production deployment, production configuration change, live Alipay request, real payment, real refund, or deletion of historical data. A changed HEAD invalidates the approval.

## Required Board evidence

- Full 40-character local candidate SHA.
- FeiCode base SHA.
- Candidate ZIP SHA-256 and manifest.
- Commands, sample counts, and exit codes for contracts, PHPStan, reproducible build, and isolated WordPress runtime.
- Confirmation that GitHub master is an ancestor of the approved FeiCode master.
- Rollback owner and procedure for each repository rename and the mall plugin-path cutover.
