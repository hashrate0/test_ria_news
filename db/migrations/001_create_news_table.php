
<?php

return static function (PDO $pdo): void {
    $sql = "
        CREATE TABLE IF NOT EXISTS news (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            guid VARCHAR(255) NOT NULL,
            title TEXT NOT NULL,
            url TEXT NOT NULL,
            category VARCHAR(255) DEFAULT NULL,
            image_url TEXT DEFAULT NULL,
            published_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            UNIQUE KEY uq_news_guid (guid),
            KEY idx_news_published_at (published_at),
            KEY idx_news_category (category)
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
    ";

    $pdo->exec($sql);
};
