#!/usr/bin/env bash
# deploy_to_dir.sh — deploy the repo's code into a deploy directory, preserving
# that directory's LIVE data.
#
# Model (works with OR without a GitHub remote — the local repo is source of
# truth; GitHub is just an optional mirror):
#   1. `git pull --ff-only` in the deploy dir to get the latest code. Because the
#      deploy dir only ever TRACKS code (data is gitignored, never committed
#      locally), it stays in line with main, so the pull is always a clean
#      fast-forward.
#   2. Overwrite the repo's DEMO data files with the LIVE data. Data is
#      gitignored, so this is a working-tree-only change that a future
#      `git pull` never touches or collides with.
#
# Usage:  scripts/deploy_to_dir.sh <deploy_dir> [data_source_dir]
#   data_source_dir: where to take live data from (default: the data already on
#   disk in the deploy dir is kept).
#
# One-time setup (per deploy dir):
#   git init && git remote add origin <repo> && git config pull.ff only
#   then `git pull` once to get the code.
set -euo pipefail
DEPLOY="${1:?usage: deploy_to_dir.sh <deploy_dir> [data_source_dir]}"
DATA_SRC="${2:-$DEPLOY}"
DATA_FILES="boards/children.json boards/adults.json boards.json tasks.json"

cd "$DEPLOY"

# 0) One-time setup: a bare `git clone` checks out the repo's DEMO data as
#    tracked. Untrack it so the gitignore protects the live data that replaces
#    it, and so the deploy dir only ever tracks code (keeps future pulls a
#    clean fast-forward). Idempotent: no-op once data is untracked.
if git ls-files | grep -qE '^(boards/.*\.json$|boards\.json$|tasks\.json$)'; then
  git rm -q --cached -- boards/children.json boards/adults.json boards.json tasks.json 2>/dev/null || true
  git -c user.name="jessica" -c user.email="jessica@localhost" \
      commit -q -m "deploy: untrack repo demo data (preserve live data)" 2>/dev/null || true
  echo "   one-time: untracked demo data so live data is protected"
fi

# 1) Pull latest code (fast-forward only; data is gitignored so it's untouched).
echo ">> pulling latest code into $DEPLOY ..."
git pull --ff-only

# 2) Overwrite demo data with the live data (working-tree only; gitignored).
for f in $DATA_FILES; do
  if [ -f "$DATA_SRC/$f" ]; then
    mkdir -p "$(dirname "$f")"
    if [ ! -f "$f" ] || ! cmp -s "$DATA_SRC/$f" "$f"; then
      cp -p "$DATA_SRC/$f" "$f"
      echo "   live data  <-  $f"
    else
      echo "   live data  =  $f (unchanged)"
    fi
  fi
done

# 3) Confirm the live data is git-ignored (protected from the next pull).
echo ">> verifying live data is git-ignored ..."
for f in boards/children.json boards/adults.json; do
  if [ -f "$f" ]; then
    git check-ignore -q "$f" && echo "   protected: $f" || echo "   WARN not ignored: $f"
  fi
done

echo ">> deploy complete: $DEPLOY @ $(git log --oneline -1)"
