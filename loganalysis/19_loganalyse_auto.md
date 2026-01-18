### ここから add0119
---
1_
 - 朝。新•フロー図にかきなおす。流れをつかむ
 - まずは記録台帳エクセルを作る。ゴールから作る。

2_ ※GeminiにGAS作成依頼の際にエクセルでモックの記録台帳を作って提出する

user.各ログ (& host.各ログ}
⇅
ID列.アカウント台帳, 有効=有効 (& サーバー種類)
(※ @付きは@以前の文言を取得する)

とを突合。
一件でもヒットすれば、以降そのログでのそのユーザー名での検索スキップ。次のユーザーへ
↓
「ログ採取日, TimeStamp, ユーザー名, サーバー名, 備考(ﾌﾞﾗﾝｸ)」
を記録用スプシに掲載する
↓
毎週月曜、記録用スプシをチェックし該当ユーザーに直接ヒアリングして確認結果を記録する
↓
月次(毎月1日)。課長へ承認依頼


3_ Geminiに学習させるためのGドライブの ”パス”集をメモにして残す
---
### ここまで最優先



4_ LogAggregator_EC2/ECS/CloudTrail 改造

- jsonをパースしない。gz解凍する
- 接頭辞を付与する  yyyy-mm-dd_

yyyy-mm-dd_CloudTrail_v3_ap-northeast-3.log
yyyy-mm-dd_{EC2各種ログ}.log

5_ S3toGdrive改造

- Googleドライブ/log_data/ にまとめて放り込む
- ECSログは収集やめる

6_ (検索 linux 異常ログ キーワード)
---
### ここまで add0119



#MyTurn
EC2です。
osはrockyまたはamazon linux。
sshまたはecs executeの不正ログインがなかったかos.secureまたはos.messagesログを機械的に分析したい。
アイディアを示して。


#AiTurn
了解です。
**EC2（Rocky Linux / Amazon Linux）で SSH / ECS Exec の不正ログイン有無を「機械的に」分析するアイディア**を、
**実運用に耐えるレベル**で整理します。

---

# 前提整理（重要）

## 対象ログ

| OS           | 主ログ                                     |
| ------------ | --------------------------------------- |
| Rocky Linux  | `/var/log/secure`                       |
| Amazon Linux | `/var/log/secure`（＋`/var/log/messages`） |

### 含まれるもの

* SSH 成功 / 失敗
* sudo 実行
* PAM 認証失敗
* ECS Exec（※CloudTrail 併用が必須）

---

# 結論サマリー（方針）

👉 **「ログ単体分析」＋「時系列・相関分析」＋「AWSログ突合」**
👉 **人が読む前提は捨てる**

---

# アイディア①：SSHログの機械的スコアリング

### ログ例

```
Failed password for invalid user admin from 1.2.3.4
Accepted publickey for ec2-user from 5.6.7.8
```

## ルールベース分析（第一段階）

### 抽出観点

| 観点           | 危険度 |
| ------------ | --- |
| invalid user | 高   |
| rootログイン     | 高   |
| password認証   | 中   |
| publickey    | 低   |
| 深夜帯          | 加点  |
| 同一IP多発       | 加点  |

### 機械的処理例

* IP単位で失敗回数カウント
* ユーザー名の辞書攻撃検知
* 時間帯ヒートマップ

👉 **Fail2banと同じ思想を「分析専用」に使う**

---

# アイディア②：成功ログの異常検知（重要）

> 不正は「成功ログ」に現れる

### 機械的チェック項目

| 観点    | 異常例            |
| ----- | -------------- |
| IP    | いつもと違う国        |
| 時刻    | 普段使わない時間       |
| ユーザー  | 普段使わない         |
| 認証方式  | password → key |
| 直後の行動 | sudo多発         |

👉 **成功ログこそ要注意**

---

# アイディア③：sudo / 権限昇格の連鎖分析

### 典型的な侵入パターン

```
Accepted publickey
→ sudo su -
→ systemctl stop firewalld
→ useradd
```

### 機械的分析

* SSH成功から5分以内のsudoを紐付け
* sudo頻度の異常検知
* root操作コマンドの辞書判定

---

# アイディア④：ECS Exec は OS ログだけでは不十分

## 重要事実

> **ECS Exec は CloudTrail が一次証拠**

### 突合すべきログ

| ログ         | 役割        |
| ---------- | --------- |
| CloudTrail | 誰がExecしたか |
| ECSイベント    | 対象コンテナ    |
| OSログ       | 実行結果      |

### CloudTrail例

```
ExecuteCommand
```

### OS側

* bash history
* auditd
* プロセス生成ログ

👉 **「OSだけ見ても証拠不十分」**

---

# アイディア⑤：ログ正規化 → 機械分析

## 最低限の正規化

| フィールド       |
| ----------- |
| timestamp   |
| src_ip      |
| user        |
| action      |
| result      |
| auth_method |

### 例

```
2026-01-05,1.2.3.4,invalid_user,ssh_login,fail,password
```

👉 **CSV化するだけで世界が変わる**

---

# アイディア⑥：時系列異常検知（軽量）

### シンプルでも効く方法

* ログイン成功数の移動平均
* sudo実行数の標準偏差
* 1分あたりイベント数

👉 **ML不要、統計で十分**

---

# アイディア⑦：known-good ベースライン方式（実務向き）

### 手順

1. 正常期間を定義（例：直近30日）
2. 平常時プロファイル作成
3. 差分のみアラート

### 観点

* IPレンジ
* 利用時間帯
* 利用ユーザー

👉 **誤検知が激減**

---

# アイディア⑧：最小構成で始めるなら

### 最低限やるべき3点

1. SSH成功・失敗のCSV化
2. sudoログの連鎖分析
3. ECS Exec × CloudTrail 突合

---

# やってはいけないこと

❌ grepだけで目視
❌ root成功だけ見て安心
❌ OSログだけでECS Execを判断

---

# プロ視点の結論

> **不正ログイン分析は「成功ログ×行動ログ×AWS制御ログ」の三位一体**

OSログだけを見ると
👉 **「侵入に気づかない」 or 「誤検知」**

---

## 次の一手としておすすめ

* **bash / awk だけで回す最小分析スクリプト**
* **CloudWatch Logs Insights クエリ**
* **auditd 併用設計**
* **ECS Exec完全証跡設計**

どこまで自動化したいですか？
「最小構成」か「本番SOC寄り」かで、次の設計を具体化します。


#MyTurn
普段使用する(信用する)接続元グローバルIP、普段使用する(信用する)ログインユーザー名を定義したい。
これらはホワイトリストですか？


#AiTurn
結論から明確に言います。

> **はい、それは「ホワイトリスト（Allowlist）」です。**

ただし **セキュリティ設計上は少し言葉を分けて考えるのが正解**です。

---

# 用語の整理（重要）

### 現在推奨される正式表現

| 従来      | 現在                   |
| ------- | -------------------- |
| ホワイトリスト | **Allowlist（許可リスト）** |
| ブラックリスト | **Denylist（拒否リスト）**  |

👉 技術的意味は同じ
👉 AWS公式ドキュメントも Allowlist 表記が増加

---

# 今回の定義は何に当たるか

あなたが定義したいもの：

* ✅ 普段使う（信用する）**接続元グローバルIP**
* ✅ 普段使う（信用する）**ログインユーザー名**

これは **完全に Allowlist** です。

---

# セキュリティ設計としての位置づけ

## ① アクセス制御の Allowlist（強制）

### 例

* Security Group
* NACL
* sshd_config（AllowUsers）
* ECS Exec IAM条件

👉 **通信・認証を「物理的に遮断」**

---

## ② 監査・検知用 Allowlist（観測）

### 例

* ログ分析時の基準
* 異常検知ベースライン

👉 **許可はするが「異常を検知」**

---

# あなたのケースは「後者＋前者」

質問文の文脈から見ると：

> **「不正ログイン検知のための基準定義」**

なのでまずは

* 🔹 **監査用 Allowlist**
* 🔹 必要に応じて **制御用 Allowlist**

この2層構造がベストです。

---

# Allowlistをどう使うか（実務設計）

## ① ログ分析での使い方（推奨）

### 判定ロジック例

```
IF src_ip NOT IN allowlist_ip
 OR user NOT IN allowlist_user
THEN suspicious = true
```

👉 **即アラート対象**

---

## ② 危険度スコアリングに昇格

| 条件             | スコア |
| -------------- | --- |
| Allowlist外IP   | +50 |
| Allowlist外ユーザー | +40 |
| 深夜帯            | +10 |
| sudo連続         | +30 |

👉 **0/1判定より現実的**

---

# 制御に使う場合の注意点（重要）

## IP Allowlistの限界

* モバイル回線
* テレワーク
* IP変動

👉 **「検知用」と「遮断用」は分ける**

---

## ユーザーAllowlistの注意

* ec2-user固定は危険
* 人ごとにユーザー分離が望ましい

---

# ECS Execとの関係

ECS Exec は OS Allowlist では防げません。

👉 **IAMポリシー側で Allowlist**

```
Condition:
  StringEquals:
    aws:PrincipalArn: ...
```

---

# プロの結論

> **今回定義したいものは Allowlist で正しい**
> ただし
> **「遮断用 Allowlist」と「検知用 Allowlist」は分けて考える**

---

## 次にやると効果が高いこと

* Allowlist定義のYAML化
* ログ分析ロジックへの組み込み
* CloudTrailとOSログの突合

もしよければ
**「Allowlist設計テンプレ（IP / User / IAM）」**を
そのまま使える形で出しますが、いきますか？

#MyTurn
「遮断用 Allowlist」、「検知用 Allowlist」それぞれについてほかに含めたほうがよい項目を示して


#AiTurn
非常に良い設計段階に来ています。
ここを整理できると **「防げる事故」と「気づける事故」**の両方を最大化できます。

以下、**「遮断用 Allowlist」と「検知用 Allowlist」それぞれに含めるべき項目**を
**実務で効いたものだけ**に絞って示します。

---

# 全体像（先に）

| 区分                | 目的     | 設計思想   |
| ----------------- | ------ | ------ |
| **遮断用 Allowlist** | 入れさせない | 最小・厳格  |
| **検知用 Allowlist** | 逸脱を検知  | 広め・現実的 |

👉 **この分離がプロの設計**

---

# ① 遮断用 Allowlist（Access Control）

> **ここに入れたもの以外は「物理的に拒否」**

## 含めるべき項目（厳選）

### ① 接続元IP / CIDR（最重要）

| 項目         | 備考              |
| ---------- | --------------- |
| 固定グローバルIP  | 自宅 / 拠点         |
| VPN出口IP    | AWS Client VPN等 |
| BastionのIP | 踏み台             |

📍適用先：

* Security Group
* NACL
* SSH Listen制限

---

### ② ログインユーザー名

| 項目       | 備考   |
| -------- | ---- |
| 個人専用ユーザー | 共用NG |
| root禁止   | 原則   |

📍適用先：

* `sshd_config`（AllowUsers）
* sudoers

---

### ③ 認証方式

| 項目          | 理由           |
| ----------- | ------------ |
| publickeyのみ | password禁止   |
| MFA必須       | IAM/ECS Exec |

📍適用先：

* sshd_config
* IAM policy

---

### ④ アクセス経路（チャネル）

| 項目         | 理由      |
| ---------- | ------- |
| SSHのみ      | ポート固定   |
| ECS Execのみ | SSH禁止など |

📍適用先：

* SG
* IAM

---

### ⑤ IAM Principal（ECS Exec用）

| 項目           | 備考  |
| ------------ | --- |
| IAM Role ARN | 人単位 |
| Session Name | 制限可 |

📍適用先：

* IAM Policy Condition

---

## ❌ 遮断用に入れてはいけないもの

* 動的IP
* 一時利用ユーザー
* 不定期作業時間帯

👉 **事故・ロックアウトの原因**

---

# ② 検知用 Allowlist（Monitoring / Detection）

> **「普段はこう」を定義して逸脱を見る**

## 含めるべき項目（実務で効く）

---

### ① 接続元IP（レンジ / 国）

| 項目      | 理由     |
| ------- | ------ |
| よく使うIP帯 | IP変動吸収 |
| 国コード    | 海外検知   |

👉 Allowlist外 = 即高スコア

---

### ② ログインユーザー

| 項目       | 理由   |
| -------- | ---- |
| 普段使うユーザー | 異常検知 |
| 時間帯      | 深夜検知 |

---

### ③ 認証方式

| 項目        | 異常例 |
| --------- | --- |
| publickey | 普段  |
| password  | 異常  |

---

### ④ 時間帯（かなり重要）

