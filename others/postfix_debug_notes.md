
# Amazon Linux 2023でのPostfixのSMTP接続トラブルシューティング

## 質問内容

> Amazon Linux 2023です。postfixを稼働中。  
> このホストへss -lすると0.0.0.0/0で25番ポートリッスン中。  
> しかし、telnet ホスト名 25するとESMTPの表示がでない。  
> しばらくするとclosedされる

---

## 回答1：状況分析とチェックポイント

### ■ 状況まとめ

- **OS**：Amazon Linux 2023  
- **MTA**：Postfix 稼働中  
- **コマンド `ss -ltnp`** で確認 → `0.0.0.0:25` で LISTEN（全てのインターフェースから受け付け）  
- **`telnet ホスト名 25`** を実行しても、Postfix の `ESMTP banner`（バナー表示）が出ず、しばらくして接続が `closed` される  

### ✅ チェックポイント

1. **ファイアウォール (firewalld / iptables) の確認**
```bash
sudo firewall-cmd --list-all
sudo iptables -L -n
sudo firewall-cmd --permanent --add-port=25/tcp
sudo firewall-cmd --reload
```

2. **SELinux の確認**
```bash
getenforce
sudo journalctl -xe | grep -i "denied"
```

3. **Postfixの設定（`main.cf`）**
- `inet_interfaces = all`
- `smtpd_banner =` の確認

4. **Postfix のログ確認**
```bash
sudo journalctl -u postfix
sudo tail -f /var/log/maillog
```

5. **IPv6接続の確認**
```bash
telnet -4 ホスト名 25
```

6. **DNS名前解決の遅延対策**
```conf
smtpd_delay_reject = no
smtpd_client_restrictions = permit
```

---

## 回答2：なぜ「しばらくするとclosedされる」のか

### 🔍 原因：Postfixの逆引きDNSチェックでタイムアウト

#### 背景
Postfix は SMTP 接続時に逆引き（PTRレコード）を行う。逆引きできない or 遅いとバナーを出さずタイムアウト → 切断。

### ✅ 対処法

#### 対策①：逆引きDNSスキップ
```conf
smtpd_delay_reject = no
smtpd_client_restrictions = permit
```
```bash
sudo systemctl restart postfix
```

#### 対策②：/etc/hosts にクライアントIP登録
```bash
echo "192.168.1.100 testclient.localdomain" | sudo tee -a /etc/hosts
```

#### その他の可能性

| 原因 | 内容 |
|------|------|
| Postfixのアクセス制限 | `smtpd_client_restrictions` による拒否 |
| Postfixの設定ミス | `main.cf`での設定不足 |
| telnetがIPv6で接続 | `telnet -4` で確認 |
| SELinuxの制限 | `journalctl -xe` で確認 |

---

## 回答3：`journalctl` コマンドとは？

### 概要
`journalctl` は `systemd` によるログ（ジャーナル）を表示するコマンド。Amazon Linux 2023でも標準使用。

### 主な使い方

| コマンド | 説明 |
|---------|------|
| `journalctl` | 全ログ表示 |
| `journalctl -u postfix` | postfixのログのみ表示 |
| `journalctl -xe` | 直近のエラーログ強調表示 |
| `journalctl -f` | リアルタイム追跡 |
| `journalctl -b` | 現在の起動からのログ |

### 補足

- `systemd` 環境では `/var/log/journal/` にバイナリ形式で保存
- `journalctl` はそれを人間可読に変換

---

## 最後に

- バナーが出ずに `telnet` が切断される多くの原因は「逆引きDNSのタイムアウト」
- `main.cf` での設定調整が最も効果的
- `journalctl` を活用してログ分析すれば、原因を迅速に特定可能
