# GPT-6 Astra 設計・実装レビューと修正計画

## 1. 結論と対象

**現在の構成は維持できるが、索引の欠損を成功扱いする経路、並行処理の整合性、日本語チャンク、プロバイダー設定の保存を優先して修正する必要がある。全面的な再設計は不要。**

| 項目 | 内容 |
| --- | --- |
| レビュー日 | 2026-09-18 |
| レビューモデル | GPT-6 Astra |
| 対象 | RiTriever 0.2.4 |
| 基準コミット | `51f20c04b5993da0154bbcc3b52ee52ae5743009` |
| 対象範囲 | bootstrap、設定、索引作成・更新、DB、キュー、検索、管理画面、embedding adapter、JavaScript、uninstall、配布・検証スクリプト、既存設計書 |
| 今回の成果物 | レビュー結果と修正計画のみ。製品コード・依存関係・DB・配布物は変更しない |
| 計画レビュー | 第8節に記録。実装完了や本番リリースの承認とは区別する |

GPT-6 Astra は今回の**レビュー担当モデル**であり、製品の埋め込みモデルを GPT-6 Astra に変更する計画ではない。過去の開発モデルではなく、現行コードと再現結果を根拠に判断した。

### 維持する設計

- MariaDB native `VECTOR` / `VECTOR INDEX`、embedding provider と repository の分離、RRF による検索統合。
- bulk 処理のトランザクション・deadlock retry の方向性、キューの claim 更新、検索結果の適格性確認。足りない失敗判定・競合制御を補う。
- MySQL native vector search を既定で無効にする方針。今回の修正で対応 DB を拡大しない。
- 管理操作の capability / nonce 確認、API キーを画面へ再表示しない処理、診断出力の escaping、既存の配布チェック。

`plan/` は初期設計として参照したが、現行実装との差がある。例えば `shadow` モードや未実装扱いのバックグラウンド処理を、そのまま今後の要件にはしない。以下を修正の基準とし、実装時に該当する既存文書も更新する。

## 2. 調査方法と検証限界

本番クラス／メソッドを読み込み、WordPress・HTTP・DB・DOM をスタブ化した PHP / JavaScript の局所再現を行った。以下の「再現済み」はこの範囲を意味し、実 WordPress / MariaDB での統合試験済みという意味ではない。再現の入力・故障条件・出力は各項目に記載した。局所ハーネスは製品のテストスイートにはまだ組み込まれていない。

| 確認 | 結果 |
| --- | --- |
| PHP 8.5.10 による bootstrap / uninstall / `includes/` の構文検証 | 成功 |
| `composer validate --strict --no-interaction` | 成功 |
| `vendor/bin/phpcs --standard=phpcs-security.xml.dist --report=summary` | 成功 |
| `make i18n-pot-check release-audit wordpress-org-assets-audit review-audit` | 成功 |
| `scripts/*.sh` の `sh -n`、管理 JavaScript の構文確認 | 成功 |
| 実 WordPress / MariaDB / WP-Cron / WP-CLI / 永続 object cache | 未実行 |
| 実 embedding provider、ブラウザー、検索品質・負荷測定 | 未実行 |

この環境には Docker CLI がなく、既存の Apple Container 環境にも変更を加えていない。外部埋め込み API を呼ばず、秘密情報・実サイトの投稿データを再現入力に使用していない。モデル固有の公開 API / model card の確認と、実サービスへの投稿送信は区別した。

既存の smoke test は正常系のチャンク数・バッジ等を確認するが、今回の故障注入、再初期化、並行処理、検索条件の組み合わせを保証しない。静的チェック成功を機能上の正しさの証明とはしない。また、本レビューは網羅的な侵入試験・専用の脆弱性監査ではない。

## 3. 指摘一覧

P1 はデータ整合性・主要機能を阻害する優先修正、P2 は機能契約・運用上の修正。マルチサイト限定の問題を単一サイト全体の阻害要因とは扱わない。行番号は基準コミットに対するもの。

