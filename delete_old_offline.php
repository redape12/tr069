<?php

require_once 'config.php';
require_once 'config_sql.php';
require_once 'db_functions.php';

// ==============================================
// Функция вызова API
// ==============================================
function callDmsMethod($method, $payload, $timeout = 30) {
    $url = DMS_URL . $method;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, DMS_LOGIN . ':' . DMS_PASSWORD);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);
    
    if ($httpCode != 200) {
        return [
            'success' => false,
            'http_code' => $httpCode,
            'response' => $response
        ];
    }
    
    $decoded = json_decode($response, true);
    
    return [
        'success' => true,
        'http_code' => $httpCode,
        'data' => $decoded
    ];
}

// ==============================================
// Функция проверки существования CPE
// ==============================================
function checkCpeExists($cpeId) {
    $payload = [
        'search_options' => [
            'cpe_id' => $cpeId
        ],
        'command_options' => [
            'limit' => 1,
            'offset' => 0
        ]
    ];
    
    $response = callDmsMethod('GetListOfCPEs', $payload, 30);
    
    if (isset($response['data']['result']['code']) && $response['data']['result']['code'] == 200
        && isset($response['data']['result']['details']) && !empty($response['data']['result']['details'])) {
        return ['exists' => true, 'data' => $response['data']['result']['details'][0]];
    }
    
    return ['exists' => false, 'data' => null];
}

// ==============================================
// Удаление одного CPE по ID с верификацией
// ==============================================
function deleteSingleCpeWithVerification($cpeId) {
    // Сначала проверяем, существует ли устройство
    $checkBefore = checkCpeExists($cpeId);
    if (!$checkBefore['exists']) {
        return [
            'success' => false, 
            'cpe_id' => $cpeId,
            'error' => 'CPE not found before deletion',
            'verified' => false
        ];
    }
    
    // Пытаемся удалить
    $payload = [
        'search_options' => [
            'cpe_id' => $cpeId
        ],
        'command_options' => [
            'limit' => 1,
            'offset' => 0,
            'sort' => 'cpe_id'
        ]
    ];
    
    $response = callDmsMethod('DeleteCPEs', $payload, 30);
    
    // Проверяем ответ API на удаление
    $apiSuccess = $response['success'] && 
                  isset($response['data']['result']['code']) && 
                  $response['data']['result']['code'] == 200;
    
    // Делаем паузу, чтобы дать время API обработать удаление
    sleep(1);
    
    // Проверяем, действительно ли устройство удалено
    $checkAfter = checkCpeExists($cpeId);
    $actuallyDeleted = !$checkAfter['exists'];
    
    // Логируем результат для отладки
    $debugInfo = [
        'api_success' => $apiSuccess,
        'actually_deleted' => $actuallyDeleted,
        'api_response' => $response
    ];
    
    if ($actuallyDeleted) {
        return [
            'success' => true, 
            'cpe_id' => $cpeId,
            'verified' => true,
            'debug' => $debugInfo
        ];
    } else {
        return [
            'success' => false, 
            'cpe_id' => $cpeId,
            'error' => 'API reported success but device still exists',
            'verified' => false,
            'debug' => $debugInfo
        ];
    }
}

// ==============================================
// ОСНОВНОЙ СКРИПТ
// ==============================================
echo "\n" . str_repeat("=", 70) . "\n";
echo "🚀 УДАЛЕНИЕ СТАРЫХ ОФЛАЙН-УСТРОЙСТВ (С ВЕРИФИКАЦИЕЙ)\n";
echo str_repeat("=", 70) . "\n";

$days = 30; // Изменено с 29 на 30 дней

