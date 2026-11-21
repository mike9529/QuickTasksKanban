#!/usr/bin/env bash
# deploy_to_dir.sh — deploy the repo's code into a deploy directory, preserving
# that directory's LIVE data.
#
# Model (works with OR without a GitHub remote — the local repo is source of
# truth; GitHub is just an optional mirror):
#   1. `git pull --ff-only` in the deploy dir to get the latest code.
#   2. The repo's committed boards/*.json / boards.json / tasks.json are DEMO
#      data. They are NOT deployed: they're removed and replaced with the live
#      data that already exists in the deploy dir (or a data source), which is
#      gitignored so the next `git pull` can't clobber it.
#
# Usage:  scripts/deploy_to_dir.sh <deploy_dir> [data_source_dir]
#   data_source_dir: where to take live data from (default: <deploy_dir> itself,
#   i.e. the data already on disk in the deploy dir is kept).
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEPLOY="${1:?usage: deploy_to_dir.sh <deploy_dir> [data_source_dir]}"
DATA_SRC="${2:-$DEPLOY}"

cd "$DEPLOY"

# 1) Pull latest code (fast-forward only; data files are gitignored so untouched).
echo ">> pulling latest code into $DEPLOY ..."
git pull --ff-only

# 2) Replace the repo's demo data with the live data (from DATA_SRC).
#    These paths are gitignored in the deploy dir, so a future `git pull` will
#    never overwrite them.
for f in boards/children.json boards/adults.json boards.json tasks.json; do
  if [ -f "$DATA_SRC/$f" ]; then
    mkdir -p "$(dirname "$f")"
    cp -p "$DATA_SRC/$f" "$f"
    echo "   live data  <-  $f"
  fi
done

# 3) Confirm the live data is gitignored (so it survives the next pull).
echo ">> verifying live data is git-ignored (protected from future pulls) ..."
for f in boards/children.json boards/adults.json; do
  if git check-ignore -q "$f"; then echo "   protected: $f"; else echo "   WARN not ignored: $f"; fi
done

echo ">> deploy complete: $DEPLOY @ $(git log --oneline -1)"
