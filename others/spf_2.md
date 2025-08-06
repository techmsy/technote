メールの送受信はできましたが受信側のログにspfに関する記述が見当たらない


ChatGPT:
SPF検証がPostfixで有効になっていないか、あるいはpolicyd-spf（またはそれに相当するSPFチェック機構）が正しく動作していない可能性があります。以下の手順でチェック・修正していきましょう。

✅ チェックポイント一覧
チェック項目	内容
① SPFポリシーデーモン導入済か？	postfix-policyd-spf-python など
② Postfix設定にポリシーサービスが記述されているか？	main.cf / master.cf
③ SPFポリシーサービスが起動しているか？	systemctl or ps aux
④ SPFチェックログが /var/log/maillog に出力されているか？	ない場合、ログレベルなどを確認

🔍 ステップバイステップ確認
① SPF用のポリシーサービスがインストールされているか？
以下を実行：

bash
コピーする
編集する
rpm -qa | grep policyd-spf
出力例：

text
コピーする
編集する
postfix-policyd-spf-python-2.0.2-4.el8.noarch
インストールされていなければ：

bash
コピーする
編集する
sudo dnf install epel-release -y
sudo dnf install postfix-policyd-spf-python -y
② Postfixの設定確認
/etc/postfix/master.cf
末尾に次があるか確認：

ini
コピーする
編集する
policyd-spf  unix  -       n       n       -       0       spawn
    user=nobody argv=/usr/bin/policyd-spf
/etc/postfix/main.cf
check_policy_service の記述が smtpd_recipient_restrictions に含まれているか確認：

ini
コピーする
編集する
smtpd_recipient_restrictions =
    permit_mynetworks,
    permit_sasl_authenticated,
    reject_unauth_destination,
    check_policy_service unix:private/policyd-spf
❗ 注意点

check_policy_service は permit_〜 のあとに記述してください。

smtpd_recipient_restrictions の記述が複数箇所にあると、後者が上書きされるので注意。

③ Postfix 再起動（設定変更後）
bash
コピーする
編集する
sudo systemctl restart postfix
念のためポリシーサービスが動いているか確認：

bash
コピーする
編集する
ps aux | grep policyd-spf
もしくは Postfix のプロセスの中に spawn されているか確認。

④ ログの確認方法
ログファイル：

bash
コピーする
編集する
sudo tail -f /var/log/maillog
送信後に "spf" や "policyd-spf" を含む行が表示されるか確認：

bash
コピーする
編集する
grep spf /var/log/maillog
表示されない場合：

ログレベルを上げる（試験的に）
/etc/postfix/main.cf に以下を追加（または編集）してログ出力を強化：

ini
コピーする
編集する
policyd-spf_time_limit = 3600
debug_peer_list = 127.0.0.1
✅ 動作確認メールをもう一度送信
送信側で以下を実行：

bash
コピーする
編集する
echo "SPFチェック" | mail -s "SPFテスト" user@mydomain.local
再度 /var/log/maillog に SPF関連のログが出るか確認してください。

🛠 トラブル時の対処（出ない場合）
/usr/bin/policyd-spf が存在するか確認

spawn で起動できているか、/var/log/maillog にエラーが出ていないか確認

/etc/postfix/master.cf や /etc/postfix/main.cf の改行やインデントの不備をチェック

Postfix の smtpd_recipient_restrictions が正しく有効な順序で記述されているか

🧪 オプション：ポリシーサービスを明示的にテスト
次のようなスクリプトで、policyd-spf が応答しているかをテストできます：

bash
コピーする
編集する
echo -e "policy-request\nclient_address=192.168.1.100\nsender=from@mydomain.local\n\n" | nc localhost 25
ご希望があれば
SPF評価をsyslogに強調表示

Postfixの受信拒否ポリシーの強化例

テストスクリプトの作成（送信 → SPF → ログ確認まで自動化）

も対応可能です。お気軽にどうぞ。








あなた:
/etc/postfix/master.cfへの設定の記述の際です。
項目間はすべてタブ区切りですか？また、spawnの直後は改行ですか？


ChatGPT:
はい、ご質問ありがとうございます。
/etc/postfix/master.cf の記述にはフォーマット上の厳格なルールがあり、以下の点に注意が必要です：

✅ 各フィールド間の区切りは スペース or タブ（どちらでもOK）
複数のスペースやタブで区切るのがPostfixの標準仕様です。

Tabで統一する必要はありませんが、混在しない方が見やすく、誤解が起きにくいです。

通常、Postfixはスペースとタブを等しく扱います（isspace()文字扱い）。

✅ spawn の行の次は 改行して引き続き設定
例：policyd-spf の正しい書き方（改行あり）
ini
コピーする
編集する
policyd-spf  unix  -       n       n       -       0       spawn
    user=nobody argv=/usr/bin/policyd-spf
spawn で終わる行の 次の行にインデントをつけて続けます（スペースでもタブでもOK）

この インデントがないとPostfixが次の行を別プロセスとして誤解します

❌ 間違った例（NG）
ini
コピーする
編集する
policyd-spf  unix  -  n  n  -  0  spawn user=nobody argv=/usr/bin/policyd-spf
→ この書き方は1行に詰め込んでおり、Postfixが正しく処理できません。

✅ 補足：Postfixのフィールド定義
php-template
コピーする
編集する
<service_name> <type> <private> <unprivileged> <chroot> <wakeup_time> <maxproc> <command>...
🔧 推奨：記述スタイルを整える
Postfixは動作はしますが、トラブル時に読みやすく保守しやすいように以下をおすすめします：

各フィールドを1タブまたは数スペースで整列

spawn などは行を分けて、次行に引き続く形に

インデントはタブ1つ or スペース4つで統一

✅ 最終確認：Postfix設定をテスト
設定後は、以下で構文エラーがないか必ずチェック：

bash
コピーする
編集する
postfix check
問題があれば postfix check で表示されます。
他にも構文を見てほしい場合は、貼っていただければ確認します。