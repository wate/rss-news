#!/usr/bin/env zx
// zx globals: $, fs, path, chalk, echo

/**
 * RSS新着収集スクリプト
 * =====================
 *
 * feeds.txtに書いたRSS 2.0 / Atom 1.0のフィードを取得し、新着だけをファイルに書き出します。
 * 記録はSQLite(news.db)に保存します。設計は design.md を参照してください。
 * 出力形式はテンプレート(NEWS_TEMPLATE)で変えられます。既定はMarkdownです。
 * 依存パッケージ(fast-xml-parser、nunjucks)は初回にnpm installで自動導入します。
 *
 * 利用方法
 * --------
 *
 * ```sh
 * zx news.mjs
 * ```
 *
 * feeds.txtとnews.dbは、このスクリプトのあるディレクトリを基準に扱います。
 * 出力ファイル(NEWS_OUTPUT)と過去の複製(NEWS_DIR)は、実行時のカレントディレクトリ(ワーキングディレクトリ)を基準に書き出します。
 * パスは環境変数(NEWS_FEEDS_PATH、NEWS_DB_PATH、NEWS_OUTPUT_PATH、NEWS_DIR_PATH)で上書きできます。
 */

import { DatabaseSync } from 'node:sqlite';

/** 古い記事の削除までの日数(最後にフィードに載っていた日時が基準)。0なら削除しない */
const KEEP_DAYS = 0;
/** 初回取得のフィードで、新着として出す記事の期間(日)。0なら期間で絞らない */
const INITIAL_DAYS = 7;
/** フィード内の並び順。true なら新しい順、false なら古い順 */
const NEWEST_FIRST = true;
/** 画面に出す日時のタイムゾーン */
const TIMEZONE = 'Asia/Tokyo';
/** フィード1本あたりの取得のタイムアウト(秒) */
const FETCH_TIMEOUT_SECONDS = 30;
/** 過去のnews.mdの複製を置くディレクトリ。相対パスは、実行時のカレントディレクトリが基準 */
const NEWS_DIR = 'news';
/** 複製ディレクトリを上書きする環境変数 */
const NEWS_DIR_PATH_ENV = 'NEWS_DIR_PATH';
/** summaryに保存する最大文字数 */
const SUMMARY_MAX_LENGTH = 500;
/** 出力テンプレートのファイル名(templatesディレクトリからの相対パス) */
const NEWS_TEMPLATE = 'news.md.j2';
/** 出力ファイル名(ワーキングディレクトリからの相対パス) */
const NEWS_OUTPUT = 'news.md';
/** フィード設定ファイルを上書きする環境変数 */
const NEWS_FEEDS_PATH_ENV = 'NEWS_FEEDS_PATH';
/** DBパスを上書きする環境変数 */
const NEWS_DB_PATH_ENV = 'NEWS_DB_PATH';
/** テンプレートパスを上書きする環境変数 */
const NEWS_TEMPLATE_PATH_ENV = 'NEWS_TEMPLATE_PATH';
/** 出力パスを上書きする環境変数 */
const NEWS_OUTPUT_PATH_ENV = 'NEWS_OUTPUT_PATH';
/** 依存パッケージが不足している場合に、自動で導入するか */
const AUTO_INSTALL_DEPENDENCIES = true;

const baseDir = import.meta.dirname;
const newsDirSetting = process.env[NEWS_DIR_PATH_ENV] || NEWS_DIR;
const paths = {
  feeds: process.env[NEWS_FEEDS_PATH_ENV]
    ? path.resolve(baseDir, process.env[NEWS_FEEDS_PATH_ENV])
    : path.join(baseDir, 'feeds.txt'),
  db: process.env[NEWS_DB_PATH_ENV]
    ? path.resolve(baseDir, process.env[NEWS_DB_PATH_ENV])
    : path.join(baseDir, 'news.db'),
  newsDir: path.resolve(process.cwd(), newsDirSetting),
};

/**
 * 使用するテンプレートの絶対パスを返します
 *
 * @returns {string} テンプレートのパス
 */
function resolveTemplatePath() {
  const override = process.env[NEWS_TEMPLATE_PATH_ENV];
  return override ? path.resolve(baseDir, override) : path.join(baseDir, 'templates', NEWS_TEMPLATE);
}

/**
 * 出力ファイルの絶対パスを返します
 *
 * @param {string} workDir ワーキングディレクトリ
 * @returns {string} 出力ファイルのパス
 */
