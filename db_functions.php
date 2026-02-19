<?php
require_once 'config_sql.php';

/**
 * Подключение к БД (синглтон)
 */
function getDbConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_SERVER . ";port=" . DB_PORT . ";dbname=" . DB_DATABASE . ";charset=" . DB_CHARSET,
                DB_USERNAME,
                DB_PASSWORD
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die("❌ Ошибка подключения к БД: " . $e->getMessage() . "\n");
        }
    }
    
    return $pdo;
}

/**
 * Очистить таблицу перед вставкой
 */
function truncateTable() {
    $pdo = getDbConnection();
    $pdo->exec("TRUNCATE TABLE tr069_detail");
    echo "✅ Таблица очищена\n";
}

/**
 * Вставить одну запись в БД
 */
function insertCpeData($data) {
    $pdo = getDbConnection();
    
    $sql = "INSERT INTO tr069_detail (
        cpe_id, vendor, model, sw_version, ip_address, last_seen,
        cpu_usage, max_bit_rate,
        lan_errors_sent, lan_errors_received, lan_discard_sent, lan_discard_received,
        wlan_errors_sent, wlan_errors_received, wlan_discard_sent, wlan_discard_received,
        wifi_5ghz,
        wan_packets_sent, wan_packets_received, wan_discard_sent, wan_discard_received,
        clients_count,
        client_1_ip, client_1_mac, client_1_hostname,
        client_2_ip, client_2_mac, client_2_hostname,
        client_3_ip, client_3_mac, client_3_hostname,
        client_4_ip, client_4_mac, client_4_hostname,
        client_5_ip, client_5_mac, client_5_hostname
    ) VALUES (
        :cpe_id, :vendor, :model, :sw_version, :ip_address, :last_seen,
        :cpu_usage, :max_bit_rate,
        :lan_errors_sent, :lan_errors_received, :lan_discard_sent, :lan_discard_received,
        :wlan_errors_sent, :wlan_errors_received, :wlan_discard_sent, :wlan_discard_received,
        :wifi_5ghz,
        :wan_packets_sent, :wan_packets_received, :wan_discard_sent, :wan_discard_received,
        :clients_count,
        :client_1_ip, :client_1_mac, :client_1_hostname,
        :client_2_ip, :client_2_mac, :client_2_hostname,
        :client_3_ip, :client_3_mac, :client_3_hostname,
        :client_4_ip, :client_4_mac, :client_4_hostname,
        :client_5_ip, :client_5_mac, :client_5_hostname
    )";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($data);
    
    return $pdo->lastInsertId();
}