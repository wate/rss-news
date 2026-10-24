#!/usr/bin/env php
<?php

/**
 * RSS新着収集スクリプト(PHP版)
 *
 * feeds.txtに書いたRSS 2.0 / Atom 1.0のフィードを取得し、新着だけをファイルに書き出します。
 * 記録はSQLite(news.db)に保存します。設計は design.md を参照してください。
 * news.mjs(zx版)と同じ設計で、news.dbの形式も共通です。
 * 出力形式はテンプレート(NEWS_TEMPLATE)で変えられます。既定はMarkdownです。
 *
 * 利用方法: php news.php
 *
 * 必要な環境: PHP 8.2以上、拡張 curl / dom / libxml / mbstring / pdo_sqlite
 * 依存パッケージ: Twig(初回にComposerで自動導入します。Composerが必要です)
 * feeds.txtとnews.dbは、このスクリプトのあるディレクトリを基準に扱います。
 * 出力ファイル(NEWS_OUTPUT)と過去の複製(NEWS_DIR)は、実行時のカレントディレクトリ(ワーキングディレクトリ)を基準に書き出します。
 * パスは環境変数(NEWS_FEEDS_PATH、NEWS_DB_PATH、NEWS_OUTPUT_PATH、NEWS_DIR_PATH)で上書きできます。
 */

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable PSR1.Files.SideEffects

declare(strict_types=1);

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * RSS新着収集
 */
final class News
{
    /** 古い記事の削除までの日数(最後にフィードに載っていた日時が基準)。0なら削除しない */
    private const KEEP_DAYS = 0;
    /** 初回取得のフィードで、新着として出す記事の期間(日)。0なら期間で絞らない */
    private const INITIAL_DAYS = 7;
    /** フィード内の並び順。true なら新しい順、false なら古い順 */
    private const NEWEST_FIRST = true;
    /** 画面に出す日時のタイムゾーン */
    private const TIMEZONE = 'Asia/Tokyo';
    /** フィード1本あたりの取得のタイムアウト(秒) */
    private const FETCH_TIMEOUT_SECONDS = 30;
    /** 過去のnews.mdの複製を置くディレクトリ。相対パスは、実行時のカレントディレクトリが基準 */
    private const NEWS_DIR = 'news';
    /** 複製ディレクトリを上書きする環境変数 */
    private const NEWS_DIR_PATH_ENV = 'NEWS_DIR_PATH';
    /** summaryに保存する最大文字数 */
    private const SUMMARY_MAX_LENGTH = 500;
    /** 出力テンプレートのファイル名(templatesディレクトリからの相対パス) */
    private const NEWS_TEMPLATE = 'news.md.j2';
    /** 出力ファイル名(ワーキングディレクトリからの相対パス) */
    private const NEWS_OUTPUT = 'news.md';
    /** フィード設定ファイルを上書きする環境変数 */
    private const NEWS_FEEDS_PATH_ENV = 'NEWS_FEEDS_PATH';
    /** DBパスを上書きする環境変数 */
    private const NEWS_DB_PATH_ENV = 'NEWS_DB_PATH';
    /** テンプレートパスを上書きする環境変数 */
    private const NEWS_TEMPLATE_PATH_ENV = 'NEWS_TEMPLATE_PATH';
    /** 出力パスを上書きする環境変数 */
    private const NEWS_OUTPUT_PATH_ENV = 'NEWS_OUTPUT_PATH';
    /** 依存パッケージが不足している場合に、自動で導入するか */
    private const AUTO_INSTALL_DEPENDENCIES = true;
    /** DBに保存する日時の形式(UTC) */
    private const DB_DATE_FORMAT = 'Y-m-d H:i:s';
    /** Atom 1.0の名前空間 */
    private const ATOM_NS = 'http://www.w3.org/2005/Atom';

    private readonly string $baseDir;
    private readonly string $workDir;
    private PDO $db;

    /**
     * コンストラクタ
     *
     * @param string $baseDir feeds.txtとnews.dbを置くディレクトリ
     * @param string $workDir news.mdと複製を書き出す基準のディレクトリ(ワーキングディレクトリ)
     */
    public function __construct(string $baseDir, string $workDir)
    {
        $this->baseDir = $baseDir;
        $this->workDir = $workDir;
    }

    /**
     * 全体の処理を実行します
     *
     * @return int 終了コード
     */
    public function run(): int
    {
        try {
            $this->checkExtensions();
            $this->main();
        } catch (Throwable $e) {
            fwrite(STDERR, 'エラー: ' . $e->getMessage() . PHP_EOL);

            return 1;
        }

        return 0;
    }

