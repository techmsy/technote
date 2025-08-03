### devcontainer.json
```json
{
  "name": "php-dev",
  "dockerComposeFile": "docker-compose.yml",
  "service": "php",
  "workspaceFolder": "/var/www/html",
  "remoteEnv": {
    "ENV_FILE": "/var/www/html/.env"
  },
  "settings": {
    "terminal.integrated.shell.linux": "/bin/bash"
  },
  "extensions": [
    "felixfbecker.php-debug",
    "xdebug.php-pack"
  ],
  "postCreateCommand": "docker-php-ext-install mysqli"
}
```

### docker-compose.yml
```yml
version: "3.8"

services:
  php:
    build: .
    volumes:
      - ../src:/var/www/html
    ports:
      - "8888:80"
    depends_on:
      - db
    environment:
      DB_HOST: db
      DB_PORT: 3306
      DB_DATABASE: appdb
      DB_USER: user
      DB_PASSWORD: *

  db:
    image: mariadb:10.5
    restart: always
    environment:
      MARIADB_DATABASE: appdb
      MARIADB_USER: user
      MARIADB_PASSWORD: *
      MARIADB_ROOT_PASSWORD: rootpass
    ports:
      - "3306:3306"
    volumes:
      - db-data:/var/lib/mysql

volumes:
  db-data:
```

### Dockerfile
```Dockerfile
FROM php:7.4-apache

RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    curl \
    mariadb-client \
    && docker-php-ext-install pdo pdo_mysql mysqli

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html
```

### index.php
```php
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
```

### .env.sample
```.env
DB_HOST=db
DB_PORT=3306
DB_DATABASE=appdb
DB_USER=user
DB_PASSWORD=*
```