function resolveOutputPath(workDir) {
  const override = process.env[NEWS_OUTPUT_PATH_ENV];
  return override ? path.resolve(workDir, override) : path.join(workDir, NEWS_OUTPUT);
}

/**
 * 出力先がHTMLか判定します
 *
 * @param {string} outputPath 出力ファイルのパス
 * @returns {boolean} HTMLならtrue
 */
function isHtmlOutput(outputPath) {
  return ['.html', '.htm'].includes(path.extname(outputPath).toLowerCase());
}

/** フィードの取得または解析の失敗(理由をそのままnews.mdとDBに記録する) */
class FeedError extends Error {}

/**
 * package.jsonのdependenciesがnode_modulesにそろっているか確認し、不足があればnpm installを実行します
 *
 * @returns {Promise<void>}
 */
async function ensureDependencies() {
  const { dependencies = {} } = JSON.parse(fs.readFileSync(path.join(baseDir, 'package.json'), 'utf8'));
  const missing = Object.keys(dependencies).filter((name) => !fs.existsSync(path.join(baseDir, 'node_modules', name)));
  if (missing.length === 0) {
    return;
  }
  if (!AUTO_INSTALL_DEPENDENCIES) {
    throw new Error(`依存パッケージが不足しています: ${missing.join(', ')}。npm install を実行してください。`);
  }
  echo(chalk.yellow(`依存パッケージが不足しています: ${missing.join(', ')}`));
  await $({ cwd: baseDir })`npm install`;
}

let nunjucks;
try {
  await ensureDependencies();
  const nunjucksModule = await import('nunjucks');
  nunjucks = nunjucksModule.default;
} catch (error) {
  console.error(chalk.red(`エラー: ${error.message}`));
  process.exit(1);
}
const { XMLParser, XMLValidator } = await import('fast-xml-parser');

const xmlParser = new XMLParser({
  ignoreAttributes: false,
  attributeNamePrefix: '@_',
  textNodeName: '#text',
  parseTagValue: false,
  isArray: (name) => ['item', 'entry', 'link'].includes(name),
});

// ---------------------------------------------------------------------------
// 日時・文字列のヘルパー
// ---------------------------------------------------------------------------

/**
 * DBに保存する形式(UTC。例: 2026-10-10 08:39:00)の文字列にします
 *
 * @param {Date} date 日時
 * @returns {string} DB形式の日時
 */
function toDbDate(date) {
  return date.toISOString().slice(0, 19).replace('T', ' ');
}

/**
 * フィードの日時文字列をDB形式のUTC文字列にします
 *
 * @param {unknown} value フィードから取り出した日時
 * @returns {string|null} DB形式の日時。解釈できない場合はnull
 */
function parseFeedDate(value) {
  const text = textOf(value);
  if (text === '') {
    return null;
  }
  const date = new Date(text);
  return Number.isNaN(date.getTime()) ? null : toDbDate(date);
}

/**
 * DB形式のUTC文字列をDateにします
 *
 * @param {string} dbDate DB形式の日時
 * @returns {Date} 日時
 */
function fromDbDate(dbDate) {
  return new Date(`${dbDate.replace(' ', 'T')}Z`);
}

/**
 * 日時を、表示用のタイムゾーンの各要素に分けます
 *
 * @param {Date} date 日時
 * @returns {{year: string, month: string, day: string, hour: string, minute: string}} 各要素
 */
function zonedParts(date) {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: TIMEZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(date);
  return Object.fromEntries(parts.map((part) => [part.type, part.value]));
}

/**
 * 記事の日付(公開日と更新日のうち新しい方)を返します
 *
 * @param {{publishedAt: string|null, updatedAt: string|null}} article 記事
 * @returns {string|null} DB形式の日時。両方なければnull
 */
function articleDate(article) {
  const dates = [article.publishedAt, article.updatedAt].filter(Boolean).sort();
  return dates.at(-1) ?? null;
}

/**
 * XMLパーサーが返した値から、テキストを取り出します
 *
 * @param {unknown} value パーサーの値(文字列、数値、属性付きオブジェクト、配列)
 * @returns {string} 前後の空白を除いたテキスト
 */
function textOf(value) {
  if (value === undefined || value === null) {
    return '';
  }
  if (Array.isArray(value)) {
    return textOf(value[0]);
  }
  if (typeof value === 'object') {
    return textOf(value['#text']);
  }
  return String(value).trim();
}