| ID | 優先度 | 指摘 | 根拠 |
| --- | --- | --- | --- |
| R01 | P1 | 再初期化・再公開・再試行が残存 hash でスキップされ、空の索引を成功扱いする | 再現済み |
| R02 | P1 | 非原子的な単件置換、SQL / COMMIT 失敗後の成功メタ更新 | 再現済み |
| R03 | P1 | 日本語等のチャンクが UTF-8 の途中で切断される | 再現済み |
| R04 | P1 | キューが次回実行を失い、running のまま停止する | 再現済み |
| R05 | P1 | 期限切れ lease・古い worker・古い編集が新しい状態を上書きする | 再現済み＋世代変更経路の静的追跡 |
| R06 | P1 | 保存・読込・画面初期化で実際の接続先がプリセットに戻る | PHP / JavaScript で再現済み |
| R07 | P2 | embedding 応答・HTTP エラーの検証不足と preflight の誤成功 | 再現済み |
| R08 | P2 | 元の検索制約・除外検索・障害時の標準検索を保持できない | 再現済み |
| R09 | P2 | 永続 object cache の無効化と uninstall 清掃が不完全 | 再現済み |
| R10 | P2 | DDL / キュー準備失敗を完了判定に反映できない | index DDL 失敗は再現済み、投入失敗経路は静的確認 |
| R11 | P2 | 選択した meta / taxonomy の変更を同期イベントが拾わない | 静的確認、保存順序は統合試験が必要 |
| R12 | P2 | 翻訳一覧の可用性が embedding 入力と保存言語の選択に影響する | 入力差・選択肢欠落を再現済み |
| R13 | P2 | 一括再試行の件数と管理画面の非同期状態が不正確 | PHP / JavaScript で再現済み |
| R14 | P2 | バッジ無効・対象外でもタイトルを変更する | 再現済み |
| R15 | P2・条件付き | `switch_to_blog()` 相当の切替後も別サイトの設定が残る | 設定ストア切替スタブで再現、実 multisite は未検証 |

### R01: hash は現在の索引への保存証明になっていない

**箇所:** `includes/BackfillRunner.php:45-57`、`includes/BulkBackfillIndexer.php:52-71`、`includes/PostSync.php:41-68`、`includes/Plugin.php:73-114`。入口は `includes/Admin/SettingsPage.php:159,407` と `includes/CLI/BackfillCommand.php:45-46`。

初期化はテーブルを再作成するが、投稿メタの hash を残す。処理側は hash 一致だけでスキップする。対象外投稿の削除後にも hash が残る。再現では初回 `rows=1` に対し、同内容での再構築は `rows=0, embedding_calls=0, errors=0`。`publish → draft → publish` でも `rows=0` となった。管理画面の初期化・再試行にもこれを補う強制処理はない。

さらに同期 hash は主に text / locale / model / dimensions で構成され、endpoint・chunk 方式等を十分に区別しない。設定更新 hook も endpoint / format / chunk サイズ / 投稿対象・除外の変更に反応しない。distance 変更はテーブルを再作成する一方、hash は変わらない。

**修正:** content hash と「現世代に保存済み」の状態を分離する。世代・抽出／embedding fingerprint・保存実体を確認し、再構築／欠損修復は通常の変更検出と区別する。対象外削除時の同期メタ、再試行後の queue item / job 状態も整合させる。

**受入条件:** 同内容で初期化を2回行って索引件数が維持される。再公開・欠損行の再試行で復旧する。成功通知には現世代の実体が必要。変更種別と無効化の契約は第4節に従う。

### R02: 保存原子性と成功メタの境界

**箇所:** `includes/Database/LocalVectorRepository.php:27-63,78,130-135`、`includes/PostSync.php:75-93`、`includes/BulkBackfillIndexer.php:152-169`。

単件処理は DELETE → INSERT をトランザクションなしで行い、返り値を確認しない。bulk も START / COMMIT の失敗を確認しない。INSERT を失敗させると `rows=0, hash_advanced=YES, error_meta=null`、COMMIT を失敗させても `errors=0, hash_advanced=YES` となった。

**修正:** R07 の検証後に、単件・bulk 共通の原子的置換を実行する。DELETE / INSERT / トランザクション境界を検査し、保存を確認できた場合だけ成功状態を進める。SQL エラーを明示的に通知し、旧データを先に不可逆に失わない。

**受入条件:** DELETE、N番目の INSERT、START、COMMIT の故障注入で成功メタが進まない。実 MariaDB で旧チャンク一式または新チャンク一式のみが見える。COMMIT 時の接続断は成否不明として再照合し、単純な「失敗なら必ず rollback 済み」と決め付けない。チャンク保存と WordPress メタ更新の間で停止しても、再試行で正しい状態へ収束する。

### R03: UTF-8 を保持するチャンク化

