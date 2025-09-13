
●ステップごとの設定例

- 1 NLB の作成

リスナー: TCP 50443
ターゲット: ALB の private IP
ヘルスチェック: TCP, ALB の 443 を指定

- 2 ALB の作成

リスナー: HTTPS 443
ターゲットグループ: EC2 の Apache
SSL証明書: ACM で管理
セキュリティグループ: NLB からのアクセスのみ許可

- 3 Apache 側

VirtualHost 例:
```vhosts.conf
Listen 0080

<VirtualHost *:0080>
    ServerName admin.example.com
    DocumentRoot /var/www/html/phpmyadmin

    <Directory /var/www/html/phpmyadmin>
        Options None
        AllowOverride None
        Require ip 999.0.0.999
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/phpmyadmin_error.log
    CustomLog ${APACHE_LOG_DIR}/phpmyadmin_access.log combined
</VirtualHost>
```

- 4 phpMyAdmin セキュリティ

Basic認証や VPN 経由アクセスも推奨
AllowOverride None + .htaccess禁止で余計な変更を防止
ALB または Apache で IP 制限を併用

- ネットワーク構成図（簡易版）

[管理者PC 999.0.0.999]<br> 
          │<br> 
          v<br> 
    ┌───────────┐<br> 
    │   NLB     │<br> 
    │ TCP:50443 │<br> 
    └───────────┘<br> 
          │ (ターゲット: ALB private IP)<br> 
          v<br> 
    ┌───────────┐<br> 
    │   ALB     │<br> 
    │ HTTPS:443 │<br> 
    └───────────┘<br> 
          │ (ターゲット: EC2 WebServer)<br> 
          v<br> 
    ┌──────────────┐<br> 
    │ Apache       │<br> 
    │ phpMyAdmin   │<br> 
    │ Port:0080    │<br> 
    └──────────────┘<br> 