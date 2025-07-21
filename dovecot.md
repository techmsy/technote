### MTA (Mail Transfer Agent) の Postfix と、IMAP/POP3サーバーの Dovecot を組合せた構築手順。

---

#### SSL/TLSにはLet's Encryptのような商用証明書ではなく、<br>
自己署名証明書（検証目的）を使用する前提。本番環境では必ず正規のCA証明書を利用のこと。

#### 前提条件の確認
- Rocky Linux 8がインストールされ、基本的なネットワーク設定が完了していること。
- mydomain.local ドメインのDNSサーバー (BIND) が同一LAN内に構築済みで、適切に名前解決ができること。
- mail.mydomain.local のAレコードが、このメールサーバーのIPアドレスを指していること。
- mydomain.local のMXレコードが mail.mydomain.local を指していること。
- firewalldが動作していること。
- SELinuxがEnforcingモードであること（適切に設定します）。

---

#### 構築手順
#### 1. ホスト名の設定とDNSレコードの確認
まず、メールサーバーのホスト名を設定し、DNSレコードが正しく引けるか確認します。

> ホスト名の設定:

```Bash
sudo hostnamectl set-hostname mail.mydomain.local
/etc/hosts にも以下の行を追加します。
```
192.168.X.Y   mail.mydomain.local mail
（192.168.X.Y はこのメールサーバーのIPアドレスに置き換えてください）

DNSレコードの確認:
DNSサーバー側で以下のレコードが設定されていることを確認してください。

Aレコード:
mail.mydomain.local.  IN  A   192.168.X.Y (このメールサーバーのIPアドレス)

MXレコード:
mydomain.local.   IN  MX  10  mail.mydomain.local.

メールサーバーからDNS解決できるか確認します。

Bash

dig A mail.mydomain.local
dig MX mydomain.local
正しいIPアドレスとホスト名が表示されることを確認してください。

2. 必要なパッケージのインストール
PostfixとDovecotをインストールします。

Bash

sudo dnf install -y postfix dovecot
3. 自己署名SSL/TLS証明書の作成
テスト目的のため、自己署名証明書を作成します。本番環境では、Let's Encryptなどで取得した正規の証明書を使用してください。

Bash

sudo mkdir -p /etc/pki/dovecot/private
sudo mkdir -p /etc/pki/postfix/private

sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 -keyout /etc/pki/dovecot/private/dovecot.pem -out /etc/pki/dovecot/dovecot.pem -subj "/CN=mail.mydomain.local"
sudo chmod 400 /etc/pki/dovecot/private/dovecot.pem

# Postfix用はDovecotと同じものを使っても良いですが、今回は別途作成します
sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 -keyout /etc/pki/postfix/private/postfix.pem -out /etc/pki/postfix/postfix.pem -subj "/CN=mail.mydomain.local"
sudo chmod 400 /etc/pki/postfix/private/postfix.pem
4. Postfixの設定
Postfixはメールの送受信を担当します。

main.cf のバックアップと編集:

Bash

sudo cp /etc/postfix/main.cf /etc/postfix/main.cf.bak
sudo vi /etc/postfix/main.cf
以下の設定項目を変更または追加します。

# ホスト名
myhostname = mail.mydomain.local

# ドメイン名
mydomain = mydomain.local

# メールを受信するドメイン
mydestination = $myhostname, localhost.$mydomain, localhost, $mydomain

# ネットワークインターフェース
inet_interfaces = all
inet_protocols = all

# 信頼するネットワーク (同一LAN内からのメールを受け付ける)
mynetworks = 127.0.0.0/8, 192.168.0.0/16  # ここは実際のLANのセグメントに合わせてください

# 新しいメールボックスの形式
home_mailbox = Maildir/

# SSL/TLS設定
smtpd_tls_security_level = may
smtpd_tls_cert_file = /etc/pki/postfix/postfix.pem
smtpd_tls_key_file = /etc/pki/postfix/private/postfix.pem
smtpd_tls_session_cache_database = btree:/var/lib/postfix/smtpd_scache
smtpd_tls_loglevel = 1
smtpd_tls_received_header = yes
smtpd_tls_mandatory_ciphers = high
smtpd_tls_mandatory_protocols = !SSLv2, !SSLv3, !TLSv1, !TLSv1.1
smtpd_tls_protocols = !SSLv2, !SSLv3, !TLSv1, !TLSv1.1
注意: mynetworks は、同一LAN内の送信元IPアドレスレンジに設定してください。例えば 192.168.0.0/24 や 10.0.0.0/8 など。

Postifxの有効化と起動:

Bash

sudo systemctl enable postfix
sudo systemctl start postfix
sudo systemctl status postfix
5. Dovecotの設定
Dovecotはメールボックスへのアクセス (IMAP/POP3) を提供します。

10-mail.conf の編集:

Bash

sudo vi /etc/dovecot/conf.d/10-mail.conf
以下の行を変更または追加します。

mail_location = maildir:~/Maildir
10-master.conf の編集:

Bash

