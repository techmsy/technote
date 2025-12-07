*>AWSのS3に保存されているec2のログ情報を一気に取得したい。1日分を

*>了解。**「S3にあるEC2ログを“1日分まとめて一気に取得”**」なら、だいたいこの3パターンが最速＆安全です。
（前提として、ログが**日付ごとのプレフィックス**や**ファイル名**で整理されているほど楽です）

---

## まず最短：日付ごとにフォルダ（プレフィックス）が切られている場合

たとえばこんな構造：

* `s3://my-bucket/ec2-logs/2025/12/05/...`
* `s3://my-bucket/ec2-logs/date=2025-12-05/...` みたいなやつ

### AWS CLIで一発

```bash
# 例1: 年/月/日で切られている
aws s3 sync s3://my-bucket/ec2-logs/2025/12/05/ ./logs/2025-12-05/

# 例2: date=YYYY-MM-DD 形式
aws s3 sync s3://my-bucket/ec2-logs/date=2025-12-05/ ./logs/2025-12-05/
```

**ポイント**

* `sync` は差分だけ落とすので再実行にも強い
* 大量ファイルでも比較的安定

---

## 次に現実的：ファイル名に日付が入っている場合

例：

* `something-2025-12-05.log.gz`
* `.../2025-12-05-0001.log.gz`

### include/excludeで絞る

```bash
aws s3 sync s3://my-bucket/ec2-logs/ ./logs/2025-12-05/ \
  --exclude "*" \
  --include "*2025-12-05*"
```

---

## ちょっと硬いが万能：S3の更新日時で“1日分”を抽出してダウンロード

プレフィックスが不明でもいける方法。
**boto3で LastModified を見て 1日分だけ落とす**。

### Python（並列DL）

```python
import os
from datetime import datetime, timezone, timedelta
from concurrent.futures import ThreadPoolExecutor, as_completed

import boto3

BUCKET = "my-bucket"
PREFIX = "ec2-logs/"  # ここは分かる範囲でOK。分からなければ "" も可
LOCAL_DIR = "./logs/2025-12-05"

# 取得したい日 (JST 기준で考えるなら、UTCに変換して判定するのが安全)
target_date = "2025-12-05"

# ここでは簡易的にUTC日付で 00:00:00-23:59:59 を判定
start = datetime.fromisoformat(target_date).replace(tzinfo=timezone.utc)
end = start + timedelta(days=1)

s3 = boto3.client("s3")

os.makedirs(LOCAL_DIR, exist_ok=True)

def iter_objects():
    token = None
    while True:
        kwargs = dict(Bucket=BUCKET, Prefix=PREFIX, MaxKeys=1000)
        if token:
            kwargs["ContinuationToken"] = token
        resp = s3.list_objects_v2(**kwargs)
        for obj in resp.get("Contents", []):
            yield obj
        if resp.get("IsTruncated"):
            token = resp.get("NextContinuationToken")
        else:
            break

def should_download(obj):
    lm = obj["LastModified"]
    return start <= lm < end

def download_key(key):
    # S3キーの階層をローカルに再現
    local_path = os.path.join(LOCAL_DIR, key[len(PREFIX):] if key.startswith(PREFIX) else key)
    os.makedirs(os.path.dirname(local_path), exist_ok=True)
    s3.download_file(BUCKET, key, local_path)
    return key

targets = [obj["Key"] for obj in iter_objects() if should_download(obj)]

print(f"targets: {len(targets)} objects")

# 並列ダウンロード
with ThreadPoolExecutor(max_workers=16) as ex:
    futures = [ex.submit(download_key, k) for k in targets]
    for f in as_completed(futures):
        k = f.result()
        print("downloaded:", k)
```

**補足**

* もしログが **JST基準で日付フォルダ**を作っているなら、
  UTC判定だとズレる可能性があるので、JST→UTCに変換して `start/end` を作る版に直すと完璧。

---

## 「ダウンロードじゃなく、1日分を分析したい」なら

**全部落とすより速くて安い**ことが多い。

* **Athena** でS3上のログをSQLで読む
* 形式がParquet/JSON/CSVなら特に強い
* 日付パーティションがあると爆速

---

## つまずきポイント（地味に重要）

* **ログがGZip大量**：ローカル容量に注意
* **オブジェクト数が膨大**：
  `list_objects_v2` のページング必須
* **コスト**：
  データ転送料（同リージョンなら安い/ゼロのことが多いがケース次第）と
  リクエスト数が積み上がる
* **権限**：
  `s3:ListBucket`, `s3:GetObject` は最低限必要
