#!/usr/bin/env bash
#
# Grafida — edit Joomla! articles on your desktop.
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later.
#
# Wraps a Grafida.app bundle (produced by scripts/make-macos-app.sh) into a
# compressed, distributable .dmg using hdiutil (built into macOS). The disk
# image shows the app next to an /Applications symlink so users can install by
# drag-and-drop.
#
# Usage:  scripts/make-dmg.sh [arch]      (arch defaults to the host: arm64|amd64)
#
# Output: build/dist/Grafida-<version>-macos-<arch>.dmg

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ARCH="${1:-$([ "$(uname -m)" = "arm64" ] && echo arm64 || echo amd64)}"

# Boson names the macOS arm64 output directory "aarch64"; amd64 stays "amd64".
case "$ARCH" in
  arm64|aarch64) ARCH=arm64; SRC_DIR="$ROOT/build/macos/aarch64" ;;
  amd64|x86_64)  ARCH=amd64; SRC_DIR="$ROOT/build/macos/amd64" ;;
  *) echo "Unknown arch: $ARCH" >&2; exit 1 ;;
esac

APP="$SRC_DIR/Grafida.app"
if [ ! -d "$APP" ]; then
  echo "App bundle not found at $APP — run scripts/make-macos-app.sh $ARCH first." >&2
  exit 1
fi

VERSION="${GRAFIDA_VERSION:-$(sed -nE "s/.*VERSION = '([^']+)'.*/\1/p" "$ROOT/src/Support/App.php" | head -1)}"
DIST="$ROOT/build/dist"
DMG="$DIST/Grafida-${VERSION}-macos-${ARCH}.dmg"
STAGING="$SRC_DIR/.dmg-staging"
TEMP_DMG="$SRC_DIR/.dmg-temp.dmg"
VOLNAME="Grafida"

mkdir -p "$DIST"

echo "Building DMG (arch: $ARCH, version: $VERSION)"
echo "  Source: $APP"
echo "  Output: $DMG"

# Stage the .app beside an /Applications symlink for drag-to-install.
rm -rf "$STAGING"
mkdir -p "$STAGING"
cp -R "$APP" "$STAGING/"
ln -s /Applications "$STAGING/Applications"

# Size the writable image from the staged contents, plus headroom.
STAGING_KB=$(du -sk "$STAGING" | awk '{print $1}')
DMG_MB=$(( (STAGING_KB / 1024) + 20 ))

rm -f "$TEMP_DMG" "$DMG"
hdiutil create -volname "$VOLNAME" -srcfolder "$STAGING" -ov \
  -format UDRW -size "${DMG_MB}m" "$TEMP_DMG"
hdiutil convert "$TEMP_DMG" -format UDZO -imagekey zlib-level=9 -o "$DMG"

rm -f "$TEMP_DMG"
rm -rf "$STAGING"

echo "Done: $DMG"