sudo vi /etc/dovecot/conf.d/10-master.conf
service auth セクションのunix_listener auth部分とservice lmtpセクションのunix_listener lmtp部分をコメントアウト解除し、userをpostfixに変更します。

service auth {
  unix_listener auth {
    mode = 0660
    user = postfix   # ここを変更
    group = postfix  # ここを変更
  }
}

# ... (中略) ...

service lmtp {
  unix_listener /var/spool/postfix/private/dovecot-lmtp {
    mode = 0666
    user = postfix   # ここを変更
    group = postfix  # ここを変更
  }
}
また、service imapとservice pop3が有効になっていることを確認してください。通常はデフォルトで有効です。

10-auth.conf の編集:

Bash

sudo vi /etc/dovecot/conf.d/10-auth.conf
以下の行を変更またはコメントアウトを解除します。

auth_mechanisms = plain login
disable_plaintext_auth = no  # テスト目的。本番ではyesにすべき
10-ssl.conf の編集:

Bash

sudo vi /etc/dovecot/conf.d/10-ssl.conf
以下の行を変更します。

ssl = yes
ssl_cert = </etc/pki/dovecot/dovecot.pem
ssl_key = </etc/pki/dovecot/private/dovecot.pem
ssl_cipher_list = high:!aNULL:!RC4:!MD5:!kDH:!DH:!SRP:!DSS:!CAMELLIA:!3DES:!TLSv1:!TLSv1.1
ssl_protocols = !SSLv2 !SSLv3 !TLSv1 !TLSv1.1
ssl_protocols の設定は、よりセキュアなプロトコルのみを許可するようにします。

Dovecotの有効化と起動:

Bash

sudo systemctl enable dovecot
sudo systemctl start dovecot
sudo systemctl status dovecot
6. Firewalldの設定
必要なポートを開放します。

Bash

sudo firewall-cmd --add-service=smtp --permanent  # Postfix
sudo firewall-cmd --add-service=imap --permanent  # Dovecot (IMAP)
sudo firewall-cmd --add-service=imaps --permanent # Dovecot (IMAPS - SSL)
sudo firewall-cmd --add-service=pop3 --permanent  # Dovecot (POP3)
sudo firewall-cmd --add-service=pop3s --permanent # Dovecot (POP3S - SSL)
sudo firewall-cmd --reload
7. SELinuxの設定
SELinuxがEnforcingモードの場合、PostfixとDovecotがメールボックスにアクセスできるように設定が必要です。

Bash

sudo setsebool -P postfix_home_certs 1
sudo setsebool -P allow_ptrace 1 # 必要に応じて
sudo setsebool -P httpd_can_sendmail 1 # 必要に応じて
sudo chcon -Rt mail_home_rw_t /home/*/Maildir # ユーザーのMaildirディレクトリにSELinuxコンテキストを設定
もしメールが受信できない場合は、sudo ausearch -c 'postfix' --raw | audit2allow -M mypostfix などで不足しているルールを見つけ、適用することを検討してください。

8. メールユーザーの作成
メールを受信するユーザーを作成します。ここでは mail というユーザーを作成し、そのメールアドレスが mail@mydomain.local になります。

Bash

sudo useradd -m mail
sudo passwd mail
パスワードを設定してください。

9. 動作確認
同一LAN内のクライアントからテストメール送信:
同一LAN内の別のマシンから、mail@mydomain.local 宛にメールを送信します。
例えば、telnet mail.mydomain.local 25 で接続し、SMTPコマンドでテスト送信できます。

telnet mail.mydomain.local 25
EHLO example.com
MAIL FROM:<test@example.com>
RCPT TO:<mail@mydomain.local>
DATA
Subject: Test Mail

This is a test mail from local network.
.
QUIT
メールクライアントでの受信確認:
ThunderbirdやOutlookなどのメールクライアントで、以下の設定でmail@mydomain.localアカウントを追加し、メールを受信できるか確認します。

ユーザー名: mail

パスワード: 上記で設定したパスワード

受信サーバー (IMAP): mail.mydomain.local

ポート: 993 (SSL/TLS)

接続の保護: SSL/TLS または STARTTLS

受信サーバー (POP3): mail.mydomain.local

ポート: 995 (SSL/TLS)

接続の保護: SSL/TLS または STARTTLS

自己署名証明書の場合、証明書の警告が表示されるので、信頼するよう選択してください。

サーバー側のログ確認:

Bash

sudo tail -f /var/log/maillog
メールの送受信に関するログが表示されます。エラーがないか確認してください。

トラブルシューティング
ログの確認: /var/log/maillog (または /var/log/secure、/var/log/messages) は常に確認の基本です。

SELinux: sudo getenforce でEnforcingになっている場合、SELinuxが原因でアクセス拒否されている可能性があります。audit2allow を使用するか、一時的にPermissiveモード (sudo setenforce 0) にして問題が解消するか確認してください。

Firewalld: sudo firewall-cmd --list-all で設定が反映されているか確認してください。

DNS: dig コマンドでMXレコードやAレコードが正しく引けるか再確認してください。

設定ファイルのtypo: 設定ファイルを再確認し、スペルミスがないか、パスが正しいかを確認してください。
