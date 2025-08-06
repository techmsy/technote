bash
```bash
サイバーセキュリティ対策の理解を深める目的でメールによる攻撃シナリオをいくつか提案して。
ただし、検証環境は同一LAN内にLinuxマシンが2台、それぞれにBIND、postfix、dovecotをインストールしてあるとします
```

bash
```bash
amazon linux 2023です。postfixを稼働中。
このホストへss -lすると0.0.0.0/0で25番ポートリッスン中。
しかし、telnet ホスト名 25するとESMTPの表示がでない。
しばらくするとclosedされる
```