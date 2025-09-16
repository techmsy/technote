coturn には util ディレクトリ配下の検証ツール がいくつか付属していて、動作確認に使えます。代表的なのは turnutils_uclient（クライアントエミュレーション）です。

🔧 1. util ツールの場所

通常、coturn をパッケージからインストールすると以下に入ります：

/usr/bin/turnutils_uclient

/usr/bin/turnutils_peer

ソースからビルドした場合は bin/ や examples/ に配置されます。

🔧 2. 動作確認の基本的な流れ

サーバー起動確認

```bash
sudo systemctl status coturn
```

active (running) であることを確認。

クライアントツールで疎通テスト

```bash
turnutils_uclient 127.0.0.1 -u testuser -w testpassword -p 3478
```

127.0.0.1 : サーバーのIP

-u : ユーザー名

-w : パスワード

-p : ポート番号（デフォルト3478）

成功すると STUN/TURN のレスポンスが返ってきます。失敗すると認証エラーや接続失敗が表示されます。

🔧 3. リレー接続のテスト（peer と組み合わせ）

turnutils_peer を使うと、実際にリレーされた通信を確認できます。

peer を起動（別の端末や別ターミナル）

```bash
turnutils_peer 127.0.0.1
```

uclient で接続

```bash
turnutils_uclient 127.0.0.1 -u testuser -w testpassword -p 3478 -L 127.0.0.1
```

-L はリレーアドレスを指定

これで peer との間で実際にデータがリレーされるか確認できます。

🔧 4. TLS/TCP のテスト
```bash
turnutils_uclient your.turn.server -u testuser -w testpassword -T -p 5349
```

-T : TLSモードでの接続確認

🔧 5. 確認ポイント

成功時
```makefile
0: Total connect time is 10 ms
0: Total transmitted packets: ...
0: Total received packets: ...
```

のように送受信統計が表示されます。

失敗時

- 401 Unauthorized → ユーザー名/パスワード mismatch
- Connection refused → ファイアウォールやポート未開放
- Allocation failed → TURN のリレー設定ミス

👉 ここまでで、LAN 内の簡易確認（uclient → TURN サーバ → peer）はできます。
本格的にブラウザの WebRTC クライアントから試す場合は STUN/TURN サーバー設定を入れた WebRTC サンプルを使います。