#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Builds the release archive for a tagged version: mainwp-for-whmcs-VERSION.zip with
# modules/addons/mainwp/ and modules/servers/mainwp/ plus LICENSE, README.md, CHANGELOG.md and docs/reference.md, and a .sha256 beside it.
# Usage: tools/release.sh VERSION [OUTDIR]      (OUTDIR defaults to dist/ in the repository; a relative one is taken from where the script is run)
# Refuses when tag vVERSION is missing, the working tree has uncommitted changes, Module::VERSION
# at the tag differs from VERSION, CHANGELOG.md at the tag has no dated heading for VERSION, or a
# PHP file of the archive does not parse. The archive is built from the tag, not the tree, and is
# the same on every machine: file modes 644 whatever the builder's umask, and file times set from
# the tag's commit, twelve hours back, so that they are in the past in every time zone.
set -euo pipefail
version=${1:?usage: tools/release.sh VERSION [OUTDIR]}
tag="v$version"
root=$(git rev-parse --show-toplevel)
outdir=${2:-$root/dist}
mkdir -p "$outdir"; outdir=$(cd "$outdir" && pwd)
cd "$root"
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "release.sh: VERSION must look like 1.2.3 (got \"$version\")" >&2; exit 2; }
git rev-parse -q --verify "refs/tags/$tag" >/dev/null || { echo "release.sh: tag $tag does not exist" >&2; exit 2; }
if ! git diff --quiet || ! git diff --cached --quiet; then echo "release.sh: working tree has uncommitted changes; commit or stash them first" >&2; exit 2; fi
module_version=$(git show "$tag:modules/addons/mainwp/lib/Module.php" | sed -n "s/.*public const VERSION = '\([^']*\)'.*/\1/p")
[[ "$module_version" == "$version" ]] || { echo "release.sh: Module::VERSION at $tag is \"$module_version\", not \"$version\"" >&2; exit 2; }
git show "$tag:CHANGELOG.md" | grep -Eq "^## ${version//./\\.} \\([0-9]{1,2} [A-Z][a-z]+ [0-9]{4}\\)" || { echo "release.sh: CHANGELOG.md at $tag has no dated heading \"## $version (day month year)\"" >&2; exit 2; }
name="mainwp-for-whmcs-$version"
work=$(mktemp -d); trap 'rm -rf "$work"' EXIT
git archive --format=tar --prefix="$name/" "$tag" modules/addons/mainwp modules/servers/mainwp LICENSE README.md CHANGELOG.md docs/reference.md | tar -xf - -C "$work"
chmod -R u=rwX,go=rX "$work/$name"
if command -v php >/dev/null; then
  while IFS= read -r file; do
    php -l "$file" >/dev/null 2>&1 || { echo "release.sh: ${file#"$work/"} does not parse" >&2; php -l "$file" >&2 || true; exit 1; }
  done < <(find "$work/$name" -type f -name '*.php' | LC_ALL=C sort)
fi
find "$work/$name" -exec touch -d "@$(( $(git log -1 --format=%ct "$tag") - 43200 ))" {} +
zip="$outdir/$name.zip"
rm -f "$zip" "$zip.sha256"
(cd "$work" && find "$name" -type f | LC_ALL=C sort | TZ=UTC zip -X -q -D "$zip" -@)
# The archive must hold exactly the module files tracked at the tag plus the four documents.
expected=$( { git ls-tree -r --name-only "$tag" -- modules/addons/mainwp modules/servers/mainwp; printf 'LICENSE\nREADME.md\nCHANGELOG.md\ndocs/reference.md\n'; } | sed "s#^#$name/#" | LC_ALL=C sort)
actual=$(unzip -Z1 "$zip" | LC_ALL=C sort)
if [[ "$expected" != "$actual" ]]; then
  echo "release.sh: archive contents differ from the files tracked at $tag:" >&2
  diff <(echo "$expected") <(echo "$actual") >&2 || true
  exit 1
fi
(cd "$outdir" && sha256sum "$name.zip" > "$name.zip.sha256")
echo "$zip ($(unzip -Z1 "$zip" | wc -l) files)"; cat "$zip.sha256"
