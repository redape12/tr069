<?php

require_once 'config.php';
require_once 'config_sql.php';
require_once 'db_functions.php';

set_time_limit(0);

// ==============================================
// Функции для работы с DMS API
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
        return null;
    }
    
    return json_decode($response, true);
}

// ==============================================
// ШАГ 1: Получаем ВСЕ CPE (с пагинацией) и сохраняем в tr069_obor
// ==============================================
function updateOborTable() {
    echo "\n📡 ШАГ 1: Получаем список всех CPE...\n";
    
    $limit = 2000;
    $offset = 0;
    $allCpes = [];
    $totalCount = 0;
    $page = 1;
    
    do {
        echo "   Страница $page (offset: $offset, limit: $limit)... ";
        
        $payload = [
            'search_options' => ['cpe_id' => '*'],
            'command_options' => [
                'limit' => $limit,
                'offset' => $offset,
                'sort' => 'cpe_id'
            ]
        ];
        
        $response = callDmsMethod('GetListOfCPEs', $payload, 60);
        
        if (!$response || !isset($response['result']['details'])) {
            echo "❌ Ошибка получения данных\n";
            break;
        }
        
        $cpes = $response['result']['details'];
        $pageCount = count($cpes);
        $allCpes = array_merge($allCpes, $cpes);
        
        // Пробуем получить общее количество из ответа (если есть)
        if ($totalCount == 0 && isset($response['result']['total_count'])) {
            $totalCount = $response['result']['total_count'];
        }
        
        echo "получено $pageCount\n";
        
        $offset += $limit;
        $page++;
        
        // Небольшая задержка между страницами
        usleep(500000);
        
    } while ($pageCount == $limit); // Если получили меньше limit'а — значит это последняя страница
    
    $total = count($allCpes);
    echo "✅ Всего получено CPE: $total\n";
    if ($totalCount > 0) {
        echo "   (по данным сервера всего: $totalCount)\n";
    }
    
    // Подключаемся к БД
    $pdo = getDbConnection();
    
    // Очищаем таблицу
    $pdo->exec("TRUNCATE TABLE tr069_obor");
    echo "✅ Таблица tr069_obor очищена\n";
    
    // Подготавливаем запрос
    $sql = "INSERT INTO tr069_obor (
        cpe_id, vendor, model, sw_version, ip_address, mac, hw_version,
        last_seen, state, first_seen, protocol, location
    ) VALUES (
        :cpe_id, :vendor, :model, :sw_version, :ip_address, :mac, :hw_version,
        :last_seen, :state, :first_seen, :protocol, :location
    )";
    
    $stmt = $pdo->prepare($sql);
    
    $inserted = 0;
    foreach ($allCpes as $cpe) {
        $lastSeen = isset($cpe['last_seen']) ? date('Y-m-d H:i:s', $cpe['last_seen']) : null;
        $firstSeen = isset($cpe['first_seen']) ? date('Y-m-d H:i:s', $cpe['first_seen']) : null;
        
        $data = [
            'cpe_id' => $cpe['cpe_id'] ?? '',
            'vendor' => $cpe['vendor'] ?? '',
            'model' => $cpe['model'] ?? '',
            'sw_version' => $cpe['sw_version'] ?? '',
            'ip_address' => $cpe['ip_address'] ?? '',
            'mac' => $cpe['mac'] ?? '',
            'hw_version' => $cpe['hw_version'] ?? '',
            'last_seen' => $lastSeen,
            'state' => $cpe['state'] ?? 0,
            'first_seen' => $firstSeen,
            'protocol' => $cpe['protocol'] ?? '',
            'location' => $cpe['location'] ?? ''
        ];
        
        $stmt->execute($data);
        $inserted++;
    }
    
    echo "✅ Сохранено в tr069_obor: $inserted\n";
    
    // Статистика по статусам
    $stats = $pdo->query("
        SELECT state, COUNT(*) as cnt 
        FROM tr069_obor 
        GROUP BY state
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    echo "📊 Статусы:\n";
    echo "   Online (0): " . ($stats[0] ?? 0) . "\n";
    echo "   Offline (5): " . ($stats[5] ?? 0) . "\n";
    echo "   Другие: " . (($stats[0] ?? 0) + ($stats[5] ?? 0) < $inserted ? ($inserted - ($stats[0] ?? 0) - ($stats[5] ?? 0)) : 0) . "\n";
    
    return $stats[0] ?? 0; // возвращаем количество online
}