    /**
     * 必要な拡張が読み込まれているか確認します
     *
     * @return void
     * @throws RuntimeException 不足している場合
     */
    private function checkExtensions(): void
    {
        $missing = array_filter(
            ['curl', 'dom', 'libxml', 'mbstring', 'pdo_sqlite'],
            static fn (string $name): bool => !extension_loaded($name)
        );
        if ($missing !== []) {
            throw new RuntimeException('PHPの拡張が不足しています: ' . implode(', ', $missing));
        }
    }

    /**
     * 処理の流れ(設計書の手順2から7)
     *
     * @return void
     */
    private function main(): void
    {
        date_default_timezone_set('UTC');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowDb = $now->format(self::DB_DATE_FORMAT);
        $this->db = $this->openDatabase();

        // 手順2: feeds.txtとfeedsテーブルの同期
        $feedNames = $this->readFeedNames();
        $feeds = $this->syncFeeds(array_map('strval', array_keys($feedNames)));

        // 手順3: 各フィードを1本ずつ取得
        $results = [];
        $failures = [];
        $recordFailure = $this->db->prepare('UPDATE feeds SET last_result = ? WHERE id = ?');
        foreach ($feeds as $feed) {
            try {
                $parsed = $this->fetchFeed($feed['feed_url']);
                $results[] = [
                    'feed' => $feed,
                    'name' => $feedNames[$feed['feed_url']] !== ''
                        ? $feedNames[$feed['feed_url']]
                        : ($parsed['title'] !== '' ? $parsed['title'] : ($feed['title'] ?? $feed['feed_url'])),
                    'title' => $parsed['title'],
                    'articles' => $parsed['articles'],
                ];
                echo '取得: ' . $feed['feed_url'] . ' (' . count($parsed['articles']) . '件)' . PHP_EOL;
            } catch (Throwable $e) {
                $message = $e instanceof UnexpectedValueException ? $e->getMessage() : 'error: ' . $e->getMessage();
                $reason = mb_substr($message, 0, 255);
                $recordFailure->execute([$reason, $feed['id']]);
                $failures[] = ['url' => $feed['feed_url'], 'reason' => $reason];
                fwrite(STDERR, '取得失敗: ' . $feed['feed_url'] . ': ' . $reason . PHP_EOL);
            }
        }

        // 手順4: 新着の判定
        $classified = $this->classify($results, $now);

        // 手順5: 新着の書き出し(ファイルは取り消せないため、DBのコミットより先に行う)
        $outputPath = $this->resolveOutputPath();
        $markdown = $this->renderNews(
            $results,
            $classified['news'],
            $failures,
            $now,
            $this->isHtmlOutput($outputPath)
        );
        $this->writeFile($outputPath, $markdown);
        if ($classified['news'] !== []) {
            $newsDir = $this->resolveNewsDir();
            if (!is_dir($newsDir) && !mkdir($newsDir, 0777, true) && !is_dir($newsDir)) {
                throw new RuntimeException($newsDir . ' を作成できません');
            }
            $local = $now->setTimezone(new DateTimeZone(self::TIMEZONE));
            $extension = pathinfo($outputPath, PATHINFO_EXTENSION);
            $copyName = $local->format('Y-m-d_Hi') . ($extension !== '' ? '.' . $extension : '');
            $this->writeFile($newsDir . '/' . $copyName, $markdown);
        }

        // 手順6: DBの更新(1つのトランザクション)
        $this->inTransaction(function () use ($classified, $results, $nowDb): void {
            $insert = $this->db->prepare(
                'INSERT INTO articles'
                . ' (match_key, feed_id, title, url, summary, published_at, updated_at, fetched_at, last_seen_at)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($classified['inserts'] as $row) {
                $a = $row['article'];
                $insert->execute([
                    $a['matchKey'], $row['feedId'], $a['title'], $a['url'], $a['summary'],
                    $a['publishedAt'], $a['updatedAt'], $nowDb, $nowDb,
                ]);
            }
            $update = $this->db->prepare(
                'UPDATE articles SET title = ?, url = ?, summary = ?, published_at = ?, updated_at = ?'
                . ' WHERE match_key = ?'
            );
            foreach ($classified['updates'] as $a) {
                $update->execute([
                    $a['title'], $a['url'], $a['summary'], $a['publishedAt'], $a['updatedAt'], $a['matchKey'],
                ]);
            }
            $touch = $this->db->prepare('UPDATE articles SET last_seen_at = ? WHERE match_key = ?');
            foreach ($classified['seenKeys'] as $key) {
                $touch->execute([$nowDb, $key]);
            }
            $succeed = $this->db->prepare(
                'UPDATE feeds SET title = ?, last_fetched_at = ?, last_result = ? WHERE id = ?'
            );
            foreach ($results as $result) {
                $title = $result['title'] !== '' ? $result['title'] : $result['feed']['title'];
                $succeed->execute([$title, $nowDb, 'ok', $result['feed']['id']]);
            }
        });
        echo sprintf(
            '新着%d件、登録%d件、取得失敗%d件',
            count($classified['news']),
            count($classified['inserts']),
            count($failures)
        ) . PHP_EOL;

        // 手順7: 古い記事の削除(別のトランザクション。失敗しても手順6には影響しない)
        if (self::KEEP_DAYS >= 1) {
            try {
                $cutoff = $now->modify('-' . self::KEEP_DAYS . ' days')->format(self::DB_DATE_FORMAT);
                $this->inTransaction(function () use ($cutoff): void {
                    $this->db->prepare('DELETE FROM articles WHERE last_seen_at < ?')->execute([$cutoff]);
                });
            } catch (Throwable $e) {
                fwrite(STDERR, '古い記事の削除に失敗しました: ' . $e->getMessage() . PHP_EOL);
            }
        }
    }

