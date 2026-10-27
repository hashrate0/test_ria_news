### 1. Запустить Memcached

В корне проекта выполнить, чтобы запустить Memcached в docker:

```bash
docker compose up -d
```

### 2. Настроить БД

Создать локальную базу MySQL `RiaNews` и указать параметры подключения в `db/config.php`:

```php
const DB_HOST = 'localhost';
const DB_NAME = 'RiaNews';
const DB_USER = 'root';
const DB_PASS = 'your_password';
```

### 3. Применить миграцию

Из корня проекта выполнить:

```bash
php db/migrate.php
```

### 4. Запустить PHP-сервер

Из корня проекта выполнить:

```bash
php -S localhost:8008
```

### 5. Открыть приложение

Перейти в браузер - [http://localhost:8008/index.html](http://localhost:8008/index.html).