| 項目    | 例  |
| ----- | -- |
| 平日日中  | 通常 |
| 深夜・休日 | 異常 |

👉 **侵入は時間外に起きる**

---

### ⑤ コマンド傾向

| 項目          | 異常例  |
| ----------- | ---- |
| systemctl   | 普段なし |
| useradd     | 異常   |
| curl / wget | 侵入兆候 |

---

### ⑥ sudo / root遷移パターン

| 項目         | 異常例 |
| ---------- | --- |
| ログイン直後sudo | 高   |
| sudo連続     | 高   |

---

### ⑦ ECS Exec特有（重要）

| 項目    | 理由     |
| ----- | ------ |
| 実行IAM | 普段と違う  |
| 対象タスク | 触らないもの |
| 実行時刻  | 深夜     |

---

### ⑧ 操作頻度（統計）

| 項目      | 異常   |
| ------- | ---- |
| SSH成功回数 | 急増   |
| sudo回数  | 急増   |
| Exec回数  | 普段ゼロ |

---

# 両者の違いを一枚で

| 観点   | 遮断用  | 検知用  |
| ---- | ---- | ---- |
| IP   | 固定のみ | レンジ  |
| ユーザー | 個人限定 | 利用履歴 |
| 時間帯  | 不使用  | 重要   |
| IAM  | 必須   | 必須   |
| コマンド | 不使用  | 使用   |
| 目的   | 防御   | 発見   |

---

# 実務的ベストプラクティス

### 二層構造

```
[遮断] SG / IAM / sshd
   ↓
[検知] ログ分析 / CloudTrail
```

### 失敗例

* 遮断に頼りすぎる → 見逃す
* 検知だけ → 侵入される

---

# プロの結論

> **Allowlistは「制御用」と「観測用」で設計思想が違う**
> 両方作って初めて「実用セキュリティ」

---

## 次に進むなら

* Allowlist定義（YAML/JSON）
* ログ→CSV正規化設計
* スコアリングルール作成
* CloudWatch Logs Insightsクエリ化

どこまで自動化しますか？
**最小構成テンプレ**から出せます。


#MyTurn
スコアリングルール作成を示して


#AiTurn
了解です。
ここでは **「実運用でそのまま使えるスコアリングルール」**を、
**設計 → 具体ルール → 判定基準 → 実装イメージ**の順で示します。

ポイントは
👉 **0/1判定を捨てる**
👉 **「単体は弱いが、重なると危険」を数値化** です。

---

# 1️⃣ スコアリングの基本設計思想

## なぜスコアリングが必要か

* 単発の異常 → 誤検知が多い
* 侵入は **複数の小さな異常の組み合わせ**

👉 **合計点で判断**するのが現実的

---

## 基本式（シンプル）

```
RiskScore = Σ(各ルールのスコア)
```

---

## 判定レベル（例）

| スコア   | 判定     |
| ----- | ------ |
| 0–29  | 正常     |
| 30–59 | 要注意    |
| 60–89 | 疑わしい   |
| 90+   | 高確度で不正 |

※ 数値は後で調整前提

---

# 2️⃣ スコアリング項目（カテゴリ別）

## A. 接続元・認証系（最重要）

### 接続元IP

| 条件              | スコア       |
| --------------- | --------- |
| 遮断用Allowlist外IP | +100（即NG） |
| 検知用Allowlist外IP | +50       |
| 国コード異常          | +40       |
| TOR / VPS帯      | +30       |

---

### ユーザー名

| 条件                | スコア |
| ----------------- | --- |
| 遮断用Allowlist外ユーザー | +80 |
| 検知用Allowlist外ユーザー | +40 |
| rootログイン          | +70 |
| invalid user      | +30 |

---

### 認証方式

| 条件           | スコア |
| ------------ | --- |
| password認証成功 | +60 |
| password失敗多発 | +40 |
| 普段と違う認証方式    | +30 |

---

## B. 時間・頻度系（効く）

### 時間帯

| 条件          | スコア |
| ----------- | --- |
| 深夜（例: 0–5時） | +20 |
| 休日          | +15 |

---

### 頻度

| 条件           | スコア |
| ------------ | --- |
| SSH失敗 >10/5分 | +40 |
| SSH成功急増      | +30 |
| sudo急増       | +30 |

---

## C. 行動・コマンド系（侵入後検知）

### sudo / root

| 条件          | スコア |
| ----------- | --- |
| ログイン直後sudo  | +40 |
| sudo連続      | +30 |
| sudoで危険コマンド | +50 |

---

### 危険コマンド例

| コマンド             | スコア |
| ---------------- | --- |
| useradd / passwd | +60 |
| curl / wget      | +40 |
| systemctl stop   | +50 |
| firewall-cmd無効化  | +70 |

---

## D. ECS Exec 専用

| 条件                 | スコア |
| ------------------ | --- |
| 普段Execしないのに実行      | +50 |
| 普段と違うIAM Principal | +70 |
| 深夜Exec             | +30 |

---

# 3️⃣ 相関ルール（単体より強い）

## 典型侵入チェーン

```
Allowlist外IP (+50)
 + 深夜 (+20)
 + sudo直後 (+40)
 = 110 → 高確度侵入
```

---

## 失敗例（単体だと弱い）

```
深夜ログイン (+20) → 誤検知
```

👉 **重なったときだけ危険**

---

# 4️⃣ 即アラート条件（例外ルール）

スコア無視で即通知：

* 遮断用Allowlist外IPで成功
* rootログイン成功
* password認証成功
* ECS Exec + 普段使わないIAM

👉 **保険として必須**

---

# 5️⃣ 実装イメージ（擬似）

```text
score = 0

if ip not in detect_allowlist:
  score += 50

if user not in detect_users:
  score += 40

if hour in [0..5]:
  score += 20

if sudo_within_5min:
  score += 40

if score >= 90:
  alert("HIGH")
elif score >= 60:
  alert("MEDIUM")
```

👉 **bash / awk / Python どれでも可能**

---

# 6️⃣ チューニング方法（重要）

### 初期1週間

* 全イベントを記録
* 誤検知を洗い出す

### 以降

* スコアを ±10 ずつ調整
* 即アラート条件は極力少なく

---

# 7️⃣ 最小構成おすすめセット

| 項目           | 理由    |
| ------------ | ----- |
| IP Allowlist | 最強    |
| 時間帯          | 軽量    |
| sudo連鎖       | 実害検知  |
| Exec検知       | 見逃し防止 |

---

# プロの結論

> **良いスコアリングは「雑でもいいから重ねる」**
> 精密さより **重なり**

---

次に進むなら

* **CSVログ定義**
* **CloudWatch Logs Insights クエリ**
* **jq / awk 実装例**
* **YAMLルール定義テンプレ**

どれを出しますか？


#MyTurn
publickey(sshキー)であるかpasswordであるかはログから判別できますか？


#AiTurn
結論から明確に答えます。

> **はい、OSログから確実に判別できます。**
> Rocky Linux / Amazon Linux ともに **sshd のログに明示的に出ます。**

以下、**どこを見れば・どう書かれているか・例外はあるか**を整理します。

---

# 1️⃣ 判別できるログと場所

## 対象ログ

| OS           | ログ                   |
| ------------ | -------------------- |
| Rocky Linux  | `/var/log/secure`    |
| Amazon Linux | `/var/log/secure`（主） |

※ `authpriv` facility

---

# 2️⃣ ログの具体例（決定的）

## 🔑 publickey 認証成功

```
Accepted publickey for ec2-user from 203.0.113.10 port 51234 ssh2
```

### 判定

* **認証方式：publickey**
* **成功**

---

## 🔑 publickey 認証失敗

```
Failed publickey for ec2-user from 203.0.113.10 port 51234 ssh2
```

---

## 🔐 password 認証成功（危険）

```
Accepted password for ec2-user from 203.0.113.10 port 49821 ssh2
```

### 判定

* **認証方式：password**
* **成功（高リスク）**

---

## 🔐 password 認証失敗

```
Failed password for invalid user admin from 1.2.3.4 port 42421 ssh2
```

---

# 3️⃣ 機械判別ルール（そのまま使える）

## 判定ロジック

| ログ文字列                | 認証方式      | 成否      |
| -------------------- | --------- | ------- |
| `Accepted publickey` | publickey | success |
| `Failed publickey`   | publickey | fail    |
| `Accepted password`  | password  | success |
| `Failed password`    | password  | fail    |

👉 **grep / awk で100%判別可能**

---

# 4️⃣ 例外・注意点（重要）

## ① keyboard-interactive

```
Accepted keyboard-interactive/pam for user from ...
```

### 実体

* ワンタイムパスワード
* MFA
* PAM連携

👉 **password系として扱うのが安全**

---

## ② GSSAPI（Kerberos）

```
Accepted gssapi-with-mic for user ...
```

👉 企業環境ではあり
👉 EC2単体では稀

---

## ③ 設定次第でログが出ない？

❌ 出ます
ただし：

* LogLevel が `QUIET` だと詳細が減る

### 推奨

```
LogLevel VERBOSE
```

---

# 5️⃣ スコアリングへの組み込み例

| 条件                   | スコア |
| -------------------- | --- |
| Accepted password    | +60 |
| Failed password      | +20 |
| Accepted publickey   | +0  |
| keyboard-interactive | +30 |

---

# 6️⃣ ECS Exec の場合（補足）

⚠️ **ECS Exec は SSH ではない**

* OSログに「password / publickey」は出ない
* CloudTrail で判別

```
eventName: ExecuteCommand
```

👉 **別系統として扱う**

---

# 7️⃣ プロの結論

> **SSH認証方式はログから100%判別可能**
> しかも **文字列一致だけでOK**

なので：

* publickey以外が出たら **即アラート**
* password成功は **即インシデント扱い**

---

次にやると効果的なのは👇

* **LogLevel設定の確認**
* **keyboard-interactive をどう扱うか決める**
* **grep / awk / CloudWatch Insights クエリ化**

どれを先にいきますか？


#MyTurn
その痕跡はos.messagesログには掲載されますか？


#AiTurn
結論からはっきり言います。

> **通常は掲載されません。**
> **SSHの認証方式（publickey / password）の痕跡は `os.messages` ではなく `os.secure` に出ます。**

以下、**なぜそうなるのか／例外はあるのか／実務での注意点**を整理します。

---

# 結論サマリー

| ログ                    | SSH認証方式（publickey / password） |
| --------------------- | ----------------------------- |
| **/var/log/secure**   | ✅ **掲載される（主ログ）**              |
| **/var/log/messages** | ❌ **原則掲載されない**                |

---

# なぜ `secure` で、`messages` ではないのか

## syslog の仕組み（重要）

SSH（sshd）は **auth / authpriv facility** を使います。

| facility             | 出力先                 |
| -------------------- | ------------------- |
| auth / authpriv      | `/var/log/secure`   |
| daemon / user / kern | `/var/log/messages` |

👉 **設計上の役割分担**

---

# 実際のログ例

## `/var/log/secure`（出る）

```
Accepted publickey for ec2-user from 203.0.113.10 port 51234 ssh2
Accepted password for ec2-user from 203.0.113.10 port 49821 ssh2
Failed password for invalid user admin from 1.2.3.4 port 42421 ssh2
```

---

## `/var/log/messages`（通常は出ない）

```
sshd[1234]: Server listening on 0.0.0.0 port 22
```

👉 **起動・停止・listen などの状態ログのみ**

---

# Rocky Linux / Amazon Linux の挙動

| OS                | 認証ログ              |
| ----------------- | ----------------- |
| Rocky Linux 8/9   | `/var/log/secure` |
| Amazon Linux 2    | `/var/log/secure` |
| Amazon Linux 2023 | `/var/log/secure` |

✅ **一貫して secure**

---

# 例外①：messages に「一部」出るケース

### sshdの起動・停止

```
systemd: Started OpenSSH server daemon.
```

👉 認証方式は分からない
👉 **ログイン成否の証拠には使えない**

---

# 例外②：rsyslog 設定を変えた場合

### 例えばこんな設定

```
*.* /var/log/messages
```

この場合：

* authログも messages に混ざる

👉 **ただしデフォルトではやらない**
👉 セキュリティ的に非推奨

---

# 実務的な結論（重要）

> **SSH認証方式の判定は
> `/var/log/secure` を唯一の正とする**

* `messages` は **補助**
* 認証分析に使うべきではない

---

# あなたの分析設計への影響

## 正しいログ収集対象

| ログ         | 用途       |
| ---------- | -------- |
| secure     | 認証方式・成否  |
| messages   | 異常挙動の補足  |
| CloudTrail | ECS Exec |

---

# スコアリング設計での扱い

* `secure` → **一次証拠**
* `messages` → **コンテキスト**

