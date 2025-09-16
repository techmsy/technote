turnutils_uclientの結果で403 forbiddenが返ってくる。対策を調査して
ChatGPT:

なるほど、turnutils_uclient を使ったときに 403 Forbidden が返るのですね。
これは coturn 側が「認証情報が不正、もしくは認可されていない」と判断している状態です。よくある原因と対策を整理します。

✅ 403 Forbidden が返る主な原因と対策
1. ユーザー認証情報の不一致

turnutils_uclient の指定した -u（ユーザー名）と -w（パスワード）が、coturn の設定と一致していない場合。

確認方法

turnserver.conf に以下のように設定しているか確認：
```conf

lt-cred-mech
user=testuser:testpassword
```

実行時に：
```bash

turnutils_uclient your.turn.server -u testuser -w testpassword -p 3478
```

❌ 間違えやすい点: -W オプションはハッシュパスワードを指定するもので、通常は -w を使います。

2. realm の不一致

coturn は ユーザー認証時に realm を組み合わせてダイジェスト計算します。

turnserver.conf に realm=example.com があるなら、クライアントも同じ realm を使わないと認証エラーになります。

確認方法
設定ファイル：

```conf

realm=example.com
user=testuser:testpassword
```

実行時：

```bash

turnutils_uclient your.turn.server -u testuser -w testpassword -r example.com
```

3. long-term credentials 機構が有効になっていない

lt-cred-mech を書き忘れると認証が無効化され、403 が返ることがあります。

```conf
lt-cred-mech
```

4. TLS / Non-TLS の不一致

サーバーが 5349/TLS のみ受け付けているのに、クライアントが 3478/UDP で叩くと認証できず 403 になる場合があります。

確認方法:

サーバー設定:

```conf
listening-port=3478
tls-listening-port=5349
```

クライアント実行:

```bash
turnutils_uclient your.turn.server -u testuser -w testpassword -p 5349 -T
```

5. firewall / NAT 設定の問題

external-ip が正しく設定されていない場合、認証の途中で失敗して 403 を返すことがあります。

特にクラウド VM（AWS, GCP, Azure）では

```conf
external-ip=203.0.113.5/10.0.0.5
```

のようにグローバル/プライベートの対応を記載するのが重要。

✅ 調査ステップ

サーバー側ログ確認：

```bash
journalctl -u coturn -f
```

または /var/log/turnserver.log
→ 「Wrong credentials」や「unauthorized realm」など詳細が出る。

クライアント実行時に -r で realm を明示指定してみる：

```bash
turnutils_uclient your.turn.server -u testuser -w testpassword -r example.com
```

サーバー設定で lt-cred-mech を確認。

🔍 まとめ

403 Forbidden の原因はほぼ 認証関連（ユーザー・パスワード・realm の不一致）。
→ 対策としては

lt-cred-mech を有効化

realm をサーバー・クライアントで一致させる

ユーザー名/パスワードが一致しているか確認

TLS / ポート設定を揃える

👉 質問ですが、あなたの turnserver.conf では realm の設定を入れていますか？
それともデフォルトで未設定のままですか？

あなた:
external-ip=にdns名を指定できますか？
ChatGPT:

良いポイントです 👍
coturn の設定ファイルにある external-ip は、名前の通り 「IP アドレス」 を指定する項目です。

🔧 仕様

external-ip は TURN サーバーがクライアントに返す「中継アドレス」を明示的に設定するものです。

coturn の公式ドキュメントでも IP アドレスを直接指定するよう求められています。

つまり：

```conf

external-ip=203.0.113.5
external-ip=203.0.113.5/10.0.0.5
```

のように書く必要があり、DNS 名は不可 です。

🔧 なぜ DNS 名が使えないのか

クライアントに返す情報は「リレーアドレス」＝ IP アドレスである必要があります。

DNS 名を返すと TURN クライアントが解決できる保証がないため、仕様上 coturn は許していません。

もし DNS 名を指定しても、coturn 側で名前解決されずエラーになったり、無視されるケースが多いです。

🔧 運用上の解決策

DNS 名しか管理していない場合は、事前に名前解決して 固定 IP を書く必要があります。

```bash

dig +short turn.example.com
```

で解決した IP を external-ip に書く。

クラウド環境で IP が変動する場合は自動化が必要です。

systemd の起動スクリプトや cron で DNS を引いて external-ip を更新し、coturn をリロードする

あるいはそもそも固定グローバル IP を利用する

✅ まとめ

external-ip には 必ず IP アドレスを指定する必要がある

DNS 名は不可

可変 IP の場合は、スクリプトなどで解決結果を反映させるのが運用上の対応策

👉 ご質問ですが、あなたの環境の クラウド VM のグローバル IP は固定（Elastic IP など）ですか？
それとも動的に変わる仕組みでしょうか？

あなた:
confが以下の場合、クライアント側はtcp/udpどの種類のどのポート番号で接続できる？

listening-port=3478
tls-listening-port=5349
ChatGPT:

なるほど、設定ありがとうございます 👍
この場合の クライアントから TURN サーバーへの接続方法（ポート番号とプロトコル種別） は以下のようになります。

🔧 設定内容の意味

```bash
listening-port=3478
tls-listening-port=5349
```

listening-port=3478

TURN/STUN の 平文接続用ポート

TCP と UDP の両方を受け付ける

つまりクライアントは

udp/3478

tcp/3478
で接続できる

tls-listening-port=5349

TLS 経由の TURN/STUN 用ポート

TCP のみ

クライアントは

tcp/5349（TLSラップ）
で接続できる

🔧 まとめ（クライアントが使えるポート）

- UDP/3478 … 平文（UDPベース）
- TCP/3478 … 平文（TCPベース）
- TCP/5349 … TLS（いわゆる turns: / stuns: スキーム）

🔧 補足

実際にクライアント（WebRTC, coturn utils, VoIP アプリなど）がどれを使うかは、

設定した URL スキーム（turn:, turns:）

transport パラメータ（?transport=tcp or ?transport=udp）
で決まります。

例:

- turn:turn.example.com:3478?transport=udp → UDP/3478
- turn:turn.example.com:3478?transport=tcp → TCP/3478
- turns:turn.example.com:5349?transport=tcp → TCP/5349 (TLS)

👉 ちなみに質問ですが、あなたのクライアントは WebRTC（ブラウザ） を想定していますか？
それとも coturn の turnutils_uclient などでの検証環境でしょうか？

あなた:
以下の-Tの意味？

turnutils_uclient your.turn.server -u testuser -w testpassword -p 5349 -T
ChatGPT:

良いところに気付きましたね 👍
turnutils_uclient の -T オプション の意味は、

➡ 「TLS を使って接続する」 です。

🔧 詳細

turnutils_uclient の主な接続モード:

- -p <port> … 接続先ポート番号を指定
- -t … TCP を利用して接続
- -T … TLS を利用して接続（通常は tls-listening-port に設定したポート、例: 5349）

（省略時） … UDP で接続

🔧 例
```bash

# UDP/3478 に接続
turnutils_uclient your.turn.server -u testuser -w testpassword -p 3478

# TCP/3478 に接続
turnutils_uclient your.turn.server -u testuser -w testpassword -p 3478 -t

# TLS/TCP/5349 に接続
turnutils_uclient your.turn.server -u testuser -w testpassword -p 5349 -T
```

つまりあなたの例は:

```bash
turnutils_uclient your.turn.server -u testuser -w testpassword -p 5349 -T
```

→ 「your.turn.server の 5349 ポートへ TLS 接続でテストする」 という意味になります。