* **最適解は“日付プレフィックス運用”**：
  今後のために `.../YYYY/MM/DD/` や `date=YYYY-MM-DD/` に統一すると
  取得も分析も劇的に楽

---

## いちばんおすすめの結論

1日分を**確実＆早く**取るなら：

1. **日付プレフィックスに合わせて `aws s3 sync`**
2. それが無理なら **`--include "*YYYY-MM-DD*"`**
3. 最後の手段として **boto3で LastModified フィルタ＋並列DL**

---

必要なら、
あなたの**バケット構造（キー例を数本）**に合わせて

* 最短の `aws s3` コマンドをピンポイント作成
* もしくは **JST基準でピッタリ1日分**に合わせたPython版
* さらに **Athenaテーブル定義とパーティション設計**

まで、実運用レベルでセットにして出すよ。

*>Win11またはWSL2(Ubuntu)で実行したい。
環境構築方法を示して。
可能であれば一般的な例でのプロジェクト構成をツリー形式で示して

*>OK。**Win11ネイティブでもWSL2(Ubuntu)でも動く**ように、
S3のEC2ログを**1日分まとめて取得**するための環境構築と、
**一般的なプロジェクト構成（ツリー）**をセットで示します。

ここでは以下の2ルートを用意します：

* ルートA：**AWS CLIで取得（最短・一番ラク）**
* ルートB：**Python(boto3)で柔軟に取得（条件抽出や並列など）**

---

# ルートA：AWS CLI（最短で動かす）

## Win11（PowerShell）

### 1) AWS CLI をインストール

最も簡単なのは winget：

```powershell
winget install -e --id Amazon.AWSCLI
```

確認：

```powershell
aws --version
```

### 2) 認証情報を設定

```powershell
aws configure
```

入力するもの：

* AWS Access Key ID
* AWS Secret Access Key
* Default region name（例：ap-northeast-1）
* Default output format（json でOK）

> 会社/組織の運用で SSO を使っているなら
> `aws configure sso` でもOK。

### 3) 1日分を取得（例）

ログが日付プレフィックスに分かれている想定：

```powershell
aws s3 sync s3://my-bucket/ec2-logs/2025/12/05/ .\logs\2025-12-05\
```

ファイル名に日付が入っている場合：

```powershell
aws s3 sync s3://my-bucket/ec2-logs/ .\logs\2025-12-05\ `
  --exclude "*" `
  --include "*2025-12-05*"
```

---

## WSL2（Ubuntu）

### 1) AWS CLI を入れる

Ubuntuならこれが楽：

```bash
sudo apt update
sudo apt install -y awscli
aws --version
```

※より新しいCLIが必要なら pip版でもOK（後述）。

### 2) 認証情報を設定

```bash
aws configure
```

### 3) 1日分を取得

```bash
aws s3 sync s3://my-bucket/ec2-logs/2025/12/05/ ./logs/2025-12-05/
```

---

# ルートB：Python(boto3)で取得

**プレフィックスが曖昧 / LastModifiedで絞りたい / 並列DLしたい**
ならPythonが強いです。

---

## Win11（PowerShell）

### 1) Python 3.11+ を用意

```powershell
winget install -e --id Python.Python.3.11
python --version
```

### 2) venv作成

```powershell
mkdir s3-ec2-log-fetcher
cd s3-ec2-log-fetcher

python -m venv .venv
.\.venv\Scripts\Activate.ps1
```

### 3) 依存インストール

```powershell
pip install -U pip
pip install boto3 python-dotenv
```

### 4) AWS認証

CLIと同じく：

```powershell
aws configure
```

---

## WSL2（Ubuntu）

### 1) Pythonとvenv

```bash
sudo apt update
sudo apt install -y python3 python3-venv python3-pip
python3 --version
```

### 2) プロジェクト作成&venv

```bash
mkdir -p ~/s3-ec2-log-fetcher
cd ~/s3-ec2-log-fetcher

python3 -m venv .venv
source .venv/bin/activate
```

### 3) 依存インストール

```bash
pip install -U pip
pip install boto3 python-dotenv
```

### 4) AWS認証

```bash
aws configure
```

---

# 一般的なプロジェクト構成（ツリー）

CLI派でもPython派でも混在できる構成例です。

