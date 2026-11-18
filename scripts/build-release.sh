#!/usr/bin/env bash
# Builds the installable release archive: dist/avunculus-<version>.zip
#
#   scripts/build-release.sh [version]     (default and required match: the VERSION file)
#
# The archive contains everything a web host needs and nothing else: the built frontend,
# the PHP backend, the database schema and the docs. No Node.js is needed on the server.
# The .sha256 file next to it lets the built-in updater verify the download.
# Used by .github/workflows/release.yml, so a local run produces the same archive.
set -euo pipefail

cd "$(dirname "$0")/.."
VERSION="$(tr -d '[:space:]' < VERSION)"
if [ -n "${1:-}" ] && [ "${1#v}" != "$VERSION" ]; then
  echo "Version ${1#v} does not match the VERSION file ($VERSION). Update VERSION first." >&2
  exit 1
fi
NAME="avunculus-$VERSION"
STAGE="dist/$NAME"

echo "Building $NAME"
( cd frontend && npm ci --no-audit --no-fund && npm run build )

rm -rf "$STAGE" "dist/$NAME.zip"
mkdir -p "$STAGE/database"
cp -r public router.php .htaccess README.md LICENSE "$STAGE/"
cp database/schema.sql "$STAGE/database/"
# backend without local credentials
rsync -a --exclude '.env' backend/ "$STAGE/backend/"
cp VERSION "$STAGE/VERSION"

( cd dist && zip -qr "$NAME.zip" "$NAME" && sha256sum "$NAME.zip" > "$NAME.zip.sha256" )
echo "dist/$NAME.zip ($(du -h "dist/$NAME.zip" | cut -f1))"
