#!/usr/bin/env node

/**
 * Node.js版(zx)の統合テスト
 */

import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { DatabaseSync } from 'node:sqlite';

const testRoot = process.env.TEST_ROOT;
const feedUrl = process.env.FEED_URL;
if (!testRoot || !feedUrl) {
  throw new Error('TEST_ROOTとFEED_URLを設定してください');
}

const scriptPath = path.resolve(import.meta.dirname, '..', 'news.mjs');
const runtimeDir = path.join(testRoot, 'node');
const workDir = path.join(runtimeDir, 'work');
const feedsPath = path.join(runtimeDir, 'feeds.txt');
const dbPath = path.join(runtimeDir, 'news.db');
const outputPath = path.join(runtimeDir, 'news.md');
const newsDir = path.join(runtimeDir, 'news');
const firstCopyPath = path.join(runtimeDir, 'news.first.md');

fs.mkdirSync(workDir, { recursive: true });
fs.writeFileSync(feedsPath, `${feedUrl} Test Feed\n`);

const env = {
  ...process.env,
  NEWS_FEEDS_PATH: feedsPath,
  NEWS_DB_PATH: dbPath,
  NEWS_OUTPUT_PATH: outputPath,
  NEWS_DIR_PATH: newsDir,
};

/**
 * メインスクリプトを実行します
 *
 * @returns {void}
 */
function runScript() {
  const result = spawnSync('zx', [scriptPath], { cwd: workDir, env, encoding: 'utf8' });
  const output = `${result.stdout ?? ''}${result.stderr ?? ''}`;
  assert.equal(result.status, 0, output);
}

/**
 * articlesテーブルの件数を返します
 *
 * @returns {number} 記事数
 */
function articleCount() {
  const db = new DatabaseSync(dbPath);
  try {
    return Number(db.prepare('SELECT COUNT(*) AS count FROM articles').get().count);
  } finally {
    db.close();
  }
}

/**
 * 複製ファイルの件数を返します
 *
 * @returns {number} 複製ファイル数
 */
function copyCount() {
  return fs.readdirSync(newsDir).filter((name) => name.endsWith('.md')).length;
}

runScript();
const firstMarkdown = fs.readFileSync(outputPath, 'utf8');
assert.match(firstMarkdown, /新着1件/);
assert.match(firstMarkdown, /Recent Test Article/);
assert.doesNotMatch(firstMarkdown, /Old Test Article/);
assert.equal(articleCount(), 2);
assert.equal(copyCount(), 1);
fs.copyFileSync(outputPath, firstCopyPath);

runScript();
const secondMarkdown = fs.readFileSync(outputPath, 'utf8');
assert.match(secondMarkdown, /新着0件/);
assert.equal(articleCount(), 2);
assert.equal(copyCount(), 1);

console.log('node: OK');
