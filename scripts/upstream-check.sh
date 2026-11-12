#!/usr/bin/env bash
# What changed upstream (mahirgul/aipbx-plugins) since the base in UPSTREAM.md, and, for every
# plugin we keep a modified copy of, what upstream changed in it that ours does not have yet.
set -euo pipefail
cd "$(dirname "$0")/.."

BASE=$(grep -o '`[0-9a-f]\{40\}`' UPSTREAM.md | head -1 | tr -d '`')
git remote get-url upstream >/dev/null 2>&1 || git remote add upstream https://github.com/mahirgul/aipbx-plugins
git fetch --quiet upstream main
git fetch --quiet upstream "$BASE" 2>/dev/null || true

echo "== Upstream commits since ${BASE:0:7}"
git log --oneline "$BASE..upstream/main" || echo "(base not in the fetched history; fetch deeper)"

echo
echo "== Upstream plugin folders changed"
git diff --stat "$BASE" upstream/main -- plugins/ | tail -n +1

# Modified copies: custom/<slug> is based on community/<slug>.
for ours in plugins/custom/*/; do
    slug=$(basename "$ours")
    if git cat-file -e "upstream/main:plugins/community/$slug/plugin.json" 2>/dev/null; then
        echo
        echo "== $slug: upstream changes since the base (merge these into plugins/custom/$slug)"
        git diff "$BASE" upstream/main -- "plugins/community/$slug" | diffstat 2>/dev/null \
            || git diff --stat "$BASE" upstream/main -- "plugins/community/$slug"
        echo "   full diff: git diff $BASE upstream/main -- plugins/community/$slug"
    fi
done
