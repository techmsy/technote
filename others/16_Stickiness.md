#2025/09/25

- Apache に X-Forwarded-Proto を解釈させる

```/etc/httpd/conf.d/phpmyadmin.conf
<IfModule mod_setenvif.c>
  SetEnvIf X-Forwarded-Proto https HTTPS=on
</IfModule>
```

- セッションの sticky 設定を有効にする（AutoScaling特有の対策）

```ALBターゲットグループで stickiness を有効化する手順
1. AWSマネジメントコンソールにログイン
→ EC2 サービスを開く

2. 左メニューから 「ターゲットグループ」 をクリック

3. phpMyAdmin用の ALB に紐づいた ターゲットグループ を選択

4. 上部タブから 「属性」(Attributes) を選択

5. 「スティッキーセッション (Stickiness)」の項目を探す

6. 「編集」 をクリック

7. 「スティッキーセッションを有効化」を選択し、以下を設定：

 - 有効化 → ON

 - スティッキーの種類 → ALB Cookie

 - 持続時間 (Duration) → デフォルトは 1時間（3600秒）でOK

8. 保存 をクリック
```

- 注意点
- stickiness を有効にすると「同じクライアント（ブラウザ）は一定時間、同じEC2に固定される」ので、phpMyAdminのようにセッションに依存するアプリには有効です。
- 逆に、均等負荷分散を重視したいアプリケーションでは stickiness を無効のままにしておく方がよいです。

[AWSのSticky Sessionを作成（スティッキーセッション） - Qiita](https://qiita.com/seikida/items/ce551f705aec25653160)
(検索 aws ターゲットグループ スティッキーセッション)