```text
s3-ec2-log-fetcher/
├─ README.md
├─ .gitignore
├─ .env.example
├─ pyproject.toml            # もしくは requirements.txt でもOK
├─ requirements.txt
├─ scripts/
│  ├─ fetch_by_prefix.sh     # WSL用
│  ├─ fetch_by_prefix.ps1    # Win用
│  ├─ fetch_by_name.sh
│  └─ fetch_by_name.ps1
├─ src/
│  └─ fetcher/
│     ├─ __init__.py
│     ├─ config.py
│     ├─ s3_list.py
│     ├─ s3_download.py
│     └─ main.py
└─ logs/
   └─ .gitkeep
```

---

# サンプルファイル（最小実用）

## `.env.example`

```env
BUCKET=my-bucket
PREFIX=ec2-logs/
TARGET_DATE=2025-12-05
LOCAL_DIR=./logs/2025-12-05
MAX_WORKERS=16
```

---

## `requirements.txt`

```txt
boto3>=1.34
python-dotenv>=1.0
```

---

## `src/fetcher/config.py`

```python
import os
from dataclasses import dataclass
from dotenv import load_dotenv

load_dotenv()

@dataclass
class Config:
    bucket: str = os.getenv("BUCKET", "")
    prefix: str = os.getenv("PREFIX", "")
    target_date: str = os.getenv("TARGET_DATE", "")
    local_dir: str = os.getenv("LOCAL_DIR", "./logs")
    max_workers: int = int(os.getenv("MAX_WORKERS", "16"))

    def validate(self):
        if not self.bucket:
            raise ValueError("BUCKET is required")
        if not self.target_date:
            raise ValueError("TARGET_DATE is required (YYYY-MM-DD)")
```

---

## `src/fetcher/s3_list.py`

```python
from typing import Iterable, Dict, Any, Optional
import boto3

def iter_objects(bucket: str, prefix: str = "") -> Iterable[Dict[str, Any]]:
    s3 = boto3.client("s3")
    token: Optional[str] = None

    while True:
        kwargs = {"Bucket": bucket, "Prefix": prefix, "MaxKeys": 1000}
        if token:
            kwargs["ContinuationToken"] = token

        resp = s3.list_objects_v2(**kwargs)
        for obj in resp.get("Contents", []):
            yield obj

        if resp.get("IsTruncated"):
            token = resp.get("NextContinuationToken")
        else:
            break
```

---

## `src/fetcher/s3_download.py`

```python
import os
import boto3

def download_key(bucket: str, key: str, local_dir: str, prefix: str = "") -> str:
    s3 = boto3.client("s3")

    # ローカルに階層を再現
    relative = key[len(prefix):] if prefix and key.startswith(prefix) else key
    local_path = os.path.join(local_dir, relative)

    os.makedirs(os.path.dirname(local_path), exist_ok=True)
    s3.download_file(bucket, key, local_path)
    return local_path
```

---

## `src/fetcher/main.py`

```python
import os
from datetime import datetime, timezone, timedelta
from concurrent.futures import ThreadPoolExecutor, as_completed

from .config import Config
from .s3_list import iter_objects
from .s3_download import download_key


def main():
    cfg = Config()
    cfg.validate()

    # ここでは簡易に「UTC基準の1日」で切る
    # ログ運用がJST日付フォルダなら、後でJST基準版に調整推奨
    start = datetime.fromisoformat(cfg.target_date).replace(tzinfo=timezone.utc)
    end = start + timedelta(days=1)

    os.makedirs(cfg.local_dir, exist_ok=True)

    targets = []
    for obj in iter_objects(cfg.bucket, cfg.prefix):
        lm = obj["LastModified"]
        if start <= lm < end:
            targets.append(obj["Key"])

    print(f"Found {len(targets)} objects to download.")

    with ThreadPoolExecutor(max_workers=cfg.max_workers) as ex:
        futures = [ex.submit(download_key, cfg.bucket, k, cfg.local_dir, cfg.prefix) for k in targets]
        for f in as_completed(futures):
            path = f.result()
            print("Downloaded:", path)


if __name__ == "__main__":
    main()
```

---

## 実行方法

### Win11

```powershell
.\.venv\Scripts\Activate.ps1
copy .env.example .env
# .env を編集
python -m src.fetcher.main
```

### WSL2

```bash
source .venv/bin/activate
cp .env.example .env
# .env を編集
python -m src.fetcher.main
```

---

# 使い分けのおすすめ

* **S3のキーが日付で整理されている**

  * → **CLIの `sync` が最強**
* **日付整理がない / LastModifiedで拾いたい**

  * → **Python**
* **今後の運用を楽にしたい**

  * → S3配置を
    `.../YYYY/MM/DD/` または `date=YYYY-MM-DD/`
    に寄せるのが最適解

---

# 追加でできること（必要なら即出せる）

あなたの運用に合わせて、すぐ実戦仕様にできます：

