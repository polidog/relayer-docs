---
name: relayer-doc-update
description: Use when a new polidog/relayer version is released and the user signals it (e.g. "v0.16.0 出た", "relayer リリースされた", "新しい relayer のドキュメント更新して"). Runs the on-demand doc-update flow — diff the relayer changelog, reflect the change into the Turso doc store via bin/docs, bump composer, open a PR. Do NOT propose /schedule or /loop for this; it is on-demand only.
---

# relayer リリース → ドキュメント更新フロー

このリポジトリは relayer 自身で作る relayer ドキュメント（ドッグフーディング）。
記事本文は **Turso が唯一の正**、リポジトリに markdown は持たない。
背景は memory `relayer-release-doc-update-flow`（なぜ）／このスキルは手順（どうやる）。

## いつ使う / 使わない

- 使う: ユーザーが「vX.Y.Z 出た」「relayer リリースされた」「ドキュメント更新して」と合図したとき。
- **`/schedule` も `/loop` も提案しない**。リリース監視の自動化はユーザーが明確に却下済み。完全にオンデマンド。
- バージョン callout は **実タグに一致**させる。推測したら明示して確認を取る。

## パス

- relayer チェックアウト: `/home/polidog/ghq/github.com/polidog/relayer`
- このリポジトリ: カレント。`bin/docs` が Turso を直接読み書き（`.env.local` に `TURSO_*`）。

## 手順

### 1. 差分解析

```bash
cd /home/polidog/ghq/github.com/polidog/relayer && git fetch --tags --quiet
git tag --sort=-creatordate | head -4          # 最新タグ
```
このリポジトリの現行制約を確認: `grep -nE '"polidog/relayer":' composer.json` →
`^0.X.Y`。`<前タグ>..<新タグ>` を解析:

```bash
git log --oneline vA..vB
git diff --stat vA..vB | tail -30
git diff vA..vB -- README.ja.md            # ★ ここが機能把握の起点
```

実 API/挙動が要るときは該当 `src/...` も読む（例: 契約クラス、Scaffold の生成物）。
0.x キャレットはマイナー固定なので、`vB` を引くには制約の移動が必須。

### 2. 反映先と文面

`mise exec -- php bin/docs list` で既存スラッグを確認し、機能に対応する
1〜数ページを選ぶ。ハウススタイル:

- 日本語・簡潔。既存セクションは保持し、追記する。
- バージョン callout は **そのページの既存スタイルに合わせる**:
  - `> **vX.Y.Z で追加。** …`（http-cache / logger 等の callout 形式）
  - または `- **vX.Y.Z** から、…`（cli ページの bullet 形式）
- `/docs/<slug>` クロスリンク、必要なら表。
- 能力が実質拡張されたら frontmatter `description` も更新（簡潔に）。
- frontmatter キーは `title/description/category/order` のみ。

### 3. Turso へ反映

`mise exec -- php bin/docs show <slug> > /tmp/<slug>.md` で取得 → 編集 →

```bash
mise exec -- php bin/docs edit <slug> --file /tmp/<slug>.md \
  --note "vX.Y.Z の〇〇を追記"
```

`--note` は **毎回付ける**（変更履歴に意図が残る。PR #28 で追加）。
`updated: <slug> -> Turso (remote)` を確認。`-> ... (local)` が出たら
`.env.local` の `TURSO_*` が読めていない（bin/docs は Dotenv 経由）。

### 4. composer バンプ + PR

```bash
git checkout -b chore/bump-relayer-0.X
# composer.json: "polidog/relayer": "^0.X.0"
mise exec -- composer update polidog/relayer --with-dependencies
```

`vA => vB` と新規 transitive 依存を確認。差分は `composer.json`/`composer.lock`
のみ（`public/usephp.js` は post-update で再生成されるが不変なら status に出ない）。
コミット（末尾に `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`）→
push → `gh pr create`。**自動マージしない**。ユーザーが「マージして」と言ったら
`gh pr merge <n> --merge --delete-branch` → `git checkout main && git pull`。

PR 本文の骨子（日本語）:

```
## What
polidog/relayer ^0.A.0 -> ^0.B.0（0.x キャレットはマイナー固定）。
## vB の変更
（relayer PR #NN 由来。要点を箇条書き）
## ドキュメント
/docs/<slug> に「…」を追記済み（callout `vB で追加。`）。本文は Turso が
唯一の正でこのリポジトリに markdown は無いため、反映はこの PR の差分外。
## 差分
composer.json / composer.lock のみ。
🤖 Generated with [Claude Code](https://claude.com/claude-code)
```

### 5. スキーマ変更を伴う場合のみ（稀）

doc ストアのスキーマが変わる変更（例: revisions/summary 列）は
`bin/docs migrate` が **本番 Turso への書き込み**。冪等・追記的でも
**勝手に実行しない** — auto モード分類器がブロックする高影響操作。
ユーザーの明示指示があってから実行し、`bin/docs` と同一 bootstrap
（Dotenv→ContainerBuilder→AppConfigurator→services.yaml）で読み取り検証する。

## 過去の実例

- PR #20: `^0.12.1→^0.13.0`（DML ヘルパー → /docs/database）
- PR #22: `^0.13.0→^0.14.0`（Firebase/Cognito トークン認証 → /docs/authentication、`firebase/php-jwt` 追加）
- PR #27: `^0.14.0→^0.15.0`（scaffold: .claude/ skill+subagent → /docs/cli）

## 完了チェック

- [ ] `git diff vA..vB -- README.ja.md` を読んだ
- [ ] 正しい doc スラッグに、既存スタイルの callout で追記
- [ ] `bin/docs edit --file --note` で Turso 反映、`-> Turso (remote)` 確認
- [ ] composer `^0.B.0`、`vA => vB` 確認、`chore/bump-relayer-0.B` で PR
- [ ] 自動マージしていない / `/schedule`・`/loop` を提案していない