**箇所:** `includes/PostSync.php:193-206`。

`*_chars` 設定に対して `strlen()` / `substr()` を使っている。`str_repeat('日本語', 1000)`、既定 `2400/250` では、チャンク 1・2・4 が不正 UTF-8 となり、ネイティブ `json_encode()` が失敗した。WordPress の `wp_json_encode()` は修復を試みるため、実 HTTP 本文の生成失敗まで実証したとは扱わない。

**修正:** 文字境界を維持する分割と overlap に変更し、設定の単位を明記する。mbstring 非搭載でも動く方式を選ぶか、必要条件を明示して処理開始前に検出する。chunk アルゴリズム版を fingerprint に含める。

**受入条件:** 日本語・絵文字・ASCII 混在、境界長、極端な overlap でも全チャンクが妥当な UTF-8。入力の欠落、無限ループ、JSON 修復への依存がない。方式変更後は R01 により旧チャンクを再生成する。

### R04: キューの永続的な再実行保証

**箇所:** `includes/BackfillRunner.php:127-129,160-165,187-197,661-665`、`includes/CLI/BackfillCommand.php:65-71`。

ロック競合、一時的な API 例外、processing item の残存で次回イベントを確保しない。単発 cron 消費後を模擬すると、いずれも `status=running, future_events=0` となった。CLI の全件実行も進捗がなくても即座にループし得る。

**修正:** active job に対する次回起動または lease 回収の watchdog を保証する。retryable / permanent error、試行上限、backoff、停止理由を区別する。CLI は進捗なしを検出し、待機または説明付きで終了する。

**受入条件:** 429 後、worker 中断後、ロック競合後に管理画面を開かなくても回復する。paused / cancelled は再開しない。次回予定・試行回数・停止理由を確認できる。WP-Cron はアクセスまたは外部 scheduler の起動が必要であり、その運用条件を文書化する。

### R05: lease と書き込みの世代・改訂確認

**箇所:** `includes/BackfillRunner.php:135-147,232-270,687-705,750-775`、`includes/PostSync.php:48-81`、`includes/BulkBackfillIndexer.php:45-52,152-169`。

期限切れ lease の read → delete → add と、読んだ job status に対する無条件更新が競合する。外部 API の応答後にも、投稿・適格性・世代を再確認しない。再現では lease を2 worker が取得し、cancel 済み job が旧 worker により complete へ戻り、古い保存処理が新しい投稿内容を上書きした。

**修正:** 所有 token 付きの原子的 lease 操作、条件付き状態遷移、commit 境界での job / index 世代と投稿の現在内容・適格性の確認を行う。確認後から書き込みまでの競合も防ぐ。新しい改訂が未処理なら再 enqueue する。TTL を延ばすだけの対処はしない。

**受入条件:** 期限超過、重複取得、cancel、再初期化、並行編集、非公開化・削除の直後に旧応答を返しても、失効した処理が新状態へ書き込まない。別 worker の lease を解放しない。R01 の世代切替と一体で提供する。

### R06: プリセットは入力補助であり永続設定ではない

**箇所:** `includes/Settings.php:188-202,289-307,357-370`、`assets/admin-settings.js:25-30,60-70,81-94`、`includes/Admin/SettingsPage.php:634-680`。

独自 endpoint / model / dimensions を指定しても、保存・読込時にプリセットが上書きする。実際の Settings → Factory → Provider を通した再現で、Azure は `YOUR-RESOURCE` / `YOUR-DEPLOYMENT`、Ollama 等は `host.docker.internal` へ戻った。画面初期化にも上書きがあり、provider 切替直後は項目が新しい既定値に揃わない。`custom_http + custom` 以外では回避できない組み合わせがある。

**修正:** プリセットは明示的な選択時・初回の未設定項目にのみ適用する。名前付き provider でもカスタマイズを保持する。未置換 Azure placeholder は送信前にエラーにする。

**受入条件:** Azure・各 local provider・Custom HTTP で編集可能な endpoint / model / dimensions が保存・再読込・無関係な設定保存後も維持され、切替時には対応する既定値が即時表示される。OpenAI は選択可能な model と既存の dimensions 対応を維持し、自由入力の endpoint を追加するものではない。既に失われた接続先は自動復元できないため、移行案内で再入力を求める。

### R07: embedding と HTTP の契約を共通化する

