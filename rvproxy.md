# Apache を使った HTTP → HTTPS リバースプロキシ構築手順（Amazon Linux 2023）

## ✅ 構成概要

- フロント（Apache）は **HTTP（ポート80）** で待ち受け
- バックエンドサーバーへは **HTTPS（ポート443など）** で転送
- バックエンドが自己署名証明書でも動作するように調整可

---

## 🛠 手順

### 1. Apache のインストール

```bash
sudo dnf install -y httpd
```

---

### 2. リバースプロキシに必要なモジュールの有効化

Amazon Linux 2023 の Apache では、以下のモジュールが必要です：  
・mod_proxy  
・mod_proxy_http  
これらはデフォルトで含まれていますが、念のため確認します。  

```bash
# Proxy 関連モジュール（ほぼ自動でロードされるが明示的に書く場合）
echo 'LoadModule proxy_module modules/mod_proxy.so' | sudo tee -a /etc/httpd/conf.modules.d/00-proxy.conf
echo 'LoadModule proxy_http_module modules/mod_proxy_http.so' | sudo tee -a /etc/httpd/conf.modules.d/00-proxy.conf

# SSL 通信用モジュール
sudo dnf install -y mod_ssl
```

---

### 3. 設定ファイルの新規作成

```bash
sudo vi /etc/httpd/conf.d/reverse-proxy.conf
```

以下を記述：

```apache
<VirtualHost *:80>
    ServerName example.com  # 適宜変更（またはIP）

    SSLProxyEngine On
    SSLProxyVerify none
    SSLProxyCheckPeerCN off
    SSLProxyCheckPeerName off

    ProxyPreserveHost On
    ProxyPass / https://localhost:3000/
    ProxyPassReverse / https://localhost:3000/

    ErrorLog /var/log/httpd/reverse-proxy-error.log
    CustomLog /var/log/httpd/reverse-proxy-access.log combined
</VirtualHost>
```

🔸 バックエンドが自己署名証明書を使っている場合でも接続できる設定です。  
🔸 バックエンドのアドレス（`localhost:3000`）は適宜変更してください。（例：http://192.168.x.x:3000/）  
🔸 HTTPS でリバースプロキシは mod_ssl が必要です。また、自己署名でない証明書は Let's Encrypt（certbot）などを使って取得してください。  

---

### 4. ファイアウォールの設定（必要に応じて）

```bash
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --reload
```

---

### 5. Apache の起動と自動起動設定

```bash
sudo systemctl enable httpd
sudo systemctl start httpd
```

---

### 6. 動作確認

ブラウザや curl で、Apache が動作しているサーバーの IP やドメインにアクセス。

```bash
curl http://<サーバーのIPまたはドメイン>/
```

→ バックエンド（例：localhost:3000）のアプリケーションが表示されれば成功です。

---

## 📁 設定ファイルの場所一覧（Amazon Linux 2023）

| 種類 | パス |
|------|------|
| メイン設定 | `/etc/httpd/conf/httpd.conf` |
| モジュール設定 | `/etc/httpd/conf.modules.d/` |
| 仮想ホストや追加設定 | `/etc/httpd/conf.d/` |

---

## 🔒 注意点まとめ

- バックエンドが **自己署名証明書** の場合は `SSLProxyVerify none` などの設定が必要です。
- バックエンドが **正式な証明書**（Let's Encrypt 等）を使用していれば `SSLProxyVerify` の無効化は不要です。
- Apache の再起動を忘れずに。（systemctl restart httpd）

---

## ✅ まとめ

| 手順 | 内容 |
|------|------|
| 1 | Apache とモジュールのインストール |
| 2 | モジュール有効化と SSL 機能の確認 |
| 3 | リバースプロキシ設定ファイル作成 |
| 4 | Firewall 開放（必要な場合） |
| 5 | Apache 起動・有効化 |
| 6 | 動作確認とトラブルシューティング |

---

## ✅ まとめ　その２

Amazon Linux 2023 における Apache（httpd）の設定ファイルの場所とファイル名は以下のとおりです。

---

## 📁 メイン設定ファイル

| ファイル名 | 内容 | 場所 |
|--------------|--------------|----------------------------|
| **httpd.conf** | Apache 全体の基本設定 | `/etc/httpd/conf/httpd.conf` |

---

## 📁 モジュール設定ファイル（必要に応じて）

| ディレクトリ/ファイル | 内容 | 説明 |
|-----------------------------------------|------------------|------------------------------------|
| `/etc/httpd/conf.modules.d/` | モジュール設定ファイル | 例: `00-proxy.conf`, `00-ssl.conf` など |
| `/etc/httpd/conf.modules.d/00-proxy.conf` | Proxy モジュールの読み込み設定 | `mod_proxy.so` などをロードする |

---

## 📁 仮想ホスト・追加設定ファイル

| ディレクトリ | 内容 | 説明 |
|-----------------------------------------|--------------------|-----------------------------------|
| `/etc/httpd/conf.d/` | 追加の設定ファイルを格納する場所 | サイトやプロキシ設定をここに個別に作成するのが一般的 |
| 例: `/etc/httpd/conf.d/reverse-proxy.conf` | あなたが作るリバースプロキシ設定ファイル | `httpd.conf` に自動で読み込まれるので、ここに書くのが推奨 |

---

## ✅ 通常の運用方針（推奨）

- `httpd.conf` **本体はなるべく編集せず、**
- `conf.d/` **配下に個別の設定ファイルを作成して運用**するのがベストプラクティスです。

---

## ✅ 確認方法

Apache が現在どの設定を読み込んでいるか確認したい場合：

```bash
apachectl -V | grep SERVER_CONFIG_FILE
```

例：

```bash
mathematica
-D SERVER_CONFIG_FILE="/etc/httpd/conf/httpd.conf"
```

---
