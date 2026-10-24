#!/usr/bin/env python3
"""RSS新着収集スクリプト(Python版)

feeds.txtに書いたRSS 2.0 / Atom 1.0のフィードを取得し、新着だけをファイルに書き出します。
記録はSQLite(news.db)に保存します。設計は design.md を参照してください。
news.mjs(zx版)、news.php(PHP版)と同じ設計で、news.dbの形式も共通です。
出力形式はテンプレート(NEWS_TEMPLATE)で変えられます。既定はMarkdownです。

利用方法: python3 news.py

必要な環境: Python 3.9以上(Jinja2を初回に自動導入します)
feeds.txtとnews.dbは、このスクリプトのあるディレクトリを基準に扱います。
出力ファイル(NEWS_OUTPUT)と過去の複製(NEWS_DIR)は、実行時のカレントディレクトリ(ワーキングディレクトリ)を基準に書き出します。
パスは環境変数(NEWS_FEEDS_PATH、NEWS_DB_PATH、NEWS_OUTPUT_PATH、NEWS_DIR_PATH)で上書きできます。
"""

from __future__ import annotations

import gzip
import importlib
import os
import re
import shutil
import socket
import sqlite3
import subprocess
import sys
import urllib.error
import urllib.request
import xml.etree.ElementTree as ET
from datetime import datetime, timedelta, timezone
from email.utils import parsedate_to_datetime
from pathlib import Path
from zoneinfo import ZoneInfo

# 古い記事の削除までの日数(最後にフィードに載っていた日時が基準)。0なら削除しない
KEEP_DAYS = 0
# 初回取得のフィードで、新着として出す記事の期間(日)。0なら期間で絞らない
INITIAL_DAYS = 7
# フィード内の並び順。True なら新しい順、False なら古い順
NEWEST_FIRST = True
# 画面に出す日時のタイムゾーン
TIMEZONE = 'Asia/Tokyo'
# フィード1本あたりの取得のタイムアウト(秒)
FETCH_TIMEOUT_SECONDS = 30
# 過去のnews.mdの複製を置くディレクトリ。相対パスは、実行時のカレントディレクトリが基準
NEWS_DIR = 'news'
# 複製ディレクトリを上書きする環境変数
NEWS_DIR_PATH_ENV = 'NEWS_DIR_PATH'
# summaryに保存する最大文字数
SUMMARY_MAX_LENGTH = 500
# 出力テンプレートのファイル名(templatesディレクトリからの相対パス)
NEWS_TEMPLATE = 'news.md.j2'
# 出力ファイル名(ワーキングディレクトリからの相対パス)
NEWS_OUTPUT = 'news.md'
# フィード設定ファイルを上書きする環境変数
NEWS_FEEDS_PATH_ENV = 'NEWS_FEEDS_PATH'
# DBパスを上書きする環境変数
NEWS_DB_PATH_ENV = 'NEWS_DB_PATH'
# テンプレートパスを上書きする環境変数
NEWS_TEMPLATE_PATH_ENV = 'NEWS_TEMPLATE_PATH'
# 出力パスを上書きする環境変数
NEWS_OUTPUT_PATH_ENV = 'NEWS_OUTPUT_PATH'
# 依存パッケージが不足している場合に、自動で導入するか
AUTO_INSTALL_DEPENDENCIES = True

# DBに保存する日時の形式(UTC)
DB_DATE_FORMAT = '%Y-%m-%d %H:%M:%S'
# Atom 1.0の名前空間
ATOM_NS = 'http://www.w3.org/2005/Atom'

BASE_DIR = Path(__file__).resolve().parent


def resolve_template_path() -> Path:
    """使用するテンプレートの絶対パスを返します"""
    override = os.environ.get(NEWS_TEMPLATE_PATH_ENV)
    if override:
        return (BASE_DIR / override).resolve()
    return BASE_DIR / 'templates' / NEWS_TEMPLATE