* **JST基準で“正確に1日”を切るPython版**
* **CloudWatch → S3 のログ配置パターン別の最適コマンド**
* **Athenaで“ダウンロードせず”1日分をSQL分析する構成**
* **DevContainer 版（あなたのいつものWSL2+Docker流儀）**

---

必要なら、
**実際のS3キー例を3〜5本**（伏せ字でOK）貼ってくれれば、
**あなたのバケット構造に完全一致**した

* 最短CLIコマンド
* 1日分取得スクリプト（Win/WSL両対応）
* プロジェクト一式（ツリー＋全コード）

まで一気に作るよ。

*>前ターンで示してもらった例ではダウンロードしたログはどこにどのような形式で保存されますか？

*>結論から言うと、**前ターンの例は「S3にあるオブジェクトをそのままローカルにファイルとして保存」**します。
ログ内容を加工・変換はしていません。

---

## 1) AWS CLI の例の場合

あなたに示したコマンドはこうでした：

### Win例

```powershell
aws s3 sync s3://my-bucket/ec2-logs/2025/12/05/ .\logs\2025-12-05\
```

### WSL例

```bash
aws s3 sync s3://my-bucket/ec2-logs/2025/12/05/ ./logs/2025-12-05/
```

### 保存先

* Win：`.\logs\2025-12-05\`
* WSL：`./logs/2025-12-05/`

### 保存のされ方

* **S3側の「2025/12/05/ 配下の階層構造を保ったまま」**ローカルにコピーされます。
  たとえば S3 がこうなら：

```text
s3://my-bucket/ec2-logs/2025/12/05/i-abc/app.log.gz
s3://my-bucket/ec2-logs/2025/12/05/i-abc/sys.log
```

ローカルはこう：

```text
logs/2025-12-05/i-abc/app.log.gz
logs/2025-12-05/i-abc/sys.log
```

---

## 2) Python(boto3) の例の場合

`.env.example` でこれを想定していました：

```env
LOCAL_DIR=./logs/2025-12-05
PREFIX=ec2-logs/
```

### 保存先

* 基本は **`.env` の `LOCAL_DIR`**

  * 例：`./logs/2025-12-05`

### 保存のされ方（重要ポイント）

コードはこういうロジックでした：

```python
relative = key[len(prefix):] if key.startswith(prefix) else key
local_path = os.path.join(local_dir, relative)
```

つまり、

* **S3キーから `PREFIX` を取り除いた“残りの階層”を**
* **`LOCAL_DIR` 配下にそのまま再現**して保存します

例：

```text
PREFIX = ec2-logs/
LOCAL_DIR = ./logs/2025-12-05

S3 key:
ec2-logs/2025/12/05/i-abc/app.log.gz
```

ローカル：

```text
./logs/2025-12-05/2025/12/05/i-abc/app.log.gz
```

> CLI例と比べると、Python例は
> **`LOCAL_DIR` の下に “2025/12/05/...” がさらに入る**形になるのが違いです。

---

## 3) 「形式」はどうなる？

**中身の形式は変わりません。**

* `.log` ならテキストログ
* `.gz` なら gzip 圧縮のまま
* `.json` なら JSON のまま
* つまり **S3のオブジェクトをバイト単位でそのまま保存**です

---

## 4) もしPython側をCLIと同じ保存構造に寄せたいなら

今のPython例だと
`LOCAL_DIR/2025/12/05/...` になりやすいので、
**あなたが「日付フォルダ直下にまとめたい」**なら、
保存先の作り方を少し変えるのが定番です。

たとえば：

* `LOCAL_DIR=./logs` にして
* `TARGET_DATE=2025-12-05` を使って
  **`./logs/2025-12-05/` にまとめる**ロジックに変更する

…みたいにすると、より期待に近い配置になります。

---

## まとめ

* **CLI例**

  * 保存先：`.\logs\2025-12-05\` / `./logs/2025-12-05/`
  * 構造：**指定したS3プレフィックス配下の階層を維持**

* **Python例**

  * 保存先：`.env` の `LOCAL_DIR`（例：`./logs/2025-12-05`）
  * 構造：**`PREFIX` を除いたキー階層を `LOCAL_DIR` の下に再現**
  * その結果、**`LOCAL_DIR/2025/12/05/...` になりやすい**

* **ファイル形式**

  * **S3の中身をそのまま**（加工なし）

---

必要なら、
**あなたのS3キーの実例に合わせて**
Python版の保存レイアウトを

* 「CLIと同じ見た目」
* 「日付フォルダにフラットに寄せる」
* 「インスタンスIDごとに整理」

みたいに、好みの運用形へ最適化したコードに直して出します。

*>もしも、Googleドライブに保存したいとするとどのような方法がありそう？

*>結構いろいろ選べます。**現実的で事故りにくい順**に並べるとこんな感じです。

---

## ① いちばん実務向き：rclone で **S3 → Google Drive** を同期

**メリット**

* ローカルに大容量を置かずに済む（※ストリーミング的に転送できる）
* 再実行や差分同期が強い
* Win11 / WSL2 どちらでも同じ発想で動く

**ざっくり手順**

1. rclone を入れる
2. `rclone config` で

   * `s3` リモート（AWS）
   * `gdrive` リモート（Google Drive）
     を作る
3. 日付プレフィックスを指定して copy / sync

**例（S3側が日付プレフィックス運用の場合）**

```bash
# 例: 2025/12/05 のフォルダを Drive にコピー
rclone copy \
  s3:my-bucket/ec2-logs/2025/12/05/ \
  gdrive:EC2-Logs/2025-12-05/ \
  --progress
