#!/usr/bin/env python3
"""Python版の統合テスト"""

from __future__ import annotations

import os
import shutil
import sqlite3
import subprocess
import sys
from pathlib import Path


def check(condition: bool, message: str) -> None:
    """条件を確認します"""
    if not condition:
        raise AssertionError(message)


def run_script(script: Path, work_dir: Path, env: dict[str, str]) -> str:
    """メインスクリプトを実行します"""
    result = subprocess.run(
        [sys.executable, str(script)],
        cwd=work_dir,
        env=env,
        capture_output=True,
        text=True,
        check=False,
    )
    output = result.stdout + result.stderr
    check(result.returncode == 0, output)
    return output


def article_count(db_path: Path) -> int:
    """記事数を返します"""
    connection = sqlite3.connect(db_path)
    try:
        return int(connection.execute('SELECT COUNT(*) FROM articles').fetchone()[0])
    finally:
        connection.close()


def copy_count(news_dir: Path) -> int:
    """複製ファイル数を返します"""
    return len(list(news_dir.glob('*.md')))


def main() -> None:
    """テストを実行します"""
    test_root = os.environ.get('TEST_ROOT')
    feed_url = os.environ.get('FEED_URL')
    if not test_root or not feed_url:
        raise RuntimeError('TEST_ROOTとFEED_URLを設定してください')

    root_dir = Path(__file__).resolve().parent.parent
    script = root_dir / 'news.py'
    runtime_dir = Path(test_root) / 'python'
    work_dir = runtime_dir / 'work'
    feeds_path = runtime_dir / 'feeds.txt'
    db_path = runtime_dir / 'news.db'
    output_path = runtime_dir / 'news.md'
    news_dir = runtime_dir / 'news'
    first_copy_path = runtime_dir / 'news.first.md'

    work_dir.mkdir(parents=True, exist_ok=True)
    feeds_path.write_text(f'{feed_url} Test Feed\n', encoding='utf-8')

    env = os.environ.copy()
    env.update({
        'NEWS_FEEDS_PATH': str(feeds_path),
        'NEWS_DB_PATH': str(db_path),
        'NEWS_OUTPUT_PATH': str(output_path),
        'NEWS_DIR_PATH': str(news_dir),
    })

    run_script(script, work_dir, env)
    first_markdown = output_path.read_text(encoding='utf-8')
    check('新着1件' in first_markdown, f'1回目の新着件数が不正です: {first_markdown}')
    check('Recent Test Article' in first_markdown, f'Recent Test Articleがありません: {first_markdown}')
    check('Old Test Article' not in first_markdown, f'Old Test Articleが出力されています: {first_markdown}')
    check(article_count(db_path) == 2, '記事数が2ではありません')
    check(copy_count(news_dir) == 1, '複製ファイル数が1ではありません')
    shutil.copyfile(output_path, first_copy_path)

    run_script(script, work_dir, env)
    second_markdown = output_path.read_text(encoding='utf-8')
    check('新着0件' in second_markdown, f'2回目の新着件数が不正です: {second_markdown}')
    check(article_count(db_path) == 2, '2回目の記事数が2ではありません')
    check(copy_count(news_dir) == 1, '2回目の複製ファイル数が1ではありません')

    print('python: OK')


if __name__ == '__main__':
    main()