def resolve_output_path(work_dir: Path) -> Path:
    """出力ファイルの絶対パスを返します"""
    override = os.environ.get(NEWS_OUTPUT_PATH_ENV)
    if override:
        return (work_dir / override).resolve()
    return work_dir / NEWS_OUTPUT


def news_dir_setting() -> str:
    """複製ディレクトリの設定値(環境変数または定数)を返します"""
    return os.environ.get(NEWS_DIR_PATH_ENV) or NEWS_DIR


def resolve_news_dir(work_dir: Path) -> Path:
    """複製を置くディレクトリの絶対パスを返します"""
    return (work_dir / news_dir_setting()).resolve()


def resolve_feeds_path() -> Path:
    """使用するfeeds.txtの絶対パスを返します"""
    override = os.environ.get(NEWS_FEEDS_PATH_ENV)
    return (BASE_DIR / override).resolve() if override else BASE_DIR / 'feeds.txt'


def resolve_db_path() -> Path:
    """使用するDBの絶対パスを返します"""
    override = os.environ.get(NEWS_DB_PATH_ENV)
    return (BASE_DIR / override).resolve() if override else BASE_DIR / 'news.db'


def is_html_output(output_path: Path) -> bool:
    """出力先がHTMLか判定します"""
    return output_path.suffix.lower() in ('.html', '.htm')


def _has_pip() -> bool:
    """現在のPythonでpipが使えるか確認します"""
    result = subprocess.run(
        [sys.executable, '-m', 'pip', '--version'],
        capture_output=True,
        text=True,
        check=False,
    )
    return result.returncode == 0


def _ensure_pip() -> bool:
    """ensurepipでpipを導入できるか確認します"""
    result = subprocess.run(
        [sys.executable, '-m', 'ensurepip', '--upgrade', '--user'],
        capture_output=True,
        text=True,
        check=False,
    )
    return result.returncode == 0 and _has_pip()


def ensure_dependencies() -> None:
    """Jinja2が読み込める状態にします。不足している場合はuvを優先し、なければpipで導入します"""
    vendor_dir = BASE_DIR / 'python-vendor'
    if vendor_dir.is_dir():
        sys.path.insert(0, str(vendor_dir))
    try:
        importlib.import_module('jinja2')
        return
    except ImportError:
        pass

    if not AUTO_INSTALL_DEPENDENCIES:
        raise RuntimeError('Jinja2がインストールされていません。pip install -r requirements.txt を実行してください。')

    requirements = BASE_DIR / 'requirements.txt'
    if not requirements.is_file():
        raise RuntimeError('requirements.txt が見つかりません。')

    print('依存パッケージ(Jinja2)を導入します。', file=sys.stderr)
    vendor_dir.mkdir(parents=True, exist_ok=True)
    commands: list[list[str]] = []
    if shutil.which('uv'):
        commands.append([
            'uv',
            'pip',
            'install',
            '--python',
            sys.executable,
            '--target',
            str(vendor_dir),
            '-r',
            str(requirements),
        ])
    if _has_pip():
        commands.append([
            sys.executable,
            '-m',
            'pip',
            'install',
            '--target',
            str(vendor_dir),
            '-r',
            str(requirements),
            '--disable-pip-version-check',
            '--no-input',
        ])
    if not commands and _ensure_pip():
        commands.append([
            sys.executable,
            '-m',
            'pip',
            'install',
            '--target',
            str(vendor_dir),
            '-r',
            str(requirements),
            '--disable-pip-version-check',
            '--no-input',
        ])
    if not commands:
        raise RuntimeError('uvまたはpipが見つかりません。pip install -r requirements.txt を実行してください。')

    last_error = ''
    for command in commands:
        result = subprocess.run(command, capture_output=True, text=True, check=False)
        if result.returncode == 0:
            sys.path.insert(0, str(vendor_dir))
            importlib.invalidate_caches()
            try:
                importlib.import_module('jinja2')
                return
            except ImportError as error:
                last_error = str(error)
        else:
            last_error = (result.stderr or result.stdout).strip()
    message = 'Jinja2の導入に失敗しました。pip install -r requirements.txt を実行してください。'
    if last_error:
        message += f' ({last_error})'
    raise RuntimeError(message)


