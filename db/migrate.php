
<?php

require_once __DIR__ . '/config.php';

$migrations = [
    __DIR__ . '/migrations/001_create_news_table.php',
];

try {
    $pdo = getConnection();

    foreach ($migrations as $migrationFile) {
        if (!is_file($migrationFile)) {
            throw new RuntimeException(
                'Файл миграции не найден: ' . basename($migrationFile)
            );
        }

        $migration = require $migrationFile;

        if (!is_callable($migration)) {
            throw new RuntimeException(
                'Файл миграции должен возвращать функцию: '
                . basename($migrationFile)
            );
        }

        $migration($pdo);

        echo 'Миграция применена: '
            . basename($migrationFile)
            . PHP_EOL;
    }

    echo 'Все миграции выполнены успешно.' . PHP_EOL;
} catch (Throwable $e) {
    error_log($e->getMessage());

    http_response_code(500);

    echo 'Ошибка применения миграций: '
        . $e->getMessage()
        . PHP_EOL;

    exit(1);
}