**箇所:** `includes/Embedding/OpenAiEmbeddingProvider.php:66-78`、`includes/Embedding/CustomHttpEmbeddingProvider.php:42-85`、`includes/Admin/SettingsPage.php:272-284`。

`data[].index` を無視した順序依存、短い batch、非数値の `floatval()` 変換を許す。2入力に対する1ベクトルや `["not-a-number", null]` が通り、設定1536次元に対して2次元でも preflight は成功した。custom provider は HTTP 503 のベクトル付き応答を受理し、401 の具体的な原因を「no embeddings」に置き換える。

**修正:** 個数・順序・index の一意性／完全性・次元・有限数値を共通 validator で確認する。index がない既存 positional Custom HTTP 形式は仕様として維持する。非2xx を先に拒否し、安全な status / error code / retry 情報を伝える。入力の UTF-8 と JSON 化の成否も確認する。

**受入条件:** 逆順 index は正しく対応付け、欠落・重複・範囲外 index、短い batch、不正数値、次元違いを保存前に拒否する。401 / 429 / 503 と構造化・文字列 error envelope を検証する。preflight・索引・検索が同じ契約を使い、秘密値や投稿本文をエラーログへ出さない。コサイン用ゼロベクトルの扱いも DB の実挙動に合わせて定義する。

### R08: 元の検索契約を維持する

**箇所:** `includes/SearchInterceptor.php:74-96,137-150,171-234,274-287,331-337,413-431,476-509`、`includes/Database/LocalVectorRepository.php:216-239`、`includes/Provider/LocalVectorProvider.php:23-43`。

再現した問題は次の3種類。

| 条件 | 観測 |
| --- | --- |
| 元の `post__in=[2]` | 書換え後 `[1,2,3]`。author / post_type の異なる検索でも cache key が衝突 |
| `apple -banana`、apple のみを含む標準検索の候補 | 独自二次判定が banana も要求して候補を除外 |
| DB 検索失敗、または provider 障害 | SQL 失敗が `ok=true, hits=0`。標準検索100件でも `top_k=3` の候補だけに書き換え、通常 TTL で cache |

最終 WordPress SQL に残る制約は適用されるため、cache 衝突だけで private 投稿の漏洩とは断定しない。一方、包含制約の拡大、候補欠落、障害時の総件数・後半ページ消失は修正対象。

**修正:** 元 query context を書換え前に保存し、候補生成・適格性・cache key に同じ条件を使う。包含条件は積集合とし、除外条件も維持する。利用者依存・外部 hook 依存で条件を特定できない場合は共有 cache を回避する。独自トークン判定で WordPress が返した標準検索候補を否定しない。除外演算子等を正しく扱えない検索は、まず元の標準検索へ戻す方針とする。

DB / API 障害は成功ゼロ件と区別して伝播し、元 query を変更せず標準検索へ戻す。縮退結果を通常の長期 cache に入れない。

**受入条件:** author / post_type / meta / date / tax / 包含・除外ID / 検索列の組み合わせと cache の温め順で結果が変わらない。除外語・stopword・sentence / exact の扱いを標準検索と比較する。障害時の ID・総件数・ページ数はプラグインによる書換えを無効にした場合と一致し、復旧後は正常検索を再試行する。

正常時の bounded hybrid 検索自体を「全標準検索結果を必ず返す」仕様へ拡大するものではない。正常時の候補上限と障害時の完全な標準検索復帰を分けて文書化する。

### R09: cache backend に依存しない無効化と清掃

**箇所:** `includes/SearchInterceptor.php:463-473,511-553`、`uninstall.php:25-31,64-95`。

キー一覧を option に持つ一方、purge は DB の transient 行だけを列挙する。object cache に値だけがある再現では `before=1, purged=0, after=1`。uninstall も一覧を先に削除するため、登録済み transient を残した。

**修正:** まず登録キーを WordPress API で削除してから管理情報を消す。並行検索と無効化の取りこぼしにはサイト別 cache 世代を用い、旧世代を書き戻しても参照されないようにする。世代管理は論理無効化であり、物理削除・期限管理の代替とはしない。live-query transient の追跡と清掃も含める。

**受入条件:** DB / 永続 object cache 両方で投稿・設定更新直後に旧結果が使われない。旧 worker による cache 再作成も無効化を越えない。uninstall で追跡中の plugin 所有キーを削除し、他サイト・他プラグインの cache を flush しない。旧版で追跡不能なキーは失効まで残る可能性を明示する。

