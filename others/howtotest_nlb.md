NLB に対して IPアドレスとポートの接続テスト をする方法を整理します。

🔹 1. 前提

- NLB は L4ロードバランサー (TCP/UDP)
- NLB の DNS名（例: nlb-xxxx.elb.ap-northeast-1.amazonaws.com）がありますが、- - Elastic IPを割り当てれば固定IPでもテスト可能
- リスナーポート例: 50443 (非メジャーポート)

🔹 2. 接続テスト方法
a. telnet

ポートが開いているか確認

```bash
telnet <NLBのIPアドレス> 50443
```

成功すると「Connected」と表示されます。
失敗すると「Connection refused」や「Timeout」が出ます。

b. nc (netcat)

より柔軟に確認可能

```bash
nc -vz <NLBのIPアドレス> 50443
```

-v → 詳細表示
-z → ポートスキャンモード（実際にデータは送らない）

成功例: Connection to <IP> 50443 port [tcp/*] succeeded!

c. curl (HTTP/HTTPSの場合)

もし ALB 経由で Apache に到達しているなら、curlでレスポンス確認できます。

```bash
curl -vk https://<NLBのIPアドレス>:50443/
```

- -k → 証明書検証を無効化（内部テスト用）
- Apacheが動作していれば HTML が返ります

d. openssl s_client (SSL証明書確認)

HTTPSで正しくALBまで到達しているか確認

```bash
openssl s_client -connect <NLBのIPアドレス>:50443
```

- サーバー証明書チェーンを確認可能
- ALBのSSL証明書が出てくればOK

🔹 3. トラブルシュートの流れ

- 1 NLBのSG / NACL でポート 50443 が許可されているか確認
- 2 ALBのターゲットがHealthyになっているか確認
- 3 EC2のApacheがALBからのアクセスを受け付けているか確認

💡 ポイント：
NLBはDNS名での利用が基本ですが、Elastic IPを割り当てて固定IPでの接続テストも可能です。
もし「固定IPで確認したい」なら、NLBのリスナーに EIPアタッチしておくと良いです。