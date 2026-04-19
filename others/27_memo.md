`config.json`
```Python
{
    "smtp_server": "email-smtp.us-east-1.amazonaws.com",
    "smtp_port": 587,
    "smtp_user": "YOUR_SES_SMTP_USER",
    "smtp_pass": "YOUR_SES_SMTP_PASSWORD",
    "sender_email": "admin@example.com",
    "recipient_email": "target@example.com",
    "user_list_path": "./userlist.txt",
    "_htpasswd_path": "/etc/httpd/.auth/BasicAuthPassRotator.htpasswd",
    "htpasswd_path": "./BasicAuthPassRotator.htpasswd",
    "normal_mail_free_word": "AuthUpdate",
    "log_filename": "./log/log.txt",
    "log_mode": "a",
    "test_mail_subject": "【テスト送信】BasicAuthPassRotator",
    "test_mail_body": "これはメールテストモードによる送信です。"
}
```

`program.py`
```Python
import json
import secrets
import string
import socket
import datetime
import smtplib
import subprocess
import os
import sys
from email.mime.text import MIMEText

def log_message(config, message):
    """ログディレクトリの自動作成とメッセージ記録"""
    now = datetime.datetime.now().strftime("%Y/%m/%d %H:%M:%S")
    log_path = os.path.join(os.path.dirname(__file__), config.get('log_filename', 'log.txt'))
    
    # ログフォルダが存在しない場合は作成
    log_dir = os.path.dirname(log_path)
    if log_dir and not os.path.exists(log_dir):
        os.makedirs(log_dir, exist_ok=True)

    mode = config.get('log_mode', 'a')
    with open(log_path, mode) as f:
        f.write(f"{now} {message}\n")

def load_config():
    config_path = os.path.join(os.path.dirname(__file__), 'config.json')
    try:
        with open(config_path, 'r') as f:
            return json.load(f)
    except FileNotFoundError:
        print("Error: config.json not found.")
        sys.exit(1)

def generate_password(length=16):
    chars = string.ascii_letters + string.digits + "!#$%&()*+,-./:;<=>?@[]^_"
    return ''.join(secrets.choice(chars) for _ in range(length))

def update_htpasswd(path, user_data):
    if not user_data:
        open(path, 'w').close()
        return

    first_user = True
    for user, password in user_data.items():
        mode = "-bc" if first_user else "-b"
        cmd = ["htpasswd", mode, path, user, password]
        subprocess.run(cmd, check=True, capture_output=True)
        first_user = False

def send_email(config, subject, body):
    msg = MIMEText(body)
    msg['Subject'] = subject
    msg['From'] = config['sender_email']
    msg['To'] = config['recipient_email']

    with smtplib.SMTP(config['smtp_server'], config['smtp_port']) as server:
        server.starttls()
        server.login(config['smtp_user'], config['smtp_pass'])
        server.send_message(msg)

def main():
    config = load_config()
    
    # モード指定引数の処理
    valid_modes = ["--normal", "--debug", "--mail-test", "--htpasswd-test"]
    # 引数省略時は --debug
    mode = sys.argv[1] if len(sys.argv) > 1 else "--debug"
    
    # 定義されていないモードが指定された場合はエラー
    if mode not in valid_modes:
        error_msg = f"Error: Invalid mode '{mode}'. Use one of {valid_modes}"
        print(error_msg)
        # ログ機能が使える状態であればログにも記録
        log_message(config, error_msg)
        sys.exit(1)

    log_message(config, f"--- Starting BasicAuthPassRotator (Mode: {mode}) ---")

    user_list_path = config.get('user_list_path', './userlist.txt')
    if not os.path.exists(user_list_path):
        log_message(config, f"Error: User list file not found at {user_list_path}")
        return

    with open(user_list_path, 'r') as f:
        # 最終行が空でも問題ないようフィルタリング [cite: 2]
        users = [line.strip() for line in f if line.strip()]

    hostname = socket.gethostname()
    today = datetime.datetime.now().strftime("%Y/%m/%d")
    
    # 通常モード時の件名構成: フリーワード + 半角スペース + 日付 + 半角スペース + ホスト名
    free_word = config.get('normal_mail_free_word', 'PassUpdate')
    subject = f"{free_word} {today} {hostname}"

    try:
        if mode == "--normal":
            user_data = {user: generate_password() for user in users}
            update_htpasswd(config['htpasswd_path'], user_data)
            
            body = "新規パスワードリスト:\n\n"
            body += "\n".join([f"User: {u} / Pass: {p}" for u, p in user_data.items()])
            send_email(config, subject, body)
            log_message(config, "Normal operation completed.")

        elif mode == "--debug":
            user_data = {user: generate_password() for user in users}
            # Source 3 のような形式でデバッグ出力を作成 
            body = f"[DEBUG] Subject: {subject}\n\n"
            body += "\n".join([f"User: {u} / Pass: {p}" for u, p in user_data.items()])
            
            debug_file = os.path.join(os.path.dirname(__file__), "debug_output.txt")
            with open(debug_file, "w") as f:
                # 最終行に改行を追加
                f.write(body + "\n")
            log_message(config, f"Debug mode: Output saved to {debug_file}.")

        elif mode == "--mail-test":
            send_email(config, config['test_mail_subject'], config['test_mail_body'])
            log_message(config, "Mail-test completed.")

        elif mode == "--htpasswd-test":
            test_user_data = {users[0]: generate_password()} if users else {}
            update_htpasswd(config['htpasswd_path'], test_user_data)
            log_message(config, f"htpasswd-test completed with {len(test_user_data)} user.")

    except Exception as e:
        log_message(config, f"Critical Error: {str(e)}")
        sys.exit(1)

if __name__ == "__main__":
    main()
```

