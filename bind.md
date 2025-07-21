### ✅ DNS サーバーを BIND を使って構築する手順

#### 前提：
- ドメイン mydomain.local

---

#### 1. 前提条件と準備
- Rocky Linux 8 サーバー: 最小限のインストールで構いません。
- 静的IPアドレス: DNSサーバーとなるRocky Linux 8には、固定IPアドレスを設定してください。例: 192.168.1.100
- ファイアウォール設定: DNSポート（UDP/TCP 53）を開放する必要があります。
- SELinux設定: BINDが正常に動作するようにSELinuxの設定も確認します。

---

#### 2. BINDのインストール
```Bash
sudo dnf install -y bind bind-utils
```

---

#### 3. BINDの設定ファイル編集
BINDの主要な設定ファイルは /etc/named.conf です。ゾーンファイルは /var/named/ ディレクトリに配置します。

#### 3.1. /etc/named.conf の編集
以下の内容に修正または追加します。

```Bash
sudo cp /etc/named.conf /etc/named.conf.bak # バックアップ
sudo vi /etc/named.conf
```
---

options ブロックを以下のように編集。

```named.conf
options {
    listen-on port 53 { any; }; # 全てのIPアドレスからの接続を許可（LAN内のみなので問題なし）
    # listen-on port 53 { 127.0.0.1; 192.168.1.100; }; # 特定のIPアドレスのみ許可する場合はこちら
    directory       "/var/named";
    dump-file       "/var/named/data/cache_dump.db";
    statistics-file "/var/named/data/named_stats.txt";
    memstatistics-file "/var/named/data/named_mem_stats.txt";
    recursing-file  "/var/named/data/named.recursing";
    secroots-file   "/var/named/data/named.secroots";
    allow-query     { localhost; 192.168.1.0/24; }; # クエリを許可するネットワーク（例: 192.168.1.0/24）
    # allow-recursion { localhost; 192.168.1.0/24; }; # 外部への再帰問い合わせを許可するネットワーク（必要に応じて）

    # 上位DNSサーバーへの転送設定（必要であれば、コメントを外して設定）
    # forwarders {
    #     8.8.8.8; # Google Public DNS
    #     8.8.4.4;
    # };
    # forward only; # 転送のみ行う場合

    dnssec-validation auto;

    managed-keys-directory "/var/named/dynamic";

    pid-file "/run/named/named.pid";
    session-keyfile "/run/named/session.key";
};

logging {
    channel default_log {
        file "/var/log/named/named.log" versions 3 size 5m;
        severity info;
        print-time yes;
    };
    category default { default_log; };
    category queries { default_log; };
};

zone "." IN {
    type hint;
    file "named.ca";
};

# mydomain.local の正引きゾーン
zone "mydomain.local" IN {
    type master;
    file "mydomain.local.zone"; # 後で作成するゾーンファイル名
    allow-update { none; };
};

# mydomain.local の逆引きゾーン (例: 192.168.1.x の場合)
zone "1.168.192.in-addr.arpa" IN {
    type master;
    file "mydomain.local.rev"; # 後で作成する逆引きゾーンファイル名
    allow-update { none; };
};

include "/etc/named.rfc1912.zones";
include "/etc/named.root.key";
```

> ポイント:
- listen-on: DNSサーバーが待ち受けるIPアドレスとポート。<br>
LAN内のみなので any でも良いですが、セキュリティを考慮してサーバーのIPアドレスを指定することも可能です。
- allow-query: DNSクエリを許可するクライアントのIPアドレスまたはネットワーク。ここでは 192.168.1.0/24 としています。
- forwarders: 内部DNSで解決できない場合に、上位のDNSサーバー（例: プロバイダのDNSやGoogle Public DNS）に問い合わせを転送するかどうか。<br>
LAN内完結なら不要ですが、インターネットへのアクセスも考慮する場合は設定します。
- mydomain.local の正引きゾーンと逆引きゾーンを追加します。

#### 3.2. ゾーンファイルの作成
BINDのゾーンファイルは /var/named/ ディレクトリに置くのが一般的です。SELinuxの制約もあるため、このディレクトリを使用してください。

/var/named/mydomain.local.zone (正引きゾーンファイル)

```Bash
sudo vi /var/named/mydomain.local.zone
``` 

``` 
$TTL 86400
@ IN SOA ns.mydomain.local. admin.mydomain.local. (
                                2024072001 ; Serial
                                3H         ; Refresh
                                1H         ; Retry
                                1W         ; Expire
                                1D )       ; Minimum TTL
@               IN      NS      ns.mydomain.local.
ns              IN      A       192.168.1.100
server1         IN      A       192.168.1.101
client1         IN      A       192.168.1.102
``` 

> 説明:

- $TTL: デフォルトのTTL (Time To Live)。
- @ IN SOA ns.mydomain.local. admin.mydomain.local. (...): Start of Authority レコード。
- ns.mydomain.local.: プライマリDNSサーバーのホスト名。
- admin.mydomain.local.: 管理者のメールアドレス (ただし @ を . に置き換え)。
- Serial: シリアル番号。ゾーンファイルを変更するたびにこの数値を増やす必要があります。一般的には日付＋連番 (例: YYYYMMDDNN) が使われます。
- Refresh, Retry, Expire, Minimum TTL: セカンダリDNSサーバーがゾーン情報を更新する間隔など。
- @ IN NS ns.mydomain.local.: mydomain.local のネームサーバーが ns.mydomain.local であることを示す。
- ns IN A 192.168.1.100: ホスト名 ns のIPアドレス。
- server1 IN A 192.168.1.101: ホスト名 server1 のIPアドレス。
- client1 IN A 192.168.1.102: ホスト名 client1 のIPアドレス。

---

/var/named/mydomain.local.rev (逆引きゾーンファイル)

```Bash
sudo vi /var/named/mydomain.local.rev
```

```
$TTL 86400
@ IN SOA ns.mydomain.local. admin.mydomain.local. (
                                2024072001 ; Serial
                                3H         ; Refresh
                                1H         ; Retry
                                1W         ; Expire
                                1D )       ; Minimum TTL
@               IN      NS      ns.mydomain.local.
100             IN      PTR     ns.mydomain.local.
101             IN      PTR     server1.mydomain.local.
102             IN      PTR     client1.mydomain.local.
```

> 説明:

100 IN PTR ns.mydomain.local.: IPアドレスの最後のオクテット (100) が ns.mydomain.local であることを示す。

---

#### 3.3. ゾーンファイルの所有者とパーミッションの変更
ゾーンファイルが BIND ユーザー (named) によって読み取れるようにします。

```Bash
sudo chown named:named /var/named/mydomain.local.zone
sudo chown named:named /var/named/mydomain.local.rev
sudo chmod 640 /var/named/mydomain.local.zone
sudo chmod 640 /var/named/mydomain.local.rev
```

---

#### 4. SELinuxの設定
BINDがゾーンファイルを読み書きできるように、SELinuxのコンテキストを設定します。

```Bash
sudo semanage fcontext -a -t named_zone_t "/var/named/mydomain.local.zone"
sudo semanage fcontext -a -t named_zone_t "/var/named/mydomain.local.rev"
sudo restorecon -Rv /var/named/
```
もし、semanage コマンドが見つからない場合は、policycoreutils-python-utils パッケージをインストール。

```Bash
sudo dnf install -y policycoreutils-python-utils
```

---

#### 5. ファイアウォールの設定
DNSポート（UDP 53, TCP 53）を開放します。

```Bash
sudo firewall-cmd --permanent --add-service=dns
sudo firewall-cmd --reload
```

---

#### 6. BINDの設定テストと起動
設定ファイルの構文チェックを行います。

```Bash
sudo named-checkconf
sudo named-checkzone mydomain.local /var/named/mydomain.local.zone
sudo named-checkzone 1.168.192.in-addr.arpa /var/named/mydomain.local.rev
```
エラーがなければ、BINDサービスを有効化して起動します。

```Bash
sudo systemctl enable named
sudo systemctl start named
sudo systemctl status named
```
Active: active (running) となっていれば成功。

---

#### 7. クライアントからのテスト
クライアントPC（Windows, Linuxなど）のDNS設定を、構築したDNSサーバーのIPアドレス（例: 192.168.1.100）に変更します。

Linuxクライアントの場合
/etc/resolv.conf を編集します。<br>（再起動やDHCPクライアントによっては上書きされるため、永続化の方法は別途検討してください）

```Bash
sudo vi /etc/resolv.conf
nameserver 192.168.1.100
search mydomain.local # 必要であれば追加
```
または、NetworkManagerを使用している場合は、GUIまたはnmcliで設定します。

Windowsクライアントの場合
ネットワークアダプターの設定から、IPv4のDNSサーバーを手動で設定します。

テスト
クライアントから nslookup または dig コマンドで確認します。

```Bash
# 正引きテスト
nslookup server1.mydomain.local
dig server1.mydomain.local

# 逆引きテスト
nslookup 192.168.1.101
dig -x 192.168.1.101
```
上記で、設定したIPアドレスとホスト名が正しく解決されれば成功。

---

#### 注意事項
- Serial番号の更新: ゾーンファイルを変更した際は、必ずSOAレコードのSerial番号を増やしてください。<br>
これを忘れると、セカンダリDNSサーバーがゾーン情報を更新しません。（今回はLAN内完結なのでセカンダリDNSは不要ですが、習慣として重要です）

- キャッシュ: クライアントやDNSサーバー自身のキャッシュが効いている場合、設定変更がすぐに反映されないことがあります。<br>
その場合は、クライアントのDNSキャッシュをクリアするか、しばらく待つか、DNSサーバーを再起動してみてください。

- DHCPとの連携: クライアントに自動的にDNSサーバーの情報を配布したい場合は、DHCPサーバー（例: dhcpd）を設定し、DNSサーバーのIPアドレスを配布するように構成する必要があります。

- セキュリティ: 今回はLAN内向けですが、本番環境で公開する場合は、より厳密なセキュリティ設定（アクセス制限、DNSSECなど）を検討する必要があります。

---