### R10: schema と job の完了を実体で判定する

**箇所:** `includes/Database/VectorSchema.php:27-44,80-84`、`includes/BackfillRunner.php:45-97,337-357,612-665`。

vector index の DDL を失敗させても `job_status=complete, completion_flag=true` となった。投入途中の SQL 失敗では部分 job を残し得る。ログだけでは正常完了との矛盾を解消できない。

**修正:** schema 操作は明示的な成功／失敗を返し、job の準備完了前には処理可能にしない。想定対象数、終端 item 数、失敗数、現世代、必要な vector index を検証して完了にする。エラー付き終了を成功完了と区別する。診断も旧世代・欠損・SQL 失敗を正常 coverage と表示しない。

**受入条件:** CREATE / ALTER / COUNT / N件目 queue INSERT の失敗で成功完了にならず、原因と安全な再試行方法が表示される。dimension / distance / index の実体を確認する。既存 job の不整合は移行時に再照合し、推測で complete に補正しない。

### R11: 最終的な投稿内容を索引に反映する

**箇所:** `includes/PostSync.php:19-27,129-159`。

同期 hook は `save_post` / `before_delete_post` のみだが、索引には指定 meta と term 名を含める。本文を保存しない meta 更新・削除、term 付替え・改名・削除には反映契機がない。REST 等では保存後段で meta / term が変わる経路もあるため、実 WordPress で確認が必要。

**修正:** 最終保存状態と、設定対象の meta / taxonomy 変更を既存キューへ集約する。自己管理メタによる再帰を防ぎ、同一操作・同一投稿の重複実行を抑える。大量の term 改名は関連投稿を分割して再同期する。

**受入条件:** 本文を変えず各変更を行っても現行内容へ収束する。REST / 通常編集 / WP-CLI の順序を確認し、非対象 meta は外部送信を発生させない。R05 により、後から返った古い同期結果は採用しない。

### R12: embedding の言語と表示ラベルを分離する

**箇所:** `includes/LanguageOptions.php:46-105,119-133`、`includes/Admin/SettingsPage.php:498-507,1335-1346`。

同じ `ja` でも翻訳一覧があると prefix は `日本語 / Japanese - ja (ja)`、ないと `ja (ja)` となる。英語サイトで保存値が `ja` のとき、一覧取得失敗後の select は `site,en_US` のみになる。ブラウザーが既定の site を送ると、無関係な保存で言語変更・索引再作成へ進む経路がある。

**修正:** embedding context は安定した locale 識別子と明示的な版で構成し、翻訳 API の表示名を使わない。保存済み locale は一覧にない場合も fallback ラベルで選択可能にする。`target_locale=site` の実効言語変更は fingerprint に反映する。

**受入条件:** 一覧取得成功・失敗・ラベル変更でも同じ入力が byte-for-byte 一致し、無関係な保存が保存済み locale を変えない。実効 locale または context 版の変更時には明示的に再構築する。併せて P3 の表示修正として、管理画面 fallback はサイト言語ではなく管理者の実効言語を使う。これは embedding 言語とは分離し、表示変更だけでは再索引しない。

### R13: 管理画面の再試行と状態機械

**箇所:** `includes/Admin/SettingsPage.php:388-420,941-952`、`includes/IndexDiagnostics.php:133-146`、`assets/admin-backfill.js:140-148,165-167,189-226`。

201件の失敗投稿で「全件再試行」は200件だけ実行し、残り1件でも `errors=0` を表示した。初回 status 通信失敗後は worker 開始を予約するだけで、idle のガードに阻まれて再取得しない。pause 成功後に古い running 応答が到着すると、実際は停止中なのに Resume が無効となった。

**修正:** 全件再試行は対象を漏らさない bounded queue とし、再試行対象の snapshot・進捗・真の残件数を表示する。個別再試行は維持する。status 取得を直接 retry し、制御操作の世代・request ID で古い応答を捨てる。R01 / R04 / R05 / R10 と完了・停止状態を共有する。

**受入条件:** 201件以上、継続失敗混在でも各対象を処理し、低い ID が取り残されない。初回通信失敗から回復する。pause / cancel 前の遅延応答で操作状態が戻らず、resume で worker loop が多重化しない。再試行成功時はベクトル・投稿メタ・queue item の状態が一致する。

### R14: 装飾しないタイトルはそのまま返す

**箇所:** `includes/SearchInterceptor.php:111-127`。

