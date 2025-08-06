
# サイバーセキュリティ対策：メール攻撃シナリオとSPF検証

## 🎯 目的
サイバーセキュリティ対策の学習目的で、同一LAN内のLinuxマシン2台における「メールを悪用した攻撃シナリオ」の理解と検証を行う。

## 🧱 環境前提

- LAN内に Linux マシン2台：`mail1.mydomain.local`、`mail2.mydomain.local`
- 各マシンには以下をインストール済み：
  - BIND（DNSサーバー）
  - Postfix（メール送信サーバー）
  - Dovecot（メール受信サーバー）
- ユーザー例：`alice@mail1.mydomain.local`、`bob@mail2.mydomain.local`

---

## 🔓 攻撃シナリオ一覧

### ① メール送信者の偽装（From Spoofing）
- SMTPセッションを手動で作成し、`From:` ヘッダを偽装
- telnet を使って手動送信が可能
- SPF/DKIM/DMARCの重要性を理解

### ② マルウェア付きメールの送信
- `.sh`, `.py`, `.exe` などの添付ファイルを検証
- ClamAV や Postfix の content_filter 設定と連携

### ③ フィッシングメール
- HTMLメールで偽リンクを挿入
- クライアント側の表示と警告機能の確認

### ④ メール中継の踏み台（Open Relay）
- Postfix設定を誤っていると中継されてしまうリスク
- `mynetworks`, `smtpd_relay_restrictions` の確認

### ⑤ 辞書攻撃によるアカウント侵入
- Dovecotログイン試行を自動化して検証
- fail2ban による防御の導入確認

---

## ✅ 実践：最も手軽な攻撃「From Spoofing」の検証

### 1. telnetでメール送信（`mail2` から `mail1` へ）

```bash
telnet mail1.mydomain.local 25
```

```smtp
EHLO attacker.local
MAIL FROM:<fake@spoofed.com>
RCPT TO:<bob@mail1.mydomain.local>
DATA
From: Alice <alice@mail1.mydomain.local>
To: Bob <bob@mail1.mydomain.local>
Subject: テストメール（なりすまし）

これは送信元を偽装したテストメールです。
.
QUIT
```

### 2. メール受信確認
- `mail` コマンドや `/home/bob/Maildir/new/` にて確認

---

## 🔐 SPFを導入して偽装対策する手順

### 🔧 BINDにSPFレコードを追加

1. ゾーンファイル `/var/named/mydomain.local.zone` に以下を追加：

```
@    IN    TXT    "v=spf1 ip4:192.168.0.2 -all"
```

2. BIND再起動：

```bash
sudo systemctl restart named
```

3. 確認：

```bash
dig +short TXT mydomain.local
```

---

### 🔧 PostfixにSPFチェック機能を追加

1. SPF検証パッケージを導入：

```bash
sudo dnf install postfix-policyd-spf-python
```

2. master.cf へ設定追加：

```
policyd-spf  unix  -       n       n       -       0       spawn
    user=policyd-spf argv=/usr/bin/policyd-spf
```

3. main.cf へチェックルール追加：

```
smtpd_recipient_restrictions =
    permit_mynetworks,
    permit_sasl_authenticated,
    reject_unauth_destination,
    check_policy_service unix:private/policyd-spf
```

4. Postfixを再起動：

```bash
sudo systemctl restart postfix
```

5. ログ確認：

```bash
sudo journalctl -u postfix
# または
sudo tail -f /var/log/maillog
```

---

## ✅ 学習ポイントまとめ

| 項目        | 内容                                  |
|-------------|---------------------------------------|
| 所要時間    | 約5～10分                             |
| 利用ツール  | telnet, dig, postfix, BIND            |
| 学習効果    | SMTP, SPF, メール偽装の理解           |
