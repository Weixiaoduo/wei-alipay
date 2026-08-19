# GitHub downstream mirror policy

## Authority

- Canonical source: ssh://git@feicode.com:2222/Weixiaoduo/wei-alipay.git
- Public downstream: git@github.com:Weixiaoduo/wei-alipay.git
- FeiCode owns development branches, pull requests, candidate CI, tags, releases, and release artifacts.
- GitHub is a public read-only-by-policy copy. It is not an upstream and must never be pulled into FeiCode automatically.

## Allowed synchronization

Synchronization is manual and fail-closed through .forgejo/workflows/sync-github-downstream.yml.

The operator must provide:

1. the full FeiCode master SHA already approved by devops Board;
2. the Board change ID bound to that SHA;
3. an optional approved release tag resolving to the same SHA.

scripts/sync-github-downstream.sh verifies that the approved SHA is the current FeiCode master, verifies that GitHub master is its ancestor, and performs a normal fast-forward push. It does not use --force or --mirror, delete refs, or publish candidate branches.

## Credentials

Use a dedicated GitHub deploy key with write access only to Weixiaoduo/wei-alipay. Store the private key as the protected Forgejo Action secret GITHUB_MIRROR_SSH_KEY. Store pinned GitHub SSH host keys as GITHUB_KNOWN_HOSTS. Never place either value in the repository, build output, logs, or Board description.

## Divergence and rollback

If GitHub is not an ancestor of the approved FeiCode SHA, synchronization stops. Resolve the divergence through a reviewed FeiCode change; never force-push around the guard.

A GitHub synchronization does not authorize a FeiCode merge, release, WordPress deployment, or payment operation. Repository renames and the first mirror run remain manual Board-governed actions.
