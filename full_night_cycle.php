// ==============================================
// Параллельное получение всех данных по одному CPE
// ==============================================
function getFullCpeDataParallel($cpeId) {
    $result = ['success' => false, 'data' => []];
    
    // Создаём массив запросов
    $handles = [];
    $easyHandles = [];
    
    $mh = curl_multi_init();
    
    // 1. Базовая информация (быстрый, 5 сек)
    $infoPayload = [
        'search_options' => ['cpe_id' => $cpeId],
        'parameters' => ['InternetGatewayDevice.DeviceInfo.'],
        'command_options' => ['async' => false, 'timeout' => 5, 'multiple' => false]
    ];
    $handles['info'] = createCurlHandle('GetParameterValues', $infoPayload, 7);
    curl_multi_add_handle($mh, $handles['info']);
    
    // 2. LAN статистика (10 сек)
    $lanPayload = [
        'search_options' => ['cpe_id' => $cpeId],
        'command_options' => ['async' => false, 'timeout' => 10, 'multiple' => false]
    ];
    $handles['lan'] = createCurlHandle('LanPortsStatistics', $lanPayload, 12);
    curl_multi_add_handle($mh, $handles['lan']);
    
    // 3. WiFi детали (10 сек)
    $handles['wifi'] = createCurlHandle('GetWifiDetails', $lanPayload, 12);
    curl_multi_add_handle($mh, $handles['wifi']);
    
    // 4. WLAN статистика (5 сек)
    $wlanStatsPayload = [
        'search_options' => ['cpe_id' => $cpeId],
        'parameters' => ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Stats.'],
        'command_options' => ['async' => false, 'timeout' => 5, 'multiple' => false]
    ];
    $handles['wlanStats'] = createCurlHandle('GetParameterValues', $wlanStatsPayload, 7);
    curl_multi_add_handle($mh, $handles['wlanStats']);
    
    // 5. WAN интерфейс (10 сек)
    $wanPayload = [
        'search_options' => ['cpe_id' => $cpeId],
        'command_options' => ['async' => false, 'timeout' => 10, 'multiple' => false],
        'parameters' => ['check_all_for_181' => false]
    ];
    $handles['wan'] = createCurlHandle('WanInterface', $wanPayload, 12);
    curl_multi_add_handle($mh, $handles['wan']);
    
    // 6. Max bit rate (5 сек)
    $wanCommonPayload = [
        'search_options' => ['cpe_id' => $cpeId],
        'parameters' => ['InternetGatewayDevice.WANDevice.1.WANCommonInterfaceConfig.'],
        'command_options' => ['async' => false, 'timeout' => 5, 'multiple' => false]
    ];
    $handles['wanCommon'] = createCurlHandle('GetParameterValues', $wanCommonPayload, 7);
    curl_multi_add_handle($mh, $handles['wanCommon']);
    
    // 7. Клиенты (5 сек)
    $clientsPayload = [
        'search_options' => ['cpe_id' => $cpeId],
        'parameters' => ['InternetGatewayDevice.LANDevice.1.Hosts.Host.'],
        'command_options' => ['async' => false, 'timeout' => 5, 'multiple' => false]
    ];
    $handles['clients'] = createCurlHandle('GetParameterValues', $clientsPayload, 7);
    curl_multi_add_handle($mh, $handles['clients']);
    
    // Запускаем все запросы параллельно
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh); // ждём активности
    } while ($running > 0);
    
    // Собираем результаты
    $responses = [];
    foreach ($handles as $key => $ch) {
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode == 200 && $response) {
            $decoded = json_decode($response, true);
            if ($decoded) {
                $responses[$key] = $decoded;
            }
        }
        
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    
    // Если ни один запрос не вернул данные — выходим
    if (empty($responses)) {
        return $result;
    }
    
    // Парсим результаты
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
    
    // WiFi статус
    if (isset($responses['wifi']['result']['details'])) {
        foreach ($responses['wifi']['result']['details'] as $wifiDetail) {
            if ($wifiDetail['key'] == 'wifi5') {
                $data['wifi5_status'] = $wifiDetail['value']['ssidStatus'] ?? 'Down';
            }
        }
    }
    
    // WLAN статистика
    if (isset($responses['wlanStats']['result']['details'])) {
        foreach ($responses['wlanStats']['result']['details'] as $item) {
            $key = str_replace('InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Stats.', '', $item['key']);
            $data['wlan_' . $key] = $item['value'];
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
    
    // Клиенты
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
        $data['clients'] = $hosts;
        $data['clients_count'] = count($hosts);
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