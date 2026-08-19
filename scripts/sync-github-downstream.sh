#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APPROVED_SHA="${1:-}"
RELEASE_TAG="${2:-}"
GITHUB_REMOTE_URL="${GITHUB_REMOTE_URL:-git@github.com:Weixiaoduo/wei-alipay.git}"
BOARD_CHANGE_ID="${BOARD_CHANGE_ID:-}"

fail() {
	printf 'BLOCKED: %s\n' "$*" >&2
	exit 1
}

[[ "$APPROVED_SHA" =~ ^[0-9a-f]{40}$ ]] || fail 'approved SHA must be a full 40-character lowercase commit'
[[ -n "$BOARD_CHANGE_ID" ]] || fail 'BOARD_CHANGE_ID is required'
git -C "$ROOT" diff --quiet && git -C "$ROOT" diff --cached --quiet || fail 'working tree must be clean'

git -C "$ROOT" fetch --no-tags origin master
CANONICAL_SHA="$(git -C "$ROOT" rev-parse refs/remotes/origin/master)"
[[ "$CANONICAL_SHA" == "$APPROVED_SHA" ]] || fail "approved SHA is not current FeiCode master ($CANONICAL_SHA)"
git -C "$ROOT" cat-file -e "${APPROVED_SHA}^{commit}" || fail 'approved SHA is not a commit'

REMOTE=github-downstream
git -C "$ROOT" remote remove "$REMOTE" 2>/dev/null || true
git -C "$ROOT" remote add "$REMOTE" "$GITHUB_REMOTE_URL"
trap 'git -C "$ROOT" remote remove "$REMOTE" >/dev/null 2>&1 || true' EXIT

git -C "$ROOT" fetch --no-tags "$REMOTE" master
GITHUB_SHA="$(git -C "$ROOT" rev-parse "refs/remotes/${REMOTE}/master")"
git -C "$ROOT" merge-base --is-ancestor "$GITHUB_SHA" "$APPROVED_SHA" ||
	fail "GitHub master diverged from FeiCode; refusing non-fast-forward update ($GITHUB_SHA)"

REFS=( "${APPROVED_SHA}:refs/heads/master" )
if [[ -n "$RELEASE_TAG" ]]; then
	[[ "$RELEASE_TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail 'release tag must match vMAJOR.MINOR.PATCH'
	git -C "$ROOT" fetch --tags origin "refs/tags/${RELEASE_TAG}:refs/tags/${RELEASE_TAG}"
	TAG_SHA="$(git -C "$ROOT" rev-list -n 1 "$RELEASE_TAG")"
	[[ "$TAG_SHA" == "$APPROVED_SHA" ]] || fail "release tag does not resolve to approved SHA ($TAG_SHA)"
	REFS+=( "refs/tags/${RELEASE_TAG}:refs/tags/${RELEASE_TAG}" )
fi

git -C "$ROOT" push --atomic "$REMOTE" "${REFS[@]}"

printf 'PASS: FeiCode %s mirrored to GitHub master; board_change=%s tag=%s\n' \
	"$APPROVED_SHA" "$BOARD_CHANGE_ID" "${RELEASE_TAG:-none}"