👉 `messages` に認証方式が出ない前提で設計するのが正解

---

# プロの最終結論

> **publickey / password の痕跡は `os.messages` には載らない**
> **載るとしたら「設定を変えた異常系」**

---

次に進むならおすすめは👇

* **rsyslog設定の確認ポイント**
* **secureログ欠損時の検知方法**
* **CloudWatch Logsへ送る最小構成**

どれを深掘りしますか？


#MyTurn
不正ログインを機械的に判定するアイディアとして、スコア以外の方法としてはどのようなものがありそう？


#AiTurn
とても良い問いです。
**スコアリング以外**にも、不正ログインを「機械的」に判定する方法は複数あります。
実務では **スコアリング＋別系統**を組み合わせるのが王道です。

以下、**考え方（思想）別に整理**します。

---

# 全体マップ（先に）

| 手法            | 思想        |
| ------------- | --------- |
| ① ルールベース      | 明確にNG     |
| ② 状態遷移（シーケンス） | 行動の流れ     |
| ③ ベースライン逸脱    | 普段との差     |
| ④ 頻度・レート      | 攻撃特有の癖    |
| ⑤ 依存関係分析      | 因果        |
| ⑥ ホワイトリスト逆転   | 「あるはずがない」 |
| ⑦ ML（軽量）      | パターン      |

👉 **MLは最後**
👉 **①〜⑥だけで8割防げる**

---

# ① ルールベース判定（YES/NO型）

> **「起きたら即アウト」**

### 例

* password認証成功
* rootログイン成功
* Allowlist外IPで成功
* 深夜のECS Exec

### 実装

* grep
* CloudWatch Logs Insights
* IAM Condition

### 強み

* 確実
* 説明可能

### 弱み

* 想定外に弱い

---

# ② 状態遷移（シーケンス）検知（非常に強い）

> **ログ単体ではなく「流れ」で見る**

### 正常遷移

```
login → 作業 → logout
```

### 不正遷移

```
login → sudo → useradd → curl
```

### 機械判定例

* SSH成功後 2分以内に sudo
* sudo直後に systemctl stop

👉 **侵入検知で最も実用的**

---

# ③ ベースライン逸脱検知

> **「いつもと違う」を見る**

### ベースライン

* IP
* 時間帯
* ユーザー
* 操作量

### 判定

* 平均 ±3σ
* 移動平均との差

### 強み

* 未知の攻撃に強い

### 弱み

* 初期学習が必要

---

# ④ レート・頻度ベース検知

> **攻撃者特有の「速さ」**

### 例

* 10秒で5回失敗
* 1分で10ユーザー試行
* 連続IP変更

### 実装

* sliding window
* IP×時間

👉 **辞書攻撃検知に最強**

---

# ⑤ 依存関係・因果分析

> **「これが起きたら、次は普通これ」**

### 例

* SSH成功 → bash history
* Exec → CloudTrail → OSログ

### 異常

* SSH成功がないのに sudo
* Execログがあるのに CloudTrailなし

👉 **ログ欠損・改ざん検知にも効く**

---

# ⑥ ホワイトリスト逆転方式（強力）

> **「起きるはずがない」ことを検知**

### 例

* 普段Execしない環境でExec
* 普段使わないユーザーで成功
* 使っていないポートでログイン

👉 **誤検知が極端に少ない**

---

# ⑦ 軽量ML（補助）

> **「ルールで拾えないもの」用**

### 例

* Isolation Forest
* k-means
* LOF

### 特徴

* 多次元で異常検知
* 説明性が弱い

👉 **最後に足す**

---

# 組み合わせ例（実務）

## 最小構成（おすすめ）

```
① ルールベース
＋
② 状態遷移
＋
④ 頻度
```

## 本格構成

```
① + ② + ③ + ④ + ⑤
```

---

# 実務での優先順位

| 優先  | 手法        |
| --- | --------- |
| ★★★ | 状態遷移      |
| ★★☆ | ホワイトリスト逆転 |
| ★★☆ | ルールベース    |
| ★☆☆ | ML        |

---

# プロの結論

> **「点」で見るな、「流れ」と「差」を見ろ**
> スコアは「まとめ役」にすぎない

---

次に進めるとしたら👇

* **状態遷移ルールの具体例**
* **CloudWatch Logs Insights クエリ**
* **bashでの実装**
* **ログ正規化フォーマット**

どれを深掘りしますか？


#MyTurn
まずは最小限のスモールスタートでやりたい。
提案して


#AiTurn
了解です。
**「最小限・今日からできる・壊れにくい」**を最優先にした
👉 **スモールスタート構成**を提案します。

前提はあなたの要件どおり👇

* EC2
* OS：Rocky Linux / Amazon Linux
* 対象ログ：`/var/log/secure`（主）
* 目的：**SSH / ECS Exec の不正ログイン検知（遮断はしない）**

---

# スモールスタートの思想

✅ 検知精度より **運用継続性**
✅ ルールは **5個以内**
✅ ML・複雑なスコアは **使わない**
✅ **grep / CloudWatch Logs Insights で完結**

---

# 最小構成（結論）

## やることはこの3点だけ

1. **ログを集める**
2. **Allowlistを定義**
3. **3つの検知ルールを作る**

---

# ① ログ収集（必須・最小）

### 対象

* `/var/log/secure`
* （ECS Execを使うなら）CloudTrail

### 手段（おすすめ順）

* CloudWatch Agent
* すでにあるなら rsyslog でもOK

👉 **ここは既存環境に合わせる**（作り込み不要）

---

# ② Allowlist定義（2つだけ）

## 検知用 Allowlist（最小）

```yaml
trusted_ips:
  - 自宅グローバルIP
  - VPN出口IP

trusted_users:
  - ec2-user
  - admin
```

❗ **遮断用ではなく「判定の材料」**

---

# ③ 最小検知ルール（これだけでOK）

## ルール①：Allowlist外からのSSH成功

### 条件

* `Accepted` ログ
* `source_ip NOT IN trusted_ips`

### 理由

* **誤検知がほぼゼロ**
* 一番価値が高い

---

## ルール②：password認証成功

### 条件

* `Accepted password for`

### 理由

* 公開鍵前提なら **即異常**
* 攻撃成功を最速検知

---

## ルール③：短時間の連続失敗

### 条件

* `Failed password`
* 同一IP
* 5回 / 1分

### 理由

* 辞書攻撃の王道パターン

---

# CloudWatch Logs Insights（例）

```sql
fields @timestamp, @message
| filter @message like /Accepted/
| filter not (@message like /xx.xx.xx.xx/)
| sort @timestamp desc
```

※ IP部分はAllowlistを手動展開（最初はベタ書きでOK）

---

# スモールスタートの成果物

| 成果物       | 中身         |
| --------- | ---------- |
| Allowlist | IP・ユーザー    |
| 検知ルール     | 3本         |
| 通知        | SNS or メール |
| 運用        | 1日1回確認     |

👉 **これだけで「侵入成功」はほぼ拾える**

---

# やらないこと（重要）

❌ スコアリング
❌ ML
❌ 自動遮断
❌ 複雑な正規化
❌ 可視化ダッシュボード

→ **後から全部足せる**

---

# 1週間後にやると良い拡張（参考）

* sudo実行検知
* 深夜ログイン検知
* ECS Exec検知
* 状態遷移ルール

---

# プロ視点の評価

| 観点    | 評価 |
| ----- | -- |
| 導入コスト | ★  |
| 誤検知   | 極小 |
| 効果    | 大  |
| 拡張性   | ◎  |

---

次に進むなら👇

* **CloudWatch Agent設定最小例**
* **grepだけで回すローカル版**
* **ECS Execを含めた最小構成**
* **この構成の落とし穴**

どれをやりますか？


#MyTurn
ECS Execはos.secure/messagesに掲載されないのですか？根拠を示して


#AiTurn
**結論：**

> **ECS Exec の実行自体は Linux の `os.secure` や `os.messages` のような OS側標準ログには記録されません。**
> これは、ECS Exec が **Docker や SSH とは異なる仕組み（AWS API + SSM Session Manager）** を使ってコンテナセッションを確立するためで、OS の標準認証ログには出力されないという仕様です。([AWS ドキュメント][1])

---

## ✅ なぜ OSログに出ないのか

### 仕組みのポイント

ECS Exec は次のような流れで機能します：

1. AWS CLI / SDK などから `ExecuteCommand` API を呼び出す
2. AWS ECS が **SSM Session Manager** を通じて対象のコンテナに接続チャネルを開く
3. 内部的には SSM エージェントがコンテナでシェルを立ち上げる

→ これは **sshd 等の OS認証機構を直接使わない**ので、
Linux の標準ログ（`/var/log/secure` や `/var/log/messages`）の
**ssh 認証ログや PAM のログがそもそも発生しない構造**です。([AWS ドキュメント][1])

---

## ✅ どこに記録されるか（ECS Exec のログ）

ECS Exec のログは、OS の `/var/log/secure` や `/var/log/messages` のような **OS側標準 syslog には載りません**。
代わりに次の方法で記録できます：

### ✔ CloudTrail に `ExecuteCommand` イベントが残る

`ExecuteCommand` API 呼び出しは CloudTrail の ECS イベントとして記録できます。
これにより「誰が、いつ、どのタスクに対してExecしたか」を把握できます。([AWS ドキュメント][2])

例：

```jsonc
{
  "eventName": "ExecuteCommand",
  ...
}
```

---

### ✔ ECS Exec のセッションログ（コンテナ内）

ECS Exec では、**ログ記録を有効にする設定**にすると：

📌 実行したコマンド
📌 その出力
を **CloudWatch Logs / S3** に自動で送信できます。

これはタスク定義で Exec のログ記録を設定する必要があります。([AWS ドキュメント][1])

例にあるオプション：

```json
"logConfiguration": {
  "cloudWatchLogGroupName": "my-log-group"
},
"executeCommandConfiguration": {
  "logging": "OVERRIDE"
}
```

（実際の CloudWatch / S3 への保存は設定による）

---

### ✔ SSM 関連ログ（補助）

ECS Exec は内部的に **SSM Session Manager** を使うため、
SSM エージェントのログ（例： `/var/log/amazon/ssm/amazon-ssm-agent.log`）にログ出力されるケースもあります。
これも OSログとは別管理です（`/var/log/secure` ではない）。([Zenn][3])

---

## ❌ 例えばこんなログは出ない

| ログ                                 | 意味           |
| ---------------------------------- | ------------ |
| `/var/log/secure` の ssh 認証ログ       | sshd の認証ログ   |
| `/var/log/messages` の daemon メッセージ | syslog の一般ログ |

→ **ECS Exec は SSH ではなく AWS の API セッションであるため、これらには何も書かれません。**

---

## まとめ（根拠付き）

| 監査対象                         | OSログに出る？            | 補足                   |
| ---------------------------- | ------------------- | -------------------- |
| SSH ログイン（publickey/password） | ✅ `/var/log/secure` | 通常の SSH              |
| ECS Exec の開始                 | ❌                   | OS標準ログには出ない          |
| ECS Exec API 呼び出し            | ❌                   | CloudTrailに記録可能      |
| ECS Exec セッションログ             | ❌                   | CloudWatch Logs / S3 |

---

## 実務的な検知アプローチ

> **ECS Exec の監査は OSログだけでは不十分**
> → **CloudTrail + CloudWatch Logs/S3 のログ設定が必須**

これがセキュリティ監査で推奨されるパターンです。([AWS ドキュメント][1])

---

必要なら次に、
✅ **CloudTrail で ExecuteCommand を検知するクエリ例**
✅ **CloudWatch Logs に Exec 出力を送る設定テンプレ**
を提示します。どちらを先に見たいですか？

[1]: https://docs.aws.amazon.com/ja_jp/AmazonECS/latest/developerguide/ecs-exec.html?utm_source=chatgpt.com "ECS Exec を使用して Amazon ECS コンテナをモニタリングする - Amazon Elastic Container Service"
[2]: https://docs.aws.amazon.com/ja_jp/AmazonECS/latest/developerguide/logging-using-cloudtrail.html?utm_source=chatgpt.com "AWS CloudTrail を使用して Amazon ECS API コールをログに記録する - Amazon Elastic Container Service"
[3]: https://zenn.dev/fflight/articles/6890f6cab3c57278fea2?utm_source=chatgpt.com "CloudFormation下のFargateでECS Exec"


#MyTurn
以前ターンの「まずは最小限のスモールスタートでやりたい」のスモールスタート後です。
その後の展開、拡充プランを具体的に段階を追って示して。わかりやすく


