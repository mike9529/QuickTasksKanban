#!/usr/bin/env bash
# deploy_to_dir.sh — deploy the repo's code into a deploy directory, preserving
# that directory's LIVE data.
#
# Model (works with OR without a GitHub remote — the local repo is source of
# truth; GitHub is just an optional mirror):
#   1. `git pull --ff-only` to get the latest code.
#   2. The repo's committed boards/*.json / boards.json / tasks.json are DEMO
#      data. They are UNTRACKED in the deploy dir (git rm --cached) and replaced
#      with the live data, so a future `git pull` can never clobber them.
#
# Usage:  scripts/deploy_to_dir.sh <deploy_dir> [data_source_dir]
#   data_source_dir: where to take live data from (default: the data already on
#   disk in the deploy dir is kept).
set -euo pipefail
DEPLOY="${1:?usage: deploy_to_dir.sh <deploy_dir> [data_source_dir]}"
DATA_SRC="${2:-$DEPLOY}"

cd "$DEPLOY"

# 1) Pull latest code (fast-forward only).
echo ">> pulling latest code into $DEPLOY ..."
git pull --ff-only

# 2) Stop tracking the repo's DEMO data so the gitignore can protect the live
#    data that replaces it.
for f in boards/children.json boards/adults.json boards.json tasks.json; do
  if git ls-files --error-unmatch "$f" >/dev/null 2>&1; then
    git rm -q --cached -- "$f" 2>/dev/null || true
    echo "   untracked demo data: $f"
  fi
done

# 3) Replace demo data with the live data (from DATA_SRC).
for f in boards/children.json boards/adults.json boards.json tasks.json; do
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

# 4) Verify the live data is git-ignored (protected from the next pull).
echo ">> verifying live data is git-ignored ..."
for f in boards/children.json boards/adults.json; do
  if [ -f "$f" ] && git check-ignore -q "$f"; then echo "   protected: $f"
  else echo "   WARN not ignored: $f"; fi
done

echo ">> deploy complete: $DEPLOY @ $(git log --oneline -1)"
