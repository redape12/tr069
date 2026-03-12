<?php

require_once 'config.php';
require_once 'config_sql.php';
require_once 'db_functions.php';

set_time_limit(0);

// ==============================================
// Конфигурация пакетной обработки
// ==============================================
define('BATCH_SIZE', 10); // Размер пакета для обработки
define('PAUSE_BETWEEN_BATCHES', 2); // Пауза между пакетами в секундах
define('REQUEST_TIMEOUT', 30); // Общий таймаут для всех запросов

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
// Вспомогательная функция для создания curl-запроса
// ==============================================
function createCurlHandle($method, $payload, $timeout) {
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
    
    return $ch;
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
// Создание набора запросов для одного CPE
// ==============================================
function createCpeRequestHandles($cpeId) {
    $handles = [];
    $timeout = REQUEST_TIMEOUT;
    
    // 1. Базовая информация
    $infoPayload = [
        'search_options' => ['cpe_id' => $cpeId],
        'parameters' => ['InternetGatewayDevice.DeviceInfo.'],
        'command_options' => ['async' => false, 'timeout' => 5, 'multiple' => false]
    ];
    $handles['info'] = createCurlHandle('GetParameterValues', $infoPayload, $timeout);
    
    // 2. LAN статистика
    $lanPayload = [
        'search_options' => ['cpe_id' => $cpeId],
        'command_options' => ['async' => false, 'timeout' => 10, 'multiple' => false]
    ];
    $handles['lan'] = createCurlHandle('LanPortsStatistics', $lanPayload, $timeout);
    
    // 3. WiFi детали (оба диапазона)
    $handles['wifi'] = createCurlHandle('GetWifiDetails', $lanPayload, $timeout);
    
    // 4. WLAN статистика для 2.4GHz
    $wlanStatsPayload24 = [
        'search_options' => ['cpe_id' => $cpeId],
        'parameters' => ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Stats.'],
        'command_options' => ['async' => false, 'timeout' => 5, 'multiple' => false]
    ];
    $handles['wlanStats24'] = createCurlHandle('GetParameterValues', $wlanStatsPayload24, $timeout);
    
    // 5. WLAN статистика для 5GHz
    $wlanStatsPayload5 = [
        'search_options' => ['cpe_id' => $cpeId],
        'parameters' => ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.Stats.'],
        'command_options' => ['async' => false, 'timeout' => 5, 'multiple' => false]
    ];
    $handles['wlanStats5'] = createCurlHandle('GetParameterValues', $wlanStatsPayload5, $timeout);
    
    // 6. WAN интерфейс
    $wanPayload = [
        'search_options' => ['cpe_id' => $cpeId],
        'command_options' => ['async' => false, 'timeout' => 10, 'multiple' => false],
        'parameters' => ['check_all_for_181' => false]
    ];
    $handles['wan'] = createCurlHandle('WanInterface', $wanPayload, $timeout);
    
    // 7. Max bit rate
    $wanCommonPayload = [
        'search_options' => ['cpe_id' => $cpeId],
        'parameters' => ['InternetGatewayDevice.WANDevice.1.WANCommonInterfaceConfig.'],
        'command_options' => ['async' => false, 'timeout' => 5, 'multiple' => false]
    ];
    $handles['wanCommon'] = createCurlHandle('GetParameterValues', $wanCommonPayload, $timeout);
    
    // 8. LAN клиенты
    $clientsPayload = [
        'search_options' => ['cpe_id' => $cpeId],
        'parameters' => ['InternetGatewayDevice.LANDevice.1.Hosts.Host.'],
        'command_options' => ['async' => false, 'timeout' => 5, 'multiple' => false]
    ];
    $handles['clients'] = createCurlHandle('GetParameterValues', $clientsPayload, $timeout);
    
    return $handles;
}

// ==============================================
// Парсинг ответов для одного CPE
// ==============================================
function parseCpeResponses($responses) {
    $result = ['success' => false, 'data' => []];
    $data = &$result['data'];
    
    // Инфо
    if (isset($responses['info']['result']['details'])) {
        foreach ($responses['info']['result']['details'] as $item) {
            $data[$item['key']] = $item['value'];
        }
    }
    
    // LAN порты
    if (isset($responses['lan']['result']['details'][0]['value'])) {
        $data['lan_ports'] = $responses['lan']['result']['details'][0]['value'];
    }
    
    // WiFi детали - парсим оба диапазона
    if (isset($responses['wifi']['result']['details'])) {
        foreach ($responses['wifi']['result']['details'] as $wifiDetail) {
            if ($wifiDetail['key'] == 'wifi24') {
                // 2.4 GHz
                $data['wifi24_status'] = $wifiDetail['value']['ssidStatus'] ?? 'Down';
                $data['wifi24_channel'] = $wifiDetail['value']['channel'] ?? 0;
                $data['wifi24_auto_channel'] = $wifiDetail['value']['autoChannel'] ?? false;
                $data['wifi24_standard'] = $wifiDetail['value']['operationStandard'] ?? '';
                
                // Собираем SSID для 2.4GHz
                if (isset($wifiDetail['value']['ssid']) && is_array($wifiDetail['value']['ssid'])) {
                    foreach ($wifiDetail['value']['ssid'] as $ssid) {
                        if ($ssid['ssid'] ?? '') {
                            $data['wifi24_ssid'] = $ssid['ssid'];
                            $data['wifi24_ssid_enabled'] = $ssid['ssidEnable'] ?? false;
                            $data['wifi24_bssid'] = $ssid['bssid'] ?? '';
                            
                            // Собираем клиентов WiFi для 2.4GHz
                            if (isset($ssid['accessPoint'][0]['associatedDevice'])) {
                                $data['wifi24_clients_count'] = count($ssid['accessPoint'][0]['associatedDevice']);
                                $data['wifi24_clients'] = $ssid['accessPoint'][0]['associatedDevice'];
                            }
                            break; // Берем первый активный SSID
                        }
                    }
                }
            }
            elseif ($wifiDetail['key'] == 'wifi5') {
                // 5 GHz
                $data['wifi5_status'] = $wifiDetail['value']['ssidStatus'] ?? 'Down';
                $data['wifi5_channel'] = $wifiDetail['value']['channel'] ?? 0;
                $data['wifi5_auto_channel'] = $wifiDetail['value']['autoChannel'] ?? false;
                $data['wifi5_standard'] = $wifiDetail['value']['operationStandard'] ?? '';
                
                // Собираем SSID для 5GHz
                if (isset($wifiDetail['value']['ssid']) && is_array($wifiDetail['value']['ssid'])) {
                    foreach ($wifiDetail['value']['ssid'] as $ssid) {
                        if ($ssid['ssid'] ?? '') {
                            $data['wifi5_ssid'] = $ssid['ssid'];
                            $data['wifi5_ssid_enabled'] = $ssid['ssidEnable'] ?? false;
                            $data['wifi5_bssid'] = $ssid['bssid'] ?? '';
                            
                            // Собираем клиентов WiFi для 5GHz
                            if (isset($ssid['accessPoint'][0]['associatedDevice'])) {
                                $data['wifi5_clients_count'] = count($ssid['accessPoint'][0]['associatedDevice']);
                                $data['wifi5_clients'] = $ssid['accessPoint'][0]['associatedDevice'];
                            }
                            break; // Берем первый активный SSID
                        }
                    }
                }
            }
        }
    }
    
    // WLAN статистика для 2.4GHz
    if (isset($responses['wlanStats24']['result']['details'])) {
        foreach ($responses['wlanStats24']['result']['details'] as $item) {
            $key = str_replace('InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Stats.', '', $item['key']);
            $data['wifi24_' . $key] = $item['value'];
        }
    }
    
    // WLAN статистика для 5GHz
    if (isset($responses['wlanStats5']['result']['details'])) {
        foreach ($responses['wlanStats5']['result']['details'] as $item) {
            $key = str_replace('InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.Stats.', '', $item['key']);
            $data['wifi5_' . $key] = $item['value'];
        }
    }
    
    // WAN интерфейс
    if (isset($responses['wan']['result']['details'][0]['value'][0])) {
        $wanData = $responses['wan']['result']['details'][0]['value'][0];
        $data['wan_packets_sent'] = $wanData['packetsSent'] ?? 0;
        $data['wan_packets_received'] = $wanData['packetsReceived'] ?? 0;
        $data['wan_discard_sent'] = $wanData['discardPacketsSent'] ?? 0;
        $data['wan_discard_received'] = $wanData['discardPacketsReceived'] ?? 0;
    }
    
    // Max bit rate
    if (isset($responses['wanCommon']['result']['details'])) {
        foreach ($responses['wanCommon']['result']['details'] as $item) {
            if ($item['key'] == 'InternetGatewayDevice.WANDevice.1.WANCommonInterfaceConfig.Layer1DownstreamMaxBitRate') {
                $data['max_bit_rate'] = $item['value'];
            }
        }
    }
    
    // LAN клиенты
    if (isset($responses['clients']['result']['details'])) {
        $hosts = [];
        foreach ($responses['clients']['result']['details'] as $item) {
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
        $data['lan_clients'] = $hosts;
        $data['lan_clients_count'] = count($hosts);
    }
    
    // Считаем LAN ошибки
    if (isset($data['lan_ports'])) {
        foreach ($data['lan_ports'] as $port) {
            $data['lan_errors_sent'] = ($data['lan_errors_sent'] ?? 0) + ($port['errorsSent'] ?? 0);
            $data['lan_errors_received'] = ($data['lan_errors_received'] ?? 0) + ($port['errorsReceived'] ?? 0);
            $data['lan_discard_sent'] = ($data['lan_discard_sent'] ?? 0) + ($port['discardPacketsSent'] ?? 0);
            $data['lan_discard_received'] = ($data['lan_discard_received'] ?? 0) + ($port['discardPacketsReceived'] ?? 0);
        }
    }
    
    $result['success'] = true;
    return $result;
}

// ==============================================
// Формирование строки для вставки в БД - ИСПРАВЛЕННАЯ ВЕРСИЯ
// ==============================================
function buildCpeRow($cpe, $data) {
    $row = [
        'cpe_id' => $cpe['cpe_id'],
        'vendor' => $cpe['vendor'],
        'model' => $cpe['model'],
        'sw_version' => $data['InternetGatewayDevice.DeviceInfo.SoftwareVersion'] ?? '',
        'ip_address' => $cpe['ip_address'],
        'last_seen' => $cpe['last_seen'],
        
        'cpu_usage' => (int)($data['InternetGatewayDevice.DeviceInfo.ProcessStatus.CPUUsage'] ?? 0),
        'max_bit_rate' => (int)($data['max_bit_rate'] ?? 0),
        
        'lan_errors_sent' => (int)($data['lan_errors_sent'] ?? 0),
        'lan_errors_received' => (int)($data['lan_errors_received'] ?? 0),
        'lan_discard_sent' => (int)($data['lan_discard_sent'] ?? 0),
        'lan_discard_received' => (int)($data['lan_discard_received'] ?? 0),
        
        // 2.4 GHz WiFi - все числовые поля приводим к int
        'wifi24_status' => $data['wifi24_status'] ?? 'Down',
        'wifi24_ssid' => $data['wifi24_ssid'] ?? '',
        'wifi24_channel' => (int)($data['wifi24_channel'] ?? 0),
        'wifi24_auto_channel' => isset($data['wifi24_auto_channel']) ? (int)$data['wifi24_auto_channel'] : 0,
        'wifi24_standard' => $data['wifi24_standard'] ?? '',
        'wifi24_bssid' => $data['wifi24_bssid'] ?? '',
        'wifi24_clients_count' => (int)($data['wifi24_clients_count'] ?? 0),
        'wifi24_errors_sent' => (int)($data['wifi24_ErrorsSent'] ?? 0),
        'wifi24_errors_received' => (int)($data['wifi24_ErrorsReceived'] ?? 0),
        'wifi24_discard_sent' => (int)($data['wifi24_DiscardPacketsSent'] ?? 0),
        'wifi24_discard_received' => (int)($data['wifi24_DiscardPacketsReceived'] ?? 0),
        
        // 5 GHz WiFi - все числовые поля приводим к int
        'wifi5_status' => $data['wifi5_status'] ?? 'Down',
        'wifi5_ssid' => $data['wifi5_ssid'] ?? '',
        'wifi5_channel' => (int)($data['wifi5_channel'] ?? 0),
        'wifi5_auto_channel' => isset($data['wifi5_auto_channel']) ? (int)$data['wifi5_auto_channel'] : 0,
        'wifi5_standard' => $data['wifi5_standard'] ?? '',
        'wifi5_bssid' => $data['wifi5_bssid'] ?? '',
        'wifi5_clients_count' => (int)($data['wifi5_clients_count'] ?? 0),
        'wifi5_errors_sent' => (int)($data['wifi5_ErrorsSent'] ?? 0),
        'wifi5_errors_received' => (int)($data['wifi5_ErrorsReceived'] ?? 0),
        'wifi5_discard_sent' => (int)($data['wifi5_DiscardPacketsSent'] ?? 0),
        'wifi5_discard_received' => (int)($data['wifi5_DiscardPacketsReceived'] ?? 0),
        
        'wan_packets_sent' => (int)($data['wan_packets_sent'] ?? 0),
        'wan_packets_received' => (int)($data['wan_packets_received'] ?? 0),
        'wan_discard_sent' => (int)($data['wan_discard_sent'] ?? 0),
        'wan_discard_received' => (int)($data['wan_discard_received'] ?? 0),
        
        'clients_count' => (int)($data['lan_clients_count'] ?? 0)
    ];
    
    // Добавляем LAN клиентов (до 5)
    $clients = array_values($data['lan_clients'] ?? []);
    for ($j = 0; $j < 5; $j++) {
        $row["client_" . ($j+1) . "_ip"] = $clients[$j]['ip'] ?? '';
        $row["client_" . ($j+1) . "_mac"] = $clients[$j]['mac'] ?? '';
        $row["client_" . ($j+1) . "_hostname"] = $clients[$j]['hostname'] ?? '';
    }
    
    return $row;
}

// ==============================================
// Обработка пакета CPE (10 штук параллельно)
// ==============================================
function processCpeBatch($batch, &$successCount, &$errorCount, &$batchTimes) {
    $batchSize = count($batch);
    $multiHandles = [];
    $startTime = microtime(true);
    
    // Создаём мульти-хендл для всех CPE в пакете
    $mh = curl_multi_init();
    
    // Для каждого CPE в пакете создаём свой набор параллельных запросов
    foreach ($batch as $index => $cpe) {
        $cpeId = $cpe['cpe_id'];
        
        // Создаём 8 параллельных запросов для этого CPE
        $handles = createCpeRequestHandles($cpeId);
        
        foreach ($handles as $handleKey => $ch) {
            // Сохраняем метаданные, чтобы потом понять, к какому CPE и какому методу относится ответ
            $chKey = $index . '_' . $handleKey;
            $multiHandles[$chKey] = [
                'ch' => $ch,
                'cpe_index' => $index,
                'method' => $handleKey,
                'cpe_id' => $cpeId
            ];
            curl_multi_add_handle($mh, $ch);
        }
    }
    
    // Выполняем все запросы параллельно
    $running = null;
    $activeHandles = count($multiHandles);
    echo "   🔄 Запущено запросов: $activeHandles\n";
    
    do {
        $status = curl_multi_exec($mh, $running);
        if ($status > 0) {
            echo "   ⚠️ Ошибка мульти-запроса: $status\n";
        }
        curl_multi_select($mh, 5); // Ждем до 5 секунд
    } while ($running > 0);
    
    // Собираем результаты и статистику по ошибкам
    $responses = [];
    $timeoutCount = 0;
    $errorDetails = [];
    
    foreach ($multiHandles as $key => $data) {
        $response = curl_multi_getcontent($data['ch']);
        $httpCode = curl_getinfo($data['ch'], CURLINFO_HTTP_CODE);
        $curlError = curl_error($data['ch']);
        
        if ($curlError) {
            $errorDetails[] = "CPE {$data['cpe_id']} метод {$data['method']}: $curlError";
            if (strpos($curlError, 'timeout') !== false) {
                $timeoutCount++;
            }
        }
        
        if ($httpCode == 200 && $response) {
            $decoded = json_decode($response, true);
            if ($decoded) {
                $responses[$data['cpe_index']][$data['method']] = $decoded;
            }
        }
        
        curl_multi_remove_handle($mh, $data['ch']);
        curl_close($data['ch']);
    }
    curl_multi_close($mh);
    
    if (!empty($errorDetails)) {
        echo "   ⚠️ Проблемы с запросами:\n";
        foreach (array_slice($errorDetails, 0, 5) as $error) {
            echo "     • $error\n";
        }
        if (count($errorDetails) > 5) {
            echo "     • ... и еще " . (count($errorDetails) - 5) . " ошибок\n";
        }
        echo "   ⏱️  Таймаутов: $timeoutCount\n";
    }
    
    // Обрабатываем результаты для каждого CPE
    $batchSuccess = 0;
    $batchErrors = 0;
    
    foreach ($batch as $index => $cpe) {
        if (isset($responses[$index])) {
            $data = parseCpeResponses($responses[$index]);
            if ($data['success']) {
                $row = buildCpeRow($cpe, $data['data']);
                insertCpeData($row);
                $successCount++;
                $batchSuccess++;
                echo "   ✅ CPE {$cpe['cpe_id']} - успешно\n";
            } else {
                $errorCount++;
                $batchErrors++;
                echo "   ❌ CPE {$cpe['cpe_id']} - ошибка парсинга\n";
            }
        } else {
            $errorCount++;
            $batchErrors++;
            echo "   ❌ CPE {$cpe['cpe_id']} - нет ответа (возможно таймаут)\n";
        }
    }
    
    $batchTime = microtime(true) - $startTime;
    $batchTimes[] = $batchTime;
    
    echo "   ⏱️  Время обработки пакета: " . round($batchTime, 1) . " сек\n";
    echo "   📊 В пакете: ✅ $batchSuccess успешно, ❌ $batchErrors ошибок\n";
    
    return $batchTime;
}

// ==============================================
// ШАГ 3: Сохраняем детальные данные в tr069_detail (пакетная обработка)
// ==============================================
function saveDetailedData($onlineCpes) {
    $pdo = getDbConnection();
    
    // Очищаем детальную таблицу
    $pdo->exec("TRUNCATE TABLE tr069_detail");
    echo "✅ Таблица tr069_detail очищена\n";
    
    $total = count($onlineCpes);
    $batchSize = BATCH_SIZE;
    $successCount = 0;
    $errorCount = 0;
    $startTime = time();
    $batchTimes = [];
    
    echo "\n📊 Всего online оборудования для обработки: $total\n";
    echo "📦 Размер пакета: $batchSize\n";
    echo "⏱️  Пауза между пакетами: " . PAUSE_BETWEEN_BATCHES . " сек\n";
    echo "⏱️  Таймаут запросов: " . REQUEST_TIMEOUT . " сек\n";
    
    for ($i = 0; $i < $total; $i += $batchSize) {
        $batch = array_slice($onlineCpes, $i, $batchSize);
        $batchNum = floor($i / $batchSize) + 1;
        $totalBatches = ceil($total / $batchSize);
        
        echo "\n" . str_repeat("=", 70) . "\n";
        echo "📦 ПАКЕТ $batchNum из $totalBatches\n";
        echo "   CPE " . ($i+1) . "-" . min($i+$batchSize, $total) . " из $total\n";
        echo str_repeat("=", 70) . "\n";
        
        $batchTime = processCpeBatch($batch, $successCount, $errorCount, $batchTimes);
        
        // Прогресс - используем скользящее среднее для более точного прогноза
        $elapsed = time() - $startTime;
        $processed = min($i + $batchSize, $total);
        
        // Берем среднее время последних 3 пакетов (или всех, если меньше 3)
        $recentBatches = array_slice($batchTimes, -3);
        $avgBatchTime = array_sum($recentBatches) / count($recentBatches);
        $remainingBatches = $totalBatches - $batchNum;
        $estimatedRemainingSeconds = $remainingBatches * $avgBatchTime + 
                                    $remainingBatches * PAUSE_BETWEEN_BATCHES;
        
        $estimatedRemainingMinutes = round($estimatedRemainingSeconds / 60, 1);
        
        // Среднее время на одно оборудование
        $avgTimePerDevice = $elapsed / $processed;
        
        echo "\n📊 СТАТИСТИКА:\n";
        echo "   ✅ Успешно: $successCount\n";
        echo "   ❌ Ошибок: $errorCount\n";
        echo "   ⏱️  Прошло: " . round($elapsed / 60, 1) . " мин\n";
        echo "   ⏱️  Среднее время на пакет: " . round($avgBatchTime, 1) . " сек\n";
        echo "   ⏱️  Среднее время на CPE: " . round($avgTimePerDevice, 1) . " сек\n";
        echo "   ⏳ Осталось пакетов: $remainingBatches\n";
        echo "   ⏳ Ориентировочное время: ~{$estimatedRemainingMinutes} мин\n";
        
        // Пауза между пакетами (кроме последнего)
        if ($i + $batchSize < $total) {
            echo "\n⏸️  Пауза " . PAUSE_BETWEEN_BATCHES . " сек перед следующим пакетом...\n";
            sleep(PAUSE_BETWEEN_BATCHES);
        }
    }
    
    $totalTime = round((time() - $startTime) / 60, 1);
    echo "\n" . str_repeat("=", 70) . "\n";
    echo "🏁 ОБРАБОТКА ЗАВЕРШЕНА\n";
    echo str_repeat("=", 70) . "\n";
    echo "📊 ИТОГИ:\n";
    echo "   Всего обработано: $total\n";
    echo "   ✅ Успешно: $successCount\n";
    echo "   ❌ Ошибок: $errorCount\n";
    echo "   ⏱️  Общее время: $totalTime мин\n";
    echo "   ⏱️  Среднее время на пакет: " . round(array_sum($batchTimes) / count($batchTimes), 1) . " сек\n";
}

// ==============================================
// ОСНОВНОЙ ЦИКЛ
// ==============================================
echo "\n" . str_repeat("=", 80) . "\n";
echo "🚀 ЗАПУСК НОЧНОГО ЦИКЛА СБОРА ДАННЫХ\n";
echo "📦 Режим: пакетная обработка по " . BATCH_SIZE . " оборудований\n";
echo "📡 Сбор данных по 2.4GHz и 5GHz WiFi\n";
echo "⏱️  Таймаут запросов: " . REQUEST_TIMEOUT . " сек\n";
echo str_repeat("=", 80) . "\n";

$startCycle = time();

// ШАГ 1: Обновляем таблицу оборудования
$onlineCount = updateOborTable();

if ($onlineCount == 0) {
    die("❌ Нет online оборудования для опроса\n");
}

// ШАГ 2: Получаем ВСЕ online CPE из БД (без LIMIT)
$pdo = getDbConnection();
$stmt = $pdo->query("
    SELECT cpe_id, vendor, model, ip_address, last_seen 
    FROM tr069_obor 
    WHERE state = 0
    ORDER BY cpe_id
");
$onlineCpes = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\n📡 ШАГ 2: Начинаем опрос всех online CPE...\n";
echo "   Всего найдено online: " . count($onlineCpes) . "\n";

// ШАГ 3: Собираем детальные данные пакетами
saveDetailedData($onlineCpes);

$totalTime = round((time() - $startCycle) / 60, 1);
echo "\n" . str_repeat("=", 80) . "\n";
echo "🏁 ЦИКЛ ЗАВЕРШЁН за $totalTime минут\n";
echo str_repeat("=", 80) . "\n";

?>