バッジ無効または source なしでも `esc_html($title)` を返す。再現では `<em>Book</em>` が `&lt;em&gt;Book&lt;/em&gt;` となり、RiTriever が装飾すべきでない他の出力にも影響する。

**修正:** 非対象は入力をそのまま返す。RiTriever が生成するバッジと装飾対象の出力境界に限って、安全な escaping を行う。

**受入条件:** 検索 off、global 停止、バッジ off、検索外、管理画面で入力が byte-for-byte 一致する。装飾対象では意図した安全なバッジのみ追加される。`Makefile` の文字列ベース audit も、単に新しいコード断片を要求するのではなく、この動作回帰試験と整合させる。

### R15: サイト切替時の状態分離

**箇所:** `includes/Settings.php:81-95`、`includes/Plugin.php:32-63`、`ritriever.php:66-74`。

設定 cache が blog ID を持たない。site 1 が `full`、site 2 が `off` の設定ストアへ切り替えた再現でも、`Settings::get()` は `full` を返した。担当範囲に `switch_blog` による無効化 hook はなく、repository の table prefix は現在サイトに追従するため、設定と保存先の対応が崩れ得る。

**修正:** サイト依存 cache を blog ID で分離または switch 時に無効化する。network activation / deactivation、新規サイトの schema 準備、サイト別キュー・cron の扱いを明示する。未対応部分を黙って有効にせず、サポート範囲と管理者への通知を用意する。

**受入条件:** 実 multisite で site 1 → site 2 → restore を行い、設定・provider・table・cache がそれぞれのサイトと一致する。サイト間で queue / 設定 / transient を混用しない。単一サイトでの既存動作も維持する。

## 4. 修正後の不変条件と設定変更の扱い

個別の hash 削除や timer 追加だけでは、再構築中の古い worker を止められない。次の契約を共通化する。ただし、新しい汎用 job framework や全面的な DB 抽象化は導入しない。

| 変更・状態 | 必要な処理 |
| --- | --- |
| provider / 実効 endpoint・deployment / model / dimensions / request format | embedding fingerprint を更新し、旧世代を検索・書込から分離して再索引 |
| 実効 locale / context 版 / chunk 方式・サイズ・overlap / 抽出設定 | 抽出・embedding fingerprint を更新して再索引 |
| distance / vector index M | 可能なら embedding を保持して index を再構築。破壊的変更が必要なら明示的な世代変更と再索引 |
| 投稿 type / status / 除外ID / password / 削除 | 対象外データ削除、新規対象の同期、cache 無効化。進行中 worker の適格性を再確認 |
| ranking / 検索正規化 / cache 設定 | 検索 fingerprint / cache 世代を更新。embedding 入力にも影響する変更だけ再索引 |
| API キーのローテーション | 秘密値自体を fingerprint やログへ含めない。接続確認を行い、同一 embedding identity なら不要な再索引をしない |
| 成功保存 | 現世代・現投稿内容・検証済みベクトル・永続化確認のすべてを満たす |
| job 完了 | 対象集合と item の終端状態、失敗数、必要 index、世代が整合する |

fingerprint は単一の共通生成処理を通常同期・bulk・検索・preflight・診断で使う。意味の異なる content hash / embedding identity / job 世代 / query cache 世代を混同しない。秘密を含む URL をそのまま保存・表示せず、endpoint identity の正規化と認証情報の分離を定義する。

索引の準備中・不整合・失敗中は標準検索へ戻す。既存索引を保持できても、新しい設定の query embedding を古い空間へ投げない。正常時の近傍探索、RRF、既存の provider 形式は維持する。

## 5. 実装順序

各作業で先に不具合を再現する回帰試験を追加し、修正後の通過を確認する。以下は実装順序であり、各段階を個別に公開するリリース順序ではない。PR を分割しても、embedding 入力・世代・worker・schema・検索 gate の契約を一部だけ本番へ出さない。