    // -----------------------------------------------------------------------
    // 日時・文字列のヘルパー
    // -----------------------------------------------------------------------

    /**
     * フィードの日時文字列をDB形式のUTC文字列にします
     *
     * @param string $text フィードから取り出した日時
     * @return string|null DB形式の日時。解釈できない場合はnull
     */
    private function parseFeedDate(string $text): ?string
    {
        if ($text === '') {
            return null;
        }
        // 曜日が日付と合わないフィードで、PHPが日付をずらさないように、先頭の曜日を除く
        $text = preg_replace('/^[A-Za-z]{3,9},\s*/', '', $text) ?? $text;
        try {
            return (new DateTimeImmutable($text))->setTimezone(new DateTimeZone('UTC'))->format(self::DB_DATE_FORMAT);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * DB形式のUTC文字列を、表示用のタイムゾーンの日時にします
     *
     * @param string $dbDate DB形式の日時
     * @return DateTimeImmutable 表示用のタイムゾーンの日時
     */
    private function toLocal(string $dbDate): DateTimeImmutable
    {
        return (new DateTimeImmutable($dbDate, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(self::TIMEZONE));
    }

    /**
     * 記事の日付(公開日と更新日のうち新しい方)を返します
     *
     * @param string|null $publishedAt 公開日時(DB形式)
     * @param string|null $updatedAt 更新日時(DB形式)
     * @return string|null DB形式の日時。両方なければnull
     */
    private function articleDate(?string $publishedAt, ?string $updatedAt): ?string
    {
        $dates = array_filter([$publishedAt, $updatedAt], static fn (?string $d): bool => $d !== null);
        if ($dates === []) {
            return null;
        }
        sort($dates);

        return end($dates);
    }

    /**
     * HTMLタグを除き、空白をまとめて、最大文字数で切ります
     *
     * @param string $html HTMLを含む文字列
     * @return string|null 加工後の文字列。空ならnull
     */
    private function toSummary(string $html): ?string
    {
        $text = preg_replace('/<[^>]*>/', ' ', $html) ?? $html;
        $text = str_replace(
            ['&nbsp;', '&lt;', '&gt;', '&quot;', '&#39;', '&amp;'],
            [' ', '<', '>', '"', "'", '&'],
            $text
        );
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return $text === '' ? null : mb_substr($text, 0, self::SUMMARY_MAX_LENGTH);
    }

    /**
     * 使用するテンプレートの絶対パスを返します
     *
     * @return string テンプレートのパス(相対パスはスクリプトのディレクトリが基準)
     */
    private function resolveTemplatePath(): string
    {
        $override = getenv(self::NEWS_TEMPLATE_PATH_ENV);
        if ($override !== false && $override !== '') {
            return $this->resolvePath($this->baseDir, $override);
        }

        return $this->baseDir . '/templates/' . self::NEWS_TEMPLATE;
    }

    /**
     * 出力ファイルの絶対パスを返します
     *
     * @return string 出力ファイルのパス(相対パスはワーキングディレクトリが基準)
     */
    private function resolveOutputPath(): string
    {
        $override = getenv(self::NEWS_OUTPUT_PATH_ENV);
        if ($override !== false && $override !== '') {
            return $this->resolvePath($this->workDir, $override);
        }

        return $this->workDir . '/' . self::NEWS_OUTPUT;
    }

    /**
     * 出力先がHTMLか判定します
     *
     * @param string $outputPath 出力ファイルのパス
     * @return bool HTMLならtrue
     */
    private function isHtmlOutput(string $outputPath): bool
    {
        return in_array(strtolower(pathinfo($outputPath, PATHINFO_EXTENSION)), ['html', 'htm'], true);
    }

    /**
     * 相対パスを基準ディレクトリからの絶対パスにします
     *
     * @param string $baseDir 基準ディレクトリ
     * @param string $path パス
     * @return string 絶対パス
     */
    private function resolvePath(string $baseDir, string $path): string
    {
        $isAbsolute = preg_match('#^(/|[A-Za-z]:[\\\\/])#', $path) === 1;

        return $isAbsolute ? $path : $baseDir . '/' . $path;
    }

    /**
     * 複製ディレクトリの設定値(環境変数または定数)を返します
     *
     * @return string ディレクトリの設定値
     */
    private function resolveNewsDirSetting(): string
    {
        $override = getenv(self::NEWS_DIR_PATH_ENV);

        return $override !== false && $override !== '' ? $override : self::NEWS_DIR;
    }

    /**
     * 過去のnews.mdの複製を置くディレクトリの絶対パスを返します
     *
     * @return string ディレクトリのパス(相対パスはワーキングディレクトリが基準)
     */
    private function resolveNewsDir(): string
    {
        return $this->resolvePath($this->workDir, $this->resolveNewsDirSetting());
    }

    /**
     * ファイルを書き出します
     *
     * @param string $path ファイルのパス
     * @param string $content 内容
     * @return void
     * @throws RuntimeException 書き出せない場合
     */
    private function writeFile(string $path, string $content): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException($directory . ' を作成できません');
        }
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException($path . ' を書き出せません');
        }
    }