/**
 * HTMLタグを除き、空白をまとめて、最大文字数で切ります
 *
 * @param {string} html HTMLを含む文字列
 * @returns {string|null} 加工後の文字列。空ならnull
 */
function toSummary(html) {
  const text = html
    .replace(/<[^>]*>/g, ' ')
    .replace(/&nbsp;/g, ' ')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/&amp;/g, '&')
    .replace(/\s+/g, ' ')
    .trim();
  return text === '' ? null : Array.from(text).slice(0, SUMMARY_MAX_LENGTH).join('');
}

// ---------------------------------------------------------------------------
// フィードの取得と解析
// ---------------------------------------------------------------------------

/**
 * フィードの1記事を、DBに登録できる形にします
 *
 * @param {{guid: string, url: string, title: string, summary: string, published: unknown, updated: unknown}} raw 取り出した値
 * @param {string} feedUrl 取得元のフィードURL(警告の表示用)
 * @returns {object|null} 記事。GUIDも記事URLもない場合はnull
 */
function buildArticle(raw, feedUrl) {
  const url = raw.url || (/^https?:\/\//.test(raw.guid) ? raw.guid : '');
  const matchKey = raw.guid ? `guid:${raw.guid}` : url ? `url:${url}` : null;
  if (matchKey === null) {
    console.warn(
      chalk.yellow(`GUIDも記事URLもない記事を取り込みません: ${raw.title || '(タイトルなし)'} (${feedUrl})`),
    );
    return null;
  }
  return {
    matchKey,
    title: raw.title || url,
    url,
    summary: toSummary(raw.summary),
    publishedAt: parseFeedDate(raw.published),
    updatedAt: parseFeedDate(raw.updated),
  };
}

/**
 * RSS 2.0のchannelから、フィード名と記事を取り出します
 *
 * @param {object} channel channel要素
 * @param {string} feedUrl フィードURL
 * @returns {{title: string, articles: object[]}} フィード名と記事
 */
function parseRss(channel, feedUrl) {
  const articles = (channel.item ?? []).map((item) =>
    buildArticle(
      {
        guid: textOf(item.guid),
        url: (item.link ?? []).map(textOf).find((link) => link !== '') ?? '',
        title: textOf(item.title),
        summary: textOf(item.description),
        published: item.pubDate,
        updated: null,
      },
      feedUrl,
    ),
  );
  return { title: textOf(channel.title), articles };
}

/**
 * Atom 1.0のfeedから、フィード名と記事を取り出します
 *
 * @param {object} feed feed要素
 * @param {string} feedUrl フィードURL
 * @returns {{title: string, articles: object[]}} フィード名と記事
 */
function parseAtom(feed, feedUrl) {
  const articles = (feed.entry ?? []).map((entry) => {
    const links = (entry.link ?? []).filter((link) => typeof link === 'object');
    const link = links.find((l) => !l['@_rel'] || l['@_rel'] === 'alternate') ?? links[0];
    return buildArticle(
      {
        guid: textOf(entry.id),
        url: link?.['@_href'] ?? '',
        title: textOf(entry.title),
        summary: textOf(entry.summary) || textOf(entry.content),
        published: entry.published,
        updated: entry.updated,
      },
      feedUrl,
    );
  });
  return { title: textOf(feed.title), articles };
}

/**
 * フィードを取得し、フィード名と記事に変換します
 *
 * @param {string} feedUrl フィードURL
 * @returns {Promise<{title: string, articles: object[]}>} フィード名と記事(キーのない記事は含めない)
 * @throws {FeedError} 取得または解析に失敗した場合
 */
async function fetchFeed(feedUrl) {
  let xml;
  try {
    const response = await fetch(feedUrl, {
      signal: AbortSignal.timeout(FETCH_TIMEOUT_SECONDS * 1000),
      headers: {
        'User-Agent': 'rss-news/1.0',
        Accept: 'application/atom+xml, application/rss+xml, application/xml, */*',
      },
    });
    if (!response.ok) {
      throw new FeedError(`HTTP ${response.status}`);
    }
    xml = await response.text();
  } catch (error) {
    if (error instanceof FeedError) {
      throw error;
    }
    if (error.name === 'TimeoutError') {
      throw new FeedError('timeout');
    }
    throw new FeedError(`network error: ${error.cause?.code ?? error.message}`);
  }

  if (XMLValidator.validate(xml) !== true) {
    throw new FeedError('parse error');
  }
  const doc = xmlParser.parse(xml);
  const parsed = doc.rss?.channel ? parseRss(doc.rss.channel, feedUrl) : doc.feed ? parseAtom(doc.feed, feedUrl) : null;
  if (parsed === null) {
    throw new FeedError('parse error');
  }
  return { title: parsed.title, articles: parsed.articles.filter(Boolean) };
}

// ---------------------------------------------------------------------------
// DB
// ---------------------------------------------------------------------------

/**
 * トランザクションの中で処理を実行し、失敗したらロールバックします
 *
 * @param {DatabaseSync} db DB接続
 * @param {() => void} work トランザクション内の処理
 * @returns {void}
 */
function inTransaction(db, work) {
  db.exec('BEGIN');
  try {
    work();
    db.exec('COMMIT');
  } catch (error) {
    db.exec('ROLLBACK');
    throw error;
  }
}

/**
 * DBに接続し、テーブルがなければ作成します
 *
 * @returns {DatabaseSync} DB接続
 */
function openDatabase() {
  fs.mkdirSync(path.dirname(paths.db), { recursive: true });
  const db = new DatabaseSync(paths.db);
  db.exec('PRAGMA foreign_keys = ON');
  db.exec(`
    CREATE TABLE IF NOT EXISTS feeds (
      id              INTEGER PRIMARY KEY,
      feed_url        VARCHAR(255) NOT NULL UNIQUE,
      title           VARCHAR(255),
      last_fetched_at DATETIME,
      last_result     VARCHAR(255)
    );
    CREATE TABLE IF NOT EXISTS articles (
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
    );
  `);
  return db;
}

/**
 * feeds.txtからURLと表示名の一覧を読みます
 *
 * 1行は「URL」または「URL 表示名」です。表示名を省略すると、フィード自身のタイトルを使います。
 *
 * @returns {Map<string, string>} URL(書かれた順。重複は最初の行を採用)と表示名(なければ空文字)
 * @throws {Error} 読めない場合、またはURLが1つもない場合
 */
function readFeedNames() {
  let content;
  try {
    content = fs.readFileSync(paths.feeds, 'utf8');
  } catch (error) {
    throw new Error(`${paths.feeds} を読めません: ${error.message}`);
  }
  const names = new Map();
  for (const line of content.split(/\r?\n/).map((l) => l.trim())) {
    if (line === '' || line.startsWith('#')) {
      continue;
    }
    const [url, ...rest] = line.split(/\s+/);
    if (!names.has(url)) {
      names.set(url, rest.join(' '));
    }
  }
  if (names.size === 0) {
    throw new Error('feeds.txt に有効なURLがありません');
  }
  return names;
}

/**
 * feeds.txtとfeedsテーブルを同期し、feeds.txtの順にフィードの行を返します
 *
 * @param {DatabaseSync} db DB接続
 * @param {string[]} urls feeds.txtのURL
 * @returns {object[]} feedsテーブルの行(feeds.txtの順)
 */
function syncFeeds(db, urls) {
  inTransaction(db, () => {
    const registered = db
      .prepare('SELECT feed_url FROM feeds')
      .all()
      .map((row) => row.feed_url);
    const insert = db.prepare('INSERT INTO feeds (feed_url) VALUES (?)');
    urls.filter((url) => !registered.includes(url)).forEach((url) => insert.run(url));
    const remove = db.prepare('DELETE FROM feeds WHERE feed_url = ?');
    registered.filter((url) => !urls.includes(url)).forEach((url) => remove.run(url));
  });
  const select = db.prepare('SELECT id, feed_url, title, last_fetched_at FROM feeds WHERE feed_url = ?');
  return urls.map((url) => select.get(url));
}

// ---------------------------------------------------------------------------
// 新着の判定
// ---------------------------------------------------------------------------

/**
 * 取得結果を登録済みの記事と照合し、新着・更新・登録だけ・重複確認の対象に分けます
 *
 * @param {DatabaseSync} db DB接続
 * @param {object[]} results フィードごとの取得結果(feeds.txtの順)
 * @param {Date} now 実行日時
 * @returns {{inserts: object[], updates: object[], seenKeys: string[], news: object[]}} 判定結果
 */
function classify(db, results, now) {
  const select = db.prepare('SELECT published_at, updated_at FROM articles WHERE match_key = ?');
  const initialCutoff = toDbDate(new Date(now.getTime() - INITIAL_DAYS * 24 * 60 * 60 * 1000));
  const handled = new Set();
  const inserts = [];
  const updates = [];
  const news = [];

  for (const result of results) {
    const isInitial = result.feed.last_fetched_at === null;
    for (const article of result.articles) {
      if (handled.has(article.matchKey)) {
        continue;
      }
      handled.add(article.matchKey);
      const date = articleDate(article);
      const stored = select.get(article.matchKey);

      if (stored) {
        const storedDate = articleDate({ publishedAt: stored.published_at, updatedAt: stored.updated_at });
        if (storedDate && date && date > storedDate) {
          updates.push(article);
          news.push({ feedId: result.feed.id, article, date });
        }
        continue;
      }

      inserts.push({ feedId: result.feed.id, article });
      const isRecent = INITIAL_DAYS === 0 || (date !== null && date >= initialCutoff);
      if (!isInitial || isRecent) {
        news.push({ feedId: result.feed.id, article, date });
      }
    }
  }
  return { inserts, updates, seenKeys: [...handled], news };
}

// ---------------------------------------------------------------------------
// news.mdの生成
// ---------------------------------------------------------------------------

/**
 * Markdownのリンクが崩れないように、タイトルを整えます
 *
 * @param {string} title 記事タイトル
 * @returns {string} 改行を空白にし、[ と ] をエスケープしたタイトル
 */
function escapeTitle(title) {
  return title.replace(/\s*[\r\n]+\s*/g, ' ').replace(/[[\]]/g, (char) => `\\${char}`);
}

/**
 * Markdownのリンクが崩れないように、URLを整えます
 *
 * @param {string} url 記事URL
 * @returns {string} Markdown用のURL
 */
function markdownUrl(url) {
  return url.replace(/\)/g, '%29').replace(/\s/g, '%20');
}