```

**ファイル名に日付が入っている場合**

```bash
rclone copy \
  s3:my-bucket/ec2-logs/ \
  gdrive:EC2-Logs/2025-12-05/ \
  --include "*2025-12-05*" \
  --progress
```

> **おすすめ度トップ。**
> 「1日分だけ」「毎日運用したい」みたいな実務に一番フィットします。

---

## ② シンプル二段構え：**AWS CLIで取得 → Driveへアップロード**

**メリット**

* 仕組みが単純で理解しやすい
* 既に AWS CLI の流れを作ってるなら最小変更

**例（WSL2）**

```bash
# 1) S3からローカルへ
aws s3 sync s3://my-bucket/ec2-logs/2025/12/05/ ./logs/2025-12-05/

# 2) ローカル -> Drive（rclone）
rclone copy ./logs/2025-12-05/ gdrive:EC2-Logs/2025-12-05/ --progress
```

**Win11（PowerShell）でも概念は同じ**

* `aws s3 sync ...`
* `rclone copy ...`

---

## ③ Pythonで直接アップロード（柔軟さ最強）

**メリット**

* **LastModifiedで1日分だけ抽出**とか、細かい条件に強い
* ダウンロードとアップロードを**1本の処理に統合**できる

**デメリット**

* OAuth/認証の作り込みが必要
* Drive API のクォータやエラー処理をちゃんと書く必要

**イメージ**

* boto3 で対象キーを列挙
* Drive API で `files.create` にストリームアップロード
  （ローカル保存を挟まずにいける）

> 「日付プレフィックスが整ってない」「S3構造がカオス」
> みたいな時に選ぶと気持ちよく勝てます。

---

## ④ GASでやる（小規模なら可能、でも基本は非推奨）

**どうしても Google 側だけで完結させたい**場合の選択肢。

**現実的な課題**

* S3 へアクセスするには

  * 署名付きURLを作る
  * もしくは AWS API を叩く
    といった前準備が必要
* **サイズ/件数が多いと6分制限が厳しい**
* 大量ファイルはトリガ分割やキュー設計が必要

> 1日分が「数ファイル・軽量」ならアリ。
> EC2ログの“本気運用”なら、**rclone or Python**が安全。

---

## ⑤ “PCユーザー運用”なら：Drive for desktop

**メリット**

* コード不要寄り
* ローカルに落としたものが自動同期

**デメリット**

* サーバ用途や自動化には不向き
* 大容量ログだとPCのストレージと同期負荷がネック

---

# おすすめ結論

あなたの目的が
**「S3のEC2ログ 1日分をまとめて Drive に保存」**
なら、最も良い順で：

1. **rclone で S3→Drive 直コピー**
2. **AWS CLI → rclone の二段構え**
3. **Pythonで直接Drive APIへ**
4. GASは小規模限定

---

# 一般的なプロジェクト構成（ツリー例）

rclone中心＋必要ならPythonも載せる、実務寄りの形：

```text
s3-to-gdrive-ec2-logs/
├─ README.md
├─ .gitignore
├─ .env.example
├─ scripts/
│  ├─ fetch_and_upload.sh
│  ├─ fetch_and_upload.ps1
│  ├─ rclone_copy_by_prefix.sh
│  └─ rclone_copy_by_prefix.ps1
├─ src/                       # Pythonを使う場合
│  └─ pipeline/
│     ├─ __init__.py
│     ├─ config.py
│     ├─ s3_scan.py
│     ├─ drive_upload.py
│     └─ main.py
└─ logs/                      # ローカル経由する場合の一時置き場
   └─ .gitkeep
