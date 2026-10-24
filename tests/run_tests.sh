#!/usr/bin/env bash
# 3版の統合テストを並列実行するランナーです。
# テスト用フィードを動的生成し、ローカルHTTPサーバーで配信します。
set -euo pipefail

TEST_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
TMP_DIR=$(mktemp -d "${TMPDIR:-/tmp}/rss-news-test.XXXXXX")
SERVER_PID=""

# テスト終了時にHTTPサーバーを停止し、一時ファイルを削除します。
cleanup() {
    if [ -n "$SERVER_PID" ]; then
        kill "$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
    fi
    rm -rf "$TMP_DIR"
}
trap cleanup EXIT

for command in node zx php python3 curl; do
    if ! command -v "$command" >/dev/null 2>&1; then
        echo "必要なコマンドがありません: $command" >&2
        exit 1
    fi
done

# 3版で共有するテスト用Atomフィードを、実行時の日付で動的生成します。
FEED_DIR="$TMP_DIR/feed"
mkdir -p "$FEED_DIR"
python3 "$TEST_DIR/make_feed.py" "$FEED_DIR/feed.atom"

# 空きポートを取得し、生成したフィードをローカルHTTPサーバーで配信します。
PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1]); s.close()')
FEED_URL="http://127.0.0.1:${PORT}/feed.atom"
# Pythonはテスト対象であると同時に、テスト用フィードの配信にだけ使います。
python3 -m http.server "$PORT" --bind 127.0.0.1 --directory "$FEED_DIR" > "$TMP_DIR/server.log" 2>&1 &
SERVER_PID=$!

if ! curl -fsS --retry 30 --retry-connrefused --retry-delay 0 "$FEED_URL" -o /dev/null 2>/dev/null; then
    echo "テスト用フィードを取得できません" >&2
    cat "$TMP_DIR/server.log" >&2
    exit 1
fi

# 3版は同じFEED_URLを読み、DB・出力・複製だけを別ディレクトリに分けます。
TEST_ROOT="$TMP_DIR" FEED_URL="$FEED_URL" node "$TEST_DIR/news_test.mjs" > "$TMP_DIR/node.log" 2>&1 &
NODE_PID=$!
TEST_ROOT="$TMP_DIR" FEED_URL="$FEED_URL" php "$TEST_DIR/news_test.php" > "$TMP_DIR/php.log" 2>&1 &
PHP_PID=$!
TEST_ROOT="$TMP_DIR" FEED_URL="$FEED_URL" python3 "$TEST_DIR/news_test.py" > "$TMP_DIR/python.log" 2>&1 &
PYTHON_PID=$!

STATUS=0
wait "$NODE_PID" || STATUS=1
wait "$PHP_PID" || STATUS=1
wait "$PYTHON_PID" || STATUS=1

if [ "$STATUS" -ne 0 ]; then
    echo "テストに失敗しました" >&2
    for runtime in node php python; do
        echo "--- $runtime ---" >&2
        cat "$TMP_DIR/$runtime.log" >&2
    done
    exit 1
fi

# 実行ごとに変わる取得日時と、版ごとに異なる複製パスを正規化します。
for runtime in node php python; do
    sed -E "s/[（(]取得: [0-9-]+ [0-9:]+[）)]/(取得: TIME)/; s#${TMP_DIR}/${runtime}/news/#NEWS/#g" \
        "$TMP_DIR/$runtime/news.first.md" > "$TMP_DIR/$runtime/news.normalized.md"
done

# パスと取得日時を除いた本文が3版で一致することを確認します。
if ! diff -u "$TMP_DIR/node/news.normalized.md" "$TMP_DIR/php/news.normalized.md"; then
    echo "Node.js版とPHP版の出力が一致しません" >&2
    exit 1
fi
if ! diff -u "$TMP_DIR/node/news.normalized.md" "$TMP_DIR/python/news.normalized.md"; then
    echo "Node.js版とPython版の出力が一致しません" >&2
    exit 1
fi

echo "OK: node, php, python"