class FeedError(Exception):
    """フィードの取得または解析の失敗(理由をそのままnews.mdとDBに記録する)"""


# ---------------------------------------------------------------------------
# 日時・文字列のヘルパー
# ---------------------------------------------------------------------------


def to_db_date(value: datetime) -> str:
    """DBに保存する形式(UTC。例: 2026-10-10 08:39:00)の文字列にします"""
    return value.astimezone(timezone.utc).strftime(DB_DATE_FORMAT)


def parse_feed_date(text: str) -> str | None:
    """フィードの日時文字列をDB形式のUTC文字列にします。解釈できない場合はNoneを返します"""
    if text == '':
        return None
    parsed: datetime | None = None
    try:
        # Python 3.11未満のfromisoformatはZを解釈できないため、置き換える
        parsed = datetime.fromisoformat(re.sub(r'Z$', '+00:00', text))
    except ValueError:
        try:
            parsed = parsedate_to_datetime(text)
        except (TypeError, ValueError):
            return None
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return to_db_date(parsed)


def from_db_date(db_date: str) -> datetime:
    """DB形式のUTC文字列を、表示用のタイムゾーンの日時にします"""
    utc = datetime.strptime(db_date, DB_DATE_FORMAT).replace(tzinfo=timezone.utc)
    return utc.astimezone(ZoneInfo(TIMEZONE))


def article_date(published_at: str | None, updated_at: str | None) -> str | None:
    """記事の日付(公開日と更新日のうち新しい方)を返します。両方なければNoneです"""
    dates = [date for date in (published_at, updated_at) if date is not None]
    return max(dates) if dates else None


def to_summary(html: str) -> str | None:
    """HTMLタグを除き、空白をまとめて、最大文字数で切ります。空ならNoneを返します"""
    text = re.sub(r'<[^>]*>', ' ', html)
    for entity, char in (('&nbsp;', ' '), ('&lt;', '<'), ('&gt;', '>'), ('&quot;', '"'), ('&#39;', "'"), ('&amp;', '&')):
        text = text.replace(entity, char)
    text = re.sub(r'\s+', ' ', text).strip()
    return text[:SUMMARY_MAX_LENGTH] if text != '' else None


# ---------------------------------------------------------------------------
# フィードの取得と解析
# ---------------------------------------------------------------------------


def children(parent: ET.Element, name: str, namespace: str) -> list[ET.Element]:
    """要素の子要素を、名前空間を区別して取り出します(namespaceが空文字なら名前空間なし)"""
    tag = f'{{{namespace}}}{name}' if namespace else name
    return [child for child in parent if child.tag == tag]


def child_text(parent: ET.Element, name: str, namespace: str) -> str:
    """子要素のテキストを取り出します。なければ空文字を返します"""
    elements = children(parent, name, namespace)
    return ''.join(elements[0].itertext()).strip() if elements else ''


def build_article(raw: dict[str, str], feed_url: str) -> dict[str, str | None] | None:
    """フィードの1記事を、DBに登録できる形にします。GUIDも記事URLもない場合はNoneを返します"""
    url = raw['url'] or (raw['guid'] if re.match(r'https?://', raw['guid']) else '')
    if raw['guid']:
        match_key = f"guid:{raw['guid']}"
    elif url:
        match_key = f'url:{url}'
    else:
        title = raw['title'] or '(タイトルなし)'
        print(f'GUIDも記事URLもない記事を取り込みません: {title} ({feed_url})', file=sys.stderr)
        return None
    return {
        'matchKey': match_key,
        'title': raw['title'] or url,
        'url': url,
        'summary': to_summary(raw['summary']),
        'publishedAt': parse_feed_date(raw['published']),
        'updatedAt': parse_feed_date(raw['updated']),
    }