| 段階 | 実装範囲 | 依存・完了条件 |
| --- | --- | --- |
| M0: 回帰基盤 | PHP の WP / HTTP / DB スタブ、JS の非同期 fixture、隔離した実 WP / MariaDB テストの入口を追加 | 既存 Composer / Makefile の流れに統合。今回の代表的な失敗を修正前に再現 |
| M1: 入出力境界 | R03・R06・R07。言語 context の安定仕様 R12 も確定 | M0。既存 positional / OpenAI-compatible / Ollama / Azure 形式を維持し、入力と応答を検証 |
| M2: 保存整合性 | R02、R01 の fingerprint / 保存世代、R10 の schema・準備完了判定 | M1。旧 hash を信用しない移行と接続断時の回復を実 DB で確認。構築中・不整合時に元の標準検索を保持する gate もここで追加 |
| M3: worker 整合性 | R04・R05、R13 の queue 側、R11 の変更イベント集約 | M2。cancel / rebuild / 並行編集で旧書込を排除。M1 の入力変更・M2 の移行と同じリリース境界 |
| M4: 検索と cache | R08・R09・R14、診断の世代・失敗表示 | M2 / M3。標準検索復帰、条件別 cache、無効化競合、表示の非干渉を確認 |
| M5: 管理とサイト境界 | R12 の言語 UI・表示、R13 の JS・件数表示、R15 | M1〜M4。管理画面・CLI・cron で状態が一致し、multisite 境界を確認 |
| M6: 統合・文書・配布 | 下記の検証 matrix、移行リハーサル、README / readme / `plan/` の同期 | 未解決の P1 がなく、公開する機能範囲の受入条件を満たしてから release |

初回の修正リリースは原則 M0〜M6 を通して提供する。少なくとも M1 の chunk / context / provider identity を変える修正を、M2〜M4 の世代管理・worker 排除・検索復帰・cache 無効化より先に公開しない。P2 でも R07 / R10 のように P1 の正しい修正に必要な項目は先送りしない。R15 の対応を別リリースにする場合は、未対応な network / site-switch 操作の範囲を明示し、安全側の gate と管理者通知を先に用意する。

### 正式な検証 matrix

| 領域 | 必須の組み合わせ・故障条件 |
| --- | --- |
| Runtime | 宣言上の最小 PHP 8.1 / WordPress 6.6、既存 baseline 7.0.4、stable 7.1。対応可能な PHP / WP の組合せで最小と安定版を網羅 |
| DB | MariaDB 11.7 および既存環境の 11.8 系。MySQL は既定で native vector 無効の fallback を維持 |
| 保存 | 単件・bulk、全 SQL 境界の失敗、接続断、再初期化2回、状態往復、欠損修復、同一投稿の並行更新 |
| Queue | 複数 worker、lease 期限切れ、429 / 503 / 恒久エラー、cancel / pause / resume、再構築、worker 中断、201件以上の再試行 |
| 検索 | 元 query 条件、除外構文、成功ゼロ件、DB / API 障害、復旧、ページング、候補上限、バッジ非対象 |
| Cache / Site | DB transient、永続 object cache、並行 purge、uninstall、単一サイト、multisite の switch / restore |
| Provider / UI | 各 provider の request fixture、index 順序・次元・不正応答、設定の round-trip、翻訳一覧障害、遅延 AJAX 応答 |

既存の `make wordpress-compat-*`、Plugin Check、構文・PHPCS・i18n・package の gate は残す。新しいテストは実投稿・API キーを必要としない fixture を基本とし、実 provider の確認が必要な場合だけ明示的に opt-in する。全 stack は専用 project / volume / port を用い、既存環境に対する reset や uninstall を実行しない。

## 6. 移行・ロールバック

1. 対象サイトの settings、投稿メタ、vector table、queue のバックアップを取り、現行 schema・件数・進行中 job を記録する。バックアップに含まれる API キーは通常の秘密情報として管理する。
2. 旧 worker を停止・失効させ、標準検索への切替を確認してから schema / 状態を移行する。停止確認にはブラウザーを閉じるだけでなく、cron / CLI / 実行中 HTTP worker も含める。
3. 旧 hash の存在を現世代の保存済みフラグへ変換しない。新しい fingerprint・世代を付与し、必要な再索引を明示的に開始する。設定保存だけで無警告の全削除・有料 API 全件再実行を起こさない。
4. 対象件数、成功・失敗数、保存実体、vector index、検索回帰を確認してから新世代を有効にする。不完全なら標準検索を継続し、再試行可能な状態と原因を表示する。
5. 失敗時は worker を止めて標準検索を維持し、コードと DB / options / メタを**整合した組**で復元する。コードだけを戻して新旧 schema を混在させない。破壊的な次元変更は DDL rollback に期待せず、バックアップ復元または明示的な再構築を行う。

