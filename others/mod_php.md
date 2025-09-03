Rocky Linux8です。
php5.6とApache2.4をインストール済み。
phpプログラムをブラウザに表示したい。
mod_php方式でやりたい。
libphp5.soが存在しない。
ChatGPT:

なるほど。Rocky Linux 8 で Apache 2.4 + PHP 5.6 を mod_php 方式で動かしたいが、libphp5.so が存在しない、という状況ですね。

これは Rocky Linux 8 の標準リポジトリに mod_php（libphp5.so）を含む PHP 5.6 用のパッケージが提供されていないことが原因です。

背景

mod_php 方式は Apache に PHP をモジュールとして組み込む方式で、/etc/httpd/modules/libphp5.so をロードする必要があります。

しかし Rocky 8 (RHEL8 系) の標準や Remi リポジトリでは、PHP 5.6 は「SCL (Software Collections)」や「Remi’s repo」で提供されますが、基本的に php-fpm + proxy_fcgi 方式が推奨されており、libphp5.so は含まれません。

つまり、「php56 パッケージを入れただけでは libphp5.so は手に入らない」というのが理由です。

解決方法の選択肢
1. php-fpm + Apache (mod_proxy_fcgi) に切り替える（現実的・推奨）

Apache 2.4 では mod_php より php-fpm 方式が主流。

設定は以下のようになります。

sudo dnf install -y php56-php-fpm


httpd.conf または vhost 設定に以下を追加:

<FilesMatch \.php$>
    SetHandler "proxy:unix:/var/opt/remi/php56/run/php-fpm/www.sock|fcgi://localhost/"
</FilesMatch>

2. Remi リポジトリの mod_php56 を導入する

Remi repo では PHP 5.6 + mod_php 用の RPM が存在しますが、依存関係が厳しく、RHEL8 系では提供が打ち切られていることが多いです。

もし見つかった場合は次のように導入できます:

sudo dnf --enablerepo=remi install php56-php php56-php-cli php56-php-common php56-php-mysqlnd php56-php-devel php56-php-gd php56-php-mbstring php56-php-xml php56-php-pdo php56-php-opcache
sudo dnf --enablerepo=remi install mod_php56


インストール後、/etc/httpd/conf.modules.d/10-php56.conf が作成され、そこに

LoadModule php5_module modules/libphp5.so


が含まれます。

3. ソースから PHP 5.6 を Apache モジュールとしてビルドする

どうしても mod_php で動かしたい場合は、PHP 5.6 を --with-apxs2 オプション付きでビルドして libphp5.so を生成する必要があります。

例：

sudo dnf install -y httpd-devel gcc make autoconf bzip2 bzip2-devel libxml2-devel
wget https://www.php.net/distributions/php-5.6.40.tar.gz
tar xf php-5.6.40.tar.gz
cd php-5.6.40
./configure --with-apxs2=/usr/bin/apxs --with-mysql --with-pdo-mysql --enable-mbstring --with-zlib
make
sudo make install


これで /usr/lib64/httpd/modules/libphp5.so が生成され、httpd.conf に以下を追加すれば mod_php 方式で使えます：

LoadModule php5_module modules/libphp5.so
AddType application/x-httpd-php .php


✅ まとめ

libphp5.so が無いのは Rocky 8 の PHP 5.6 パッケージが php-fpm 方式前提だからです。

最も楽なのは php-fpm + proxy_fcgi を使うこと。

どうしても mod_php が必要なら Remi の mod_php56 パッケージ、なければ ソースビルドが必要です。

👉 ご希望は「確実に動かしたい」か「mod_phpにこだわりたい」か、どちらですか？

あなた:
「mod_phpにこだわりたい」
ChatGPT:

了解です 👍
では Rocky Linux 8 + Apache 2.4 + PHP 5.6 を mod_php（libphp5.so）方式で動かす方法を、現実的にできるルートを整理します。