/**
 * DB形式の日時を、表示用の日付(YYYY-MM-DD)にします
 *
 * @param {string} dbDate DB形式の日時
 * @returns {string} 表示用の日付
 */
function formatDate(dbDate) {
  const { year, month, day } = zonedParts(fromDbDate(dbDate));
  return `${year}-${month}-${day}`;
}

/**
 * フィード内の並びを、記事の日付の順にします(日付なしは末尾)
 *
 * @param {{date: string|null}} a 新着
 * @param {{date: string|null}} b 新着
 * @returns {number} 並べ替えの比較値
 */
function compareByDate(a, b) {
  if (a.date === b.date) {
    return 0;
  }
  if (a.date === null) {
    return 1;
  }
  if (b.date === null) {
    return -1;
  }
  return (a.date < b.date ? -1 : 1) * (NEWEST_FIRST ? -1 : 1);
}

/**
 * 出力本文を作ります
 *
 * @param {object[]} results フィードごとの取得結果(feeds.txtの順)
 * @param {object[]} news 新着
 * @param {{url: string, reason: string}[]} failures 取得に失敗したフィード
 * @param {Date} now 実行日時
 * @param {boolean} autoescape HTMLならtrue
 * @returns {string} 出力本文
 */
function renderNews(results, news, failures, now, autoescape) {
  const { year, month, day, hour, minute } = zonedParts(now);
  const entriesByFeed = new Map();
  for (const entry of news) {
    if (!entriesByFeed.has(entry.feedId)) {
      entriesByFeed.set(entry.feedId, []);
    }
    entriesByFeed.get(entry.feedId).push(entry);
  }

  const feeds = results
    .map((result) => {
      const entries = (entriesByFeed.get(result.feed.id) ?? []).sort(compareByDate).map((entry) => {
        const date = entry.date === null ? '' : formatDate(entry.date);
        return {
          title: entry.article.title,
          url: entry.article.url,
          hasUrl: entry.article.url !== '',
          isHttpUrl: /^https?:\/\//i.test(entry.article.url),
          titleMarkdown: escapeTitle(entry.article.title),
          urlMarkdown: markdownUrl(entry.article.url),
          date,
          hasDate: entry.date !== null,
          dateSuffix: date === '' ? '' : ` (${date})`,
        };
      });
      return { name: result.name, title: result.title, url: result.feed.feed_url, entries };
    })
    .filter((feed) => feed.entries.length > 0);

  const context = {
    newsCount: news.length,
    fetchedAt: `${year}-${month}-${day} ${hour}:${minute}`,
    newsDir: newsDirSetting,
    feeds,
    failures,
    hasFailures: failures.length > 0,
  };
  const templatePath = resolveTemplatePath();
  const loader = new nunjucks.FileSystemLoader(path.dirname(templatePath));
  const env = new nunjucks.Environment(loader, { autoescape, trimBlocks: true, lstripBlocks: true });
  return env.render(path.basename(templatePath), context);
}

