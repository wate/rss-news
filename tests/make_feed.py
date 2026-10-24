#!/usr/bin/env python3
"""テスト用のAtomフィードを生成します。

tests/run_tests.shから呼び出し、Node.js版、PHP版、Python版で共有する
一時フィードを書き出します。日付は実行時に生成するため、INITIAL_DAYSの
判定が将来ずれません。本番スクリプトからは使いません。
"""

from __future__ import annotations

import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path


def make_feed(path: Path) -> None:
    """テスト用のAtomフィードを書き出します"""
    now = datetime.now(timezone.utc).replace(microsecond=0)
    recent = now - timedelta(days=1)
    old = now - timedelta(days=365)
    content = f'''<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>Test Feed</title>
  <id>http://example.com/feed</id>
  <updated>{now.strftime('%Y-%m-%dT%H:%M:%SZ')}</updated>
  <entry>
    <id>tag:example.com,test:recent</id>
    <title>Recent Test Article</title>
    <link href="https://example.com/recent" rel="alternate" />
    <summary>Recent test summary</summary>
    <published>{recent.strftime('%Y-%m-%dT%H:%M:%SZ')}</published>
    <updated>{recent.strftime('%Y-%m-%dT%H:%M:%SZ')}</updated>
  </entry>
  <entry>
    <id>tag:example.com,test:old</id>
    <title>Old Test Article</title>
    <link href="https://example.com/old" rel="alternate" />
    <summary>Old test summary</summary>
    <published>{old.strftime('%Y-%m-%dT%H:%M:%SZ')}</published>
    <updated>{old.strftime('%Y-%m-%dT%H:%M:%SZ')}</updated>
  </entry>
</feed>
'''
    path.write_text(content, encoding='utf-8')


def main() -> None:
    """コマンドラインからフィードを生成します"""
    if len(sys.argv) != 2:
        print('usage: make_feed.py <output-path>', file=sys.stderr)
        sys.exit(1)
    make_feed(Path(sys.argv[1]))


if __name__ == '__main__':
    main()