    // -----------------------------------------------------------------------
    // フィードの取得と解析
    // -----------------------------------------------------------------------

    /**
     * 要素の子要素を、名前空間を区別して取り出します
     *
     * @param DOMNode $parent 親要素
     * @param string $name 子要素のローカル名
     * @param string $namespace 名前空間(なしは空文字)
     * @return list<DOMElement> 一致した子要素
     */
    private function children(DOMNode $parent, string $name, string $namespace): array
    {
        $found = [];
        foreach ($parent->childNodes as $node) {
            if (!$node instanceof DOMElement || $node->localName !== $name) {
                continue;
            }
            if ((string)$node->namespaceURI === $namespace) {
                $found[] = $node;
            }
        }

        return $found;
    }

    /**
     * 子要素のテキストを取り出します(なければ空文字)
     *
     * @param DOMNode $parent 親要素
     * @param string $name 子要素のローカル名
     * @param string $namespace 名前空間(なしは空文字)
     * @return string 前後の空白を除いたテキスト
     */
    private function childText(DOMNode $parent, string $name, string $namespace): string
    {
        $elements = $this->children($parent, $name, $namespace);

        return $elements === [] ? '' : trim($elements[0]->textContent);
    }

    /**
     * フィードの1記事を、DBに登録できる形にします
     *
     * @param array<string, string> $raw 取り出した値(guid, url, title, summary, published, updated)
     * @param string $feedUrl 取得元のフィードURL(警告の表示用)
     * @return array<string, string|null>|null 記事。GUIDも記事URLもない場合はnull
     */
    private function buildArticle(array $raw, string $feedUrl): ?array
    {
        $url = $raw['url'] !== '' ? $raw['url'] : (preg_match('#^https?://#', $raw['guid']) === 1 ? $raw['guid'] : '');
        if ($raw['guid'] !== '') {
            $matchKey = 'guid:' . $raw['guid'];
        } elseif ($url !== '') {
            $matchKey = 'url:' . $url;
        } else {
            $title = $raw['title'] !== '' ? $raw['title'] : '(タイトルなし)';
            fwrite(STDERR, 'GUIDも記事URLもない記事を取り込みません: ' . $title . ' (' . $feedUrl . ')' . PHP_EOL);

            return null;
        }

        return [
            'matchKey' => $matchKey,
            'title' => $raw['title'] !== '' ? $raw['title'] : $url,
            'url' => $url,
            'summary' => $this->toSummary($raw['summary']),
            'publishedAt' => $this->parseFeedDate($raw['published']),
            'updatedAt' => $this->parseFeedDate($raw['updated']),
        ];
    }