// ---------------------------------------------------------------------------
// 本体
// ---------------------------------------------------------------------------

/**
 * 全体の処理を実行します
 *
 * @returns {Promise<void>}
 */
async function main() {
  const now = new Date();
  const nowDb = toDbDate(now);
  const db = openDatabase();

  // 手順2: feeds.txtとfeedsテーブルの同期
  const feedNames = readFeedNames();
  const feeds = syncFeeds(db, [...feedNames.keys()]);

  // 手順3: 各フィードを1本ずつ取得
  const results = [];
  const failures = [];
  const recordFailure = db.prepare('UPDATE feeds SET last_result = ? WHERE id = ?');
  for (const feed of feeds) {
    try {
      const { title, articles } = await fetchFeed(feed.feed_url);
      results.push({
        feed,
        name: feedNames.get(feed.feed_url) || title || feed.title || feed.feed_url,
        title,
        articles,
      });
      echo(`取得: ${feed.feed_url} (${articles.length}件)`);
    } catch (error) {
      const reason = (error instanceof FeedError ? error.message : `error: ${error.message}`).slice(0, 255);
      recordFailure.run(reason, feed.id);
      failures.push({ url: feed.feed_url, reason });
      console.error(chalk.red(`取得失敗: ${feed.feed_url}: ${reason}`));
    }
  }

  // 手順4: 新着の判定
  const { inserts, updates, seenKeys, news } = classify(db, results, now);

  // 手順5: 新着の書き出し(ファイルは取り消せないため、DBのコミットより先に行う)
  const outputPath = resolveOutputPath(process.cwd());
  const markdown = renderNews(results, news, failures, now, isHtmlOutput(outputPath));
  fs.mkdirSync(path.dirname(outputPath), { recursive: true });
  fs.writeFileSync(outputPath, markdown);
  if (news.length > 0) {
    const { year, month, day, hour, minute } = zonedParts(now);
    fs.mkdirSync(paths.newsDir, { recursive: true });
    const extension = path.extname(outputPath);
    fs.writeFileSync(path.join(paths.newsDir, `${year}-${month}-${day}_${hour}${minute}${extension}`), markdown);
  }

  // 手順6: DBの更新(1つのトランザクション)
  inTransaction(db, () => {
    const insert = db.prepare(`
      INSERT INTO articles
        (match_key, feed_id, title, url, summary, published_at, updated_at, fetched_at, last_seen_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`);
    for (const { feedId, article } of inserts) {
      insert.run(
        article.matchKey,
        feedId,
        article.title,
        article.url,
        article.summary,
        article.publishedAt,
        article.updatedAt,
        nowDb,
        nowDb,
      );
    }
    const update = db.prepare(
      'UPDATE articles SET title = ?, url = ?, summary = ?, published_at = ?, updated_at = ? WHERE match_key = ?',
    );
    for (const article of updates) {
      update.run(article.title, article.url, article.summary, article.publishedAt, article.updatedAt, article.matchKey);
    }
    const touch = db.prepare('UPDATE articles SET last_seen_at = ? WHERE match_key = ?');
    seenKeys.forEach((key) => touch.run(nowDb, key));
    const succeed = db.prepare('UPDATE feeds SET title = ?, last_fetched_at = ?, last_result = ? WHERE id = ?');
    for (const result of results) {
      succeed.run(result.title || result.feed.title, nowDb, 'ok', result.feed.id);
    }
  });
  echo(chalk.green(`新着${news.length}件、登録${inserts.length}件、取得失敗${failures.length}件`));

  // 手順7: 古い記事の削除(別のトランザクション。失敗しても手順6には影響しない)
  if (KEEP_DAYS >= 1) {
    try {
      const cutoff = toDbDate(new Date(now.getTime() - KEEP_DAYS * 24 * 60 * 60 * 1000));
      inTransaction(db, () => db.prepare('DELETE FROM articles WHERE last_seen_at < ?').run(cutoff));
    } catch (error) {
      console.error(chalk.red(`古い記事の削除に失敗しました: ${error.message}`));
    }
  }
  db.close();
}

try {
  await main();
} catch (error) {
  console.error(chalk.red(`エラー: ${error.message}`));
  process.exit(1);
}
