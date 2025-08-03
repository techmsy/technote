<?php
$host = getenv('DB_HOST');
$db   = getenv('DB_DATABASE');
$user = getenv('DB_USER');
$pass = getenv('DB_PASSWORD');
$port = getenv('DB_PORT');

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8";

try {
    $pdo = new PDO($dsn, $user, $pass);
    echo "DB接続成功！";
} catch (PDOException $e) {
    echo "DB接続エラー: " . $e->getMessage();
}
?>