#AiTurn
了解です。
ここでは **「最小限のスモールスタート」完了後**を前提に、
👉 **無理なく・壊さず・戻れる** 拡充ロードマップを **段階別**に示します。

キーワードは
**検知 → 可視化 → 網羅 → 自動化 → 予防**
です。

---

# 全体像（先に）

```
Phase 0：スモールスタート（完了）
Phase 1：精度向上
Phase 2：範囲拡張（ECS Exec）
Phase 3：行動分析
Phase 4：自動対応（慎重）
Phase 5：予防・統合
```

👉 **各Phaseは単独で止めてOK**

---

# Phase 0（完了済み）

### 状態

* `/var/log/secure` を収集
* Allowlist（IP / ユーザー）
* 3ルール

  * Allowlist外SSH成功
  * password認証成功
  * 短時間連続失敗

🎯 **侵入成功の早期検知**

---

# Phase 1：精度向上（最優先）

## 目的

**誤検知を減らし、判断を速くする**

---

## 1-1. ルールを「条件付き」にする

### 追加条件

* 時間帯（例：9–21時以外）
* 曜日（土日）

```text
Allowlist外IP
AND
深夜
```

👉 「即インシデント」→「要確認」に変換

---

## 1-2. ログの正規化（最低限）

### 抜き出す項目だけ固定

* timestamp
* src_ip
* user
* result（success/fail）
* auth_method

👉 **grep / Insights が楽になる**

---

## 1-3. 通知レベル分け

| レベル      | 条件           |
| -------- | ------------ |
| INFO     | 失敗           |
| WARN     | Allowlist外成功 |
| CRITICAL | password成功   |

---

# Phase 2：範囲拡張（ECS Exec）

## 目的

**「OSログに出ない侵入」を捕まえる**

---

## 2-1. CloudTrail 追加

### 対象イベント

* `ExecuteCommand`

### 判定例

* 普段使わないIAMユーザー
* 深夜Exec
* 本番環境Exec

---

## 2-2. ECS Exec ログ保存（最小）

* CloudWatch Logs だけ
* コマンド履歴は **後回しでも可**

👉 **「誰がExecしたか」だけで8割守れる**

---

# Phase 3：行動分析（強力）

## 目的

**侵入後の不正行動検知**

---

## 3-1. 状態遷移ルール

### 例

```
login
→ sudo（即）
→ useradd / curl
```

### 検知例

* SSH成功後 2分以内に sudo
* Exec後すぐにパッケージ操作

👉 **侵入検知の本丸**

---

## 3-2. 普段との差分検知

| 指標  | 例        |
| --- | -------- |
| 操作量 | コマンド数    |
| 時間  | 平均ログイン時刻 |
| IP  | 国・ASN    |

---

# Phase 4：自動対応（慎重）

## 目的

**人が起きていなくても止める**

---

## 4-1. ソフトブロック

* セキュリティグループでIP遮断
* IAMポリシー一時無効

⚠️ **即遮断はしない**

---

## 4-2. フェイルセーフ

* 自分のIPは絶対遮断しない
* TTL付きブロック（30分）

---

# Phase 5：予防・統合（完成形）

## 目的

**「起きない仕組み」にする**

---

## 5-1. 予防設定

* password認証無効
* rootログイン禁止
* Execは特定IAMロールのみ

---

## 5-2. 統合可視化

* CloudWatch Dashboard
* インシデント履歴

---

# フェーズ別 価値とコスト

| Phase | 効果   | 工数 |
| ----- | ---- | -- |
| 1     | 大    | 小  |
| 2     | 大    | 中  |
| 3     | 非常に大 | 中  |
| 4     | 中    | 大  |
| 5     | 安定   | 小  |

---

# プロの推奨ルート（現実的）

```
Phase 1
↓
Phase 2
↓
Phase 3（ここで十分強い）
```

Phase 4以降は **必要になってから**

---

# 最重要メッセージ

> **「守る仕組み」は育てるもの**
> 最初から完成を目指さないのが成功のコツ

---

次にやるとしたら👇

* **Phase 1の具体ルール例**
* **Phase 2のCloudTrailクエリ**
* **Phase 3の状態遷移テンプレ**
* **誤検知を潰すコツ集**

どれを深掘りしますか？


#MyTurn
スコアリングを盛り込むとしたらどこのフェーズですか。
それとも新たに別にフェーズを設けますか。

#AiTurn
とても良い問いです。
これは設計思想がはっきり分かれるポイントなので、**結論 → 理由 → 具体像**の順で答えます。

---

## 結論（プロの推奨）

👉 **スコアリングは「独立した新フェーズ」は設けない**
👉 **Phase 3（行動分析）に“補助要素として”組み込む**

つまり：

```
Phase 3.5：行動分析＋スコアリング（補助）
```

という位置づけが **最も失敗しにくい** です。

---

## なぜ独立フェーズにしないのか

### 理由①：スコアは「主役」になれない

スコアは：

* 単独では説明しづらい
* 誤検知時に納得しにくい
* 閾値調整が地獄

👉 **判断の材料であって、判断そのものではない**

---

### 理由②：早すぎると必ず腐る

Phase 0〜2 の段階で入れると：

* ログが少ない
* ベースラインがない
* 例外だらけ

👉 **運用が破綻する**

---

### 理由③：行動分析と相性が良すぎる

Phase 3 ではすでに：

* 状態遷移
* 普段との差
* 文脈

が揃っている
→ **スコア化しやすい**

---

## 正しい位置づけ（図）

```
Phase 0：点の検知
Phase 1：精度向上
Phase 2：範囲拡張
Phase 3：流れを見る
          ↓
      スコアで「強弱」を付ける
```

---

## Phase 3.5 の具体像

### ① 既存ルールに「重み」を付けるだけ

| 事象           | 点数  |
| ------------ | --- |
| Allowlist外IP | +40 |
| password成功   | +80 |
| 深夜ログイン       | +20 |
| sudo即実行      | +30 |
| 普段と違うIP      | +20 |

👉 **新しいルールは作らない**

---

### ② スコアは「分類」にしか使わない

| 合計    | 判定       |
| ----- | -------- |
| <40   | INFO     |
| 40–79 | WARN     |
| ≥80   | CRITICAL |

❌ 遮断条件には使わない
⭕ **通知の優先度だけ**

---

### ③ スコアの中身は必ず可視化

```text
CRITICAL (120点)
- password成功 (+80)
- Allowlist外IP (+40)
```

👉 **説明可能性を死守**

---

## フェーズ別 スコアリング可否

| Phase | スコア      |
| ----- | -------- |
| 0     | ❌        |
| 1     | ❌        |
| 2     | ❌        |
| 3     | ⭕（補助）    |
| 4     | ⭕（条件付き）  |
| 5     | △（不要になる） |

---

## 自動対応とスコアの関係

> **スコア × 確定ルール**

例：

```
password成功
AND
スコア >= 100
```

👉 **二重条件が安全**

---

## よくある失敗パターン（重要）

❌ スコア >= 60 で即遮断
❌ スコアだけ見て中身を見ない
❌ 点数調整に時間を使いすぎる

---

## プロの最終結論

> **スコアリングは「判断を楽にするための整理棚」**
> **判断の責任はルールと人が持つ**

---

次に進めるなら👇

* **Phase 3.5 の具体スコアルール雛形**
* **CloudWatch Logs Insights でのスコア算出例**
* **スコアが壊れた時のデバッグ方法**
* **スコアを捨てる判断基準**

どれを見たいですか？


#MyTurn
Phase 0です。
os.messagesも対象にするにしたい。
従って、os.secureとos.messagesを対象にしたい。


#AiTurn
了解です。
では **Phase 0（スモールスタート）を「/var/log/secure ＋ /var/log/messages」対象に拡張**した形で、
**やること・やらないこと・理由**を明確に整理します。

---

# Phase 0（改訂版）の位置づけ

## 目的（変わらない）

> **「侵入の成功」や「明らかな異常」を最速で知る**

## ポリシー（重要）

* ❌ 相関分析しない
* ❌ 状態遷移しない
* ❌ スコアリングしない
* ⭕ **ログ単体で意味が明確なものだけ拾う**

---

# なぜ os.messages も対象にするのか

## /var/log/secure だけでは不足する理由

`secure` は **認証中心**
→ 侵入「前後」の兆候が抜ける

### 例

* sshd の異常終了
* PAM 以外の認証エラー
* systemd / kernel / network 異常

これらは **/var/log/messages 側** に出ます。

---

# Phase 0 の対象ログ（明確化）

| ログ                  | 役割         |
| ------------------- | ---------- |
| `/var/log/secure`   | 認証の成否      |
| `/var/log/messages` | OS・デーモンの異常 |

👉 **「人が入ったか」＋「OSが騒いでないか」**

---

# Phase 0 でやること（具体）

## ① 収集

### 対象ファイル

```
/var/log/secure
/var/log/messages
```

### 最小要件

* CloudWatch Agent or rsyslog
* ローテート対応（logrotate任せでOK）

---

## ② Allowlist（最小・共通）

```yaml
trusted_ips:
  - 管理者のグローバルIP
trusted_users:
  - ec2-user
  - admin
```

※ Phase 0 では **messages 側には適用しない**

---

## ③ 検知ルール（Phase 0）

### 【secure】ルール

#### ルール S-1：Allowlist外 SSH 成功

* `Accepted`
* IP ∉ trusted_ips

👉 **最重要**

---

#### ルール S-2：password 認証成功

* `Accepted password for`

👉 **即インシデント候補**

---

#### ルール S-3：短時間の連続失敗

* `Failed password`
* 同一IP
* 5回 / 1分

👉 **攻撃兆候**

---

### 【messages】ルール（厳選）

#### ルール M-1：sshd / systemd の異常

キーワード例：

* `sshd.*error`
* `sshd.*fatal`
* `PAM.*authentication error`

👉 **侵入試行の副作用を拾う**

---

#### ルール M-2：network / kernel 異常

キーワード例：

* `kernel.*panic`
* `Out of memory`
* `segfault`

👉 **侵入後の不安定化検知**

---

# Phase 0 で「やらない」messages 活用

❌ 全行解析
❌ ユーザー行動推定
❌ コマンド推測
❌ sudo 解析

👉 **messages は「異常音検知」**

---

# secure / messages の役割分担（重要）

| 観点         | secure | messages |
| ---------- | ------ | -------- |
| 人の行為       | ◎      | △        |
| OS異常       | ✕      | ◎        |
| 誤検知耐性      | 高      | 低        |
| Phase 0 適性 | ◎      | △（限定）    |

---

# Phase 0 の完成形（イメージ）

```
[secure]
  ├─ 成功した？
  ├─ 失敗多すぎ？
  └─ password？

[messages]
  ├─ sshd壊れてない？
  ├─ PAM騒いでない？
  └─ kernel落ちてない？
```

---

# なぜこれ以上やらないのか（重要）

> Phase 0 は
> **「異常を見逃さない」フェーズであって
> 「正確に判断する」フェーズではない**

messages を深掘ると：

* ノイズ地獄
* 運用疲れ
* 放置される

👉 **必ず失敗する**

---

# 次フェーズへの自然な接続

| Phase | messages の使い方 |
| ----- | ------------- |
| 0     | 異常キーワード       |
| 1     | 条件追加          |
| 2     | Exec補完        |
| 3     | 状態遷移          |

---

## プロの一言まとめ

> **Phase 0 に messages を入れるなら
> 「音が鳴ったか」だけ聞け。内容は聞くな**

---

次に進めるなら👇

* **messages用の最小キーワードリスト**
* **CloudWatch Logs Insights 両ログ共通クエリ**
* **secure/messages の誤検知潰し**
* **Phase 1 で messages をどう昇格させるか**

どれをやりますか？


#MyTurn
以下をお願い。
•messages用の最小キーワードリスト
•secure/messages の誤検知潰し

#AiTurn
了解です。
**Phase 0前提（スモールスタート）**として、

* ✔ `/var/log/messages` 用の **最小キーワードリスト**
* ✔ `/var/log/secure` / `/var/log/messages` の **誤検知潰し（実務向け）**

を **そのまま使える形**で示します。

---

# ① `/var/log/messages` 用「最小キーワードリスト」

## 方針（重要）

* **侵入の“結果”や“副作用”だけを見る**
* ノイズ源（cron / systemd正常ログ）は見ない
* **grep一発で意味が通じるものだけ**

---

## ✅ 採用キーワード（Phase 0確定版）

### A. sshd / 認証系（secureを補完）

```text
sshd.*error
sshd.*fatal
PAM.*authentication error
PAM.*failed
```

📌 **意味**

* sshd が異常状態
* PAMレベルでの異常（ブルートフォースの副作用）

---