```

---

# 運用の小ワザ

* **Drive側も日付フォルダで整理**
  `EC2-Logs/2025-12-05/` みたいに固定化
* **圧縮済み（.gz）ならそのまま保存**
  → Drive容量と転送が節約
* **将来のためにS3配置を日付プレフィックスに寄せる**
  `.../YYYY/MM/DD/` or `date=YYYY-MM-DD/`
  これがあるだけで全自動が一気に簡単

---

必要ならこのまま、
**Win11用ps1 / WSL2用sh の完成版スクリプト**を

* S3の実キー構造
* Drive保存先のルール
* “JST基準の1日”解釈

に合わせて**コピペ即運用の形**で作って出すよ。

*>お願い

*>了解。**コピペで即運用できる形**で、

* **Win11（PowerShell）**
* **WSL2（Ubuntu / bash）**

の両方に対応した
**「S3のEC2ログ 1日分 → Google Driveへ保存」**の

1. 環境構築
2. プロジェクト構成ツリー
3. スクリプト一式（全部）

をまとめて出します。

---

# 全体方針（最も安全で実務向け）

**rcloneを中核**にします。

* **S3 → Google Drive へ直接コピー**
* 失敗時の再実行にも強い
* ローカル容量を浪費しにくい

> もしS3が日付プレフィックス運用ならほぼ一撃。
> そうでなくても「ファイル名に日付が入る」方式で対応可能。

---

# 1) 一般的なプロジェクト構成（ツリー）

```text
s3-to-gdrive-ec2-logs/
├─ README.md
├─ .gitignore
├─ .env.example
├─ scripts/
│  ├─ copy_by_prefix.sh
│  ├─ copy_by_prefix.ps1
│  ├─ copy_by_name.sh
│  ├─ copy_by_name.ps1
│  ├─ verify_rclone.sh
│  └─ verify_rclone.ps1
└─ logs/                  # 使わない運用でもOK（保険）
   └─ .gitkeep
