#!/usr/bin/env bash
# deploy_to_dir.sh — deploy the repo's code into a deploy directory, preserving
# that directory's LIVE data.
#
# Model (works with OR without a GitHub remote — the local repo is source of
# truth; GitHub is just an optional mirror):
#   1. `git archive` the latest code from the repo's HEAD into the deploy dir
#      (CODE ONLY — the data files are excluded).
#   2. Overlay the LIVE data from the data source onto the deploy dir.
#
# Because only CODE is ever pulled from the repo and live data is written
# separately on top, a demo-data change in the repo can NEVER reach the live
# data on disk. The deploy dir needs no .git of its own, so there is nothing to
# diverge — every deploy is just "archive code + overlay data".
#
# Usage:  scripts/deploy_to_dir.sh <deploy_dir> [data_source_dir]
#   data_source_dir: where to take live data from (default: the data already on
#   disk in the deploy dir is kept).
set -euo pipefail
DEPLOY="${1:?usage: deploy_to_dir.sh <deploy_dir> [data_source_dir]}"
DATA_SRC="${2:-$DEPLOY}"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Code to pull from the repo (everything EXCEPT the live data).
CODE_FILES="index.html admin.html api.php css/ docs/ .github/ scripts/ \
            README.md REVISIONS.md VERSION .gitignore boards.json"

mkdir -p "$DEPLOY"
cd "$DEPLOY"

# 1) Archive the latest code from the repo (fast, no git state in the deploy dir).
echo ">> archiving latest code from $REPO ..."
git -C "$REPO" archive --format=tar HEAD -- $CODE_FILES \
  | tar -x -C "$DEPLOY" 2>/dev/null || true
echo "   code @ $(git -C "$REPO" log --oneline -1)"

# 2) Overlay the LIVE data (this is what actually changes between deploys).
echo ">> overlaying live data from $DATA_SRC ..."
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

echo ">> deploy complete: $DEPLOY (code @ $(git -C "$REPO" log --oneline -1) + live data)"
