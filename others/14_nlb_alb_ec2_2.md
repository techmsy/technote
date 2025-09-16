```vhost.conf
<VirtualHost *:56782>
    ServerName example.com
    DocumentRoot /usr/share/phpmyadmin
    
    <Directory /usr/share/phpmyadmin>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```
<br>
<br>
<br>
- EC2 ポート 80 には既存Webサイトが稼働中
- phpMyAdmin を別ポートで運用したい
- 外部公開したい（インターネットアクセス）
- 前回試した非標準ポートはISP/ブラウザ側でブロックされる可能性がある

現実的な解決策
1. 外部公開用は標準ポートを維持

クライアントからのアクセスは HTTPS 443 を使う

複数サービスを同じ443で公開するにはパスベースリバースプロキシ が有効

例:

Apache/ALBで パスで振り分け
```arduino
https://example.com/      → 既存Webサイト（80→443経由）
https://example.com/phpmyadmin → phpMyAdmin（内部56782）
```

これにより外部からは 443 固定でアクセス可能

内部は EC2:56782 に転送して既存80との競合回避

2. ALBでパスベースルーティング

ALBリスナーは 443/TLS

ルール例:

/ → ターゲットグループ：既存Webサイト（EC2:80）

/phpmyadmin/* → ターゲットグループ：phpMyAdmin（EC2:56782）

この構成なら NLBは443固定、非標準ポート不要

3. Apache単体でパスで分ける場合

VirtualHostを1つにまとめて ReverseProxy を使用

```apache
<VirtualHost *:56782>
    ServerName example.com
    DocumentRoot /var/www/html
</VirtualHost>

# phpMyAdminを別ディレクトリに
Alias /phpmyadmin /usr/share/phpmyadmin
<Directory /usr/share/phpmyadmin>
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

外部からのアクセスは NLB/ALB経由で 443 → Apache内部で 56782 または /phpmyadmin に振り分け

4. ポートベースでどうしても分けたい場合

外部公開は標準ポート必須

内部ポートは 56782 でも良い

NLB/ALBで 443→56782 にマッピングし、クライアントは 443固定でアクセス

つまり「外からは非標準ポートを見せず、内部でのみ56782使用」

💡 結論

インターネット公開で非標準ポートは基本的に避ける

既存WebサイトとphpMyAdminを別けるには パスベースのルーティング（ALBまたはApache Reverse Proxy） が最も安全

希望であれば、NLB 443 → ALB → EC2 80/56782（phpMyAdmin）で、ブラウザから /phpmyadmin でアクセス可能にする具体構成図 を作れます。

作りますか？

あなた:
お願い
ChatGPT:

了解です。では、既存Webサイト（EC2:80）とphpMyAdmin（EC2:56782）を同じNLB/ALB経由で外部公開し、ブラウザから /phpmyadmin でアクセス可能にする構成を整理します。

```bash
1. 全体構成図
Client (Internet)
   ↓ HTTPS 443
NLB (TLS終端 443)
   ↓ HTTP 443
ALB (HTTPS → HTTPリスナー)
   ├─ /           → ターゲットグループ: EC2 80（既存Web）
   └─ /phpmyadmin → ターゲットグループ: EC2 56782（phpMyAdmin）
```

ポイント

- 外部からは常に 443
- ALBでパスベースルーティング
- EC2のポートは既存Webサイト:80、phpMyAdmin:56782

2. ALBの設定
(A) リスナー

プロトコル: HTTPS

ポート: 443

SSL証明書: ACMで取得済みの証明書

(B) ルール
パス	ターゲットグループ
/	EC2 80（既存Webサイト）
/phpmyadmin/*	EC2 56782（phpMyAdmin）

デフォルトは / → 既存Webサイト

/phpmyadmin/* で phpMyAdmin に振り分け

3. EC2設定
(A) 既存Webサイト

Apache Listen 80

DocumentRoot 通常のWebサイトディレクトリ

(B) phpMyAdmin

- Apache Listen 56782
- DocumentRoot /usr/share/phpmyadmin
- Alias または VirtualHostを設定して /phpmyadmin を公開

```apache
<VirtualHost *:56782>
    ServerName example.com
    DocumentRoot /usr/share/phpmyadmin

    <Directory /usr/share/phpmyadmin>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

4. セキュリティグループ設定
リソース	許可ポート/送信元
NLB	443 TCP / 0.0.0.0/0
ALB	443 TCP / NLB SG
EC2 (既存Web)	80 TCP / ALB SG
EC2 (phpMyAdmin)	56782 TCP / ALB SG

NLBの443のみ外部公開

ALB・EC2は 内部通信のみ許可

5. クライアントアクセス例
```arduino
https://example.com/            → 既存Webサイト
https://example.com/phpmyadmin  → phpMyAdmin
```

外部から非標準ポートは見えない

ALBで内部ポートに振り分けるため安全

💡 メリット

- 外部は標準HTTPSポート443のみ
- 複数サービスを同じEC2で公開可能
- 非標準ポートのISP遮断問題を回避
- phpMyAdminはパスベースなので既存Webサイトとポート競合なし

必要であれば、私は この構成に合わせた NLB→ALB→EC2 のターゲットグループ設定手順 を AWS コンソール操作例つきで作成できます。

作りますか？

あなた:
お願い
ChatGPT:

了解です。では、NLB → ALB → EC2 (既存Web:80 / phpMyAdmin:56782) のターゲットグループ設定手順を AWS コンソール操作例付きで整理します。

1. 前提条件

NLB: TLS 443, Internet-facing

ALB: HTTP/HTTPSリスナー, パスベースルーティング

EC2: 既存Web 80、phpMyAdmin 56782

SSL証明書: ACMで取得済み

2. NLB 設定

NLB作成

Name: nlb-public

Scheme: internet-facing

Listeners: TCP 443

AZ選択: 必要なパブリックサブネットを選択

ターゲットグループ作成 (ALB用)

Target type: IP（ALBのPrivate IPを指定）

Protocol: TCP

Port: 443（ALBリスナーに合わせる）

Health check: TCP / 443

ターゲットに ALBのPrivate IP を追加

NLBリスナー設定

Listener: TCP 443

Default action: Forward → 上記ターゲットグループ（ALB）

3. ALB 設定

ALB作成

Name: alb-internal

Scheme: internal（VPC内部）

Listeners: HTTP 56782（phpMyAdmin用）、HTTP 80（既存Web用）

Security group: NLBからのTCPアクセスを許可

ターゲットグループ作成

既存Webサイト用

Protocol: HTTP

Port: 80

Targets: EC2 (既存Web:80)

phpMyAdmin用

Protocol: HTTP

Port: 56782

Targets: EC2 (phpMyAdmin:56782)

ALBリスナーのルール設定（パスベース）

Listener: HTTP 56782 or HTTPS 443

デフォルト: / → 既存Webサイト用ターゲットグループ

ルール追加: /phpmyadmin/* → phpMyAdmin用ターゲットグループ

4. EC2設定

既存Web

Apache Listen 80

DocumentRoot 通常Webサイトディレクトリ

phpMyAdmin

Apache Listen 56782

DocumentRoot /usr/share/phpmyadmin

VirtualHost 設定例:

```apache
<VirtualHost *:56782>
    ServerName example.com
    DocumentRoot /usr/share/phpmyadmin
    <Directory /usr/share/phpmyadmin>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

5. セキュリティグループ設定
リソース	許可ポート/送信元
NLB	443 TCP / 0.0.0.0/0
ALB	80,56782 TCP / NLB SG
EC2 Web	80 TCP / ALB SG
EC2 phpMyAdmin	56782 TCP / ALB SG

NLBの443のみ外部公開

ALB→EC2 は内部通信のみ許可

6. クライアントアクセス例
```arduino
https://example.com/            → 既存Webサイト
https://example.com/phpmyadmin  → phpMyAdmin
```

外部からは 常に標準HTTPSポート443

パスベースで内部ターゲットに振り分け

ISPやブラウザで非標準ポートブロックの問題を回避

💡 ポイント

phpMyAdminを安全に公開するには、パスベースルーティングとALB内部ターゲットグループがベスト

非標準ポート（56782）を外部に見せず内部で利用する

EC2上の既存Webとポート競合しない

必要であれば、この構成を作るための具体的コンソール操作手順（画面キャプチャなしで手順のみ） も作成できます。

作りますか？

あなた:
ALBはどこでパスベースルーティングを指定できますか？
ChatGPT:

いい質問です 👍
ALB の パスベースルーティング は、ALBのリスナー設定 の中で行います。

設定場所
AWS マネジメントコンソール

EC2 → ロードバランサー → Application Load Balancer(ALB) を選択

ALBの詳細画面 → リスナー タブ

443 (HTTPS) または 80 (HTTP) のリスナーを選択 → ルールの表示/編集

ルールの編集 画面で以下を設定

デフォルトアクション: 既存Web用ターゲットグループ（EC2:80）

新しいルールを追加:

条件: パスが /phpmyadmin/*

アクション: ターゲットグループに転送 (EC2:56782)

ルール設定のイメージ
```makefile
Listener: HTTPS : 443
 ├─ ルール1: IF パス == "/phpmyadmin/*"
 │       → TargetGroup-phpmyadmin (Port 56782)
 └─ デフォルトルール: 
         → TargetGroup-web (Port 80)
```

注意点

リスナーは1つでOK（443）

ルールを複数登録してパスごとにターゲットを分ける

/phpmyadmin/* のようにワイルドカードも利用可能

💡 結論:
ALBの「リスナー → ルール編集」でパスベースルーティングを指定します。