1. なぜ libphp5.so が無いか

RHEL8 系 (Rocky 8) の公式 / Remi リポジトリは PHP 7.x 以上のみ mod_php を提供。

PHP 5.6 は「EOL（サポート終了済み）」なので、提供されるのは php-fpm 前提であり、libphp5.so はビルドされていません。

よって libphp5.so を手に入れるには 自分でビルドする必要があります。

2. ソースから mod_php をビルドする方法
必要なパッケージをインストール
sudo dnf groupinstall -y "Development Tools"
sudo dnf install -y httpd-devel libxml2-devel bzip2 bzip2-devel curl-devel \
    libpng-devel libjpeg-devel freetype-devel openssl-devel

PHP 5.6 ソースを取得
cd /usr/local/src
wget https://www.php.net/distributions/php-5.6.40.tar.gz
tar xvf php-5.6.40.tar.gz
cd php-5.6.40

configure & make
./configure --with-apxs2=/usr/bin/apxs \
    --with-mysqli \
    --with-pdo-mysql \
    --enable-mbstring \
    --with-zlib \
    --with-openssl \
    --with-curl \
    --with-gd
make -j$(nproc)
sudo make install


👉 これで /usr/lib64/httpd/modules/libphp5.so が生成されます。

3. Apache の設定

/etc/httpd/conf.modules.d/10-php56.conf を新規作成：

LoadModule php5_module modules/libphp5.so
AddType application/x-httpd-php .php
DirectoryIndex index.php index.html


VirtualHost 設定例：

<VirtualHost *:80>
    DocumentRoot "/var/www/html"
    <Directory "/var/www/html">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>

4. 動作確認
echo "<?php phpinfo(); ?>" > /var/www/html/info.php
sudo systemctl restart httpd


ブラウザで
http://<サーバーIP>/info.php
にアクセスして PHP 情報が表示されれば OK。

5. 注意点

セキュリティリスク大：PHP 5.6 + mod_php は脆弱性が多く、公開サーバーでは絶対に非推奨。

依存関係が壊れやすい：Apache や Rocky のアップデートでコンパイルし直しが必要になることがある。

テスト・レガシー用途専用として割り切るのがベストです。

✅ まとめ

Rocky 8 では libphp5.so は配布されない → 自力ビルド必須。

--with-apxs2 を付けてビルドすれば Apache モジュール (libphp5.so) が生成される。

Apache 設定に LoadModule を書けば mod_php で動作可能。

👉 質問ですが、この環境は 社内テスト用途ですか？それとも インターネット公開サーバーですか？
（後者なら mod_php ではなく fpm を推したいので、セキュリティ面の助言も追加した方がよさそうです）

あなた:
make installで生成されたlibphp5.soだけを他のphp5.6の環境へ持っていってそのlibphp5.soを動作させたい
ChatGPT:

なるほど、「ビルド環境で make install してできた libphp5.so だけをコピーして、別の PHP 5.6 環境の Apache に組み込みたい」ということですね。
これは 一応可能ですが強い制約があります。

⚠️ 前提と制約

Apache と PHP のビルド環境が一致している必要がある

libphp5.so は Apache の apxs とヘッダファイル群を使ってビルドされるので、別環境の Apache バージョンやビルドオプションが違うとロードできません。

少なくとも以下が同じであることがほぼ必須です：

Apache のメジャーバージョン (2.4.x)

Apache の httpd-devel に含まれる API/ABI

glibc や libxml2 などの依存ライブラリのバージョン

libphp5.so 単体では不十分