`HowToUse`
```Bash
3. 使い方
コマンドライン引数に -- を付与する形式に変更されました。

デバッグモード（デフォルト）:

Bash
python3 program.py
# または
python3 program.py --debug
実行後、debug_output.txt が生成されます 。

通常モード（本番稼働）:

Bash
python3 program.py --normal
.htpasswd が更新され、設定したフリーワードを含む件名でメールが送信されます。

メール送信テスト:

Bash
python3 program.py --mail-test
htpasswd更新テスト:

Bash
python3 program.py --htpasswd-test

userlist.txt の先頭1行のみが反映されます 。
```

`Prepare`
```
4. 準備と環境構築
- 必要なパッケージのインストール:
htpasswd コマンドが必要です。

Bash
# Rocky Linux / AL2023 共通
sudo dnf install -y httpd-tools
- AWS SESの設定:

AWSコンソールで送信元アドレス（Sender）を検証済み（Verified）にする。

SESのSMTP認証情報（ユーザー名とパスワード）を発行し、config.json に記載。

- 権限の設定:
パスワード情報を含むため、ファイルの所有権と権限を厳しく設定します。

Bash
chmod 600 /opt/user-manager/config.json
chmod 700 /opt/user-manager/user_management.py
- セキュリティグループ:
EC2からAWS SESへのアウトバウンド通信（ポート587）を許可してください。

- 自動実行（cron設定）
crontab -e で毎日0時に実行する設定を追加します。

コード スニペット
0 0 * * * /usr/bin/python3 /opt/user-manager/user_management.py >> /var/log/user_update.log 2>&1
```

`Prepare2`
```
4. 最終的な配置・設定手順イメージ
プロジェクトを /opt に配置した場合のセットアップコマンドです。

Bash
# ディレクトリ作成と移動
sudo mkdir -p /opt/AuthPassRotator
cd /opt/AuthPassRotator

# ファイルを配置（エディタ等で作成）
# user_management.py, config.json, users.txt

# 権限の最小化（root実行の場合）
sudo chown root:root -R /opt/AuthPassRotator
sudo chmod 700 user_management.py
sudo chmod 600 config.json  # 認証情報が含まれるため最重要
```

`Prepare3`
```
具体的な設定手順
専用ディレクトリの作成と権限付与

Bash
sudo mkdir /etc/httpd/.auth
sudo chown manager:apache /etc/httpd/.auth
sudo chmod 770 /etc/httpd/.auth
※所有者を manager に、グループを apache にすることで、managerが更新でき、Apacheが読み取れる状態にします。

AuthPassRotatorの設定変更 (config.json)
htpasswd_path を以下に変更します。

JSON
"htpasswd_path": "/etc/httpd/.auth/AuthPassRotator.htpasswd"
Apache設定ファイル (/etc/httpd/conf.d/auth.conf) の記述

Apache
<Directory "/var/www/html/protected">
    AuthType Basic
    AuthName "Restricted Area"
    AuthUserFile /etc/httpd/.auth/AuthPassRotator.htpasswd
    Require valid-user
</Directory>
```

`Prepare4`
```
5. 準備・環境構築
htpasswdコマンドの導入

Bash
# Rocky9 / AL2023
sudo dnf install -y httpd-tools
ディレクトリとユーザー作成（推奨）

Bash
sudo mkdir -p /opt/AuthPassRotator
sudo mkdir -p /etc/httpd/.auth
# root以外で動かす場合は chown で権限調整
SESのSMTP認証情報
AWSコンソールから「SMTP credentials」を発行し、config.json に反映してください。
```

`Ubuntu`
```
1. パッケージ管理システムから確認する（推奨）
パッケージとしてシステムに登録されているかを確認します。

Bash
dpkg -l | grep apache2-utils
インストールされている場合: 行の先頭が ii で始まり、パッケージ名が表示されます。

インストールされていない場合: 何も表示されないか、先頭が un（unknown）などになります。

2. コマンド自体が存在するか確認する
今回の目的である htpasswd コマンドが実行可能なパスにあるかを確認します。

Bash
which htpasswd
インストールされている場合: /usr/bin/htpasswd のようにインストール先のパスが表示されます。

インストールされていない場合: 何も表示されません。

もしインストールされていなかったら
以下のコマンドでインストールできます。

Bash
sudo apt update
sudo apt install apache2-utils
```