### B. 権限・ユーザー関連（侵入後兆候）

```text
useradd
groupadd
new user
```

📌 **意味**

* OSユーザー追加（secureに出ない場合がある）

---

### C. プロセス異常（侵入後の荒れ）

```text
segfault
killed process
Out of memory
```

📌 **意味**

* 不正ツール実行
* 暗号通貨マイナー典型

---

### D. kernel / system 異常（最重要）

```text
kernel.*panic
kernel.*BUG
systemd.*failed
```

📌 **意味**

* **即インシデント候補**
* Phase 0でも通知してよい

---

## ❌ あえて入れないキーワード（重要）

```text
cron
systemd.*Started
systemd.*Stopped
NetworkManager
```

👉 **100%ノイズ**

---

## 🎯 Phase 0 用 最小まとめ

```text
(sshd.*error|sshd.*fatal|PAM.*authentication error|
useradd|groupadd|segfault|Out of memory|
kernel.*panic|kernel.*BUG|systemd.*failed)
```

👉 **これ1本でOK**

---

# ② `/var/log/secure` / `/var/log/messages` 誤検知潰し

## 原則（超重要）

> **Phase 0では「誤検知ゼロ」を目指さない**
> **「通知疲れゼロ」を目指す**

---

## A. `/var/log/secure` の誤検知潰し

### 1️⃣ 正常成功ログは「条件付き」にする

❌ 悪い例

```
Accepted publickey
```

⭕ 良い例

```
Accepted publickey
AND IP ∉ trusted_ips
```

👉 **Allowlistが命**

---

### 2️⃣ 失敗ログは「回数」で縛る

❌

```
Failed password
```

⭕

```
Failed password ≥ 5回 / 1分 / 同一IP
```

👉 1回2回は無視

---

### 3️⃣ cron / sudo の通常動作を除外

```text
CRON
sudo.*session opened for user root
```

※ sudoは Phase 3 まで **見ない**

---

## B. `/var/log/messages` の誤検知潰し（最重要）

### 1️⃣ 「1回だけ」は無視する

messages は：

> **連続したら意味がある**

#### 実務ルール

* 同一キーワード
* 3回 / 5分 以上 → 通知

---

### 2️⃣ 起動直後は除外

```text
uptime < 10min
```

理由：

* systemd / kernel ノイズ多発

---

### 3️⃣ 定期ジョブ由来を除外

```text
cron
logrotate
```

---

## C. secure × messages の合わせ技（Phase 0の上限）

### 条件付き昇格ルール

```
secure で SSH成功
AND
5分以内に messages で kernel/sshd error
```

👉 **単体では通知しない**
👉 **組み合わさったら通知**

---

# Phase 0 完成形（実務目線）

```
[secure]
  - 成功（Allowlist外）
  - password成功
  - 連続失敗

[messages]
  - kernel/system 異常（連続）
  - sshd/PAM 異常（連続）

[抑制]
  - 起動直後
  - cron/systemd正常系
```

---

# プロの最終コメント

> **messages は「うるさいログ」ではない
> 使い方を間違えると「うるさくなるログ」**

Phase 0では
**「鳴ったら見に行く価値がある音」だけ拾う**
これが正解です。

---

次に進めるなら👇

* **CloudWatch Logs Insights 用の実クエリ**
* **grep + awk のローカル最小実装**
* **Phase 1 で messages をどう進化させるか**
* **secure/messages 正規化フォーマット**

どれをやりますか？


#MyTurn
**まだコードは書かないで**

Phase 0を実践するプラットフォームとプログラム言語の推奨を示して。
なお、前提としてos.secure/os.messagesの各ログは、日別EC2またはECSサーバー名別ログ種類別に、内容は元のログフォーマットのまま*.logというファイル名でGoogleドライブに保存されているとします。

例：
- AWSマネジメントコンソールのCloudShell上でシェルスクリプトで実行
- GoogleWorkSpace上でGASで実行


#AiTurn
了解しました。
**「まだコードは書かない」前提**で、
**Phase 0 を実践するためのプラットフォーム／言語の推奨**を、
あなたの前提条件に完全に合わせて整理します。

---

# 前提の再確認（重要）

* 対象ログ

  * `os.secure`
  * `os.messages`
* 形式

  * **元のログフォーマットそのまま**
  * `*.log`
* 保存先

  * **Google ドライブ**
* 単位

  * 日別
  * EC2 / ECS サーバー名別
  * ログ種類別

👉 **ログ収集・保存はすでに完了している**

---

# Phase 0 に求められる実行環境要件

Phase 0 は以下だけができれば十分です。

| 要件       | 内容         |
| -------- | ---------- |
| ファイル読み込み | *.log      |
| テキスト検索   | grep相当     |
| 時間集計     | 回数・期間      |
| 手動実行     | 定期自動化は後    |
| 説明可能     | ブラックボックス不可 |

❌ 高速処理
❌ 並列処理
❌ ML
❌ ストリーム処理

---

# 結論サマリ（先に）

| 候補                 | Phase 0適性 | 推奨度 |
| ------------------ | --------- | --- |
| CloudShell + shell | ◎         | ★★★ |
| ローカルPC + shell     | ◎         | ★★★ |
| Google Apps Script | △         | ★★  |
| Python（Colab等）     | △         | ★   |
| BigQuery           | ❌         | ★   |

---

# 推奨①：CloudShell + シェルスクリプト（最有力）

## なぜ最適か

### 技術的理由

* grep / awk / sed が最適解
* ログ形式をそのまま扱える
* 正規表現が強い

### 運用的理由

* **Phase 0 の思想と完全一致**
* 思考と処理が直結
* デバッグが圧倒的に楽

---

## 想定ユースケース

* Google Drive からログを取得
* 日付・サーバー単位で処理
* 結果を標準出力 or CSV

👉 **最初に選ぶならこれ**

---

## 向いている人

* ITエンジニア
* インフラ経験者
* ログを「読む」力がある

---

# 推奨②：ローカルPC + シェル（CloudShell代替）

## 位置づけ

CloudShellが使えない場合の **完全代替**

### メリット

* 実行制限が少ない
* ファイル操作が自由
* 試行錯誤がしやすい

### デメリット

* 環境差分管理が必要

---

# 推奨③：Google Apps Script（GAS）

## 位置づけ

**「非エンジニア共有」「可視化寄り」**

---

## 向いている理由

* Google Drive ネイティブ
* ログ管理と相性が良い
* 結果をスプレッドシートに出せる

---

## Phase 0での注意点（重要）

| 観点    | 評価 |
| ----- | -- |
| 正規表現  | △  |
| 大容量ログ | ✕  |
| 高速解析  | ✕  |
| 説明性   | ◎  |

👉 **件数が少ない／要約目的ならOK**

---

## GASの正しい役割

* 「異常候補の一覧化」
* 「人が見るための整理」

❌ 重いログ解析
❌ リアルタイム処理

---

# あえて勧めない選択肢

## Python（初期）

❌ 理由

* Phase 0には過剰
* ライブラリ設計が先行する
* 「コードを書くこと」が目的化しやすい

👉 Phase 3以降向け

---

## BigQuery / SIEM

❌ 理由

* 初期コストが高すぎる
* Phase 0の価値と不釣り合い

---

# フェーズ別 正しい進化ルート

```
Phase 0
  ↓
shell（grep）
  ↓
Phase 1
  ↓
shell + 軽い正規化
  ↓
Phase 2
  ↓
CloudTrail / Exec追加
  ↓
Phase 3
  ↓
Python / スコアリング
```

---

# プロの最終推奨（断言）

> **Phase 0は「道具を選ばない」のが成功条件**
> だが、
> **「ログをそのまま扱える環境」を選ばないと必ず詰む**

### したがって：

🥇 **CloudShell + shell**
🥈 **ローカル + shell**
🥉 **GAS（補助）**

---

次に進めるなら👇

* **CloudShell想定のディレクトリ設計**
* **GASでやるならどこまで許すか**
* **Phase 1で言語を切り替える判断基準**
* **複数サーバー横断時の考え方**

どれを整理しますか？


#MyTurn
**まだコードは書かないで**
敢えて「Google Apps Script（GAS）」の方法でを検討したい。
前提として前ターンでお伝えした通り既にログファイルはGoogleドライブに保存されているとします。
Phase 0の進め方、考慮すべきこと、確認すべきこと、その他注意点などがあれば示して


#AiTurn
了解です。
**「まだコードは書かない」**ことを厳守し、
**Google Apps Script（GAS）で Phase 0 を実践する場合の進め方**を
**設計・運用・注意点**の観点で体系的にまとめます。

---

# GASで Phase 0 をやる位置づけ（まず重要）

## 正直な評価（プロ視点）

> **GASは Phase 0 の“分析エンジン”ではなく
> 「異常候補の仕分け係」**

これを理解して使うと **失敗しません**。

---

# GAS Phase 0 のゴール定義

### やること

* ログファイルを**機械的に走査**
* **見る価値がある行だけを抽出**
* **人が判断できる形で並べる**

### やらないこと

* 正確な侵入判定
* 大量ログの高速処理
* 相関・スコアリング

---

# Phase 0（GAS版）全体フロー

```
① 対象ログを決める
② ログを分割して読む
③ 重要行だけ拾う
④ 誤検知を減らす
⑤ 結果を可視化
```

---

# ① 対象ログの決め方（最初の分岐）

## 対象ファイル条件（推奨）

* サイズ：**5MB以下**
* 行数：数万行以内
* 種類：

  * os.secure
  * os.messages

❗ これを超える場合
→ **Phase 0では分割前提**

---

# ② GASでログを扱う際の設計ポイント

## 重要制約（必ず理解）

| 制約      | 影響   |
| ------- | ---- |
| 実行時間制限  | 約6分  |
| メモリ     | 小さい  |
| 正規表現    | JS準拠 |
| ファイルI/O | 遅い   |

👉 **一気に全部読む設計はNG**

---

## 推奨アプローチ（考え方）

* ファイル単位で処理
* 行単位で逐次判定
* 途中で結果を捨てない

---

# ③ Phase 0 用「検知ロジックの考え方」

## secureログ（GAS向き）

### 見るもの

* `Accepted`
* `Failed password`
* `Accepted password`

### 理由

* フォーマットが比較的安定
* 行単位で意味が完結

---

## messagesログ（GASは慎重）

### 見るもの

* 事前に決めた**最小キーワードのみ**
* 単発では判断しない

👉 **拾いすぎるとGASが死ぬ**

---

# ④ 誤検知を抑えるための設計（超重要）

## 1️⃣ Allowlistは「除外条件」

* trusted IP
* trusted user

👉 GASでは
**「一致したらスキップ」**
が基本

---

## 2️⃣ 連続性を必須条件にする

### messages例

* 同一キーワード
* 同一ファイル
* 3回以上

👉 単発は無視

---

## 3️⃣ 起動直後ログを除外

* `system boot`
* `Started`

👉 Phase 0ではノイズ

---

# ⑤ 結果の出し方（GAS最大の強み）

## おすすめ出力先

### 🥇 Google スプレッドシート

| 列     | 内容              |
| ----- | --------------- |
| 日付    | ログ日             |
| サーバー  | EC2/ECS名        |
| ログ種別  | secure/messages |
| 種別    | ルールID           |
| メッセージ | 原文              |

👉 **人が即読める**

---

### 🥈 Google ドキュメント

* 日次レポート向き
* 定性確認

---

# Phase 0 実行頻度の考え方

| 頻度     | 推奨 |
| ------ | -- |
| 手動     | ◎  |
| 時間トリガー | △  |
| リアルタイム | ❌  |

👉 **最初は手動実行**

---

# GASで Phase 0 をやる際の注意点（失敗例）

## ❌ 失敗パターン

* 正規表現を凝りすぎる
* 全ログを一気に読む
* 検知＝即異常とする
* messagesを深掘りする

---

## ⭕ 成功パターン

* 「拾う」だけに徹する
* 人が最終判断
* 件数が増えたら撤退

---

# GAS採用時の撤退基準（重要）

> 次のどれかに該当したら
> **迷わず shell / Python に移行**

* 実行時間制限に頻繁に当たる
* ログサイズが増えた
* ルールが10個を超えた
* 日次自動化したくなった

---

# Phase 0（GAS版）チェックリスト

* [ ] 対象ログサイズを把握したか
* [ ] Allowlistを決めたか
* [ ] messages用キーワードを絞ったか
* [ ] 単発ログを除外しているか
* [ ] 出力先を決めたか

---

# プロの最終コメント

> **GASは「正しく使えば便利、欲張ると破綻」**

Phase 0では
**「Googleドライブにあるログを
人が判断できる形に整える」**
それだけで成功です。