// ==============================================
// ШАГ 2: Получаем детальные данные для одного CPE
// ==============================================
function getFullCpeData($cpeId) {
    $result = ['success' => false, 'data' => []];
    
    // 1. Базовая информация
    $infoPayload = [
        'search_options' => ['cpe_id' => $cpeId],
        'parameters' => ['InternetGatewayDevice.DeviceInfo.'],
        'command_options' => ['async' => false, 'timeout' => 15, 'multiple' => false]
    ];
    $info = callDmsMethod('GetParameterValues', $infoPayload, 17);
    if ($info && isset($info['result']['details'])) {
        foreach ($info['result']['details'] as $item) {
            $result['data'][$item['key']] = $item['value'];
        }
    }
    
    // 2. LAN статистика
    $lanPayload = [
        'search_options' => ['cpe_id' => $cpeId],
        'command_options' => ['async' => false, 'timeout' => 15, 'multiple' => false]
    ];
    $lan = callDmsMethod('LanPortsStatistics', $lanPayload, 17);
    if ($lan && isset($lan['result']['details'][0]['value'])) {
        $result['data']['lan_ports'] = $lan['result']['details'][0]['value'];
    }
    
    // 3. WiFi детали
    $wifi = callDmsMethod('GetWifiDetails', $lanPayload, 17);
    if ($wifi && isset($wifi['result']['details'])) {
        foreach ($wifi['result']['details'] as $wifiDetail) {
            if ($wifiDetail['key'] == 'wifi5') {
                $result['data']['wifi5_status'] = $wifiDetail['value']['ssidStatus'] ?? 'Down';
            }
        }
    }
    
    // 4. WLAN статистика
    $wlanStatsPayload = [
        'search_options' => ['cpe_id' => $cpeId],
        'parameters' => ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Stats.'],
        'command_options' => ['async' => false, 'timeout' => 15, 'multiple' => false]
    ];
    $wlanStats = callDmsMethod('GetParameterValues', $wlanStatsPayload, 17);
    if ($wlanStats && isset($wlanStats['result']['details'])) {
        foreach ($wlanStats['result']['details'] as $item) {
            $key = str_replace('InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Stats.', '', $item['key']);
            $result['data']['wlan_' . $key] = $item['value'];
        }
    }
    
    // 5. WAN интерфейс
    $wanPayload = [
        'search_options' => ['cpe_id' => $cpeId],
        'command_options' => ['async' => false, 'timeout' => 15, 'multiple' => false],
        'parameters' => ['check_all_for_181' => false]
    ];
    $wan = callDmsMethod('WanInterface', $wanPayload, 17);
    if ($wan && isset($wan['result']['details'][0]['value'][0])) {
        $wanData = $wan['result']['details'][0]['value'][0];
        $result['data']['wan_packets_sent'] = $wanData['packetsSent'] ?? 0;
        $result['data']['wan_packets_received'] = $wanData['packetsReceived'] ?? 0;
        $result['data']['wan_discard_sent'] = $wanData['discardPacketsSent'] ?? 0;
        $result['data']['wan_discard_received'] = $wanData['discardPacketsReceived'] ?? 0;
    }
    
    // 6. Max bit rate
    $wanCommonPayload = [
        'search_options' => ['cpe_id' => $cpeId],
        'parameters' => ['InternetGatewayDevice.WANDevice.1.WANCommonInterfaceConfig.'],
        'command_options' => ['async' => false, 'timeout' => 15, 'multiple' => false]
    ];
    $wanCommon = callDmsMethod('GetParameterValues', $wanCommonPayload, 17);
    if ($wanCommon && isset($wanCommon['result']['details'])) {
        foreach ($wanCommon['result']['details'] as $item) {
            if ($item['key'] == 'InternetGatewayDevice.WANDevice.1.WANCommonInterfaceConfig.Layer1DownstreamMaxBitRate') {
                $result['data']['max_bit_rate'] = $item['value'];
            }
        }
    }
    
    // 7. Клиенты
    $clientsPayload = [
        'search_options' => ['cpe_id' => $cpeId],
        'parameters' => ['InternetGatewayDevice.LANDevice.1.Hosts.Host.'],
        'command_options' => ['async' => false, 'timeout' => 15, 'multiple' => false]
    ];
    $clients = callDmsMethod('GetParameterValues', $clientsPayload, 17);
    if ($clients && isset($clients['result']['details'])) {
        $hosts = [];
        foreach ($clients['result']['details'] as $item) {
            if (preg_match('/Host\.(\d+)\.IPAddress$/', $item['key'], $matches)) {
                $hosts[$matches[1]]['ip'] = $item['value'];
            }
            if (preg_match('/Host\.(\d+)\.MACAddress$/', $item['key'], $matches)) {
                $hosts[$matches[1]]['mac'] = $item['value'];
            }
            if (preg_match('/Host\.(\d+)\.HostName$/', $item['key'], $matches)) {
                $hosts[$matches[1]]['hostname'] = $item['value'];
            }
        }
        $result['data']['clients'] = $hosts;
        $result['data']['clients_count'] = count($hosts);
    }
    
    // Считаем LAN ошибки
    if (isset($result['data']['lan_ports'])) {
        foreach ($result['data']['lan_ports'] as $port) {
            $result['data']['lan_errors_sent'] = ($result['data']['lan_errors_sent'] ?? 0) + ($port['errorsSent'] ?? 0);
            $result['data']['lan_errors_received'] = ($result['data']['lan_errors_received'] ?? 0) + ($port['errorsReceived'] ?? 0);
            $result['data']['lan_discard_sent'] = ($result['data']['lan_discard_sent'] ?? 0) + ($port['discardPacketsSent'] ?? 0);
            $result['data']['lan_discard_received'] = ($result['data']['lan_discard_received'] ?? 0) + ($port['discardPacketsReceived'] ?? 0);
        }
    }
    
    $result['success'] = true;
    return $result;
}