def parse_rss(channel: ET.Element, feed_url: str) -> dict:
    """RSS 2.0のchannelから、フィード名と記事を取り出します"""
    articles = []
    for item in children(channel, 'item', ''):
        articles.append(
            build_article(
                {
                    'guid': child_text(item, 'guid', ''),
                    'url': child_text(item, 'link', ''),
                    'title': child_text(item, 'title', ''),
                    'summary': child_text(item, 'description', ''),
                    'published': child_text(item, 'pubDate', ''),
                    'updated': '',
                },
                feed_url,
            )
        )
    return {'title': child_text(channel, 'title', ''), 'articles': [a for a in articles if a is not None]}


def parse_atom(feed: ET.Element, feed_url: str) -> dict:
    """Atom 1.0のfeedから、フィード名と記事を取り出します"""
    articles = []
    for entry in children(feed, 'entry', ATOM_NS):
        links = [link for link in children(entry, 'link', ATOM_NS) if link.get('href') is not None]
        link = next((candidate for candidate in links if candidate.get('rel') in (None, 'alternate')), None)
        if link is None and links:
            link = links[0]
        summary = child_text(entry, 'summary', ATOM_NS) or child_text(entry, 'content', ATOM_NS)
        articles.append(
            build_article(
                {
                    'guid': child_text(entry, 'id', ATOM_NS),
                    'url': (link.get('href') or '').strip() if link is not None else '',
                    'title': child_text(entry, 'title', ATOM_NS),
                    'summary': summary,
                    'published': child_text(entry, 'published', ATOM_NS),
                    'updated': child_text(entry, 'updated', ATOM_NS),
                },
                feed_url,
            )
        )
    return {'title': child_text(feed, 'title', ATOM_NS), 'articles': [a for a in articles if a is not None]}


