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

# Code signing.
#
# For DISTRIBUTION set MACOS_SIGN_IDENTITY to a "Developer ID Application: …"
# identity (see build/readme/01-macos-signing.md). We then sign inside-out — the
# bundled dylib(s), then the main executable, then the whole bundle — each with
# the hardened runtime (--options runtime) and the entitlements the Boson SFX +
# bundled PHP runtime need (build/macos/entitlements.plist). This REPLACES the
# binary's original ad-hoc "micro.sfx" signature, which cannot be notarised.
#
# For LOCAL dev (MACOS_SIGN_IDENTITY unset) we keep the previous behaviour: the
# pre-signed SFX is left alone and we only ad-hoc sign the bundled dylib. A
# bundle-level ad-hoc signature around a pre-signed SFX is unreliable, so we skip
# it — the app runs locally as-is (but such a build cannot be notarised).
SIGN_IDENTITY="${MACOS_SIGN_IDENTITY:-}"
ENTITLEMENTS="${MACOS_ENTITLEMENTS:-$ROOT/build/macos/entitlements.plist}"

if [ -n "$SIGN_IDENTITY" ]; then
  if ! command -v codesign >/dev/null 2>&1; then
    echo "MACOS_SIGN_IDENTITY is set but 'codesign' was not found — install Xcode or the Command Line Tools." >&2
    exit 1
  fi
  if [ ! -f "$ENTITLEMENTS" ]; then
    echo "Entitlements file not found at $ENTITLEMENTS." >&2
    exit 1
  fi
  echo "Signing with Developer ID identity: $SIGN_IDENTITY"

  # 1) inner dylibs first (normal Mach-O files — these sign cleanly)
  for dylib in "$MACOS"/*.dylib; do
    [ -f "$dylib" ] || continue
    codesign --force --timestamp --options runtime --sign "$SIGN_IDENTITY" "$dylib"
  done

  # 2) the main executable.
  #
  # KNOWN LIMITATION — see build/readme/01-macos-signing.md ("Signing impossible under current Boson architecture"). Boson's
  # compiled binary is a phpmicro self-executable: a Mach-O followed by the PHP
  # PHAR appended *after* the code signature (EOF). codesign requires its
  # signature to be the last bytes of the file, so any trailing data makes it
  # fail with "main executable failed strict validation". phpmicro locates that
  # PHAR as the bytes from the Mach-O image-end to EOF, so the payload cannot be
  # moved into a Mach-O segment without breaking startup. There is therefore no
  # way (today) to Developer-ID sign / notarise the combined binary from here;
  # it needs a fix in Boson/phpmicro. We detect the trailing payload and fail
  # with a clear message instead of a cryptic codesign error.
  MACHO_END="$(otool -l "$MACOS/grafida" 2>/dev/null | awk '
    /LC_CODE_SIGNATURE/ {sig=1}
    sig && /dataoff/ {off=$2}
    sig && /datasize/ {print off+$2; exit}')"
  FILE_SIZE="$(stat -f%z "$MACOS/grafida")"
  if [ -n "$MACHO_END" ] && [ "$FILE_SIZE" -gt "$MACHO_END" ]; then
    echo "" >&2
    echo "ERROR: cannot Developer-ID sign $MACOS/grafida." >&2
    echo "  The Boson binary has $((FILE_SIZE - MACHO_END)) bytes of PHP payload appended after its" >&2
    echo "  Mach-O signature (EOF $FILE_SIZE > Mach-O end $MACHO_END). codesign rejects trailing" >&2
    echo "  data, and the payload cannot be relocated without breaking phpmicro startup." >&2
    echo "  This is a Boson/phpmicro limitation. See the 'Signing impossible under" >&2
    echo "  current Boson architecture' section of build/readme/01-macos-signing.md." >&2
    echo "  Leave MACOS_SIGN_IDENTITY unset to build an ad-hoc (local-only) app instead." >&2
    exit 1
  fi
  codesign --force --timestamp --options runtime \
    --entitlements "$ENTITLEMENTS" --sign "$SIGN_IDENTITY" "$MACOS/grafida"

  # 3) the whole bundle
  codesign --force --timestamp --options runtime \
    --entitlements "$ENTITLEMENTS" --sign "$SIGN_IDENTITY" "$APP"

  codesign --verify --deep --strict --verbose=2 "$APP"
  echo "Signed and verified: $APP"
elif command -v codesign >/dev/null 2>&1; then
  # Local dev: best-effort ad-hoc signature on the bundled dylib only.
  for dylib in "$MACOS"/*.dylib; do
    [ -f "$dylib" ] && codesign --force --sign - "$dylib" >/dev/null 2>&1 || true
  done
fi

echo "Done: $APP"
