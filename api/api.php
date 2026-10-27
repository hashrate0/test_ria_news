<?php

require_once '../controllers/RiaNewsController.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'getNews':
        $response = getNews();
        break;

    default:
        $response = [
            'success' => false,
            'message' => 'Неизвестное действие.'
        ];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