```

---

# 2) 事前に必要なもの

* **AWS 側**

  * `s3:ListBucket`
  * `s3:GetObject`

* **Google Drive 側**

  * rcloneのOAuth設定に従って認可

---

# 3) 環境構築

## 3-1) Win11（PowerShell）

### A. AWS CLI

```powershell
winget install -e --id Amazon.AWSCLI
aws --version
```

認証：

```powershell
aws configure
```

### B. rclone

```powershell
winget install -e --id Rclone.Rclone
rclone version
```

### C. rclone のリモート設定

```powershell
rclone config
```

#### 1) S3リモート（例名: `s3`)

* `New remote` → name: `s3`
* `Storage` → `s3`
* `provider` → `AWS`
* `env_auth` → `false`（通常は）
* `access_key_id` / `secret_access_key`
* `region` → `ap-northeast-1` など
* 他は基本デフォルト

#### 2) Google Driveリモート（例名: `gdrive`)

* `New remote` → name: `gdrive`
* `Storage` → `drive`
* ブラウザで認証（ガイドに従う）

---

## 3-2) WSL2（Ubuntu）

### A. AWS CLI

```bash
sudo apt update
sudo apt install -y awscli
aws --version
```

認証：

```bash
aws configure
```

### B. rclone

```bash
sudo apt install -y rclone
rclone version
```

> もしapt版が古くて困るなら公式インストールでもOK：

```bash
curl https://rclone.org/install.sh | sudo bash
```

### C. rclone のリモート設定

```bash
rclone config
```

Winと同じ内容で
`type=s3` の `s3` リモート、
`type=drive` の `gdrive` リモートを作成。

---

# 4) ファイル一式

## `.gitignore`

```gitignore
.env
logs/**
!.gitkeep
```

---

## `.env.example`

```env
# ===== rclone remote names =====
RCLONE_S3_REMOTE=s3
RCLONE_GDRIVE_REMOTE=gdrive

# ===== S3 settings =====
S3_BUCKET=my-bucket
S3_PREFIX=ec2-logs/

# 日付プレフィックスがこう切られている想定：
#   ec2-logs/YYYY/MM/DD/
# 例: 2025/12/05
TARGET_DATE=2025-12-05

# ===== Drive settings =====
# Drive上の格納先ベースフォルダ
GDRIVE_BASE=EC2-Logs

# ===== Name filter mode (file name contains date) =====
# 例: *2025-12-05*
NAME_FILTER_DATE=2025-12-05

# ===== optional =====
# 転送の詳細ログが欲しい場合
VERBOSE=false
```

---

## `scripts/verify_rclone.ps1`

```powershell
Write-Host "== rclone remotes =="

rclone listremotes

Write-Host ""
Write-Host "If you don't see your expected remotes (e.g., s3: and gdrive:), run:"
Write-Host "  rclone config"
```

---

## `scripts/verify_rclone.sh`

```bash
#!/usr/bin/env bash
set -euo pipefail

echo "== rclone remotes =="
rclone listremotes

echo ""
echo "If you don't see expected remotes (e.g., s3: and gdrive:), run:"
echo "  rclone config"
```

---

## `scripts/copy_by_prefix.ps1`

**S3が日付プレフィックス運用の場合、これが最強。**

```powershell
Param()

if (-Not (Test-Path ".env")) {
  Write-Host "Missing .env. Copy from .env.example and edit it."
  exit 1
}

# Simple dotenv loader
Get-Content .env | ForEach-Object {
  if ($_ -match "^\s*#") { return }
  if ($_ -match "^\s*$") { return }
  $kv = $_ -split "=", 2
  if ($kv.Count -eq 2) {
    $name = $kv[0].Trim()
    $value = $kv[1].Trim()
    Set-Item -Path "Env:$name" -Value $value
  }
}

$S3_REMOTE = $Env:RCLONE_S3_REMOTE
$GD_REMOTE = $Env:RCLONE_GDRIVE_REMOTE
$BUCKET = $Env:S3_BUCKET
$PREFIX = $Env:S3_PREFIX
$DATE = $Env:TARGET_DATE
$GD_BASE = $Env:GDRIVE_BASE
$VERBOSE = $Env:VERBOSE

if (-not $S3_REMOTE -or -not $GD_REMOTE -or -not $BUCKET -or -not $PREFIX -or -not $DATE -or -not $GD_BASE) {
  Write-Host "Required env missing. Check .env"
  exit 1
}

# DATE: YYYY-MM-DD -> YYYY/MM/DD
$DATE_PATH = $DATE -replace "-", "/"

$SRC = "$S3_REMOTE`:$BUCKET/$PREFIX$DATE_PATH/"
$DST = "$GD_REMOTE`:$GD_BASE/$DATE/"

Write-Host "SRC: $SRC"
Write-Host "DST: $DST"

$opts = @("--progress")
if ($VERBOSE -eq "true") { $opts += "-v" }

rclone copy $SRC $DST @opts
```

---

## `scripts/copy_by_prefix.sh`

```bash
#!/usr/bin/env bash
set -euo pipefail

if [[ ! -f ".env" ]]; then
  echo "Missing .env. Copy from .env.example and edit it."
  exit 1
fi

# load .env
set -a
# shellcheck disable=SC1091
source .env
set +a

: "${RCLONE_S3_REMOTE:?missing}"
: "${RCLONE_GDRIVE_REMOTE:?missing}"
: "${S3_BUCKET:?missing}"
: "${S3_PREFIX:?missing}"
: "${TARGET_DATE:?missing}"
: "${GDRIVE_BASE:?missing}"

DATE_PATH="${TARGET_DATE//-/\/}"

SRC="${RCLONE_S3_REMOTE}:${S3_BUCKET}/${S3_PREFIX}${DATE_PATH}/"
DST="${RCLONE_GDRIVE_REMOTE}:${GDRIVE_BASE}/${TARGET_DATE}/"

echo "SRC: ${SRC}"
echo "DST: ${DST}"

OPTS=(--progress)
if [[ "${VERBOSE:-false}" == "true" ]]; then
  OPTS+=(-v)
fi

rclone copy "${SRC}" "${DST}" "${OPTS[@]}"
```

---

## `scripts/copy_by_name.ps1`

**S3側に日付フォルダが無い場合の保険。
ファイル名に日付が入っている想定。**

```powershell
Param()

if (-Not (Test-Path ".env")) {
  Write-Host "Missing .env. Copy from .env.example and edit it."
  exit 1
}

Get-Content .env | ForEach-Object {
  if ($_ -match "^\s*#") { return }
  if ($_ -match "^\s*$") { return }
  $kv = $_ -split "=", 2
  if ($kv.Count -eq 2) {
    $name = $kv[0].Trim()
    $value = $kv[1].Trim()
    Set-Item -Path "Env:$name" -Value $value
  }
}

$S3_REMOTE = $Env:RCLONE_S3_REMOTE
$GD_REMOTE = $Env:RCLONE_GDRIVE_REMOTE
$BUCKET = $Env:S3_BUCKET
$PREFIX = $Env:S3_PREFIX
$FILTER_DATE = $Env:NAME_FILTER_DATE
$GD_BASE = $Env:GDRIVE_BASE
$VERBOSE = $Env:VERBOSE

if (-not $S3_REMOTE -or -not $GD_REMOTE -or -not $BUCKET -or -not $PREFIX -or -not $FILTER_DATE -or -not $GD_BASE) {
  Write-Host "Required env missing. Check .env"
  exit 1
}

$SRC = "$S3_REMOTE`:$BUCKET/$PREFIX"
$DST = "$GD_REMOTE`:$GD_BASE/$FILTER_DATE/"

Write-Host "SRC: $SRC"
Write-Host "DST: $DST"
Write-Host "Include: *$FILTER_DATE*"

$opts = @("--progress", "--include", "*$FILTER_DATE*", "--exclude", "*")
if ($VERBOSE -eq "true") { $opts += "-v" }

rclone copy $SRC $DST @opts
```

---

## `scripts/copy_by_name.sh`

```bash
#!/usr/bin/env bash
set -euo pipefail

if [[ ! -f ".env" ]]; then
  echo "Missing .env. Copy from .env.example and edit it."
  exit 1
fi

set -a
# shellcheck disable=SC1091
source .env
set +a

: "${RCLONE_S3_REMOTE:?missing}"
: "${RCLONE_GDRIVE_REMOTE:?missing}"
: "${S3_BUCKET:?missing}"
: "${S3_PREFIX:?missing}"
: "${NAME_FILTER_DATE:?missing}"
: "${GDRIVE_BASE:?missing}"

SRC="${RCLONE_S3_REMOTE}:${S3_BUCKET}/${S3_PREFIX}"
DST="${RCLONE_GDRIVE_REMOTE}:${GDRIVE_BASE}/${NAME_FILTER_DATE}/"

echo "SRC: ${SRC}"
echo "DST: ${DST}"
echo "Include: *${NAME_FILTER_DATE}*"

OPTS=(--progress --include "*${NAME_FILTER_DATE}*" --exclude "*")
if [[ "${VERBOSE:-false}" == "true" ]]; then
  OPTS+=(-v)
fi

rclone copy "${SRC}" "${DST}" "${OPTS[@]}"
```

---

# 5) 使い方（最短）

## STEP 1

```bash
git clone <your-repo>  # なくてもOK
cd s3-to-gdrive-ec2-logs
```

## STEP 2

```bash
cp .env.example .env
# Winならエクスプローラやエディタで .env を編集
```

## STEP 3

rcloneのリモート確認

### Win

```powershell
.\scripts\verify_rclone.ps1
```

### WSL

```bash
bash ./scripts/verify_rclone.sh
```

## STEP 4（本命）

### 日付プレフィックス運用なら

#### Win

```powershell
.\scripts\copy_by_prefix.ps1
```

#### WSL

```bash
bash ./scripts/copy_by_prefix.sh
```

### ファイル名に日付が入る運用なら

#### Win

```powershell
.\scripts\copy_by_name.ps1
```

#### WSL

```bash
bash ./scripts/copy_by_name.sh
```

---

# 6) 保存先はどうなる？

Drive 上で例えば：

* `GDRIVE_BASE=EC2-Logs`
* `TARGET_DATE=2025-12-05`

なら

```text
Google Drive/
└─ EC2-Logs/
   └─ 2025-12-05/
      └─ (S3の日付配下/またはフィルタ一致ファイル)
```

**形式はS3の中身そのまま**
（.log, .gz, .json など加工なし）。

---

# 7) よくある落とし穴と対策

* **S3側の“日付の切り方”がJSTかUTCか**

  * フォルダ運用なら問題になりにくい
  * ファイル名フィルタ運用だとズレることがある
    → 必要なら **JST基準のフィルタ命名ルール**に統一

* **大量ファイル**

  * `rclone copy` は再実行に強い
  * さらに堅くするなら `--checksum` 追加も検討

---

# 8) 次にやると超強い

もし毎日回したいなら、

* Win：タスクスケジューラ
* WSL：cron

で
`copy_by_prefix` を **毎晩 1回**にするだけで完成です。

---

必要ならこの構成をベースに、

* **あなたの実S3キー構造に完全一致**した `.env` とコマンド例
* **JST基準で“今日の1日分”を自動計算して入れる版**
* **ログ件数/容量を事前に見積もるチェック用スクリプト**
* **DevContainer込みのリポジトリ雛形**

まで、さらに実戦仕様に仕上げて出します。
