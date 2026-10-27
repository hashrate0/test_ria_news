<?php
require_once __DIR__ . '/../services/RiaNews.php';

function syncNews(): array
{
    try {
        $xml = loadRssFeed();
    } catch (RuntimeException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }

    try {
        $result = saveRssNews($xml);
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return ['success' => false, 'message' => 'Не удалось сохранить новости в базе данных.'];
    }

    if ($result['changed'] > 0) {
        invalidateNewsCache();
    }
    return ['success' => true, 'message' => 'Синхронизация завершена.', 'count' => $result['saved']];
}

function getNews(): array
{
    $pageInput = $_GET['page'] ?? '1';
    $limitInput = $_GET['limit'] ?? '10';
    if (
        !is_scalar($pageInput) || !is_scalar($limitInput)
        || !ctype_digit((string) $pageInput) || !ctype_digit((string) $limitInput)
    ) {
        return ['success' => false, 'message' => 'Некорректные параметры пагинации.'];
    }
    $page = filter_var(ltrim((string) $pageInput, '0'), FILTER_VALIDATE_INT);
    $limit = filter_var(ltrim((string) $limitInput, '0'), FILTER_VALIDATE_INT);
    if ($page === false || $limit === false || $limit > 100 || $page > intdiv(PHP_INT_MAX, $limit)) {
        return ['success' => false, 'message' => 'Некорректные параметры пагинации.'];
    }

    $category = $_GET['category'] ?? '';
    if (!is_string($category)) {
        return ['success' => false, 'message' => 'Некорректная категория.'];
    }
    $category = trim($category);
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    if (!is_string($dateFrom) || !is_string($dateTo)) {
        return ['success' => false, 'message' => 'Некорректный формат даты.'];
    }

    foreach ([$dateFrom, $dateTo] as $dateValue) {
        if ($dateValue === '') {
            continue;
        }
        if (
            preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $dateValue, $parts) !== 1
            || (int) $parts[1] < 1000
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        ) {
            return ['success' => false, 'message' => 'Некорректный формат даты.'];
        }
    }
    if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
        return ['success' => false, 'message' => 'Начальная дата позже конечной.'];
    }

    $cacheKey = null;
    $memcached = null;
    $skipCacheRead = false;
    try {
        $memcached = getMemcached();
        if ($memcached !== null) {
            $lastSync = getLastSyncTime($memcached);
            if ($lastSync === false) {
                $memcached = null;
            } elseif ($lastSync === null || time() - $lastSync >= 300) {
                $lockToken = acquireSyncLock($memcached);
                if ($lockToken !== null) {
                    $skipCacheRead = true;
                    try {
                        $syncResult = syncNews();
                        if ($syncResult['success'] && !saveLastSyncTime($memcached)) {
                            error_log('Не удалось сохранить время синхронизации в Memcached.');
                        }
                    } finally {
                        releaseSyncLock($memcached, $lockToken);
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Ошибка автоматической синхронизации: ' . $e->getMessage());
        $memcached = null;
    }

    try {
        if ($memcached !== null) {
            $cacheKey = getNewsCacheKey($memcached, $page, $limit, $category, $dateFrom, $dateTo);
            if ($cacheKey !== null && !$skipCacheRead) {
                $cached = readNewsCache($memcached, $cacheKey);
                if ($cached !== null) {
                    return $cached;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Ошибка чтения Memcached: ' . $e->getMessage());
        $cacheKey = null;
    }

    try {
        $pageData = fetchNewsPage($page, $limit, $category, $dateFrom, $dateTo);
        $response = [
            'success' => true,
            'count' => count($pageData['news']),
            'news' => $pageData['news'],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $pageData['total'],
                'total_pages' => (int) ceil($pageData['total'] / $limit),
            ],
            'categories' => $pageData['categories'],
        ];
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return ['success' => false, 'message' => 'Не удалось получить новости из базы данных.'];
    }

    if ($cacheKey !== null && $memcached !== null) {
        try {
            writeNewsCache($memcached, $cacheKey, $response);
        } catch (Throwable $e) {
            error_log('Ошибка записи Memcached: ' . $e->getMessage());
        }
    }
    return $response;
}