    /**
     * RSS 2.0のchannelから、フィード名と記事を取り出します
     *
     * @param DOMElement $channel channel要素
     * @param string $feedUrl フィードURL
     * @return array{title: string, articles: list<array<string, string|null>>} フィード名と記事
     */
    private function parseRss(DOMElement $channel, string $feedUrl): array
    {
        $articles = [];
        foreach ($this->children($channel, 'item', '') as $item) {
            $articles[] = $this->buildArticle([
                'guid' => $this->childText($item, 'guid', ''),
                'url' => $this->childText($item, 'link', ''),
                'title' => $this->childText($item, 'title', ''),
                'summary' => $this->childText($item, 'description', ''),
                'published' => $this->childText($item, 'pubDate', ''),
                'updated' => '',
            ], $feedUrl);
        }

        return [
            'title' => $this->childText($channel, 'title', ''),
            'articles' => array_values(array_filter($articles)),
        ];
    }

    /**
     * Atom 1.0のfeedから、フィード名と記事を取り出します
     *
     * @param DOMElement $feed feed要素
     * @param string $feedUrl フィードURL
     * @return array{title: string, articles: list<array<string, string|null>>} フィード名と記事
     */
    private function parseAtom(DOMElement $feed, string $feedUrl): array
    {
        $ns = self::ATOM_NS;
        $articles = [];
        foreach ($this->children($feed, 'entry', $ns) as $entry) {
            $links = array_values(array_filter(
                $this->children($entry, 'link', $ns),
                static fn (DOMElement $link): bool => $link->hasAttribute('href')
            ));
            $link = null;
            foreach ($links as $candidate) {
                if (!$candidate->hasAttribute('rel') || $candidate->getAttribute('rel') === 'alternate') {
                    $link = $candidate;
                    break;
                }
            }
            $link ??= $links[0] ?? null;
            $summary = $this->childText($entry, 'summary', $ns);
            $articles[] = $this->buildArticle([
                'guid' => $this->childText($entry, 'id', $ns),
                'url' => $link === null ? '' : trim($link->getAttribute('href')),
                'title' => $this->childText($entry, 'title', $ns),
                'summary' => $summary !== '' ? $summary : $this->childText($entry, 'content', $ns),
                'published' => $this->childText($entry, 'published', $ns),
                'updated' => $this->childText($entry, 'updated', $ns),
            ], $feedUrl);
        }

        return [
            'title' => $this->childText($feed, 'title', $ns),
            'articles' => array_values(array_filter($articles)),
        ];
    }