// Получаем список кандидатов из БД
$pdo = getDbConnection();
$stmt = $pdo->prepare("
    SELECT cpe_id, vendor, model, last_seen 
    FROM tr069_obor 
    WHERE state = 5 
      AND last_seen < DATE_SUB(NOW(), INTERVAL :days DAY)
    ORDER BY last_seen ASC
");
$stmt->execute(['days' => $days]);
$candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = count($candidates);
echo "📋 Найдено CPE офлайн >{$days} дней: $total\n";

if ($total == 0) {
    echo "✅ Удалять нечего.\n";
    exit;
}

// Показываем первых 5 для информации
echo "\n📋 Первые 5 из списка:\n";
foreach (array_slice($candidates, 0, 5) as $cpe) {
    echo "   - {$cpe['cpe_id']} | {$cpe['vendor']} {$cpe['model']} | Последнее: {$cpe['last_seen']}\n";
}

echo "\n⏳ Начинаем удаление с верификацией...\n";

$successful = [];
$failed = [];
$apiSuccessButExists = []; // Отдельная категория для проблемных случаев
$notFoundBeforeDeletion = [];

foreach ($candidates as $cpe) {
    echo "   Обрабатываем {$cpe['cpe_id']}... ";
    
    $result = deleteSingleCpeWithVerification($cpe['cpe_id']);
    
    if ($result['success'] && $result['verified']) {
        echo "✅ (удалено и подтверждено)\n";
        $successful[] = $cpe['cpe_id'];
    } elseif (!$result['success'] && isset($result['error']) && $result['error'] == 'CPE not found before deletion') {
        echo "⚠️ (не найдено до удаления)\n";
        $notFoundBeforeDeletion[] = $cpe['cpe_id'];
    } elseif ($result['success'] && !$result['verified']) {
        echo "❓ (API сказало OK, но устройство всё ещё существует)\n";
        $apiSuccessButExists[] = $cpe['cpe_id'];
        
        // Дополнительная отладочная информация
        if (isset($result['debug'])) {
            echo "      Отладка: API успех = " . ($result['debug']['api_success'] ? 'да' : 'нет') . 
                 ", реально удалено = " . ($result['debug']['actually_deleted'] ? 'да' : 'нет') . "\n";
        }
    } else {
        echo "❌ ({$result['error']})\n";
        $failed[] = $cpe['cpe_id'];
    }
    
    usleep(300000); // 0.3 сек между запросами
}

// Итог
echo "\n" . str_repeat("=", 70) . "\n";
echo "🏁 РЕЗУЛЬТАТ\n";
echo str_repeat("=", 70) . "\n";
echo "✅ Успешно удалено и подтверждено: " . count($successful) . "\n";
echo "⚠️ Не найдено до удаления: " . count($notFoundBeforeDeletion) . "\n";
echo "❓ API сказало OK, но устройство всё ещё существует: " . count($apiSuccessButExists) . "\n";
echo "❌ Явные ошибки удаления: " . count($failed) . "\n";

if (!empty($notFoundBeforeDeletion)) {
    echo "\nСписок устройств, которых не было в системе:\n";
    foreach ($notFoundBeforeDeletion as $cpeId) {
        echo "   - $cpeId\n";
    }
}

if (!empty($apiSuccessButExists)) {
    echo "\nСписок устройств, где API сказало OK, но они всё ещё существуют:\n";
    foreach ($apiSuccessButExists as $cpeId) {
        echo "   - $cpeId\n";
    }
}

if (!empty($failed)) {
    echo "\nСписок явных ошибок:\n";
    foreach ($failed as $cpeId) {
        echo "   - $cpeId\n";
    }
}

// Расширенное логирование
$logFile = 'delete_by_id_log_' . date('Ymd') . '.txt';
$logEntry = date('Y-m-d H:i:s') . " | Всего: $total | Подтверждено: " . count($successful) . 
            " | Не найдено: " . count($notFoundBeforeDeletion) . 
            " | API OK но существует: " . count($apiSuccessButExists) . 
            " | Ошибок: " . count($failed) . " | Дней: $days\n";
file_put_contents($logFile, $logEntry, FILE_APPEND);

// Сохраняем подробный лог проблемных случаев
if (!empty($apiSuccessButExists)) {
    $problemLogFile = 'problematic_deletions_' . date('Ymd') . '.txt';
    $problemData = date('Y-m-d H:i:s') . " - Устройства с ложным успехом: " . implode(', ', $apiSuccessButExists) . "\n";
    file_put_contents($problemLogFile, $problemData, FILE_APPEND);
}

echo "\n📁 Основной лог сохранён в: $logFile\n";
if (!empty($apiSuccessButExists)) {
    echo "📁 Проблемные случаи сохранены в: $problemLogFile\n";
}
echo "✅ Готово.\n";
