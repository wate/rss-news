#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * PHP版の統合テスト
 */

/**
 * テストを失敗させます
 *
 * @param string $message メッセージ
 * @return never
 */
function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

/**
 * 条件を確認します
 *
 * @param bool $condition 条件
 * @param string $message 失敗時のメッセージ
 * @return void
 */
function check(bool $condition, string $message): void
{
    if (!$condition) {
        fail($message);
    }
}

/**
 * メインスクリプトを実行します
 *
 * @param string $script スクリプトのパス
 * @param string $workDir ワーキングディレクトリ
 * @param array<string, string> $env 環境変数
 * @return array{0: int, 1: string} 終了コードと出力
 */
function runScript(string $script, string $workDir, array $env): array
{
    $baseEnv = getenv();
    if (!is_array($baseEnv)) {
        $baseEnv = [];
    }
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([PHP_BINARY, $script], $descriptors, $pipes, $workDir, array_merge($baseEnv, $env));
    if (!is_resource($process)) {
        fail('PHP版を起動できません');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    return [$status, (string)$stdout . (string)$stderr];
}

/**
 * 記事数を返します
 *
 * @param string $dbPath DBのパス
 * @return int 記事数
 */
function articleCount(string $dbPath): int
{
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return (int)$pdo->query('SELECT COUNT(*) FROM articles')->fetchColumn();
}

/**
 * 複製ファイル数を返します
 *
 * @param string $newsDir 複製ディレクトリ
 * @return int ファイル数
 */
function copyCount(string $newsDir): int
{
    return count(glob($newsDir . '/*.md') ?: []);
}

$testRoot = getenv('TEST_ROOT');
$feedUrl = getenv('FEED_URL');
if ($testRoot === false || $feedUrl === false || $testRoot === '' || $feedUrl === '') {
    fail('TEST_ROOTとFEED_URLを設定してください');
}

$rootDir = dirname(__DIR__);
$script = $rootDir . '/news.php';
$runtimeDir = $testRoot . '/php';
$workDir = $runtimeDir . '/work';
$feedsPath = $runtimeDir . '/feeds.txt';
$dbPath = $runtimeDir . '/news.db';
$outputPath = $runtimeDir . '/news.md';
$newsDir = $runtimeDir . '/news';
$firstCopyPath = $runtimeDir . '/news.first.md';

if (!is_dir($workDir) && !mkdir($workDir, 0777, true) && !is_dir($workDir)) {
    fail($workDir . ' を作成できません');
}
if (file_put_contents($feedsPath, $feedUrl . " Test Feed\n") === false) {
    fail($feedsPath . ' を書き出せません');
}

$env = [
    'NEWS_FEEDS_PATH' => $feedsPath,
    'NEWS_DB_PATH' => $dbPath,
    'NEWS_OUTPUT_PATH' => $outputPath,
    'NEWS_DIR_PATH' => $newsDir,
];

[$status, $output] = runScript($script, $workDir, $env);
check($status === 0, "PHP版が失敗しました: {$output}");
$firstMarkdown = file_get_contents($outputPath);
check($firstMarkdown !== false, $outputPath . ' を読めません');
check(str_contains($firstMarkdown, '新着1件'), '1回目の新着件数が不正です: ' . $firstMarkdown);
check(str_contains($firstMarkdown, 'Recent Test Article'), 'Recent Test Articleがありません: ' . $firstMarkdown);
check(!str_contains($firstMarkdown, 'Old Test Article'), 'Old Test Articleが出力されています: ' . $firstMarkdown);
check(articleCount($dbPath) === 2, '記事数が2ではありません');
check(copyCount($newsDir) === 1, '複製ファイル数が1ではありません');
if (!copy($outputPath, $firstCopyPath)) {
    fail($firstCopyPath . ' をコピーできません');
}

[$status, $output] = runScript($script, $workDir, $env);
check($status === 0, "PHP版が失敗しました: {$output}");
$secondMarkdown = file_get_contents($outputPath);
check($secondMarkdown !== false, $outputPath . ' を読めません');
check(str_contains($secondMarkdown, '新着0件'), '2回目の新着件数が不正です: ' . $secondMarkdown);
check(articleCount($dbPath) === 2, '2回目の記事数が2ではありません');
check(copyCount($newsDir) === 1, '2回目の複製ファイル数が1ではありません');

echo 'php: OK' . PHP_EOL;