    /**
     * フィードを取得し、フィード名と記事に変換します
     *
     * @param string $feedUrl フィードURL
     * @return array{title: string, articles: list<array<string, string|null>>} フィード名と記事(キーのない記事は含めない)
     * @throws UnexpectedValueException 取得または解析に失敗した場合(理由をそのままnews.mdとDBに記録する)
     */
    private function fetchFeed(string $feedUrl): array
    {
        if (preg_match('#^https?://#i', $feedUrl) !== 1) {
            throw new UnexpectedValueException('network error: unsupported URL');
        }
        $curl = curl_init($feedUrl);
        if ($curl === false) {
            throw new UnexpectedValueException('network error: curl_init');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => self::FETCH_TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => 'rss-news/1.0',
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: application/atom+xml, application/rss+xml, application/xml, */*'],
        ]);
        $body = curl_exec($curl);
        if ($body === false) {
            $timedOut = curl_errno($curl) === CURLE_OPERATION_TIMEDOUT;
            $reason = $timedOut ? 'timeout' : 'network error: ' . curl_error($curl);
            throw new UnexpectedValueException($reason);
        }
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($status < 200 || $status >= 300) {
            throw new UnexpectedValueException('HTTP ' . $status);
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument();
            $loaded = $doc->loadXML((string)$body, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $loaded ? $doc->documentElement : null;
        if ($root === null) {
            throw new UnexpectedValueException('parse error');
        }
        if ($root->localName === 'rss') {
            $channels = $this->children($root, 'channel', '');
            if ($channels !== []) {
                return $this->parseRss($channels[0], $feedUrl);
            }
        } elseif ($root->localName === 'feed' && (string)$root->namespaceURI === self::ATOM_NS) {
            return $this->parseAtom($root, $feedUrl);
        }
        throw new UnexpectedValueException('parse error');
    }

    // -----------------------------------------------------------------------
    // DB
    // -----------------------------------------------------------------------

    /**
     * トランザクションの中で処理を実行し、失敗したらロールバックします
     *
     * @param callable(): void $work トランザクション内の処理
     * @return void
     */
    private function inTransaction(callable $work): void
    {
        $this->db->beginTransaction();
        try {
            $work();
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * DBに接続し、テーブルがなければ作成します
     *
     * @return PDO DB接続
     */
    private function openDatabase(): PDO
    {
        $override = getenv(self::NEWS_DB_PATH_ENV);
        $dbPath = $override !== false && $override !== ''
            ? $this->resolvePath($this->baseDir, $override)
            : $this->baseDir . '/news.db';
        $dbDirectory = dirname($dbPath);
        if (!is_dir($dbDirectory) && !mkdir($dbDirectory, 0777, true) && !is_dir($dbDirectory)) {
            throw new RuntimeException($dbDirectory . ' を作成できません');
        }
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec(
            'CREATE TABLE IF NOT EXISTS feeds (
                id              INTEGER PRIMARY KEY,
                feed_url        VARCHAR(255) NOT NULL UNIQUE,
                title           VARCHAR(255),
                last_fetched_at DATETIME,
                last_result     VARCHAR(255)
            )'
        );
        $db->exec(
            'CREATE TABLE IF NOT EXISTS articles (
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
            )'
        );

        return $db;
    }

    /**
     * feeds.txtの絶対パスを返します
     *
     * @return string feeds.txtのパス(相対パスはスクリプトのディレクトリが基準)
     */
    private function resolveFeedsPath(): string
    {
        $override = getenv(self::NEWS_FEEDS_PATH_ENV);

        return $override !== false && $override !== ''
            ? $this->resolvePath($this->baseDir, $override)
            : $this->baseDir . '/feeds.txt';
    }

    /**
     * feeds.txtからURLと表示名の一覧を読みます
     *
     * 1行は「URL」または「URL 表示名」です。表示名を省略すると、フィード自身のタイトルを使います。
     *
     * @return array<string, string> URL(書かれた順。重複は最初の行を採用)をキー、表示名(なければ空文字)を値にした配列
     * @throws RuntimeException 読めない場合、またはURLが1つもない場合
     */
    private function readFeedNames(): array
    {
        $feedsPath = $this->resolveFeedsPath();
        $content = @file_get_contents($feedsPath);
        if ($content === false) {
            throw new RuntimeException($feedsPath . ' を読めません');
        }
        $names = [];
        foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 2) ?: [$line];
            $names[$parts[0]] ??= $parts[1] ?? '';
        }
        if ($names === []) {
            throw new RuntimeException('feeds.txt に有効なURLがありません');
        }

        return $names;
    }

    /**
     * feeds.txtとfeedsテーブルを同期し、feeds.txtの順にフィードの行を返します
     *
     * @param list<string> $urls feeds.txtのURL
     * @return list<array{id: int, feed_url: string, title: string|null, last_fetched_at: string|null}> feedsテーブルの行
     */
    private function syncFeeds(array $urls): array
    {
        $this->inTransaction(function () use ($urls): void {
            $registered = $this->db->query('SELECT feed_url FROM feeds')->fetchAll(PDO::FETCH_COLUMN);
            $insert = $this->db->prepare('INSERT INTO feeds (feed_url) VALUES (?)');
            foreach (array_diff($urls, $registered) as $url) {
                $insert->execute([$url]);
            }
            $remove = $this->db->prepare('DELETE FROM feeds WHERE feed_url = ?');
            foreach (array_diff($registered, $urls) as $url) {
                $remove->execute([$url]);
            }
        });
        $select = $this->db->prepare('SELECT id, feed_url, title, last_fetched_at FROM feeds WHERE feed_url = ?');
        $feeds = [];
        foreach ($urls as $url) {
            $select->execute([$url]);
            $feeds[] = $select->fetch();
        }

        return $feeds;
    }

    // -----------------------------------------------------------------------
    // 新着の判定
    // -----------------------------------------------------------------------

