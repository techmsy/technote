### Rocky Linux 8 Postfix 送信メールサーバー構築手順 (SSL/TLS使用)

---

#### 1. 前提条件の確認
- Rocky Linux 8 がインストール済みであること。
- BIND DNSサーバーが同一LAN内に稼働しており、mydomain.local ドメインの名前解決が正しく行えること。
- mydomain.local のAレコードとしてPostfixサーバーのIPアドレスが登録されていること。
- 逆引きレコード (PTR) も設定されていることが望ましい。
- Postfixサーバーのホスト名が mail.mydomain.local など、FQDNとして適切に設定されていること。

```Bash
hostnamectl set-hostname mail.mydomain.local
```

#### ファイアウォール（firewalld）が有効な場合は、必要なポートを開放すること。

---

#### 2. 必要なパッケージのインストール
Postfixと、SSL/TLSに必要なopensslをインストールします。

Bash

sudo dnf install postfix openssl -y
3. SSL/TLS証明書の準備
自己署名証明書を作成します。商用利用や外部へのメール送信を考慮する場合は、認証局 (CA) から発行された証明書を使用することを強く推奨しますが、今回は同一LAN内での使用のため自己署名で進めます。

Bash

sudo mkdir /etc/postfix/ssl
sudo chmod 700 /etc/postfix/ssl # セキュリティのため所有者のみ書き込み可能にする

sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 -keyout /etc/postfix/ssl/postfix.key -out /etc/postfix/ssl/postfix.crt
証明書作成時にいくつかの情報を尋ねられますので、適切に入力してください。

Common Name (e.g. server FQDN or YOUR name) には、PostfixサーバーのFQDN (mail.mydomain.local など) を入力してください。

作成した秘密鍵と証明書のパーミッションを設定します。

Bash

sudo chmod 600 /etc/postfix/ssl/postfix.key
sudo chmod 644 /etc/postfix/ssl/postfix.crt
4. Postfix の設定
Postfixのメイン設定ファイル /etc/postfix/main.cf を編集します。元のファイルをバックアップしてから作業を開始することをお勧めします。

Bash

sudo cp /etc/postfix/main.cf /etc/postfix/main.cf.bak
sudo vi /etc/postfix/main.cf
以下の設定項目を確認・追加・変更してください。

# ホスト名 (FQDN)
myhostname = mail.mydomain.local

# ドメイン名
mydomain = mydomain.local

# 送信元アドレスのドメイン部分を上書きする場合
# 後述のcanonical設定で上書きするため、ここではコメントアウトまたはデフォルト値のまま

# メールボックス形式 (今回は利用しないが、基本的な設定として)
# home_mailbox = Maildir/

# 受信するドメイン (同一LAN内のmydomain.local宛てのみ受信)
# 今回は送信サーバーのため、これだけでは不十分。後述のmynetworksで制御。
#mydestination = $myhostname, localhost.$mydomain, localhost, $mydomain

# ローカルネットワーク
# ここにメール送信を許可するクライアントのIPアドレス範囲を指定します。
# 例: 192.168.1.0/24 のネットワーク内のクライアントからの送信を許可
mynetworks = 127.0.0.0/8, 192.168.1.0/24  # 環境に合わせて変更してください

# SASL認証 (今回は利用しないが、セキュリティを高める場合は必要)
# smtpd_sasl_auth_enable = no

# SSL/TLS設定
smtpd_use_tls = yes
smtpd_tls_cert_file = /etc/postfix/ssl/postfix.crt
smtpd_tls_key_file = /etc/postfix/ssl/postfix.key
smtpd_tls_security_level = may # or encrypt
# smtpd_tls_session_cache_database = btree:${data_directory}/smtpd_scache
# smtp_tls_session_cache_database = btree:${data_directory}/smtp_scache
smtpd_tls_loglevel = 1
smtpd_tls_received_header = yes
smtpd_tls_mandatory_protocols = !SSLv2, !SSLv3
smtpd_tls_protocols = !SSLv2, !SSLv3
# smtpd_tls_mandatory_ciphers = high

smtp_use_tls = yes
smtp_tls_cert_file = /etc/postfix/ssl/postfix.crt
smtp_tls_key_file = /etc/postfix/ssl/postfix.key
smtp_tls_security_level = may # or encrypt
smtp_tls_loglevel = 1
smtp_tls_mandatory_protocols = !SSLv2, !SSLv3
smtp_tls_protocols = !SSLv2, !SSLv3
# smtp_tls_mandatory_ciphers = high

# 送信元アドレスの上書き (mail@mydomain.local に統一)
# canonical_maps は Postfix がメールアドレスを変更するために使用するマップです。
# sendmail からの送信時や、アプリケーションからの送信時など、
# ローカルユーザー名がFromアドレスになる場合にこれを指定します。
sender_canonical_maps = hash:/etc/postfix/sender_canonical
smtp_header_checks = pcre:/etc/postfix/header_checks.pcre # 後述で設定

# 中継を許可する宛先 (今回はLAN内のみなので、明示的に設定)
relay_domains = $mydomain
# permit_mynetworks を指定することで、mynetworksで定義されたホストからのメールを中継します。
smtpd_relay_restrictions = permit_mynetworks, reject_unauth_destination

