### ✅Rocky Linux 8を Dockerコンテナで構築

---

#### 🔧 前提条件
 - Docker がインストールされていること
  （docker --version で確認）

 - インターネット接続があること

#### ✅ 手順概要
 1. Rocky Linux 8 の Docker イメージを取得
 2. コンテナを起動
 3. 永続化したい場合は volume や Dockerfile を使う

---

##### 🧾 1. 公式イメージの確認（Docker Hub）
Rocky Linux の公式 Docker イメージ:
👉 https://hub.docker.com/_/rockylinux

---

##### 🚀 2. Rocky Linux 8 の Docker イメージを取得
```bash
docker pull rockylinux:8
```

---

##### 🧪 3. コンテナを起動（対話モード）
```bash
docker run -it rockylinux:8 /bin/bash
```
これで、Rocky Linux 8 のシェルが起動します。

---

##### 💾 4. 永続化したい場合（例：ローカルディレクトリをマウント）
```bash
docker run -it -v $PWD/data:/data rockylinux:8 /bin/bash
```
または Dockerfile を使ってカスタムイメージを作成してもよいです。

---

##### 🛠 5. コンテナ内での初期セットアップ（任意）
```bash
dnf update -y
dnf install -y vim curl net-tools
```

---

📌 コンテナをバックグラウンドで起動（--name 指定）
```bash
docker run -dit --name rocky8_test rockylinux:8 /bin/bash
```
再接続：

```bash
docker exec -it rocky8_test /bin/bash
```

---

##### 🔁 補足：コンテナを停止・起動
```bash
docker stop rocky8_test
docker start rocky8_test
```

---

### ✅ Docker Hub 以外で Rocky Linux 公式の Rocky 8 を Docker で利用する手順。
##### Rocky Linux は Docker Hub に加えて、Rocky Linux 公式の Git リポジトリ（GitLab）や<br>Quay.io などにも Docker 関連の成果物を提供しています。

---

#### ✅ 目的
Docker Hub を使わずに、Rocky Linux 公式の提供する Rocky 8 イメージを使って Docker コンテナを構築したい。

#### ✅ 方式
以下のいずれかで入手します：

##### ① Quay.io（Rocky Linux 公式ミラー）
 - Quay は Docker Hub の代替リポジトリで、Rocky Linux 公式イメージがホストされています。

URL: https://quay.io/repository/rockylinux/rockylinux

##### ② Rocky Linux 公式 GitLab リポジトリ
 - https://git.rockylinux.org/rocky/kickstarts
 - https://git.rockylinux.org/rocky/rocky-container

ただし GitLab は「Dockerfile」や「ビルド定義」の提供であり、イメージそのものを pull する場所ではありません。

#### 🔁 方法①：Quay.io から取得して起動する
```bash
docker pull quay.io/rockylinux/rockylinux:8
```
起動：

---

```bash
docker run -it quay.io/rockylinux/rockylinux:8 /bin/bash
```

#### 🔁 方法②：Rocky 公式の Dockerfile から自分でビルドする
1. ソースを clone

```bash
git clone https://git.rockylinux.org/rocky/rocky-container.git
cd rocky-container
```
2. Rocky 8 用の Dockerfile があるディレクトリに移動

```bash
cd 8/
```
3. Docker イメージをビルド

```bash
docker build -t myrocky8 .
```
4. コンテナとして起動

```bash
docker run -it myrocky8 /bin/bash
```
⚠️ 初回ビルドは多少時間がかかる場合があります。

#### 🎯 補足：どちらが「公式」か？
| リポジトリ |	信頼性 |	 役割 | 
|-------|-------|-------| 
| Docker Hub | ◎ |	一般的な pull 用リポジトリ（推奨） | 
| Quay.io | ◎ |	Docker Hub が使えない場合の代替 | 
| GitLab | ◎ |	ビルドレシピ（Dockerfile）を提供 | 

Rocky Linux チームが管理しているため、Quay.io および GitLab も完全に「公式」です。

#### ✅ まとめ
| 方法 |	コマンド例 | 
|-------|-------| 
| Quay.io から pull |	docker pull quay.io/rockylinux/rockylinux:8 | 
| GitLab の Dockerfile をビルド |	git clone → docker build | 

---