    /**
     * 取得結果を登録済みの記事と照合し、新着・更新・登録だけ・重複確認の対象に分けます
     *
     * @param list<array<string, mixed>> $results フィードごとの取得結果(feeds.txtの順)
     * @param DateTimeImmutable $now 実行日時
     * @return array<string, array<int, mixed>> 判定結果(inserts, updates, seenKeys, news)
     */
    private function classify(array $results, DateTimeImmutable $now): array
    {
        $select = $this->db->prepare('SELECT published_at, updated_at FROM articles WHERE match_key = ?');
        $initialCutoff = $now->modify('-' . self::INITIAL_DAYS . ' days')->format(self::DB_DATE_FORMAT);
        $handled = [];
        $inserts = [];
        $updates = [];
        $news = [];

        foreach ($results as $result) {
            $feedId = $result['feed']['id'];
            $isInitial = $result['feed']['last_fetched_at'] === null;
            foreach ($result['articles'] as $article) {
                if (isset($handled[$article['matchKey']])) {
                    continue;
                }
                $handled[$article['matchKey']] = true;
                $date = $this->articleDate($article['publishedAt'], $article['updatedAt']);
                $select->execute([$article['matchKey']]);
                $stored = $select->fetch();

                if ($stored !== false) {
                    $storedDate = $this->articleDate($stored['published_at'], $stored['updated_at']);
                    if ($storedDate !== null && $date !== null && $date > $storedDate) {
                        $updates[] = $article;
                        $news[] = ['feedId' => $feedId, 'article' => $article, 'date' => $date];
                    }
                    continue;
                }

                $inserts[] = ['feedId' => $feedId, 'article' => $article];
                $isRecent = self::INITIAL_DAYS === 0 || ($date !== null && $date >= $initialCutoff);
                if (!$isInitial || $isRecent) {
                    $news[] = ['feedId' => $feedId, 'article' => $article, 'date' => $date];
                }
            }
        }

        return [
            'inserts' => $inserts,
            'updates' => $updates,
            'seenKeys' => array_map('strval', array_keys($handled)),
            'news' => $news,
        ];
    }

    // -----------------------------------------------------------------------
    // news.mdの生成
    // -----------------------------------------------------------------------

    /**
     * Markdownのリンクが崩れないように、タイトルを整えます
     *
     * @param string $title 記事タイトル
     * @return string Markdown用のタイトル
     */
    private function escapeTitle(string $title): string
    {
        $oneLine = preg_replace('/\s*[\r\n]+\s*/', ' ', $title) ?? $title;

        return str_replace(['[', ']'], ['\\[', '\\]'], $oneLine);
    }

    /**
     * Markdownのリンクが崩れないように、URLを整えます
     *
     * @param string $url 記事URL
     * @return string Markdown用のURL
     */
    private function markdownUrl(string $url): string
    {
        return preg_replace('/\s/', '%20', str_replace(')', '%29', $url)) ?? $url;
    }

    /**
     * 出力本文を作ります
     *
     * @param list<array<string, mixed>> $results フィードごとの取得結果(feeds.txtの順)
     * @param list<array<string, mixed>> $news 新着
     * @param list<array{url: string, reason: string}> $failures 取得に失敗したフィード
     * @param DateTimeImmutable $now 実行日時
     * @param bool $autoescape HTMLならtrue
     * @return string 出力本文
     */
    private function renderNews(
        array $results,
        array $news,
        array $failures,
        DateTimeImmutable $now,
        bool $autoescape
    ): string {
        $local = $now->setTimezone(new DateTimeZone(self::TIMEZONE));
        $entriesByFeed = [];
        foreach ($news as $entry) {
            $entriesByFeed[$entry['feedId']][] = $entry;
        }
        $feeds = [];
        foreach ($results as $result) {
            $entries = $entriesByFeed[$result['feed']['id']] ?? [];
            if ($entries === []) {
                continue;
            }
            usort($entries, fn (array $a, array $b): int => $this->compareByDate($a['date'], $b['date']));
            $items = [];
            foreach ($entries as $entry) {
                $date = $entry['date'] === null ? '' : $this->toLocal($entry['date'])->format('Y-m-d');
                $items[] = [
                    'title' => $entry['article']['title'],
                    'url' => $entry['article']['url'],
                    'hasUrl' => $entry['article']['url'] !== '',
                    'isHttpUrl' => preg_match('/^https?:\/\//i', $entry['article']['url']) === 1,
                    'titleMarkdown' => $this->escapeTitle($entry['article']['title']),
                    'urlMarkdown' => $this->markdownUrl($entry['article']['url']),
                    'date' => $date,
                    'hasDate' => $entry['date'] !== null,
                    'dateSuffix' => $date === '' ? '' : ' (' . $date . ')',
                ];
            }
            $feeds[] = [
                'name' => $result['name'],
                'title' => $result['title'],
                'url' => $result['feed']['feed_url'],
                'entries' => $items,
            ];
        }
        $context = [
            'newsCount' => count($news),
            'fetchedAt' => $local->format('Y-m-d H:i'),
            'newsDir' => $this->resolveNewsDirSetting(),
            'feeds' => $feeds,
            'failures' => $failures,
            'hasFailures' => $failures !== [],
        ];
        $templatePath = $this->resolveTemplatePath();
        $loader = new FilesystemLoader(dirname($templatePath));
        $twig = new Environment($loader, ['autoescape' => $autoescape ? 'html' : false]);

        return $twig->render(basename($templatePath), $context);
    }

