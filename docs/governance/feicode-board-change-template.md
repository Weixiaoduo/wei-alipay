# [CX] Wei Alipay 4.0.0 rename candidate change

## Requested writes

Each write requires a Board record bound to the exact reviewed SHA.

1. Push the exact local HEAD from cx/wei-alipay-4.0.0 to a new FeiCode review branch in the existing canonical repository Weixiaoduo/woo-alipay.
2. Run candidate CI for that exact SHA and review the resulting pull request.
3. After candidate acceptance, rename the FeiCode repository to Weixiaoduo/wei-alipay.
4. Rename the GitHub repository to Weixiaoduo/wei-alipay and configure a repository-scoped deploy key.
5. Only after an approved merge to FeiCode master, manually run sync-github-downstream with the approved master SHA and Board change ID.

Repository rename, branch push, CI approval, merge, tag, release, public mirror synchronization, and mall deployment are separate writes. Approval of one does not authorize another.

## Prohibited operations

No force push, full-ref mirror, unreviewed default-branch merge, release/tag creation, production deployment, production configuration change, live Alipay request, real payment, real refund, or deletion of historical data. A changed HEAD invalidates the approval.

## Required Board evidence

- Full 40-character local candidate SHA.
- FeiCode base SHA.
- Candidate ZIP SHA-256 and manifest.
- Commands, sample counts, and exit codes for contracts, PHPStan, reproducible build, and isolated WordPress runtime.
- Confirmation that GitHub master is an ancestor of the approved FeiCode master.
- Rollback owner and procedure for each repository rename and the mall plugin-path cutover.
