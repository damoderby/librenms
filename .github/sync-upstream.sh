#!/usr/bin/env bash
# Keep a fork current with upstream without overwriting the fork's own work.
#
#   1. Fast-forward the mirror branch to upstream. Never forced: if the mirror has
#      commits upstream does not have, stop.
#   2. Merge the mirror into the deploy branch. If the merge conflicts, abort it and
#      stop, leaving the deploy branch exactly as it was.
#
# Run from a full clone of the fork with the deploy branch checked out.
set -euo pipefail

UPSTREAM_URL="${UPSTREAM_URL:-https://github.com/librenms/librenms.git}"
UPSTREAM_BRANCH="${UPSTREAM_BRANCH:-master}"
MIRROR_BRANCH="${MIRROR_BRANCH:-master}"
DEPLOY_BRANCH="${DEPLOY_BRANCH:-deploy}"
SUMMARY="${GITHUB_STEP_SUMMARY:-/dev/null}"

say() {
    echo "$*"
    echo "$*" >> "$SUMMARY"
}

fail() {
    say "**Sync stopped:** $*"
    exit 1
}

if [[ "$(git rev-parse --abbrev-ref HEAD)" != "$DEPLOY_BRANCH" ]]; then
    fail "expected $DEPLOY_BRANCH to be checked out"
fi

git remote remove upstream 2> /dev/null || true
git remote add upstream "$UPSTREAM_URL"
git fetch --quiet --no-tags upstream "+refs/heads/$UPSTREAM_BRANCH:refs/remotes/upstream/$UPSTREAM_BRANCH"
git fetch --quiet --no-tags origin "+refs/heads/$MIRROR_BRANCH:refs/remotes/origin/$MIRROR_BRANCH"

upstream_ref="refs/remotes/upstream/$UPSTREAM_BRANCH"
mirror_ref="refs/remotes/origin/$MIRROR_BRANCH"

# 1. mirror branch
if [[ "$(git rev-parse "$mirror_ref")" == "$(git rev-parse "$upstream_ref")" ]]; then
    say "$MIRROR_BRANCH is already at upstream $(git rev-parse --short "$upstream_ref")."
elif git merge-base --is-ancestor "$mirror_ref" "$upstream_ref"; then
    git push --quiet origin "$upstream_ref:refs/heads/$MIRROR_BRANCH" \
        || fail "could not push $MIRROR_BRANCH. If the message above mentions workflow permission, upstream changed a workflow file and the SYNC_TOKEN secret is missing or lacks the workflow permission."
    say "$MIRROR_BRANCH fast-forwarded to upstream $(git rev-parse --short "$upstream_ref")."
else
    fail "$MIRROR_BRANCH has commits that are not in upstream. It must only ever mirror upstream; move those commits to a feature branch."
fi

# 2. deploy branch
if git merge-base --is-ancestor "$upstream_ref" HEAD; then
    say "$DEPLOY_BRANCH already contains upstream $(git rev-parse --short "$upstream_ref")."
    exit 0
fi

if ! git merge --quiet --no-ff --no-edit -m "Merge upstream $UPSTREAM_BRANCH into $DEPLOY_BRANCH" "$upstream_ref"; then
    conflicts="$(git diff --name-only --diff-filter=U | sed 's/^/- /')"
    git merge --abort
    say "Upstream changed the same lines as our work in:"
    say "$conflicts"
    fail "merge conflict. $DEPLOY_BRANCH was left unchanged and needs the merge done by hand."
fi

git push --quiet origin "HEAD:refs/heads/$DEPLOY_BRANCH" \
    || fail "could not push $DEPLOY_BRANCH. If the message above mentions workflow permission, upstream changed a workflow file and the SYNC_TOKEN secret is missing or lacks the workflow permission."
say "$DEPLOY_BRANCH now contains upstream $(git rev-parse --short "$upstream_ref")."