def fetch_feed(feed_url: str) -> dict:
    """フィードを取得し、フィード名と記事に変換します。失敗した場合はFeedErrorを送出します"""
    if not re.match(r'https?://', feed_url, re.IGNORECASE):
        raise FeedError('network error: unsupported URL')
    request = urllib.request.Request(
        feed_url,
        headers={
            'User-Agent': 'rss-news/1.0',
            'Accept': 'application/atom+xml, application/rss+xml, application/xml, */*',
            'Accept-Encoding': 'gzip',
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=FETCH_TIMEOUT_SECONDS) as response:
            body = response.read()
            if response.headers.get('Content-Encoding', '').lower() == 'gzip':
                body = gzip.decompress(body)
    except urllib.error.HTTPError as error:
        raise FeedError(f'HTTP {error.code}') from error
    except (socket.timeout, TimeoutError) as error:
        raise FeedError('timeout') from error
    except urllib.error.URLError as error:
        if isinstance(error.reason, (socket.timeout, TimeoutError)):
            raise FeedError('timeout') from error
        raise FeedError(f'network error: {error.reason}') from error
    except OSError as error:
        raise FeedError(f'network error: {error}') from error

    try:
        root = ET.fromstring(body)
    except (ET.ParseError, ValueError) as error:
        raise FeedError('parse error') from error
    if root.tag == 'rss':
        channels = children(root, 'channel', '')
        if channels:
            return parse_rss(channels[0], feed_url)
    elif root.tag == f'{{{ATOM_NS}}}feed':
        return parse_atom(root, feed_url)
    raise FeedError('parse error')


# ---------------------------------------------------------------------------
# DB
# ---------------------------------------------------------------------------


class Database:
    """news.dbの操作"""

    def __init__(self, path: Path) -> None:
        path.parent.mkdir(parents=True, exist_ok=True)
        # 自動コミットにして、トランザクションはBEGIN/COMMITで明示する
        self.connection = sqlite3.connect(path, isolation_level=None)
        self.connection.row_factory = sqlite3.Row
        self.connection.execute('PRAGMA foreign_keys = ON')
        self.connection.execute(
            """CREATE TABLE IF NOT EXISTS feeds (
                id              INTEGER PRIMARY KEY,
                feed_url        VARCHAR(255) NOT NULL UNIQUE,
                title           VARCHAR(255),
                last_fetched_at DATETIME,
                last_result     VARCHAR(255)
            )"""
        )
        self.connection.execute(
            """CREATE TABLE IF NOT EXISTS articles (
                match_key    VARCHAR(255) PRIMARY KEY,
                feed_id      INTEGER NOT NULL,
                title        VARCHAR(255) NOT NULL,
                url          VARCHAR(255) NOT NULL,
                summary      TEXT,
                published_at DATETIME,
                updated_at   DATETIME,
                fetched_at   DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL,
                FOREIGN KEY (feed_id) REFERENCES feeds(id) ON DELETE CASCADE
            )"""
        )

    def transaction(self) -> 'Transaction':
        """トランザクションを開始します(with文で使い、失敗したらロールバックします)"""
        return Transaction(self.connection)

    def sync_feeds(self, urls: list[str]) -> list[sqlite3.Row]:
        """feeds.txtとfeedsテーブルを同期し、feeds.txtの順にフィードの行を返します"""
        with self.transaction():
            registered = [row['feed_url'] for row in self.connection.execute('SELECT feed_url FROM feeds')]
            for url in urls:
                if url not in registered:
                    self.connection.execute('INSERT INTO feeds (feed_url) VALUES (?)', (url,))
            for url in registered:
                if url not in urls:
                    self.connection.execute('DELETE FROM feeds WHERE feed_url = ?', (url,))
        return [
            self.connection.execute(
                'SELECT id, feed_url, title, last_fetched_at FROM feeds WHERE feed_url = ?', (url,)
            ).fetchone()
            for url in urls
        ]


class Transaction:
    """トランザクションの範囲(with文で使います)"""

    def __init__(self, connection: sqlite3.Connection) -> None:
        self.connection = connection

    def __enter__(self) -> None:
        self.connection.execute('BEGIN')

    def __exit__(self, exc_type, exc_value, traceback) -> bool:
        self.connection.execute('ROLLBACK' if exc_type else 'COMMIT')
        return False


def read_feed_names() -> dict[str, str]:
    """feeds.txtからURLと表示名の一覧を読みます

    1行は「URL」または「URL 表示名」です。表示名を省略すると、フィード自身のタイトルを使います。
    戻り値は、URL(書かれた順。重複は最初の行を採用)をキー、表示名(なければ空文字)を値にした辞書です。
    """
    path = resolve_feeds_path()
    try:
        content = path.read_text(encoding='utf-8')
    except OSError as error:
        raise RuntimeError(f'{path} を読めません: {error}') from error
    names: dict[str, str] = {}
    for line in (line.strip() for line in content.splitlines()):
        if line == '' or line.startswith('#'):
            continue
        url, *rest = line.split()
        names.setdefault(url, ' '.join(rest))
    if not names:
        raise RuntimeError('feeds.txt に有効なURLがありません')
    return names


# ---------------------------------------------------------------------------
# 新着の判定
# ---------------------------------------------------------------------------


def classify(db: Database, results: list[dict], now: datetime) -> dict:
    """取得結果を登録済みの記事と照合し、新着・更新・登録だけ・重複確認の対象に分けます"""
    initial_cutoff = to_db_date(now - timedelta(days=INITIAL_DAYS))
    handled: set[str] = set()
    inserts: list[dict] = []
    updates: list[dict] = []
    news: list[dict] = []

    for result in results:
        feed_id = result['feed']['id']
        is_initial = result['feed']['last_fetched_at'] is None
        for article in result['articles']:
            if article['matchKey'] in handled:
                continue
            handled.add(article['matchKey'])
            date = article_date(article['publishedAt'], article['updatedAt'])
            stored = db.connection.execute(
                'SELECT published_at, updated_at FROM articles WHERE match_key = ?', (article['matchKey'],)
            ).fetchone()

            if stored is not None:
                stored_date = article_date(stored['published_at'], stored['updated_at'])
                if stored_date is not None and date is not None and date > stored_date:
                    updates.append(article)
                    news.append({'feedId': feed_id, 'article': article, 'date': date})
                continue

            inserts.append({'feedId': feed_id, 'article': article})
            is_recent = INITIAL_DAYS == 0 or (date is not None and date >= initial_cutoff)
            if not is_initial or is_recent:
                news.append({'feedId': feed_id, 'article': article, 'date': date})
    return {'inserts': inserts, 'updates': updates, 'seenKeys': list(handled), 'news': news}


# ---------------------------------------------------------------------------
# news.mdの生成
# ---------------------------------------------------------------------------


def escape_title(title: str) -> str:
    """Markdownのリンクが崩れないように、タイトルを整えます"""
    return re.sub(r'\s*[\r\n]+\s*', ' ', title).replace('[', '\\[').replace(']', '\\]')


def markdown_url(url: str) -> str:
    """Markdownのリンクが崩れないように、URLを整えます"""
    return re.sub(r'\s', '%20', url.replace(')', '%29'))


def sort_by_date(entries: list[dict]) -> list[dict]:
    """フィード内の並びを、記事の日付の順にします(日付なしは末尾)"""
    dated = sorted((e for e in entries if e['date'] is not None), key=lambda e: e['date'], reverse=NEWEST_FIRST)
    return dated + [e for e in entries if e['date'] is None]


def build_context(results: list[dict], news: list[dict], failures: list[dict], now: datetime) -> dict:
    """テンプレートに渡す値を組み立てます"""
    local = now.astimezone(ZoneInfo(TIMEZONE))
    entries_by_feed: dict[int, list[dict]] = {}
    for entry in news:
        entries_by_feed.setdefault(entry['feedId'], []).append(entry)
    feeds = []
    for result in results:
        entries = sort_by_date(entries_by_feed.get(result['feed']['id'], []))
        if not entries:
            continue
        items = []
        for entry in entries:
            date = '' if entry['date'] is None else from_db_date(entry['date']).strftime('%Y-%m-%d')
            items.append({
                'title': entry['article']['title'],
                'url': entry['article']['url'],
                'hasUrl': entry['article']['url'] != '',
                'isHttpUrl': bool(re.match(r'https?://', entry['article']['url'], re.IGNORECASE)),
                'titleMarkdown': escape_title(entry['article']['title']),
                'urlMarkdown': markdown_url(entry['article']['url']),
                'date': date,
                'hasDate': entry['date'] is not None,
                'dateSuffix': '' if date == '' else f' ({date})',
            })
        feeds.append({
            'name': result['name'],
            'title': result['title'],
            'url': result['feed']['feed_url'],
            'entries': items,
        })
    return {
        'newsCount': len(news),
        'fetchedAt': local.strftime('%Y-%m-%d %H:%M'),
        'newsDir': news_dir_setting(),
        'feeds': feeds,
        'failures': failures,
        'hasFailures': bool(failures),
    }


def render_news(
    results: list[dict],
    news: list[dict],
    failures: list[dict],
    now: datetime,
    autoescape: bool,
) -> str:
    """出力本文を作ります"""
    context = build_context(results, news, failures, now)
    # mainで依存パッケージを導入した後に読み込むため、関数内でインポートする
    from jinja2 import Environment, FileSystemLoader

    template_path = resolve_template_path()
    env = Environment(
        loader=FileSystemLoader(str(template_path.parent)),
        autoescape=autoescape,
        trim_blocks=True,
        lstrip_blocks=True,
        keep_trailing_newline=True,
    )
    template = env.get_template(template_path.name)
    return template.render(**context)


# ---------------------------------------------------------------------------
# 本体
# ---------------------------------------------------------------------------


def main() -> None:
    """処理の流れ(設計書の手順2から7)"""
    ensure_dependencies()
    now = datetime.now(timezone.utc)
    now_db = to_db_date(now)
    work_dir = Path.cwd()
    db = Database(resolve_db_path())

    # 手順2: feeds.txtとfeedsテーブルの同期
    feed_names = read_feed_names()
    feeds = db.sync_feeds(list(feed_names))

    # 手順3: 各フィードを1本ずつ取得
    results: list[dict] = []
    failures: list[dict] = []
    for feed in feeds:
        try:
            parsed = fetch_feed(feed['feed_url'])
            name = feed_names[feed['feed_url']] or parsed['title'] or feed['title'] or feed['feed_url']
            results.append({'feed': feed, 'name': name, 'title': parsed['title'], 'articles': parsed['articles']})
            print(f"取得: {feed['feed_url']} ({len(parsed['articles'])}件)")
        except Exception as error:  # noqa: BLE001 失敗したフィードがあっても次へ進む
            reason = (str(error) if isinstance(error, FeedError) else f'error: {error}')[:255]
            db.connection.execute('UPDATE feeds SET last_result = ? WHERE id = ?', (reason, feed['id']))
            failures.append({'url': feed['feed_url'], 'reason': reason})
            print(f"取得失敗: {feed['feed_url']}: {reason}", file=sys.stderr)

    # 手順4: 新着の判定
    classified = classify(db, results, now)

    # 手順5: 新着の書き出し(ファイルは取り消せないため、DBのコミットより先に行う)
    output_path = resolve_output_path(work_dir)
    markdown = render_news(results, classified['news'], failures, now, is_html_output(output_path))
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(markdown, encoding='utf-8')
    if classified['news']:
        news_dir = resolve_news_dir(work_dir)
        news_dir.mkdir(parents=True, exist_ok=True)
        local = now.astimezone(ZoneInfo(TIMEZONE))
        (news_dir / f"{local.strftime('%Y-%m-%d_%H%M')}{output_path.suffix}").write_text(markdown, encoding='utf-8')

    # 手順6: DBの更新(1つのトランザクション)
    with db.transaction():
        for row in classified['inserts']:
            a = row['article']
            db.connection.execute(
                'INSERT INTO articles'
                ' (match_key, feed_id, title, url, summary, published_at, updated_at, fetched_at, last_seen_at)'
                ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                (a['matchKey'], row['feedId'], a['title'], a['url'], a['summary'],
                 a['publishedAt'], a['updatedAt'], now_db, now_db),
            )
        for a in classified['updates']:
            db.connection.execute(
                'UPDATE articles SET title = ?, url = ?, summary = ?, published_at = ?, updated_at = ?'
                ' WHERE match_key = ?',
                (a['title'], a['url'], a['summary'], a['publishedAt'], a['updatedAt'], a['matchKey']),
            )
        for key in classified['seenKeys']:
            db.connection.execute('UPDATE articles SET last_seen_at = ? WHERE match_key = ?', (now_db, key))
        for result in results:
            title = result['title'] or result['feed']['title']
            db.connection.execute(
                'UPDATE feeds SET title = ?, last_fetched_at = ?, last_result = ? WHERE id = ?',
                (title, now_db, 'ok', result['feed']['id']),
            )
    print(f"新着{len(classified['news'])}件、登録{len(classified['inserts'])}件、取得失敗{len(failures)}件")

    # 手順7: 古い記事の削除(別のトランザクション。失敗しても手順6には影響しない)
    if KEEP_DAYS >= 1:
        try:
            cutoff = to_db_date(now - timedelta(days=KEEP_DAYS))
            with db.transaction():
                db.connection.execute('DELETE FROM articles WHERE last_seen_at < ?', (cutoff,))
        except sqlite3.Error as error:
            print(f'古い記事の削除に失敗しました: {error}', file=sys.stderr)
    db.connection.close()


if __name__ == '__main__':
    try:
        main()
    except Exception as error:  # noqa: BLE001 失敗の内容を表示して終了コード1で終わる
        print(f'エラー: {error}', file=sys.stderr)
        sys.exit(1)
