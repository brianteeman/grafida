#!/usr/bin/env bash
#
# Grafida — edit Joomla! articles on your desktop.
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later.
#
# Copies the compiler's cross-platform PHAR (build/phar/grafida.phar) into the
# distribution directory under its versioned name. There is nothing to assemble:
# the PHAR is already a self-contained artifact emitted by `boson compile`.
#
# Usage:  scripts/make-phar-dist.sh
#
# Output: build/dist/Grafida-<version>.phar

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

PHAR="$ROOT/build/phar/grafida.phar"
if [ ! -f "$PHAR" ]; then
  echo "PHAR not found at $PHAR — run 'phing git-phar' first." >&2
  exit 1
fi

VERSION="${GRAFIDA_VERSION:-$(sed -nE "s/.*VERSION = '([^']+)'.*/\1/p" "$ROOT/src/Support/App.php" | head -1)}"
DIST="$ROOT/build/dist"
mkdir -p "$DIST"

PHAR_OUT="$DIST/Grafida-${VERSION}.phar"
echo "Packaging PHAR (version: $VERSION)"
cp "$PHAR" "$PHAR_OUT"

echo "Done: $PHAR_OUT"