当初は標準検索への一時的な切替を許容し、無停止の二重インデックス基盤を必須化しない。再索引の対象数・外部 API 費用が発生することを事前表示する。Azure 等で既に上書きされた endpoint は再入力が必要。今回のドキュメント追加だけでは、これらの移行を実行しない。

## 7. 確定不具合と分ける追加検証

以下は検証・仕様決定の対象であり、未測定の性能低下や未確認の実サービス障害を断定しない。

| 項目 | 確認事項・扱い |
| --- | --- |
| E5 / Nomic の query / document prefix | 現在の interface は用途を区別しない。公開 model card の `query:` / `passage:`、`search_query:` / `search_document:` と backend 自動付与の有無を確認。必要な場合だけ一度付与し、context 版更新と再索引を行う。品質差は未測定 |
| token 上限 | 文字数の保証と各モデルの token 制約は別。E5 等の実効上限・切捨て動作を確認し、超過検出／分割方針を決める。全 provider への一律 token 仮定は置かない |
| distinct な投稿候補数 | `5 × top_k` チャンク取得後の重複排除では、`top_k=2` でも近傍10チャンクが同じ投稿なら1投稿となる。まず「最大候補数」の仕様を明示し、追加取得は必要性を測定してから検討 |
| WP-CLI callback | クラス名と非 static method の登録について、素の PHP の callable 判定だけで WP-CLI 不具合と断定しない。実 WP-CLI で `--start / --status / --all` を検証 |
| global 停止 | 通常同期と backfill で gate が揃っていない。検索・新規送信・実行中 worker の停止範囲を定義し、運用上の緊急停止と個別 pause を区別して試験 |
| TEI 等の wire format | TEI の `/v1/embeddings` と native `/embed` を混同しない。現行 OpenAI-compatible endpoint を根拠なく `inputs` 形式へ変更しない |

モデルの公開契約の参照先:

- [multilingual-E5-small model card](https://huggingface.co/intfloat/multilingual-e5-small)
- [Nomic Embed Text v1.5 model card](https://huggingface.co/nomic-ai/nomic-embed-text-v1.5)
- [TEI quick tour](https://huggingface.co/docs/text-embeddings-inference/quick_tour)
- [Ollama embed API](https://docs.ollama.com/api/embed)

## 8. 修正計画のレビュー

**判定: OK — 修正着手のための計画として妥当。製品の修正完了・統合試験通過・本番投入の承認ではない。**

コードレビュー結果の統合後、計画を別の確認工程として読み直し、以下を照合した。再読で見つけた「入力方式の修正だけを先行公開できてしまう依存関係」「locale 変更時の再構築条件」「OpenAI の編集可能項目との区別」を修正したうえで、この判定とした。

| 観点 | 確認結果 |
| --- | --- |
| 根拠と範囲 | R01〜R15 に基準コミットの箇所・再現条件または静的確認の区別がある。実 DB / WP の未検証事項を明記 |
| 過大な断定の排除 | private 投稿漏洩、WordPress HTTP 本文の JSON 化失敗、CLI 実障害、モデル品質低下を未確認のまま確定扱いしていない |
| 原因への対処 | hash 全削除だけ、TTL 延長だけ、JS retry だけで済ませず、保存実体・世代・原子性・状態遷移まで対象化 |
| 作業漏れ | 通常同期・bulk・管理画面・CLI・cron・検索・診断・uninstall を含み、共通境界を別実装で重複させない |
| 依存関係 | validator → 保存 → 世代／worker → 検索／UI の順序を明示。M1 の入力変更から M4 の検索・cache までを一体のリリース境界とし、M2 に移行中の標準検索 gate を含めた |
| 検証可能性 | 各項目の出力形・件数・状態・競合順序を受入条件にした。静的 gate やバッジ表示だけで合格にしない |
| 互換性 | 既存 provider 形式、正常 hybrid の範囲、MySQL 無効方針、標準検索への復帰、非対象タイトルの不変性を保持 |
| 移行・運用 | 費用告知、旧 worker 失効、標準検索退避、旧 hash の扱い、接続断、整合した rollback、条件付き multisite を記載 |
| 今回の変更範囲 | この計画書のみを commit / push の対象とし、製品コード・schema・設定・配布 ZIP は変更しない |

実装時に第7節の判断が確定した場合や実 DB 試験で前提が変わった場合は、受入条件・移行手順を更新して再レビューする。既知の P1 を残したまま、静的チェックの成功のみを理由にリリースしない。