---

次に進めるなら👇

* **GAS前提のディレクトリ／命名ルール**
* **secure/messages をどう分類するか**
* **Phase 1に進む判断基準**
* **GASでやってはいけない実装例**

どれを整理しますか？


#MyTurn
**まだコードは書かないで**
Phase 0です。
具体的な入力例、出力例、AllowList例を示して。
もしもAllowListを検知用とそれ以外用に分けるならそれら内容も示して。


#AiTurn
了解です。
**「まだコードは書かない」**を厳守し、
**Phase 0（GAS前提）**として、

* 具体的な **入力例（ログ）**
* 具体的な **出力例（人が見る結果）**
* **AllowList の具体例**
* 検知用 AllowList とそれ以外（抑制・例外）を分けた設計

を **そのまま設計書として使える形**で示します。

---

# Phase 0 の前提整理（再掲）

* 対象ログ

  * `os.secure`
  * `os.messages`
* ログ形式

  * 元のログフォーマットそのまま
* 保存先

  * Google ドライブ
* 目的

  * **「見る価値があるログ行」を抽出する**

---

# ① 入力例（ログファイル）

## 1-1. os.secure（入力例）

```
Jan 10 01:23:45 ip-10-0-1-5 sshd[1234]: Accepted publickey for ec2-user from 203.0.113.10 port 54321 ssh2
Jan 10 01:24:01 ip-10-0-1-5 sshd[1234]: Failed password for invalid user admin from 198.51.100.23 port 41122 ssh2
Jan 10 01:24:10 ip-10-0-1-5 sshd[1234]: Failed password for invalid user admin from 198.51.100.23 port 41123 ssh2
Jan 10 01:24:20 ip-10-0-1-5 sshd[1234]: Accepted password for root from 198.51.100.23 port 41124 ssh2
```

---

## 1-2. os.messages（入力例）

```
Jan 10 01:25:03 ip-10-0-1-5 sshd[1234]: error: PAM: Authentication failure for illegal user admin
Jan 10 01:25:10 ip-10-0-1-5 kernel: Out of memory: Killed process 2345 (xmrig)
Jan 10 01:25:20 ip-10-0-1-5 systemd[1]: sshd.service: Failed with result 'exit-code'
```

---

# ② AllowList の具体例

## 2-1. 検知用 AllowList（Detection AllowList）

> **「これに該当すれば“通常”とみなす」**

### IP

```
203.0.113.10    # 自宅回線
198.51.100.100 # 会社VPN
```

### ユーザー

```
ec2-user
admin
```

---

## 2-2. 抑制・例外用 AllowList（Suppression AllowList）

> **「検知はするが、通知しない／弱める」**

### 時間帯

```
09:00 - 21:00
```

### 既知の正常ノイズ

```
CRON
logrotate
systemd: Started
```

---

## 2-3. 分ける理由（重要）

| 種類  | 役割        |
| --- | --------- |
| 検知用 | 異常か正常かの判断 |
| 抑制用 | 通知疲れ防止    |

👉 **混ぜると破綻する**

---

# ③ 出力例（Phase 0 の成果物）

## 3-1. 出力形式（推奨）

**Google スプレッドシート 1行＝1事象**

| 日付         | サーバー        | ログ種別     | ルールID | 重要度      | 原文                                                |
| ---------- | ----------- | -------- | ----- | -------- | ------------------------------------------------- |
| 2026-01-10 | ip-10-0-1-5 | secure   | S-1   | WARN     | Accepted publickey for ec2-user from 203.0.113.10 |
| 2026-01-10 | ip-10-0-1-5 | secure   | S-2   | CRITICAL | Accepted password for root from 198.51.100.23     |
| 2026-01-10 | ip-10-0-1-5 | messages | M-1   | WARN     | PAM: Authentication failure                       |
| 2026-01-10 | ip-10-0-1-5 | messages | M-2   | CRITICAL | Out of memory: Killed process (xmrig)             |

---

## 3-2. 重要度の考え方（Phase 0）

| 重要度      | 意味  |
| -------- | --- |
| INFO     | 参考  |
| WARN     | 要確認 |
| CRITICAL | 即確認 |

👉 **遮断はしない**

---

# ④ ルールと AllowList の関係（図解）

```
ログ行
 ↓
ルール一致？
 ↓ YES
AllowList該当？
 ↓ YES → INFO or 無視
 ↓ NO
CRITICAL / WARN として出力
```

---

# ⑤ AllowList を分ける場合の設計まとめ

| 種類  | 内容        | 例          |
| --- | --------- | ---------- |
| 検知用 | IP / ユーザー | trusted IP |
| 抑制用 | 時間 / ノイズ  | cron       |
| 将来用 | 環境        | 本番 / 検証    |

---

# ⑥ Phase 0 で「やらない」こと（再確認）

❌ IP国判定
❌ ユーザー行動推定
❌ Exec / CloudTrail 相関
❌ スコアリング

---

# プロの最終まとめ

> **Phase 0 の正解は
> 「ログを“事件候補リスト”に変換すること」**

AllowList を
**用途別に分ける設計ができていれば**
この後の Phase 1〜3 が驚くほど楽になります。

---

次に進めるなら👇

* **Phase 0 のルール一覧（完全版）**
* **Phase 1 で AllowList をどう進化させるか**
* **誤検知が出たときの調整フロー**
* **GAS向けフォルダ構成案**

どれを見たいですか？


#MyTurn
Phase 0 のルール一覧（完全版）


#AiTurn
了解です。
ここでは **Phase 0（スモールスタート）で実装すべきルールを「完全版」**として、
**目的／入力／判定条件／出力レベル**まで含めて一覧化します。

> 前提
>
> * 対象ログ：`os.secure` / `os.messages`
> * 実装：GAS想定（だが汎用）
> * 方針：**単体ログで意味が完結するものだけ**

---

# Phase 0 ルール一覧（完全版）

## 【S系】os.secure（認証系）

---

### **S-1：AllowList 外 SSH ログイン成功**

**目的**

> 不正侵入「成功」の即時検知

**入力例**

```
Accepted publickey for ec2-user from 198.51.100.23
```

**判定条件**

* `Accepted`
* 接続元 IP ∉ 検知用 AllowList

**出力**

* 重要度：`WARN`
* 理由：通常運用外アクセス

---

### **S-2：password 認証でのログイン成功**

**目的**

> 設計違反・侵入成功の検知

**入力例**

```
Accepted password for root from 198.51.100.23
```

**判定条件**

* `Accepted password`

**出力**

* 重要度：`CRITICAL`
* 理由：パスワード認証成功

---

### **S-3：root ユーザーでのログイン成功**

**目的**

> 高リスク権限での侵入検知

**入力例**

```
Accepted publickey for root from 203.0.113.10
```

**判定条件**

* `Accepted`
* user = `root`

**出力**

* 重要度：`CRITICAL`

---

### **S-4：短時間の連続ログイン失敗（ブルートフォース）**

**目的**

> 攻撃兆候の早期検知

**入力例**

```
Failed password for invalid user admin from 198.51.100.23
```

**判定条件**

* `Failed password`
* 同一IP
* 5回以上 / 1分

**出力**

* 重要度：`WARN`

---

### **S-5：存在しないユーザーへのログイン試行**

**目的**

> 自動攻撃ツールの検知

**入力例**

```
Failed password for invalid user test from 198.51.100.23
```

**判定条件**

* `invalid user`

**出力**

* 重要度：`INFO`
  （単発では通知しない）

---

## 【M系】os.messages（OS・デーモン系）

---

### **M-1：sshd / PAM の異常**

**目的**

> 認証系の異常状態検知

**入力例**

```
sshd[1234]: error: PAM: Authentication failure
```

**判定条件**

* `sshd.*error` または
* `PAM.*authentication error`

**出力**

* 重要度：`WARN`

---

### **M-2：ユーザー／グループ追加**

**目的**

> 侵入後の権限維持行為検知

**入力例**

```
useradd[3456]: new user: name=evil
```

**判定条件**

* `useradd`
* `groupadd`

**出力**

* 重要度：`CRITICAL`

---

### **M-3：OOM / 不審プロセス終了**

**目的**

> マルウェア・マイナー兆候検知

**入力例**

```
Out of memory: Killed process 2345 (xmrig)
```

**判定条件**

* `Out of memory`
* `Killed process`

**出力**

* 重要度：`CRITICAL`

---

### **M-4：kernel 異常**

**目的**

> OS 侵害・不安定化検知

**入力例**

```
kernel: panic
```

**判定条件**

* `kernel.*panic`
* `kernel.*BUG`

**出力**

* 重要度：`CRITICAL`

---

### **M-5：systemd サービス異常終了**

**目的**

> sshd 停止などの兆候検知

**入力例**

```
systemd[1]: sshd.service: Failed with result 'exit-code'
```

**判定条件**

* `systemd.*Failed`

**出力**

* 重要度：`WARN`

---

## 【X系】横断ルール（Phase 0 上限）

---

### **X-1：secure 成功後、短時間で messages 異常**

**目的**

> 侵入直後の異常行動検知

**判定条件**

* S-1〜S-3 発生後
* 5分以内に M-1〜M-5

**出力**

* 重要度：`CRITICAL`

👉 **Phase 0 で唯一の相関ルール**

---

# Phase 0 ルール総数

| 種別       | 数      |
| -------- | ------ |
| secure   | 5      |
| messages | 5      |
| 横断       | 1      |
| **合計**   | **11** |

👉 **これ以上は Phase 1**

---

# Phase 0 判定レベルの使い分け

| レベル      | 対応     |
| -------- | ------ |
| INFO     | 記録のみ   |
| WARN     | 当日中に確認 |
| CRITICAL | 即確認    |

---

# プロの最終コメント

> **Phase 0 の完成条件は
> 「侵入成功を見逃さない」こと**

この **11ルール**を守れば、

* GAS
* shell
* Python

どれで実装しても **破綻しません**。

---

次に進めるなら👇

* **Phase 1 で追加するルール一覧**
* **Phase 0 → Phase 1 の昇格基準**
* **このルールをGASでどう表現するか（設計）**
* **誤検知が出た場合の具体的調整例**

どれを見たいですか？


#MyTurn
•このルールをGASでどう表現するか（設計）


#AiTurn
了解です。
ここでは **「まだコードは書かず」**、**Phase 0 ルールを Google Apps Script（GAS）でどう表現・設計するか**を、
**構造・責務・データの流れ**が分かるレベルまで落とします。

---

# Phase 0 を GAS で表現する設計

## 1. 全体像（思想）

### GAS でやること

* **ログを「読む」**
* **ルールに「当てはめる」**
* **結果を「構造化して出す」**

👉
**ログ解析エンジンを作る**
（自動対応・遮断はしない）

---

## 2. GAS における役割分離（最重要）

Phase 0 では **責務を必ず分けます**

```
[LogLoader] → [Normalizer] → [RuleEngine] → [Reporter]
```

---

## 3. コンポーネント設計

---

### ① LogLoader（入力担当）

**役割**

* Google Drive 上の `.log` を読むだけ
* 中身は一切解釈しない

**入力**

* フォルダ
* ファイル名

  * 日付
  * サーバー名
  * ログ種別（secure / messages）

**出力（概念）**

```
{
  server: "ec2-prod-01",
  logType: "secure",
  date: "2026-01-10",
  lines: [ "...", "..." ]
}
```

👉
**ここでは if 文を書かない**

---

### ② Normalizer（共通化担当）

**役割**

* ログ行を **最低限の共通構造** にする
* 意味解析はしない

**入力**

```
Jan 10 12:34:56 ip-10-0-0-1 sshd[1234]: Accepted publickey for ec2-user from 198.51.100.23
```

**出力（正規化例）**

```
{
  raw: "元のログ行",
  timestamp: "2026-01-10 12:34:56",
  program: "sshd",
  message: "Accepted publickey for ec2-user from 198.51.100.23",
  logType: "secure",
  server: "ec2-prod-01"
}
```

👉
**GAS で扱いやすくするための形**

---

### ③ RuleEngine（判定担当・核心）

#### ルールは「データ」として定義する

**重要思想**

> ルールを if 文で書かない

---

#### ルール定義（設計イメージ）

```
Rule {
  id: "S-1",
  targetLogType: "secure",
  includeKeywords: ["Accepted"],
  excludeKeywords: [],
  condition: (normalizedLog) => true / false,
  severity: "WARN",
  description: "AllowList外SSH成功"
}
```

👉
Phase 0 では

* 正規表現
* 単純文字列一致
  のみ使用

---

#### AllowList の扱い

AllowList は **ルールとは別**

