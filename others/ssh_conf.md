Usage: ssh web-server

```~/.ssh/config
# 作成コマンド: touch ~/.ssh/config && chmod 600 ~/.ssh/config

# Webサーバーへの接続設定
Host web-server
    HostName 192.168.1.10
    User webadmin
    Port 22
    IdentityFile ~/.ssh/web_server_key

# DBサーバーへの接続設定
Host db-server
    HostName db.example.com
    User dbuser
    Port 2222
    IdentityFile ~/.ssh/db_server_key
    # 10秒ごとに生存確認パケットを送信
    ServerAliveInterval 10

# GitHubへの接続設定
Host github.com
    HostName github.com
    User git
    IdentityFile ~/.ssh/id_ed25519_github
```