# ネットワークインターフェース
inet_interfaces = all
inet_protocols = ipv4
sender_canonical ファイルの作成

mail@mydomain.local 以外の送信元アドレスを mail@mydomain.local に書き換えるための設定です。

Bash

sudo vi /etc/postfix/sender_canonical
内容：

# ローカルユーザー名がFromアドレスになる場合
/.*/        mail@mydomain.local

# もし特定のユーザーからのメールだけを上書きしたい場合
# user1       mail@mydomain.local
# user2       mail@mydomain.local
マップファイルを反映させます。

Bash

sudo postmap /etc/postfix/sender_canonical
header_checks.pcre ファイルの作成

From: ヘッダが mail@mydomain.local 以外だった場合に書き換えるための設定です。これはアプリケーションなどから From: ヘッダが既に設定されている場合に対応します。

Bash

sudo vi /etc/postfix/header_checks.pcre
内容：

/^From:.*$/ REPLACE From: mail@mydomain.local
ファイルを反映させます。

Bash

sudo postmap -q - /etc/postfix/header_checks.pcre < /dev/null # シンタックスチェック
5. ファイアウォールの設定
Postfixが使用するポート (SMTP: 25) を開放します。SSL/TLSを使用するため、Submissionポート (587) も開放することを推奨しますが、今回は送信専用でLAN内のみのため、ポート25で十分な場合もあります。ここでは両方開放します。

Bash

sudo firewall-cmd --permanent --add-service=smtp
sudo firewall-cmd --permanent --add-port=587/tcp # Submissionポート
sudo firewall-cmd --reload
6. Postfix サービスの起動と自動起動設定
Bash

sudo systemctl enable postfix
sudo systemctl start postfix
sudo systemctl status postfix
status コマンドで active (running) と表示されていれば成功です。

7. 動作確認
同一LAN内のクライアントPCからテストメールを送信してみます。

テスト方法例1: telnet (手動でTLS開始)

telnet コマンドでポート25に接続し、STARTTLS コマンドでTLSセッションを開始し、メールを送信します。
ただし、telnetクライアントがSTARTTLSに対応していない場合、うまく動作しない可能性があります。

Bash

telnet mail.mydomain.local 25
# 接続後...
EHLO yourclient.local
STARTTLS
# TLSハンドシェイクが成功すると、暗号化された通信になります。
# その後、通常のSMTPコマンドでメールを送信します。
MAIL FROM:<testuser@yourclient.local>
RCPT TO:<recipient@mydomain.local> # LAN内の受信者
DATA
Subject: Test mail from Postfix with TLS
This is a test email.
.
QUIT
注意: sender_canonical と header_checks.pcre が正しく設定されていれば、MAIL FROM や From: が mail@mydomain.local に書き換えられます。

テスト方法例2: mailx または sendmail コマンド (Postfixを介して送信)

Postfixが正しく設定されていれば、ローカルの mailx (または mail) コマンドや sendmail コマンドを使ってメールを送信すると、Postfixを介して送信され、mail@mydomain.local からのメールとして扱われるはずです。

Bash

echo "This is a test email body." | mail -s "Test from Postfix" recipient@mydomain.local
または

Bash

sendmail recipient@mydomain.local <<EOF
From: testuser@yourclient.local # これもmail@mydomain.localに書き換えられるはず
Subject: Test from Postfix via sendmail
This is another test email.
.
EOF
送信されたメールが受信側で正しく届き、送信元アドレスが mail@mydomain.local となっていることを確認してください。また、受信側のメールヘッダでTLSが使用されているかどうかも確認できます (Received: from ... by ... (TLS...) のような記述)。

8. ロギングの確認
Postfixのログは通常 /var/log/maillog に出力されます。

Bash

sudo tail -f /var/log/maillog
ここにエラーがないか、メールの送信が正しく記録されているかを確認してください。特にTLSに関するログ (smtpd_tls_loglevel = 1 で設定) も確認できます。

補足事項:

DNS設定: mydomain.local のMXレコードもPostfixサーバーを指すように設定されていると、同一LAN内の他のメールサーバーが mydomain.local 宛てのメールをこのPostfixサーバーに配送する際に便利です。ただし、今回は送信専用なので必須ではありません。

認証: 今回は mynetworks でIPアドレスベースのアクセス制御を行っていますが、よりセキュアな運用にはSMTP認証 (SASL) の導入を検討してください。その場合は、dovecot-sasl などのSASL認証プロバイダと連携させる必要があります。

エラーハンドリング: エラーが発生した場合は、/var/log/maillog を詳しく調査してください。Postfixの設定ミスやファイアウォールの問題などがよくある原因です。

セキュリティ: 自己署名証明書は開発・テスト環境向けです。本番環境や外部との通信では、Let's Encryptなどの信頼できるCAから発行された証明書を使用してください。

smtpd_tls_security_level: may はTLS接続を推奨しますが必須ではありません。encrypt はTLS接続を強制します。今回は同一LAN内での利用のため、may でも問題ない場合が多いですが、より厳密にしたい場合は encrypt を検討してください。ただし、クライアント側もTLSに対応している必要があります。

---

