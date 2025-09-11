```/etc/httpd/conf.d/vhosts.conf
# HTTP (80)
<VirtualHost *:80>
    ServerName test.example.com
    DocumentRoot /var/www/site1
</VirtualHost>

# 443 ポートで待ち受け
Listen 443

# HTTPS (443)
<VirtualHost *:443>
    ServerName test.example.com
    DocumentRoot /var/www/site2

    SSLEngine on
    SSLCertificateFile /etc/pki/tls/certs/test.crt
    SSLCertificateKeyFile /etc/pki/tls/private/test.key
</VirtualHost>
```

```/etc/httpd/conf.d/ssl.conf
Listen 443
```
