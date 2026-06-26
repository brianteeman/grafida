#!/usr/bin/env bash
#
# Grafida — edit Joomla! articles on your desktop.
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later.
#
# Wraps the compiled Boson binary (build/macos/<arch>/) into a macOS .app bundle.
# The Boson compiler emits a bare executable + dylib + assets; this assembles the
# standard Contents/MacOS layout, writes Info.plist, and ad-hoc code-signs it so
# it launches on Apple Silicon.
#
# Usage:  scripts/make-macos-app.sh [arch]      (arch defaults to the host: arm64|amd64)

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ARCH="${1:-$([ "$(uname -m)" = "arm64" ] && echo arm64 || echo amd64)}"

# Boson names the macOS arm64 output directory "aarch64"; amd64 stays "amd64".
case "$ARCH" in
  arm64|aarch64) ARCH=arm64; SRC_DIR="$ROOT/build/macos/aarch64" ;;
  amd64|x86_64)  ARCH=amd64; SRC_DIR="$ROOT/build/macos/amd64" ;;
  *) echo "Unknown arch: $ARCH" >&2; exit 1 ;;
esac

BIN="$SRC_DIR/grafida"
if [ ! -x "$BIN" ]; then
  echo "Compiled binary not found at $BIN — run 'composer compile' (or boson compile -c boson.macos.json) first." >&2
  exit 1
fi

VERSION="${GRAFIDA_VERSION:-$(sed -nE "s/.*VERSION = '([^']+)'.*/\1/p" "$ROOT/src/Support/App.php" | head -1)}"
# The bundle is written beside its source binary (per-arch) so building both the
# arm64 and amd64 .app/.dmg in one run does not clobber a single shared path.
APP="$SRC_DIR/Grafida.app"
MACOS="$APP/Contents/MacOS"

echo "Assembling $APP (arch: $ARCH, version: $VERSION)"
rm -rf "$APP"
mkdir -p "$MACOS" "$APP/Contents/Resources"

cp "$BIN" "$MACOS/grafida"
cp "$SRC_DIR"/*.dylib "$MACOS/" 2>/dev/null || true
[ -d "$SRC_DIR/assets" ] && cp -R "$SRC_DIR/assets" "$MACOS/assets"
chmod +x "$MACOS/grafida"

# Application icon. Generate it from the master SVG if it is missing.
ICNS="$ROOT/build/icon/Grafida.icns"
if [ ! -f "$ICNS" ] && [ -x "$ROOT/scripts/make-icons.sh" ]; then
  "$ROOT/scripts/make-icons.sh" >/dev/null
fi
if [ -f "$ICNS" ]; then
  cp "$ICNS" "$APP/Contents/Resources/Grafida.icns"
else
  echo "Warning: $ICNS not found — bundle will use the generic app icon." >&2
fi

cat > "$APP/Contents/Info.plist" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>CFBundleName</key><string>Grafida</string>
    <key>CFBundleDisplayName</key><string>Grafida</string>
    <key>CFBundleIdentifier</key><string>com.akeeba.grafida</string>
    <key>CFBundleVersion</key><string>${VERSION}</string>
    <key>CFBundleShortVersionString</key><string>${VERSION}</string>
    <key>CFBundleExecutable</key><string>grafida</string>
    <key>CFBundleIconFile</key><string>Grafida</string>
    <key>CFBundlePackageType</key><string>APPL</string>
    <key>CFBundleInfoDictionaryVersion</key><string>6.0</string>
    <key>LSMinimumSystemVersion</key><string>14.0</string>
    <key>NSHighResolutionCapable</key><true/>
    <key>LSApplicationCategoryType</key><string>public.app-category.productivity</string>
    <key>NSHumanReadableCopyright</key><string>Copyright (c) 2026 Nicholas K. Dionysopoulos. GNU GPL v3 or later.</string>
</dict>
</plist>
PLIST

# The Boson SFX executable ships with its own valid ad-hoc signature
# (identifier "micro.sfx"), which is what lets it run on Apple Silicon — do NOT
# re-sign it. We only (best-effort) sign the bundled dylib. A bundle-level
# signature around a pre-signed SFX is unreliable with an ad-hoc identity, so we
# skip it: the app runs locally as-is. For DISTRIBUTION, sign the whole bundle
# with a real Developer ID identity and notarise:
#   codesign --force --options runtime --sign "Developer ID Application: …" "$APP"
#   xcrun notarytool submit … && xcrun stapler staple "$APP"
if command -v codesign >/dev/null 2>&1; then
  for dylib in "$MACOS"/*.dylib; do
    [ -f "$dylib" ] && codesign --force --sign - "$dylib" >/dev/null 2>&1 || true
  done
fi

echo "Done: $APP"
