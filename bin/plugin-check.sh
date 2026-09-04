#!/usr/bin/env bash
#
# Run the official WordPress Plugin Check against the built plugin, with the
# same ignore list as .github/workflows/lint.yml. Plugin Check does not read
# phpcs.xml.dist, so the documented false positives are repeated here.
#
# Needs wp-cli with the plugin-check package:
#   wp package install wordpress/plugin-check-cli
#
# Usage: bin/plugin-check.sh

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

bin/build-zip.sh
rm -rf build && mkdir build
unzip -q dist/perxel-ai-translate.zip -d build

IGNORE="WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound"
IGNORE+=",WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound"
IGNORE+=",WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound"
IGNORE+=",WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters"

wp plugin check build/perxel-ai-translate \
	--slug=perxel-ai-translate \
	--ignore-codes="$IGNORE"
