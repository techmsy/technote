### ✅ 投稿後に Location: ヘッダでリダイレクトする。

✅ なぜリダイレクトするのか？

🔁 再送信防止（Post/Redirect/Get パターン）
- フォーム送信（POST）のあと、そのまま画面を表示すると…
  - ページをリロードした際に「フォームを再送信しますか？」と出る
  - 間違って送信が重複される危険あり
- そこで、投稿完了後に header("Location: ～") を使ってGETで再アクセスさせ、リロードしてもPOSTは再実行されないようにする。

---

✅ 修正後の全コード（bbs_mysql.php）
```php
<?php
// --- データベース接続 ---
$dsn = 'mysql:host=localhost;dbname=bbs_db;charset=utf8mb4';
$user = 'your_username';
$pass = 'your_password';

try {
    $pdo = new PDO($dsn, $user, $pass);
} catch (PDOException $e) {
    exit("DB接続エラー: " . $e->getMessage());
}

// --- 投稿処理 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($name !== '' && $message !== '') {
        $stmt = $pdo->prepare("INSERT INTO posts (name, message) VALUES (:name, :message)");
        $stmt->execute([
            ':name' => $name,
            ':message' => $message
        ]);

        // --- ここでリダイレクト ---
        header("Location: " . $_SERVER['PHP_SELF']);
        exit(); // 重要！このあと出力しないようにする
    }
}

// --- 投稿一覧の取得（GET時） ---
$stmt = $pdo->query("SELECT * FROM posts ORDER BY created_at DESC");
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>MySQL掲示板（リダイレクト対応）</title>
</head>
<body>
  <h1>MySQL掲示板</h1>

  <form method="post" action="">
    <p>
      名前：<br>
      <input type="text" name="name" required>
    </p>
    <p>
      メッセージ：<br>
      <textarea name="message" rows="4" cols="40" required></textarea>
    </p>
    <p><input type="submit" value="投稿"></p>
  </form>

  <hr>

  <h2>投稿一覧</h2>
  <ul>
    <?php foreach ($posts as $post): ?>
      <li>
        <strong><?php echo htmlspecialchars($post['created_at'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
        <strong><?php echo htmlspecialchars($post['name'], ENT_QUOTES, 'UTF-8'); ?>：</strong>
        <?php echo nl2br(htmlspecialchars($post['message'], ENT_QUOTES, 'UTF-8')); ?>
      </li>
      <hr>
    <?php endforeach; ?>
  </ul>
</body>
</html>
```
---
✅ 変更点まとめ
| 修正ポイント | 内容 |
|-------|-------|
| header("Location: ...") |POST成功後、同じファイルにGETで再アクセスさせる |
| exit(); の追加 | リダイレクト後にHTML出力が走らないようにするため（必須！） |
| $_SERVER['PHP_SELF'] | 現在のファイル自身のパス（たとえば /bbs_mysql.php） |

📌 補足：リダイレクトしないと…
リロード時にブラウザから警告が出る：

このページを再度表示すると、フォームに入力された情報が再送信されます。
これを防ぐのがPost/Redirect/Getの基本パターンです。

---

#### 環境：
- MySQL 10.11（※実際は MariaDB 10.11 の可能性が高い）
- PHP 5.6

この場合における 推奨DB接続コード（PDO使用）とその理由 を解説します。

✅ 推奨コード（PHP 5.6 + MySQL/MariaDB 10.11対応）
```php
<?php
$dsn = 'mysql:host=localhost;dbname=your_db_name;charset=utf8';
$user = 'your_username';
$pass = 'your_password';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,         // 例外でエラー通知
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,    // 連想配列で取得
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"     // 初期文字コード設定（PHP 5.6向け）
    ]);
} catch (PDOException $e) {
    die('データベース接続失敗: ' . $e->getMessage());
}
?>
```

---

✅ 推奨理由
| 項目 | 内容 | なぜ推奨？ |
|-------|-------|-------|
| PDOを使用 | new PDO(...) | mysqli より汎用性が高く、後のバージョン移行にも対応しやすい |
| エラーモード指定 | PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION | SQLエラー時に try-catch で補足しやすく、バグに気づきやすい |
| 文字コード設定 | SET NAMES utf8 明示 | PHP 5.6では charset=utf8 だけでは不十分なことがあるため安全のために設定 |
| FETCHモード指定 | PDO::FETCH_ASSOC | 結果の配列が読みやすくなる（連想配列のみ） |
| 例外補足 | try-catch を使用 | 接続失敗時に丁寧なエラーメッセージを出せる |

---

⚠ 注意点（PHP 5.6 + MariaDB 10.11）
- PHP 5.6 は既に EOL（サポート終了）：セキュリティ的に非常に危険
- MariaDB 10.11 は PHP 5.6 と組み合わせるには**やや古いMySQLドライバ（libmysql）**になる可能性あり
- utf8mb4 を使うなら SET NAMES utf8mb4 に変更し、DBの照合順序も合わせることが必要です

✅ 文字コードの補足（重要）
PHP 5.6 時代は charset=utf8 を DSN に書くだけでは、
実際の接続時に utf8 が使われないケースがあるため、SET NAMES を使うのが安全策です。

---

✅ まとめ：PHP 5.6での安全なDB接続には
- PDO を使用（セキュアで可搬性あり）
- SET NAMES utf8 を追加（5.6特有の不具合対策）
- エラーモードは 例外 に（デバッグ・運用性向上）

---
