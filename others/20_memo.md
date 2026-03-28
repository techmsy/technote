#### Section1
- `vscodeでc#ビルドしたい。sdkなしでやる方法があるか調査して。もしない、または難しいなら、管理者権限を必要としないsdkインストール方法を調査して`
- `「Windowsのインストーラが管理者権限を要求してNG」かつ「既に WSL2 + Docker 運用している」かつ「vscodeからビルドしたい」`
- `ビルドしたいのは **WinForms（net10.0-windows）**`
- `.slnx`

#### Section2
`空のwinformsプロジェクト作成【slnx】(小さめ単体プロジェクト)` 
- 前提
  - .NET SDK 10.0以上

```Bash
mkdir MyTinyWinForms && cd MyTinyWinForms

dotnet new sln -n MyTinyWinForms
dotnet new winforms -n MyTinyWinForms -f net10.0 -o MyTinyWinForms
dotnet sln MyTinyWinForms.slnx add MyTinyWinForms/MyTinyWinForms.csproj

dotnet build MyTinyWinForms.slnx
dotnet run --project MyTinyWinForms/MyTinyWinForms.csproj
```

#### Section3
Rocky
MySQL
Apache
Basic-Auth
php-fpm

basicauth-apache-test
├─ docker-compose.yml
├─ .env
│
├─ apache
│  ├─ Dockerfile
│  ├─ phpmyadmin-proxy.conf
│  └─ .htpasswd
│
└─ mysql
   ├─ my.cnf
   └─ init.sql

`.env`
```env
MYSQL_ROOT_PASSWORD=rootpass
MYSQL_DATABASE=sampledb
MYSQL_USER=user
MYSQL_PASSWORD=password
```

`.gitignore`
```
.env
```

起動
docker compose up -d --build

接続
http://localhost:8080