```
AllowList {
  trustedIps: [...],
  trustedUsers: [...],
  detectionOnlyIps: [...]
}
```

RuleEngine は

* 「IP が AllowList に含まれるか」を **参照するだけ**

---

### ④ Reporter（出力担当）

**役割**

* 判定結果を人間が読める形にする
* まだ通知はしない

**出力例（概念）**

```
{
  ruleId: "S-2",
  severity: "CRITICAL",
  server: "ec2-prod-01",
  logType: "secure",
  timestamp: "2026-01-10 12:34:56",
  reason: "password認証でのログイン成功",
  rawLog: "..."
}
```

**保存先**

* Google スプレッドシート
* JSON ファイル
* ログ用 Google Drive フォルダ

👉
Phase 0 では **可視化最優先**

---

## 4. GAS でのデータフロー（時系列）

```
1. Driveから*.log取得
2. 行単位に分割
3. Normalizerで整形
4. RuleEngineで全ルール評価
5. 該当結果のみ Reporterへ
```

---

## 5. Phase 0 用の設計上の制限（重要）

### ❌ やらないこと

* 相関分析（except X-1）
* 機械学習
* 自動遮断
* CloudWatch連携
* API連携

### ✅ やること

* 再現性
* 可読性
* ログを壊さない

---

## 6. Phase 0 の成功条件（設計観点）

| 項目    | 合格条件          |
| ----- | ------------- |
| ログ追加  | フォルダに置くだけ     |
| ルール追加 | 定義を1つ増やすだけ    |
| 誤検知対応 | AllowList編集のみ |
| 再実行   | 同じ結果が出る       |

---

## 7. Phase 1 へ進める設計上の伏線

この設計なら👇がそのまま可能

* スコアリング導入 → RuleEngine拡張
* 時系列相関 → Reporter拡張
* 通知 → Reporter差し替え
* BigQuery移行 → LogLoader差し替え

---

## プロの視点まとめ

> **Phase 0 は
> 「ログ × ルール × 人間の目」**

GAS は

* **運用者が読める**
* **修正が楽**
* **失敗しても安全**

という点で、
**Phase 0 に最適解**です。

---

次の一手としておすすめ👇

* **GAS 用ディレクトリ／ファイル構成案**
* **ルール定義をどうSpreadsheetで持つか**
* **Phase 0 の疑似コード（非実装）**
* **誤検知が出た時の調整フロー**

どこまで深掘りしますか？


#MyTurn
ルール定義をどうSpreadsheetで持つか


#AiTurn
了解です。
ここでは **「Phase 0 のルール定義を Google スプレッドシートでどう持つか」**を、
**実運用で破綻しない設計**として具体化します。

※ **まだコードは書きません**

---

# なぜ Spreadsheet でルールを持つのか

* 非エンジニアでも見られる
* 変更履歴が残る
* GAS との親和性が非常に高い
* Phase 1 / 2 へ自然に拡張できる

👉
**「if文を書かない」思想の実体**

---

# シート構成（Phase 0 推奨）

```
[Rules]          ← ルール定義（最重要）
[AllowList]      ← 信頼情報
[KeywordDict]    ← 誤検知潰し用（任意）
[Config]         ← 全体設定（任意）
```

---

## ① Rules シート（中核）

### 列構成（Phase 0 完全版）

| 列                 | 内容                    | 例               |
| ----------------- | --------------------- | --------------- |
| A: RuleID         | ルール識別子                | S-1             |
| B: Enabled        | 有効/無効                 | TRUE            |
| C: LogType        | secure / messages     | secure          |
| D: Program        | 対象プロセス                | sshd            |
| E: Include        | 含めるキーワード              | Accepted        |
| F: Exclude        | 除外キーワード               | from 127.0.0.1  |
| G: MatchType      | match / regex         | match           |
| H: Severity       | INFO/WARN/CRITICAL    | WARN            |
| I: Description    | 説明                    | AllowList外SSH成功 |
| J: AllowListCheck | NONE / DETECT / BLOCK | DETECT          |

---

### Rules シート例

| RuleID | Enabled | LogType  | Program | Include           | Exclude | MatchType | Severity | Description     | AllowListCheck |
| ------ | ------- | -------- | ------- | ----------------- | ------- | --------- | -------- | --------------- | -------------- |
| S-1    | TRUE    | secure   | sshd    | Accepted          |         | match     | WARN     | AllowList外SSH成功 | DETECT         |
| S-2    | TRUE    | secure   | sshd    | Accepted password |         | match     | CRITICAL | password認証成功    | NONE           |
| M-2    | TRUE    | messages | useradd | useradd           |         | match     | CRITICAL | ユーザー追加          | NONE           |

---

### 設計意図

* **1行 = 1ルール**
* 条件は「できるだけ文字列」
* ロジックは GAS に閉じ込める
* AllowList参照はフラグで制御

---

## ② AllowList シート

### 列構成

| 列          | 内容             |
| ---------- | -------------- |
| A: Type    | IP / USER      |
| B: Value   | 値              |
| C: Purpose | BLOCK / DETECT |
| D: Note    | メモ             |

---

### AllowList 例

| Type | Value           | Purpose | Note   |
| ---- | --------------- | ------- | ------ |
| IP   | 203.0.113.10    | BLOCK   | 自宅固定IP |
| IP   | 198.51.100.0/24 | DETECT  | 社内回線   |
| USER | ec2-user        | BLOCK   | 正規ユーザー |
| USER | deploy          | DETECT  | CI用    |

---

### Purpose の意味

| Purpose | 意味           |
| ------- | ------------ |
| BLOCK   | 完全に無視（誤検知潰し） |
| DETECT  | 検知対象だがスコア下げ  |

👉
Phase 0 では **BLOCK/DETECT の2段階で十分**

---

## ③ KeywordDict シート（誤検知対策）

**目的**

* Rules を汚さない
* 環境依存ログを吸収

### 列構成

| 列          | 内容                 |
| ---------- | ------------------ |
| A: Keyword | キーワード              |
| B: LogType | secure/messages    |
| C: Action  | IGNORE / DOWNGRADE |
| D: Note    | 理由                 |

---

### 例

| Keyword        | LogType  | Action | Note |
| -------------- | -------- | ------ | ---- |
| sudo           | messages | IGNORE | 通常操作 |
| CRON           | messages | IGNORE | 定期処理 |
| systemd-logind | messages | IGNORE | 正常   |

---

## ④ Config シート（任意）

### 例

| Key               | Value |
| ----------------- | ----- |
| TimeWindowMinutes | 5     |
| FailThreshold     | 5     |
| OutputSeverity    | WARN  |

---

## 8. GAS 側の扱い方（設計）

### ルール評価の順序

```
1. Enabled = TRUE
2. LogType 一致
3. Program 一致（空なら無視）
4. Include 判定
5. Exclude 判定
6. AllowList 判定
7. Reporter へ
```

---

## Phase 0 における Spreadsheet 設計の成功条件

| 観点    | OK                       |
| ----- | ------------------------ |
| ルール追加 | 行を追加                     |
| 誤検知   | AllowList or KeywordDict |
| 無効化   | Enabled = FALSE          |
| 監査    | シート履歴                    |

---

## プロの視点（重要）

> **Spreadsheet は「ルールブック」**
> **GAS は「審判」**

この分離ができていれば

* 後戻りしない
* 拡張が怖くない
* 人に引き継げる

---

次の深掘り候補👇

* **Rules シートの列を Phase 1 用に拡張する案**
* **Spreadsheet → GAS の読み込み設計**
* **誤検知が出た時の運用フロー（人間側）**
* **ルールID設計の流儀**

どれに進みますか？


#MyTurn
Phase 0の処理をフローチャートに示して


#AiTurn
了解です。
ここでは **Phase 0 の処理全体を「フローチャート（思考・処理の流れ）」として可視化**します。
GAS実装を前提にしていますが、**処理概念として読める形**にします。

---

# Phase 0 処理フローチャート（全体）

```
┌──────────────┐
│ 処理開始     │
└──────┬───────┘
       │
       ▼
┌────────────────────┐
│ 設定・定義の読込   │
│ - Rules シート     │
│ - AllowList        │
│ - KeywordDict      │
└──────┬─────────────┘
       │
       ▼
┌────────────────────┐
│ 対象ログ取得       │
│ Google Drive から  │
│ *.log ファイル列挙 │
└──────┬─────────────┘
       │
       ▼
┌────────────────────┐
│ ログファイル単位   │
│ でループ開始       │
└──────┬─────────────┘
       │
       ▼
┌────────────────────┐
│ ログ行を1行取得    │
└──────┬─────────────┘
       │
       ▼
┌────────────────────┐
│ Normalizer          │
│ - 時刻抽出         │
│ - program抽出      │
│ - message抽出      │
│ - logType付与      │
└──────┬─────────────┘
       │
       ▼
┌────────────────────┐
│ KeywordDict 判定   │
│ IGNORE ?           │
└──────┬─────────────┘
       │Yes                 No
       ├──────────▶ 次の行
       ▼
┌────────────────────┐
│ Rules ループ開始   │
└──────┬─────────────┘
       │
       ▼
┌────────────────────┐
│ Rule 有効？        │
└──────┬─────────────┘
       │No                  Yes
       ├──────────▶ 次ルール
       ▼
┌────────────────────┐
│ LogType 一致？     │
└──────┬─────────────┘
       │No                  Yes
       ├──────────▶ 次ルール
       ▼
┌────────────────────┐
│ Program 一致？     │
│ (空ならスキップ)  │
└──────┬─────────────┘
       │No                  Yes
       ├──────────▶ 次ルール
       ▼
┌────────────────────┐
│ Include 条件一致？ │
└──────┬─────────────┘
       │No                  Yes
       ├──────────▶ 次ルール
       ▼
┌────────────────────┐
│ Exclude 条件一致？ │
└──────┬─────────────┘
       │Yes                 No
       ├──────────▶ 次ルール
       ▼
┌────────────────────┐
│ AllowList 判定     │
│ - BLOCK ?          │
│ - DETECT ?         │
└──────┬─────────────┘
       │BLOCK
       ├──────────▶ 次ルール
       │
       ▼
┌────────────────────┐
│ 検知結果生成       │
│ - RuleID           │
│ - Severity         │
│ - 理由             │
│ - raw log          │
└──────┬─────────────┘
       │
       ▼
┌────────────────────┐
│ Reporter へ渡す    │
│ (保存・表示)      │
└──────┬─────────────┘
       │
       ▼
┌────────────────────┐
│ 次のルール         │
└──────┬─────────────┘
       │
       ▼
┌────────────────────┐
│ 次のログ行         │
└──────┬─────────────┘
       │
       ▼
┌────────────────────┐
│ 次のログファイル   │
└──────┬─────────────┘
       │
       ▼
┌────────────────────┐
│ 処理終了           │
└────────────────────┘
```

---

# フローチャートの読みどころ（重要）

## ① 「早期 continue」が多い

* 誤検知を**できるだけ早く落とす**
* CPU・GAS 実行時間節約

👉 実装がきれいになる

---

## ② AllowList は「最後の関門」

* ルール一致後に初めて参照
* ルールを汚さない

---

## ③ Phase 0 の限界線

```
1ログ行 = 0 or 1 判定
```

* 時系列相関は **X-1 のみ**
* スコアは **まだ導入しない**

---

## ④ Phase 1 で増える場所（予告）

このフローの **差し込みポイント**：

* Normalizer後 → 行バッファ
* Reporter前 → スコア集計
* Reporter → 通知連携

👉 **フローは壊さず拡張**

---

# まとめ（プロ視点）

> Phase 0 の理想状態は
> **「静かに、確実に、侵入成功だけ拾う」**

このフローチャートは

* GAS
* Python
* shell

どれで実装しても **同じ思想で動きます**。

---

次に進むなら👇

* **Phase 1 用のフローチャート（差分）**
* **このフローを擬似コードで表す**
* **人間の運用フロー（誰がいつ見るか）**
* **この設計で起きやすい失敗例**

どれにしますか？


#MyTurn
この設計で起きやすい失敗例


#AiTurn
とても良い観点です。
**Phase 0 のこの設計は「安全で堅実」ですが、実際に回すと必ずハマりどころが出ます。**
ここでは **「この設計だからこそ起きやすい失敗例」**を、
**原因 → 兆候 → 回避策** の形で整理します。

---

# Phase 0 設計で起きやすい失敗例（実践版）

## 失敗①：ルールを増やしすぎて Phase 0 が壊れる

### 原因

* 「これも検知したい」が積み重なる
* messages に細かいルールを入れすぎる

### 兆候

* WARN が大量に出る
* 毎回スプレッドシートを見る気が失せる
* CRITICAL が埋もれる

