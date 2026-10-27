<?php
require_once __DIR__ . '/../db/config.php';

function getMemcached(): ?Memcached
{
    if (!class_exists('Memcached')) {
        return null;
    }

    static $memcached = null;
    if ($memcached === null) {
        $memcached = new Memcached();
        $memcached->addServer('127.0.0.1', 11211);
    }

    return $memcached;
}

function getLastSyncTime(Memcached $memcached): int|false|null
{
    $lastSync = $memcached->get('ria_news_last_sync');
    $status = $memcached->getResultCode();
    if ($status === Memcached::RES_NOTFOUND) {
        return null;
    }
    if ($status !== Memcached::RES_SUCCESS) {
        return false;
    }
    return is_int($lastSync) ? $lastSync : null;
}

function acquireSyncLock(Memcached $memcached): ?string
{
    $token = bin2hex(random_bytes(8));
    return $memcached->add('ria_news_sync_lock', $token, 300) ? $token : null;
}

function releaseSyncLock(Memcached $memcached, string $token): void
{
    $current = $memcached->get('ria_news_sync_lock');
    if ($memcached->getResultCode() === Memcached::RES_SUCCESS && $current === $token) {
        $memcached->delete('ria_news_sync_lock');
    }
}

function saveLastSyncTime(Memcached $memcached): bool
{
    return $memcached->set('ria_news_last_sync', time());
}

function loadRssFeed(): SimpleXMLElement
{
    $content = @file_get_contents('https://ria.ru/export/rss2/archive/index.xml');
    if ($content === false) {
        throw new RuntimeException('Не удалось загрузить RSS-ленту РИА Новости.');
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET);
    libxml_clear_errors();
    if ($xml === false || !isset($xml->channel)) {
        throw new RuntimeException('Не удалось разобрать XML.');
    }
    return $xml;
}

function saveRssNews(SimpleXMLElement $xml): array
{
    $pdo = getConnection();
    $stmt = $pdo->prepare("
        INSERT INTO news (guid, title, url, category, image_url, published_at)
        VALUES (:guid, :title, :url, :category, :image_url, :published_at)
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            url = VALUES(url),
            category = VALUES(category),
            image_url = VALUES(image_url),
            published_at = VALUES(published_at)
    ");

    $savedCount = 0;
    $changedCount = 0;
    $pdo->beginTransaction();
    try {
        foreach ($xml->channel->item as $item) {
            $link = trim((string) $item->link);
            $guid = trim((string) $item->guid);
            $title = trim((string) $item->title);
            if ($guid === '') {
                $guid = $link;
            }
            if ($guid === '' || $link === '' || $title === '') {
                continue;
            }

            $category = trim((string) $item->category);
            $imageUrl = null;
            if (isset($item->enclosure)) {
                $attributes = $item->enclosure->attributes();
                if (isset($attributes['url'])) {
                    $imageUrl = (string) $attributes['url'];
                }
            }

            $timestamp = strtotime((string) $item->pubDate);
            $publishedAt = $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);

            $stmt->execute([
                'guid' => $guid,
                'title' => $title,
                'url' => $link,
                'category' => $category !== '' ? $category : null,
                'image_url' => $imageUrl,
                'published_at' => $publishedAt,
            ]);
            $affected = $stmt->rowCount();
            if ($affected === 1) {
                $savedCount++;
            }
            if ($affected > 0) {
                $changedCount++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['saved' => $savedCount, 'changed' => $changedCount];
}

function invalidateNewsCache(): void
{
    try {
        $memcached = getMemcached();
        if ($memcached !== null && !$memcached->set('ria_news_version', bin2hex(random_bytes(16)))) {
            error_log('Не удалось обновить версию кэша Memcached: ' . $memcached->getResultMessage());
        }
    } catch (Throwable $e) {
        error_log('Ошибка обновления версии кэша: ' . $e->getMessage());
    }
}

function getNewsCacheKey(Memcached $memcached, int $page, int $limit, string $category, string $dateFrom, string $dateTo): ?string
{
    $version = $memcached->get('ria_news_version');
    $status = $memcached->getResultCode();
    if ($status === Memcached::RES_NOTFOUND) {
        $version = '0';
    }
    if (($status !== Memcached::RES_SUCCESS && $status !== Memcached::RES_NOTFOUND) || !is_string($version)) {
        return null;
    }
    return 'ria_news_page_' . hash('sha256', serialize([
        $version, $page, $limit, $category, $dateFrom, $dateTo
    ]));
}

function readNewsCache(Memcached $memcached, string $key): ?array
{
    $cached = $memcached->get($key);
    return $memcached->getResultCode() === Memcached::RES_SUCCESS && is_array($cached) ? $cached : null;
}

function writeNewsCache(Memcached $memcached, string $key, array $response): void
{
    if (!$memcached->set($key, $response, 60)) {
        error_log('Не удалось записать кэш Memcached: ' . $memcached->getResultMessage());
    }
}

function fetchNewsPage(int $page, int $limit, string $category, string $dateFrom, string $dateTo): array
{
    $pdo = getConnection();
    $conditions = [];
    $params = [];
    if ($category !== '') {
        $conditions[] = 'category = :category';
        $params['category'] = $category;
    }
    if ($dateFrom !== '') {
        $conditions[] = 'published_at >= :date_from';
        $params['date_from'] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $conditions[] = 'published_at <= :date_to';
        $params['date_to'] = $dateTo . ' 23:59:59';
    }
    $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM news' . $where);
    foreach ($params as $name => $value) {
        $countStmt->bindValue(':' . $name, $value);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    $newsStmt = $pdo->prepare("
        SELECT id, guid, title, url, category, image_url, published_at, created_at, updated_at
        FROM news" . $where . "
        ORDER BY published_at DESC, id DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $name => $value) {
        $newsStmt->bindValue(':' . $name, $value);
    }
    $newsStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $newsStmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
    $newsStmt->execute();
    $news = $newsStmt->fetchAll();

    $categoriesStmt = $pdo->query("SELECT DISTINCT category FROM news WHERE category IS NOT NULL AND category <> '' ORDER BY category");
    $categories = $categoriesStmt->fetchAll(PDO::FETCH_COLUMN);

    return ['news' => $news, 'total' => $total, 'categories' => $categories];
}