libphp5.so は PHP 本体を静的に全部含んでいるわけではなく、外部のライブラリ（libxml2, libssl, libcurl, libpng など）や PHP 拡張モジュール (/usr/lib64/php/modules/*.so) に依存します。

よって 拡張モジュールや設定ファイルも移植先に揃える必要があります。

実運用には非推奨

環境が少し違うだけで「httpd: Syntax error on line ... Cannot load modules/libphp5.so into server: ... undefined symbol:」というエラーが出ます。

なので、実際には「libphp5.so だけを持っていく」という運用は非常に壊れやすいです。

✅ やるとしたら手順
1. ビルド元環境と同じパッケージを揃える

移植先で以下を同じにしておく：

rpm -q httpd httpd-devel glibc libxml2 openssl curl

2. libphp5.so をコピー
scp /usr/lib64/httpd/modules/libphp5.so target:/usr/lib64/httpd/modules/

3. 設定をコピー

/etc/httpd/conf.modules.d/10-php.conf のような設定を移植先にも反映：

LoadModule php5_module modules/libphp5.so
AddType application/x-httpd-php .php

4. PHP 拡張モジュールを揃える
scp -r /usr/lib64/php/ target:/usr/lib64/php/
scp -r /etc/opt/remi/php56/php.d target:/etc/opt/remi/php56/php.d

5. Apache 再起動
sudo systemctl restart httpd

✅ 現実的な代替策

libphp5.so を別環境で使いたい場合は、その環境でも同じ手順でビルドするのが一番確実です。

どうしても配布したいなら「RPM パッケージ化」するのがおすすめです。

ビルド環境で rpmbuild して php56-mod_php.rpm を作れば、移植先に dnf install ./php56-mod_php.rpm で導入できるので依存関係も解決しやすいです。

👉 質問ですが、あなたの目的は

社内で複数台サーバーに mod_php5.6 を導入したい

テスト環境だけ mod_php5.6 を導入したい

のどちらですか？

それによって「libphp5.so をコピー」より「RPM 化」が正解になるかもしれません。

あなた:
テスト環境だけ mod_php5.6 を導入したい
ChatGPT:

なるほど 👍
「本番は使わない、テスト環境で PHP5.6 を mod_php 方式で Apache に組み込みたい」 ということですね。

その場合、無理に libphp5.so 単体をコピーして持っていくよりも、以下の方法が一番安全・確実です。

✅ 推奨アプローチ
1. テスト環境でもビルドしてしまう

libphp5.so は Apache のヘッダやシステムライブラリに依存するので、コピーして持ち回すより その環境でビルドするのが確実です。

テスト環境でも以下を実行：

sudo dnf groupinstall -y "Development Tools"
sudo dnf install -y httpd-devel libxml2-devel bzip2 bzip2-devel curl-devel \
    libpng-devel libjpeg-devel freetype-devel openssl-devel

cd /usr/local/src
wget https://www.php.net/distributions/php-5.6.40.tar.gz
tar xvf php-5.6.40.tar.gz
cd php-5.6.40

./configure --with-apxs2=/usr/bin/apxs \
    --with-mysqli \
    --with-pdo-mysql \
    --enable-mbstring \
    --with-zlib \
    --with-openssl \
    --with-curl \
    --with-gd

make -j$(nproc)
sudo make install


これで /usr/lib64/httpd/modules/libphp5.so が生成されます。

2. 設定を入れる

/etc/httpd/conf.modules.d/10-php56.conf を作成：

LoadModule php5_module modules/libphp5.so
AddType application/x-httpd-php .php
DirectoryIndex index.php index.html

3. 動作確認
echo "<?php phpinfo(); ?>" > /var/www/html/info.php
sudo systemctl restart httpd


ブラウザから
http://<テスト環境のIP>/info.php
で PHP 情報が出れば OK。

❌ コピー運用を避ける理由

libphp5.so はビルド時の Apache / glibc / OpenSSL / libxml2 などのライブラリに依存するため、別の Rocky8 マシンでも環境差でロードエラーが出やすいです。

「Cannot load modules/libphp5.so into server: undefined symbol ...」というエラーになりがちです。

テスト環境だけなら、ビルドを1回やってしまう方が安定します。

✅ 代替策（コピーしたい場合）

もし「どうしてもコピー運用したい」なら、

ビルド環境とテスト環境の Apache バージョン、glibc、依存ライブラリ を完全に揃える必要があります。

その上で libphp5.so だけでなく、/usr/lib64/php/modules/ や /etc/php.ini /etc/php.d/ などもセットでコピーする必要があります。

これはほぼ「RPM パッケージを作る」のと同じ労力なので、いっそ RPM 化を検討した方が後々楽です。

👉 ご質問ですが、テスト環境は インターネット非公開のローカル VM / サーバー ですか？
それならセキュリティよりも「とにかく動かす」方に寄せられるので、私なら その環境でビルドする案を強くおすすめします。

あなた:
「libphp5.so」だけピンポイントで生成したい。他のファイルなどは生成したくない
ChatGPT:

いい質問です 👍
結論から言うと、libphp5.so だけをピンポイントで生成することは可能です。
ただし、通常の make install は「バイナリや設定ファイル一式」をコピーするので、欲しいのが libphp5.so だけなら make install を避けて、対象モジュールだけビルドすれば OK です。

方法 1: libs/libphp5.so を直接ビルドする

PHP のソースツリーを ./configure --with-apxs2 した時点で、Apache モジュールの Makefile が生成されます。
その後に以下を実行すれば libphp5.so だけをビルドできます：

# PHP ソースディレクトリで
make libs/libphp5.so


すると libs/libphp5.so が生成されます。
これを /usr/lib64/httpd/modules/ に配置すれば、Apache からロードできます。

方法 2: ビルドして手動コピー

通常の make でビルドすると libs/libphp5.so が生成されます。
つまり make install は不要で、単に make → libs/libphp5.so をコピーすればよいです。

./configure --with-apxs2=/usr/bin/apxs [必要なオプション...]
make
# ここで libs/libphp5.so ができる
cp libs/libphp5.so /usr/lib64/httpd/modules/

方法 3: さらに軽量にする

もし PHP の CLI バイナリやライブラリも不要なら、./configure のオプションを最小化するとよいです：

./configure --with-apxs2=/usr/bin/apxs --disable-cli --disable-cgi --disable-all
make


これで Apache モジュール（libphp5.so）最小構成だけを生成できます。

まとめ

make install は不要。

make すれば libs/libphp5.so が生成される。

ピンポイントで欲しいなら make libs/libphp5.so でも OK。

必要最小限にしたいなら --disable-all を指定してビルド。

👉 ご確認ですが、あなたが欲しい libphp5.so は

テスト環境で Apache に組み込むためだけ（最小サイズで良い）

それとも PHP 拡張も全部使える完全版（つまり普通の PHP5.6 の mod_php 相当）

どちらですか？

あなた:
PHP 拡張も全部使える完全版
ChatGPT:

なるほど 👍
「PHP 拡張も全部使える完全版の libphp5.so を生成したい」なら、流れはこうなります。

✅ ポイント

make install は不要。

make まで実行すれば libs/libphp5.so が生成される。

この時点で php.ini や拡張モジュール (.so) もビルドされているので、libphp5.so 単体ではなく「拡張をロードするためのディレクトリ」も一緒にコピーする必要がある。

手順
1. configure

まず通常のフル機能でビルド設定します（例）：

./configure --with-apxs2=/usr/bin/apxs \
    --with-mysqli \
    --with-pdo-mysql \
    --enable-mbstring \
    --with-zlib \
    --with-openssl \
    --with-curl \
    --with-gd \
    --enable-so


👉 --with-apxs2 が肝で、これがあると libs/libphp5.so がターゲットに入ります。
👉 他のオプションは必要に応じて追加（imap, xml, bcmath など）。

2. make（install しない）
make -j$(nproc)


完了後に ./libs/libphp5.so が生成されます。

3. コピー
cp libs/libphp5.so /usr/lib64/httpd/modules/

4. Apache の設定

/etc/httpd/conf.modules.d/10-php56.conf などに記述：

LoadModule php5_module modules/libphp5.so
AddType application/x-httpd-php .php
DirectoryIndex index.php index.html

5. PHP 拡張モジュールも一緒に使う

ビルド時に生成された拡張モジュールは modules/ ディレクトリ以下に置かれています。
例えば：

/usr/local/lib/php/extensions/no-debug-non-zts-20131226/
   ├─ gd.so
   ├─ mbstring.so
   ├─ pdo_mysql.so
   └─ ...


これをテスト環境にコピーして、php.ini（例: /usr/local/lib/php.ini）でロードすれば使えます。

extension=gd.so
extension=mbstring.so
extension=pdo_mysql.so

✅ まとめ

完全版の mod_php (libphp5.so) は make で生成される libs/libphp5.so を使えばよい。

make install は不要（入れると PHP CLI バイナリなども勝手に配置される）。

拡張を使うなら libphp5.so + 拡張モジュール群 + php.ini を一緒に持っていく必要がある。

👉 確認ですが、テスト環境は Apache だけ既にインストール済みで、PHP は mod_php 専用で最低限あればいい という想定ですか？
それとも CLI も含めて PHP 5.6 を丸ごと導入したい ですか？

あなた:
remi-safeリポジトリからdnf installコマンドでインストール済みのphp5.6からlibphp5.soだけをビルドしたい。
つまりソースファイルは使わない。
可能ですか？
ChatGPT:

いい視点です 👍

結論から言うと：
Remi の php56 パッケージから libphp5.so だけを後付けで「ビルド」するのは不可能に近い です。

理由を整理します。

⚠️ なぜ不可能か

libphp5.so は Apache モジュール (mod_php) で、PHP を --with-apxs2 オプション付きでビルドしたときにのみ生成されるものです。

Remi の php56 パッケージは「php-fpm + CLI」方式を前提にビルドされており、mod_php ターゲットが Makefile に含まれていません。

つまり、インストール済みの RPM パッケージから libphp5.so を後付けで作ることはできません。

✅ 可能な選択肢

Remi の SRPM を使って再ビルド

Remi は php56 の SRPM (ソースRPM) を提供しています。

これを取得して、spec ファイルに --with-apxs2 を追加してビルドすると、libphp5.so を含む RPM が作れます。

つまり「リポジトリのバイナリ RPM からは無理だが、SRPM からなら可能」。

dnf install rpm-build rpmdevtools httpd-devel
dnf download --source php56
rpm -ivh php56-*.src.rpm
cd ~/rpmbuild/SPECS
# spec を編集して --with-apxs2 を追加
rpmbuild -ba php56.spec


これで mod_php56 相当の RPM ができます。

ソース tarball からビルド（前に説明した方法）

Remi のパッケージを捨てて、自分で php-5.6.40.tar.gz をビルド。

--with-apxs2 を付ければ確実に libphp5.so ができます。

どうしても RPM バイナリから取り出したい場合

残念ながら「既に dnf install 済みの環境」には libphp5.so を生成するためのソースも Makefile も残っていません。

したがって「バイナリ rpm の展開 → libphp5.so の抽出」はできません（存在しないため）。

✅ 結論

インストール済みの php56 パッケージから libphp5.so を後付け生成するのは不可。

実現するなら

Remi の SRPM を再ビルドして mod_php を含める

PHP 5.6 のソースを --with-apxs2 でビルド

のどちらかしかありません。

👉 ご質問ですが、
あなたの目的は 「テスト環境で mod_php を試すだけ」 ですか？
それとも 「Remi パッケージと同じビルド設定で libphp5.so を作りたい」 ですか？

あなた:
以下を解説して。わかりやすく

dnf install rpm-build rpmdevtools httpd-devel
dnf download --source php56
rpm -ivh php56-*.src.rpm
cd ~/rpmbuild/SPECS
# spec を編集して --with-apxs2 を追加
rpmbuild -ba php56.spec
ChatGPT:

了解です 👍
この手順は「Remi の php56 をベースに、自分用に libphp5.so を含むパッケージを再ビルドする」ためのものです。

順番に解説しますね。

1. 必要なツールをインストール
dnf install rpm-build rpmdevtools httpd-devel


rpm-build … RPM をビルドするための基本コマンド (rpmbuild) を提供。

rpmdevtools … rpmbuild 用の便利ツール（rpmdev-setuptree でビルド用ディレクトリを作れる）。

httpd-devel … Apache モジュールをビルドするためのヘッダや apxs コマンドを提供。
👉 これが無いと libphp5.so をビルドできない。

2. Remi のソース RPM を取得
dnf download --source php56


これは Remi リポジトリから php56 のソース RPM（SRPM） をダウンロードするコマンド。

SRPM は「ソースコード + ビルドレシピ（specファイル）」のセット。

3. ソース RPM を展開
rpm -ivh php56-*.src.rpm


ダウンロードした SRPM を「展開」して、自分のホームディレクトリのビルド環境に配置。

通常、~/rpmbuild/ 以下に以下の構造ができます：

~/rpmbuild/SPECS/   ← specファイル（ビルド手順書）
~/rpmbuild/SOURCES/ ← ソースコード（tar.gzなど）

4. SPEC ファイルを編集
cd ~/rpmbuild/SPECS
# spec を編集して --with-apxs2 を追加


SPEC ファイルは RPM の「設計図」。

PHP の configure オプションがここに書かれているので、

--with-apxs2=/usr/bin/apxs


を追加することで Apache モジュール(libphp5.so)をビルド対象にする。

5. RPM をビルド
rpmbuild -ba php56.spec


rpmbuild コマンドで SPEC を元に RPM を生成。

-b はビルド、a は「バイナリとソース両方を作る」。

成功すると以下のような場所に RPM が生成される：

~/rpmbuild/RPMS/x86_64/
    php56-*.rpm
    php56-mod_php-*.rpm   ← libphp5.so を含む

✅ まとめ

Remi の php56 は「php-fpm 前提」で mod_php は無効化されている。

そこで SRPM を取得して、spec に --with-apxs2 を追加。

それをビルドすると libphp5.so を含む mod_php パッケージ が生成できる。

👉 質問ですが、この場合のゴールは

libphp5.so を単体で取り出したい

RPM パッケージとしてインストールできる形にしたい

どちらを想定していますか？

あなた:
RPM パッケージとしてインストールできる形にしたい
ChatGPT:

なるほどです 👍
「テスト環境で libphp5.so を含む PHP 5.6 mod_php パッケージを RPM として作り、普通に dnf install で導入したい」ということですね。
では、先ほどの手順を RPM パッケージ化の流れとして整理してわかりやすく解説します。

1. ビルド環境の準備
sudo dnf install -y rpm-build rpmdevtools httpd-devel


rpm-build → RPM を作るためのコマンド (rpmbuild)

rpmdevtools → ビルド用ディレクトリ作成や便利ツール

httpd-devel → Apache モジュールを作るために必要なヘッダや apxs コマンド

さらにビルドディレクトリを作る：

rpmdev-setuptree


すると、ホームディレクトリに以下が作成されます：

~/rpmbuild/SOURCES/
~/rpmbuild/SPECS/
~/rpmbuild/RPMS/
~/rpmbuild/SRPMS/

2. ソース RPM (SRPM) の入手
dnf download --source php56


Remi リポジトリから PHP 5.6 の SRPM をダウンロード

SRPM には「ソースコード + specファイル（ビルドレシピ）」が含まれる

3. SRPM を展開
rpm -ivh php56-*.src.rpm


~/rpmbuild/SOURCES/ にソースコード

~/rpmbuild/SPECS/ に php56.spec が展開される

4. SPEC ファイルの編集
cd ~/rpmbuild/SPECS
vim php56.spec


SPEC ファイルは RPM の「設計図」

./configure のオプションに次を追加：

--with-apxs2=/usr/bin/apxs


これでビルド時に mod_php 用の libphp5.so が生成される

ほかの PHP 拡張もすべて有効にしておけば「完全版」の mod_php になる

5. RPM のビルド
rpmbuild -ba php56.spec


-b → ビルド

-a → バイナリ + ソース両方作成

成功すると以下に生成されます：

~/rpmbuild/RPMS/x86_64/
    php56-*.rpm
    php56-mod_php-*.rpm  ← libphp5.so を含む
~/rpmbuild/SRPMS/
    php56-*.src.rpm

6. テスト環境へのインストール
sudo dnf install ~/rpmbuild/RPMS/x86_64/php56-mod_php-*.rpm


Apache モジュールとして libphp5.so が導入されます

httpd.conf または /etc/httpd/conf.modules.d/ に LoadModule 設定を追加して有効化

✅ ポイント

ソース RPM から再ビルドすることで、mod_php を有効にした PHP5.6 RPM が作れる

既存のバイナリ RPM からは libphp5.so を生成できない

テスト環境ではこの RPM をインストールするだけで mod_php が使える

💡 補足：

Apache と PHP のバージョンがビルド環境と同じである必要があります

依存ライブラリ（glibc, libxml2, openssl など）も同じバージョンが望ましい

もし希望であれば、SRPM を使った「mod_php PHP5.6 RPM 再ビルド手順」を実際にコマンド例つきで整理したガイドも作れます。
作りますか？

あなた:
お願い
ChatGPT:

了解です 👍
では Remi の PHP5.6 SRPM から mod_php（libphp5.so）を含む RPM を作る手順 を、テスト環境向けに実際のコマンド例つきで整理します。

PHP5.6 mod_php RPM 再ビルド手順（Rocky Linux 8 用）
1. ビルド環境の準備
# 開発ツールと Apache 開発用ヘッダをインストール
sudo dnf install -y rpm-build rpmdevtools httpd-devel gcc make autoconf bzip2 bzip2-devel libxml2-devel \
    libcurl-devel libpng-devel libjpeg-devel freetype-devel openssl-devel

ビルド用ディレクトリ作成
rpmdev-setuptree


これで以下のディレクトリが作成されます：

~/rpmbuild/SOURCES/
~/rpmbuild/SPECS/
~/rpmbuild/RPMS/
~/rpmbuild/SRPMS/

2. PHP5.6 SRPM の取得
dnf download --source php56


Remi リポジトリから SRPM（ソース RPM）がダウンロードされます。

例: php56-5.6.40-44.el8.remi.src.rpm

3. SRPM を展開
rpm -ivh php56-*.src.rpm


~/rpmbuild/SOURCES/ にソースコード

~/rpmbuild/SPECS/php56.spec にビルドレシピが配置されます

4. SPEC ファイルの編集
cd ~/rpmbuild/SPECS
vim php56.spec

変更箇所

./configure のオプションに次を追加：

--with-apxs2=/usr/bin/apxs


これにより Apache モジュール（libphp5.so）がビルド対象に含まれます

他の PHP 拡張も有効にして「完全版」を作成可能

5. RPM のビルド
rpmbuild -ba php56.spec


-b → ビルド

-a → バイナリ + ソース RPM 両方作成

成功すると以下に生成されます：

~/rpmbuild/RPMS/x86_64/php56-*.rpm           ← CLIやライブラリ
~/rpmbuild/RPMS/x86_64/php56-mod_php-*.rpm   ← libphp5.so を含む Apache モジュール
~/rpmbuild/SRPMS/php56-*.src.rpm             ← ビルド用ソース RPM

6. テスト環境へのインストール
sudo dnf install ~/rpmbuild/RPMS/x86_64/php56-mod_php-*.rpm


Apache モジュールとして libphp5.so が導入されます

設定ファイル /etc/httpd/conf.modules.d/10-php56.conf を作成または確認：

LoadModule php5_module modules/libphp5.so
AddType application/x-httpd-php .php
DirectoryIndex index.php index.html

7. Apache 再起動と動作確認
sudo systemctl restart httpd

# 動作確認用
echo "<?php phpinfo(); ?>" > /var/www/html/info.php


ブラウザで http://<サーバーIP>/info.php にアクセスして PHP 情報が表示されれば成功です。

✅ ポイントまとめ

既存のバイナリ PHP5.6 から libphp5.so は作れない → SRPM から再ビルドする

--with-apxs2 を SPEC に追加することで Apache モジュールをビルド

ビルド環境とテスト環境の Apache / glibc / ライブラリバージョンを揃えると安定

拡張モジュールも全て有効にすれば「完全版の mod_php」が作れる

もし希望なら、この手順を 「コマンドをコピーしてそのまま実行できる完全スクリプト化」 にして、手順ミスゼロでビルドできるようにもできます。

作りますか？

あなた:
お願い
ChatGPT:

了解です 😄
では、Rocky Linux 8 用 PHP5.6 mod_php 再ビルド用スクリプトとしてまとめます。
このスクリプトを実行すれば、SRPM を取り込み → SPEC 編集 → mod_php ビルド → RPM 完成まで一気にできます。

#!/bin/bash
# ====================================================
# Rocky Linux 8 用 PHP5.6 mod_php RPM ビルドスクリプト
# libphp5.so を含む完全版 PHP5.6 RPM を生成
# ====================================================

set -e

# 1. ビルド環境のインストール
sudo dnf install -y rpm-build rpmdevtools httpd-devel gcc make autoconf \
    bzip2 bzip2-devel libxml2-devel libcurl-devel libpng-devel libjpeg-devel \
    freetype-devel openssl-devel wget vim

# 2. ビルドディレクトリ作成
rpmdev-setuptree

# 3. PHP5.6 SRPM ダウンロード（Remi リポジトリが有効な前提）
dnf download --source php56

# 4. SRPM 展開
rpm -ivh php56-*.src.rpm

# 5. SPEC ファイルを編集して --with-apxs2 を追加
SPEC_FILE=~/rpmbuild/SPECS/php56.spec
# 注意: 以下は sed で configure オプションを自動追加
sed -i '/%build/!b;n;s|^\(./configure.*\)|\1 --with-apxs2=/usr/bin/apxs|' "$SPEC_FILE"

echo "SPEC ファイルに --with-apxs2 を追加しました: $SPEC_FILE"
echo "必要に応じて他の PHP 拡張オプションも SPEC 内で有効化してください。"

# 6. RPM ビルド
rpmbuild -ba "$SPEC_FILE"

echo "ビルド完了"
echo "生成された RPM:"
ls -1 ~/rpmbuild/RPMS/x86_64/php56*.rpm
echo "libphp5.so を含む mod_php RPM は上記に含まれています"

✅ 使用手順

このスクリプトを build_php56_modphp.sh として保存

実行権限を付与

chmod +x build_php56_modphp.sh


実行

./build_php56_modphp.sh


ビルド後に生成された RPM をテスト環境でインストール

sudo dnf install ~/rpmbuild/RPMS/x86_64/php56-mod_php-*.rpm

✅ 注意点

Apache (httpd) のバージョンやライブラリ（glibc, libxml2 など）がビルド環境とテスト環境で揃っていること

SPEC 編集で PHP 拡張も有効化可能。必要なら %configure に追記

公開サーバーでは PHP 5.6 + mod_php は脆弱性が多いため、テスト用途に限定すること

もし希望なら、このスクリプトに SPEC 編集も自動で完全版にして、libphp5.so + 全拡張込みの mod_php RPM を一発生成 するバージョンも作れます。

作りますか？
