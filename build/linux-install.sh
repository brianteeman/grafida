#!/usr/bin/env bash
#
# Grafida — edit Joomla! articles on your desktop.
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later.
#
# This script ships INSIDE the Linux tarball, beside the `grafida` binary. Run
# it after extracting the tarball to register Grafida and its icon with your
# desktop environment (no root required):
#
#   tar xzf Grafida-<version>-linux-amd64.tar.gz
#   cd grafida
#   ./install.sh
#
# It installs into the per-user XDG locations:
#   binary  -> stays in the extracted folder (launched from there)
#   icon    -> ~/.local/share/icons/hicolor/512x512/apps/grafida.png
#   desktop -> ~/.local/share/applications/grafida.desktop   (Exec/Icon rewritten
#              to absolute paths so the launcher works from anywhere)
#
# Run ./install.sh --uninstall to remove the icon and desktop entry.

set -euo pipefail

APP_ID="grafida"
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BINARY="$APP_DIR/grafida"

DATA_HOME="${XDG_DATA_HOME:-$HOME/.local/share}"
ICON_DIR="$DATA_HOME/icons/hicolor/512x512/apps"
APPS_DIR="$DATA_HOME/applications"
ICON_DEST="$ICON_DIR/$APP_ID.png"
DESKTOP_DEST="$APPS_DIR/$APP_ID.desktop"

refresh_caches() {
    command -v update-desktop-database >/dev/null 2>&1 && update-desktop-database "$APPS_DIR" || true
    command -v gtk-update-icon-cache   >/dev/null 2>&1 && gtk-update-icon-cache -f -t "$DATA_HOME/icons/hicolor" 2>/dev/null || true
}

if [[ "${1:-}" == "--uninstall" ]]; then
    rm -f "$ICON_DEST" "$DESKTOP_DEST"
    refresh_caches
    echo "Grafida: desktop entry and icon removed."
    exit 0
fi

[[ -f "$APP_DIR/$APP_ID.png"     ]] || { echo "ERROR: $APP_ID.png not found beside this script."     >&2; exit 1; }
[[ -f "$APP_DIR/$APP_ID.desktop" ]] || { echo "ERROR: $APP_ID.desktop not found beside this script." >&2; exit 1; }

mkdir -p "$ICON_DIR" "$APPS_DIR"

install -m 0644 "$APP_DIR/$APP_ID.png" "$ICON_DEST"

# Rewrite Exec/Icon to absolute paths so the launcher works regardless of where
# the tarball was extracted and whether the binary is on $PATH.
sed -e "s|^Exec=.*|Exec=$BINARY|" \
    -e "s|^Icon=.*|Icon=$ICON_DEST|" \
    "$APP_DIR/$APP_ID.desktop" > "$DESKTOP_DEST"
chmod 0644 "$DESKTOP_DEST"

refresh_caches

echo "Grafida: installed desktop entry and icon for the current user."
echo "  Launcher: $DESKTOP_DEST"
echo "  Icon    : $ICON_DEST"
echo "Run './install.sh --uninstall' to remove them."
