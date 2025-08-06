spfをやりたい。方法を示して。
rocky linux8が受信側。amazon linux 2023が送信側。
どちらにもpostfix、dovecot、bindインストール済。
2台は同一セグメントのlan内です。


ChatGPT:
了解しました。
**SPF（Sender Policy Framework）**は、送信元のドメインが使用してよいメール送信サーバー（IPアドレス）をDNSに記述し、受信側でそれを検証する仕組みです。

✅ 構成概要
送信側：Amazon Linux 2023（Postfixでメール送信）

受信側：Rocky Linux 8（Postfix + Dovecotでメール受信）

両方に BIND 導入済

LAN内（例：192.168.1.0/24）で閉じた環境

ドメイン名の例：mydomain.local

🔧 手順の概要（SPF導入）
送信側（Amazon Linux）で使うドメインを決める

DNSサーバー（BIND）にSPFレコードを設定する（受信側で利用）

受信側（Rocky Linux）のPostfixでSPF検証を有効にする

動作確認

🧾 1. 送信元のメールドメインを確認（例：mail.mydomain.local）
Postfix設定 /etc/postfix/main.cf に以下を設定済であるとします：

ini
コピーする
編集する
myhostname = mail.mydomain.local
myorigin = mydomain.local
この場合、SPFは mydomain.local のDNSゾーンに対して設定します。

📄 2. BINDでのSPFレコード追加（受信側のBIND）
受信側サーバー（Rocky Linux）にある mydomain.local のゾーンファイル（例：/var/named/mydomain.local.zone）に、以下のようにSPFレコード（TXT形式）を追加します：

dns
コピーする
編集する
mydomain.local. IN TXT "v=spf1 ip4:192.168.1.100 -all"
192.168.1.100 はAmazon Linux 送信サーバーのIPです。

追加後、BINDをリロード：

bash
コピーする
編集する
sudo rndc reload
🔍 SPFレコードの確認（任意）
受信側のサーバーまたは他のLAN内の端末から、以下で確認できます：

bash
コピーする
編集する
dig @localhost mydomain.local TXT
出力に "v=spf1 ip4:192.168.1.100 -all" が含まれていればOK。

📥 3. 受信側 Postfix で SPF チェックを有効にする
推奨方法：postfix-policyd-spf-python を使う
🔧 インストール
bash
コピーする
編集する
sudo dnf install epel-release -y
sudo dnf install postfix-policyd-spf-python -y
🛠 Postfix設定（/etc/postfix/master.cf）
以下を追加（末尾あたり）：

bash
コピーする
編集する
policyd-spf  unix  -       n       n       -       0       spawn
    user=nobody argv=/usr/bin/policyd-spf
🛠 Postfix設定（/etc/postfix/main.cf）
以下を追加：

ini
コピーする
編集する
smtpd_recipient_restrictions =
    permit_mynetworks,
    permit_sasl_authenticated,
    reject_unauth_destination,
    check_policy_service unix:private/policyd-spf
（既存の smtpd_recipient_restrictions を編集する場合もあります）

🔄 Postfix再起動
bash
コピーする
編集する
sudo systemctl restart postfix
✅ 動作確認
送信側（Amazon Linux）から、次のようなメールを送信：

bash
コピーする
編集する
echo "SPFテスト" | mail -s "SPFテスト" user@mydomain.local
受信側の /var/log/maillog を確認：

bash
コピーする
編集する
grep spf /var/log/maillog
例：

makefile
コピーする
編集する
spf: pass (sender SPF authorized)
📌 注意事項
SPF検証は「受信時のドメインのDNSレコードに送信元IPが記載されているか」を確認するだけで、なりすまし防止には必ずしも十分ではありません（DKIMやDMARCと併用推奨）。

.local は本来LAN向けで、外部には伝播されないため、完全にLAN内で閉じたメール検証の用途に限ります。

💬 必要であれば次もサポートします
DKIM / DMARC 設定

BINDでのPTRレコード（逆引き）

Postfix/Dovecotのログ解析

LAN内専用メールテストスクリプト作成

ご希望あればお知らせください。