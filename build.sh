#!/bin/sh
# Builds the plugin zips, ready for "Plugins → Add New → Upload Plugin":
#   dist/parsi-news-robot.zip            the plain plugin (for sale / any site)
#   dist/parsi-news-robot-<site>.zip     the same plugin plus presets/<site>.json as preset.json,
#                                        applied automatically right after activation
set -e
cd "$(dirname "$0")"
mkdir -p dist
rm -f dist/parsi-news-robot*.zip
zip -rq dist/parsi-news-robot.zip parsi-news-robot -x '*.DS_Store' -x '*/.git*' -x 'parsi-news-robot/preset.json'
echo "dist/parsi-news-robot.zip"
for preset in presets/*.json; do
	[ -f "$preset" ] || continue
	site=$(basename "$preset" .json)
	tmp=$(mktemp -d)
	cp -r parsi-news-robot "$tmp/"
	cp "$preset" "$tmp/parsi-news-robot/preset.json"
	(cd "$tmp" && zip -rq "$OLDPWD/dist/parsi-news-robot-$site.zip" parsi-news-robot -x '*.DS_Store' -x '*/.git*')
	rm -rf "$tmp"
	echo "dist/parsi-news-robot-$site.zip"
done