### 回避策

* **Phase 0 の合格条件を固定**

  * 「侵入成功を見逃さない」
* ルール数を **15未満** に制限
* messages は「侵入後兆候」だけ

---

## 失敗②：AllowList がブラックホール化する

### 原因

* 誤検知が出るたびに BLOCK に入れる
* Purpose の使い分けをしない

### 兆候

* 何も検知されなくなる
* 「安全なはずなのに何も見えない」

### 回避策

* **初期は DETECT のみ**
* BLOCK は

  * 固定IP
  * 明確な定常ユーザー
* Note 列を必須化

---

## 失敗③：messages を信頼しすぎる

### 原因

* kernel / systemd を過剰に重視
* 正常な再起動を異常扱い

### 兆候

* 深夜に CRITICAL が出るが問題なし
* 毎回「仕様でした」で終わる

### 回避策

* messages 単体で CRITICAL にしない
* **secure → messages の順序を守る**

---

## 失敗④：ログフォーマット差異を甘く見る

### 原因

* Amazon Linux と Rocky の差
* sshd のバージョン差

### 兆候

* あるサーバーだけ検知されない
* Program が空になる

### 回避策

* Normalizer は「抽出失敗を許容」
* Program 一致は **必須にしない**
* raw log を必ず残す

---

## 失敗⑤：Severity の意味が崩壊する

### 原因

* CRITICAL を多用
* WARN と INFO の境界が曖昧

### 兆候

* CRITICAL が来ても驚かない
* 後から見返さない

### 回避策

**Phase 0 の厳格ルール**

| Severity | 許可条件         |
| -------- | ------------ |
| INFO     | 攻撃兆候         |
| WARN     | 通常外挙動        |
| CRITICAL | 侵入成功 or 破壊行為 |

---

## 失敗⑥：人間の運用フローが未定義

### 原因

* 技術的には完成して満足
* 誰がいつ見るか決めていない

### 兆候

* 検知結果が放置
* 数週間後に存在を忘れる

### 回避策

* Phase 0 の運用を決める

```
・1日1回見る
・CRITICAL は即確認
・WARN は週次棚卸し
```

---

## 失敗⑦：GAS 実行制限に後から気づく

### 原因

* ログ量増加を想定しない
* 一括処理

### 兆候

* 実行時間超過
* 途中で止まる

### 回避策

* 日別・サーバー別に分割
* 実行単位を小さく

---

## 失敗⑧：Phase 1 への道を塞ぐ設計

### 原因

* ルールに if 文を書きまくる
* 例外処理をコードに埋める

### 兆候

* 仕様変更が怖い
* 自分しか触れない

### 回避策

* ルールは Spreadsheet
* GAS は「評価エンジン」

---

## 失敗⑨：「完璧にしたくなる病」

### 原因

* セキュリティに真面目
* 正解を作ろうとする

### 兆候

* いつまでも運用開始しない
* 設計が終わらない

### 回避策

> **Phase 0 は雑でいい**

* 誤検知は出てよい
* 見逃しだけ防ぐ

---

# 失敗しないための Phase 0 チェックリスト

* [ ] CRITICAL は 1日0〜2件以内
* [ ] raw log を必ず残している
* [ ] BLOCK は最小限
* [ ] ルール数 < 15
* [ ] 「今日は何もなかった」と言える

---

# プロの総括

> **Phase 0 の敵は攻撃者ではなく「自分」**

この設計は
**やりすぎると壊れ、
抑えれば長く使える**。

---

次に進むなら👇

* **この失敗を防ぐための初期値テンプレ**
* **Phase 0 運用の1週間モデル**
* **Phase 1 に進む判断基準**
* **この設計での「絶対にやってはいけない5つ」**

どれを見たいですか？


#MyTurn
Phase 0 運用の1週間モデル


#AiTurn
了解です。
ここでは **「Phase 0 を“本当に回す”ための1週間モデル」**を示します。
技術ではなく、**人間の行動レベル**に落とします。

---

# Phase 0 運用の1週間モデル（実践用）

## 基本前提（重要）

* Phase 0 の目的
  👉 **侵入成功を見逃さない**
* 運用者
  👉 **あなた 1人（属人OK）**
* 所要時間
  👉 **1日 5〜10分**

---

## Day 0（導入日・初回実行）

### やること（30〜60分）

1. GAS 手動実行
2. 出力結果を**全部読む**
3. CRITICAL / WARN / INFO を目視確認

### 見るポイント

* 「これは本当に異常か？」
* 「設計上、想定通り出ているか？」

### この日は調整 OK

* AllowList（DETECT のみ）
* KeywordDict（IGNORE）

❌ BLOCK はまだ使わない

---

## Day 1（月）

### 朝 or 昼（5分）

1. 前日分を確認
2. CRITICAL があるか？

#### CRITICAL があった場合

* raw log を必ず確認
* 自分の操作か？

  * Yes → Note を書く
  * No → 即調査

### この日にやらないこと

* ルール追加
* Severity 変更

---

## Day 2（火）

### 確認（5分）

* WARN が多いか？
* 同じ種類が繰り返されているか？

### 調整 OK

* KeywordDict に IGNORE 追加
* AllowList に DETECT 追加

👉
**BLOCK はまだ禁止**

---

## Day 3（水）

### 中間点レビュー（10分）

#### 自問する

* CRITICAL は「緊張感」があるか？
* WARN をちゃんと読んでいるか？

### 調整 OK

* Severity を下げる（CRITICAL → WARN）
* ルールを 1つ無効化

---

## Day 4（木）

### 安定性チェック（5分）

* 前日と似た結果か？
* ノイズが減っているか？

### この日から許可

* AllowList の **BLOCK 追加**

条件：

* 固定IP
* 1週間繰り返し出ている
* 明確に正規操作

---

## Day 5（金）

### 週次まとめ（15分）

#### 見る観点

| 観点       | 確認       |
| -------- | -------- |
| CRITICAL | 本当に0〜数件か |
| WARN     | 意味のあるものか |
| INFO     | 多すぎないか   |

#### 決めること

* 残すルール
* 捨てるルール
* Phase 1 に進むか？

---

## Day 6（土）

### 軽い再確認（任意）

* 異常が出ていないか
* GAS が正常完走しているか

👉
**何もなければ何もしない**

---

## Day 7（日）

### 判断日（10分）

#### Yes/No チェック

* [ ] CRITICAL を即確認できた
* [ ] 誤検知にイライラしない
* [ ] 結果を見る習慣がついた

👉
**YES が2つ以上 → Phase 0 成功**

---

# Phase 0 成功の状態（理想）

* 「今日は何もなかった」
* ログを全部読まなくていい
* 侵入成功は確実に拾える
* 自分が壊していないと分かる

---

# Phase 0 を失敗させない黄金ルール

1. **毎日短時間**
2. **いじりすぎない**
3. **BLOCK は遅らせる**
4. **完璧を目指さない**

---

# 次に進む判断基準（重要）

### Phase 1 に進むべきタイミング

* 「侵入成功は見逃さない」と確信できた
* WARN を“眺める余裕”が出た
* スコアリングを入れたくなった

---

# プロの一言

> **Phase 0 は「技術導入」ではなく
> 「習慣づくり」**

ここを越えられた人だけが
Phase 1 を“武器”として使えます。

---

次の候補👇

* **Phase 1 運用モデル**
* **スコアリング導入の瞬間**
* **通知（Slack/メール）を入れるタイミング**
* **この運用を他人に引き継ぐ方法**

どれに進みますか？


#MyTurn
**まだコードは書かないで**
Phase 0(GAS想定)のプロジェクト構成をツリー形式で示して。
各ルールの定義はGoogleスプレッドシートへ掲載にするとします。
ログはプロジェクトとは別フォルダに設置されているとします。


#AiTurn
了解です。
**「まだコードは書かない」**を厳守し、
**Phase 0（GAS想定）のプロジェクト構成**を
**役割が一目で分かるツリー形式**で示します。

前提条件はすべて反映しています。

---

# 前提条件（反映済み）

* 実行基盤：**Google Apps Script**
* ルール定義：**Google スプレッドシート**
* ログファイル：**プロジェクトとは別フォルダ（Google Drive）**
* 対象ログ：

  * `os.secure`
  * `os.messages`
* Phase：**0（スモールスタート）**

---

# Phase 0（GAS想定）プロジェクト構成ツリー

```
Phase0-LogDetection-GAS/
│
├─ README.md
│   └─ プロジェクト概要・Phase 0 の目的
│
├─ appsscript.json
│   └─ GAS マニフェスト
│
├─ src/
│   │
│   ├─ Main.gs
│   │   └─ 実行エントリーポイント
│   │      ・手動実行用
│   │      ・全体フロー制御のみ
│   │
│   ├─ loader/
│   │   ├─ LogLoader.gs
│   │   │   └─ Google Drive から *.log を取得
│   │   │      ・日付別
│   │   │      ・サーバー別
│   │   │      ・ログ種別別
│   │   │
│   │   └─ FileIterator.gs
│   │       └─ ファイル・行の逐次処理
│   │
│   ├─ normalizer/
│   │   └─ LogNormalizer.gs
│   │       └─ ログ1行を最低限の共通形式に変換
│   │          ・timestamp
│   │          ・program
│   │          ・message
│   │          ・raw log
│   │
│   ├─ rules/
│   │   ├─ RuleEngine.gs
│   │   │   └─ ルール評価エンジン
│   │   │      ・Spreadsheet 定義を評価
│   │   │      ・if文を極力書かない
│   │   │
│   │   ├─ RuleDefinition.gs
│   │   │   └─ Spreadsheet 上の Rules シート構造定義
│   │   │
│   │   └─ RuleResult.gs
│   │       └─ ルール判定結果のデータ構造
│   │
│   ├─ allowlist/
│   │   ├─ AllowListLoader.gs
│   │   │   └─ AllowList シート読込
│   │   │
│   │   └─ AllowListChecker.gs
│   │       └─ IP / USER の一致判定
│   │
│   ├─ filter/
│   │   └─ KeywordFilter.gs
│   │       └─ KeywordDict による除外・抑制判定
│   │
│   ├─ reporter/
│   │   ├─ ResultCollector.gs
│   │   │   └─ 検知結果を一時保持
│   │   │
│   │   └─ SpreadsheetReporter.gs
│   │       └─ 結果をスプレッドシートへ出力
│   │
│   └─ config/
│       └─ ConfigLoader.gs
│           └─ Config シートの値取得
│
└─ docs/
    ├─ phase0-design.md
    │   └─ Phase 0 設計思想・制約
    │
    ├─ rule-list.md
    │   └─ Phase 0 ルール一覧（S-1〜M-5, X-1）
    │
    └─ operation.md
        └─ Phase 0 運用ルール（1週間モデル）
```

---

# Google スプレッドシート側（別管理）

```
Phase0-RuleBook (Spreadsheet)
│
├─ Rules
│   └─ ルール定義（1行=1ルール）
│
├─ AllowList
│   └─ IP / USER の信頼情報
│
├─ KeywordDict
│   └─ 誤検知抑制用キーワード
│
└─ Config
    └─ 全体設定（閾値など）
```

👉 **GAS はこの Spreadsheet を読むだけ**

---

# ログファイル配置（完全分離）

```
LogArchive/
│
├─ 2026-01-10/
│   ├─ ec2-prod-01/
│   │   ├─ os.secure.log
│   │   └─ os.messages.log
│   │
│   └─ ecs-batch-01/
│       ├─ os.secure.log
│       └─ os.messages.log
│
└─ 2026-01-11/
    └─ ...
```

👉 **GAS プロジェクトとは完全に独立**

---

# この構成の設計意図（重要）

## ① 変更頻度の分離

| 要素        | 変更頻度 |
| --------- | ---- |
| GAS ロジック  | 低    |
| ルール       | 高    |
| AllowList | 高    |
| ログ        | 日次   |

👉 **壊れにくい**

---

## ② Phase 1 以降への拡張余地

* rules/ にスコア項目追加
* reporter/ に通知追加
* loader/ を差し替え（CloudWatch 等）

👉 **ツリーを壊さず成長可能**

---

# プロの最終コメント

> **Phase 0 のプロジェクト構成は
> 「今のため」ではなく
> 「あとで捨てなくていい」ことが重要**

この構成なら：

* Phase 0 で止めてもOK
* Phase 3 まで育ててもOK

---

次に進めるなら👇

* **この構成での初期ファイル役割詳細**
* **Phase 0 → Phase 1 の差分ツリー**
* **最小構成に削った簡易版ツリー**
* **この構成でのレビュー観点チェックリスト**

どれを見たいですか？
