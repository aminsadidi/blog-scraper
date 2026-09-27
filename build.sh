#!/bin/sh
# Builds dist/parsi-news-robot.zip, ready for "Plugins → Add New → Upload Plugin".
set -e
cd "$(dirname "$0")"
mkdir -p dist
rm -f dist/parsi-news-robot.zip
zip -rq dist/parsi-news-robot.zip parsi-news-robot -x '*.DS_Store' -x '*/.git*'
echo "dist/parsi-news-robot.zip"