    /**
     * フィード内の並びを、記事の日付の順にします(日付なしは末尾)
     *
     * @param string|null $a 一方の日付(DB形式)
     * @param string|null $b 他方の日付(DB形式)
     * @return int 並べ替えの比較値
     */
    private function compareByDate(?string $a, ?string $b): int
    {
        if ($a === $b) {
            return 0;
        }
        if ($a === null) {
            return 1;
        }
        if ($b === null) {
            return -1;
        }

        return ($a <=> $b) * (self::NEWEST_FIRST ? -1 : 1);
    }

    /**
     * Twigが読み込める状態にします。不足している場合はComposerで導入します
     *
     * @param string $baseDir スクリプトのあるディレクトリ
     * @return bool 準備できた場合true
     */
    public static function ensureDependencies(string $baseDir): bool
    {
        $autoload = $baseDir . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
            if (class_exists(Environment::class)) {
                return true;
            }
        }
        if (!self::AUTO_INSTALL_DEPENDENCIES) {
            fwrite(STDERR, 'エラー: Twigがインストールされていません。rss-newsディレクトリで composer install を実行してください。' . PHP_EOL);

            return false;
        }
        $composer = self::findComposer($baseDir);
        if ($composer === null) {
            fwrite(STDERR, 'エラー: Composerが見つかりません。rss-newsディレクトリで composer install を実行してください。' . PHP_EOL);

            return false;
        }
        fwrite(STDERR, '依存パッケージ(Twig)を導入します。' . PHP_EOL);
        $command = sprintf(
            '%s install --working-dir=%s --no-dev --no-interaction --no-progress --no-scripts 2>&1',
            self::composerCommand($composer),
            escapeshellarg($baseDir)
        );
        passthru($command, $status);
        if ($status !== 0 || !is_file($autoload)) {
            fwrite(STDERR, 'エラー: Twigの導入に失敗しました。rss-newsディレクトリで composer install を実行してください。' . PHP_EOL);

            return false;
        }
        require_once $autoload;
        if (!class_exists(Environment::class)) {
            fwrite(STDERR, 'エラー: Twigを読み込めませんでした。rss-newsディレクトリで composer install を実行してください。' . PHP_EOL);

            return false;
        }

        return true;
    }

    /**
     * Composerの実行ファイルを探します
     *
     * @param string $baseDir スクリプトのあるディレクトリ
     * @return string|null 見つかったパス
     */
    private static function findComposer(string $baseDir): ?string
    {
        $candidates = array_filter([
            $baseDir . '/composer.phar',
            '/usr/local/bin/composer',
            '/usr/bin/composer',
            (string)getenv('HOME') . '/.composer/vendor/bin/composer',
        ]);
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        if (!function_exists('shell_exec')) {
            return null;
        }
        $found = trim((string)shell_exec('command -v composer 2>/dev/null'));

        return $found !== '' ? $found : null;
    }

    /**
     * Composerを実行するコマンド文字列を返します
     *
     * @param string $composer Composerのパス
     * @return string 実行コマンド
     */
    private static function composerCommand(string $composer): string
    {
        if (str_ends_with($composer, '.phar')) {
            return escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($composer);
        }

        return escapeshellarg($composer);
    }
}

$workDir = getcwd();
if ($workDir === false) {
    fwrite(STDERR, 'エラー: ワーキングディレクトリを取得できません' . PHP_EOL);
    exit(1);
}

if (!News::ensureDependencies(__DIR__)) {
    exit(1);
}

exit((new News(__DIR__, $workDir))->run());
