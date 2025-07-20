# Apache を使った HTTP → HTTPS リバースプロキシ構築手順（Amazon Linux 2023）

## $2705 構成概要

- フロント（Apache）は **HTTP（ポート80）** で待ち受け
- バックエンドサーバーへは **HTTPS（ポート443など）** で転送
- バックエンドが自己署名証明書でも動作するように調整可

---

## $D83D$DEE0 手順

### 1. Apache のインストール

```bash
sudo dnf install -y httpd
```

---

### 2. 必要なモジュールの有効化

```bash
# Proxy 関連モジュール（ほぼ自動でロードされるが明示的に書く場合）
echo 'LoadModule proxy_module modules/mod_proxy.so' | sudo tee -a /etc/httpd/conf.modules.d/00-proxy.conf
echo 'LoadModule proxy_http_module modules/mod_proxy_http.so' | sudo tee -a /etc/httpd/conf.modules.d/00-proxy.conf

# SSL 通信用モジュール
sudo dnf install -y mod_ssl
```

---

### 3. 設定ファイルの作成

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

$D83D$DD38 バックエンドが自己署名証明書を使っている場合でも接続できる設定です。  
$D83D$DD38 バックエンドのアドレス（`localhost:3000`）は適宜変更してください。

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

```bash
curl http://<サーバーのIPまたはドメイン>/
```

→ バックエンドのアプリケーションが表示されれば成功です。

---

## $D83D$DCC1 設定ファイルの場所一覧（Amazon Linux 2023）

| 種類 | パス |
|------|------|
| メイン設定 | `/etc/httpd/conf/httpd.conf` |
| モジュール設定 | `/etc/httpd/conf.modules.d/` |
| 仮想ホストや追加設定 | `/etc/httpd/conf.d/` |

---

## $D83D$DD12 注意点まとめ

- バックエンドが **自己署名証明書** の場合は `SSLProxyVerify none` などの設定が必要です。
- バックエンドが **正式な証明書**（Let's Encrypt 等）を使用していれば `SSLProxyVerify` の無効化は不要です。
- Apache の再起動を忘れずに。

---

## $2705 まとめ

| 手順 | 内容 |
|------|------|
| 1 | Apache とモジュールのインストール |
| 2 | モジュール有効化と SSL 機能の確認 |
| 3 | リバースプロキシ設定ファイル作成 |
| 4 | Firewall 開放（必要な場合） |
| 5 | Apache 起動・有効化 |
| 6 | 動作確認とトラブルシューティング |

---