// ==============================================
// ШАГ 3: Сохраняем детальные данные в tr069_detail
// ==============================================
function saveDetailedData($onlineCpes) {
    $pdo = getDbConnection();
    
    // Очищаем детальную таблицу
    $pdo->exec("TRUNCATE TABLE tr069_detail");
    echo "✅ Таблица tr069_detail очищена\n";
    
    $total = count($onlineCpes);
    $successCount = 0;
    $errorCount = 0;
    $startTime = time();
    
    for ($i = 0; $i < $total; $i++) {
        $cpe = $onlineCpes[$i];
        
        // Прогресс
        $percent = round(($i + 1) / $total * 100, 1);
        $elapsed = time() - $startTime;
        $avgTime = $elapsed / max(1, $i + 1);
        $remaining = round($avgTime * ($total - $i - 1) / 60, 1);
        
        echo "\r[{$percent}%] $i/$total, успех: $successCount, ошибок: $errorCount, осталось ~{$remaining}мин   ";
        
        $data = getFullCpeData($cpe['cpe_id']);
        
        if ($data['success']) {
            $row = [
                'cpe_id' => $cpe['cpe_id'],
                'vendor' => $cpe['vendor'],
                'model' => $cpe['model'],
                'sw_version' => $data['data']['InternetGatewayDevice.DeviceInfo.SoftwareVersion'] ?? '',
                'ip_address' => $cpe['ip_address'],
                'last_seen' => $cpe['last_seen'],
                
                'cpu_usage' => $data['data']['InternetGatewayDevice.DeviceInfo.ProcessStatus.CPUUsage'] ?? 0,
                'max_bit_rate' => $data['data']['max_bit_rate'] ?? 0,
                
                'lan_errors_sent' => $data['data']['lan_errors_sent'] ?? 0,
                'lan_errors_received' => $data['data']['lan_errors_received'] ?? 0,
                'lan_discard_sent' => $data['data']['lan_discard_sent'] ?? 0,
                'lan_discard_received' => $data['data']['lan_discard_received'] ?? 0,
                
                'wlan_errors_sent' => $data['data']['wlan_ErrorsSent'] ?? 0,
                'wlan_errors_received' => $data['data']['wlan_ErrorsReceived'] ?? 0,
                'wlan_discard_sent' => $data['data']['wlan_DiscardPacketsSent'] ?? 0,
                'wlan_discard_received' => $data['data']['wlan_DiscardPacketsReceived'] ?? 0,
                
                'wifi_5ghz' => ($data['data']['wifi5_status'] ?? 'Down') == 'Up' ? 'Yes' : 'No',
                
                'wan_packets_sent' => $data['data']['wan_packets_sent'] ?? 0,
                'wan_packets_received' => $data['data']['wan_packets_received'] ?? 0,
                'wan_discard_sent' => $data['data']['wan_discard_sent'] ?? 0,
                'wan_discard_received' => $data['data']['wan_discard_received'] ?? 0,
                
                'clients_count' => $data['data']['clients_count'] ?? 0
            ];
            
            // Добавляем клиентов (до 5)
            $clients = array_values($data['data']['clients'] ?? []);
            for ($j = 0; $j < 5; $j++) {
                $row["client_" . ($j+1) . "_ip"] = $clients[$j]['ip'] ?? '';
                $row["client_" . ($j+1) . "_mac"] = $clients[$j]['mac'] ?? '';
                $row["client_" . ($j+1) . "_hostname"] = $clients[$j]['hostname'] ?? '';
            }
            
            // Заполняем пустых клиентов, если их меньше 5
            for ($j = $data['data']['clients_count'] ?? 0; $j < 5; $j++) {
                $row["client_" . ($j+1) . "_ip"] = '';
                $row["client_" . ($j+1) . "_mac"] = '';
                $row["client_" . ($j+1) . "_hostname"] = '';
            }
            
            insertCpeData($row);
            $successCount++;
        } else {
            $errorCount++;
        }
        
        usleep(300000); // 0.3 сек
    }
    
    $totalTime = round((time() - $startTime) / 60, 1);
    echo "\n\n✅ Детальные данные:\n";
    echo "   Успешно: $successCount\n";
    echo "   Ошибок: $errorCount\n";
    echo "   Время: $totalTime мин\n";
}

// ==============================================
// ОСНОВНОЙ ЦИКЛ
// ==============================================
echo "\n" . str_repeat("=", 70) . "\n";
echo "🚀 ЗАПУСК НОЧНОГО ЦИКЛА СБОРА ДАННЫХ\n";
echo str_repeat("=", 70) . "\n";

$startCycle = time();

// ШАГ 1: Обновляем таблицу оборудования
$onlineCount = updateOborTable();

if ($onlineCount == 0) {
    die("❌ Нет online оборудования для опроса\n");
}

// ШАГ 2: Получаем online CPE из БД
$pdo = getDbConnection();
$stmt = $pdo->query("
    SELECT cpe_id, vendor, model, ip_address, last_seen 
    FROM tr069_obor 
    WHERE state = 0
");
$onlineCpes = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\n📡 ШАГ 2: Опрашиваем " . count($onlineCpes) . " online CPE...\n";

// ШАГ 3: Собираем детальные данные
saveDetailedData($onlineCpes);

$totalTime = round((time() - $startCycle) / 60, 1);
echo "\n" . str_repeat("=", 70) . "\n";
echo "🏁 ЦИКЛ ЗАВЕРШЁН за $totalTime минут\n";
echo str_repeat("=", 70) . "\n";

?>