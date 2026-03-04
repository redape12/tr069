https://chat.deepseek.com/a/chat/s/2a2fcc2e-f718-47c5-ad92-e5b844847c9d

# DMS TR-069 Parser

Скрипты для сбора статистики с абонентских устройств (CPE) через TR-069 API системы DMS.

## 📋 Описание

Проект автоматизирует ночной сбор данных:
- Инвентаризация всех CPE (модель, версия ПО, IP, статус)
- Детальные метрики по онлайн-устройствам:
  - Ошибки LAN (errors/discards по портам)
  - Ошибки Wi-Fi
  - Статус 5 ГГц
  - WAN-трафик (пакеты, discards)
  - Загрузка CPU
  - Максимальная скорость
  - Список подключённых клиентов (IP, MAC, hostname)

Данные сохраняются в MySQL и могут использоваться для мониторинга, аналитики и превентивного выявления проблем.

## 🚀 Быстрый старт

### Требования
- PHP 7.4+
- MySQL 5.7+
- Доступ к DMS API

### Установка
```bash
git clone https://github.com/your-repo/dms-tr069-parser.git
cd dms-tr069-parser
Настройка
Скопируйте файлы конфигурации:

bash
cp config.php.example config.php
cp config_sql.php.example config_sql.php
Отредактируйте config.php:

php
define('DMS_URL', 'https://your-dms-server/v1/method/');
define('DMS_LOGIN', 'your_login');
define('DMS_PASSWORD', 'your_password');
Отредактируйте config_sql.php:

php
define('DB_SERVER', '10.10.54.24');
define('DB_USERNAME', 'myuser');
define('DB_PASSWORD', '111111');
define('DB_DATABASE', 'AlexeyReport');
define('DB_PORT', 3306);
Создайте таблицы в БД:

bash
mysql -h your_host -u your_user -p your_db < create_obor_table.sql
mysql -h your_host -u your_user -p your_db < create_detail_table.sql
Запуск
bash
php full_night_cycle.php
Для автоматизации добавьте в планировщик (cron / Windows Scheduler):

bash
# Ежедневно в 3:00
0 3 * * * /usr/bin/php /path/to/full_night_cycle.php
📊 Структура БД
tr069_obor
Инвентаризация всех CPE:

cpe_id — уникальный идентификатор

vendor / model / sw_version

ip_address / mac

state — 0 (online), 5 (offline)

last_seen / first_seen

tr069_detail
Детальные метрики по онлайн-устройствам:

Все поля из tr069_obor

lan_errors_sent / lan_errors_received

lan_discard_sent / lan_discard_received

wlan_errors_sent / wlan_errors_received

wlan_discard_sent / wlan_discard_received

wifi_5ghz (Yes/No)

wan_packets_sent / wan_packets_received

wan_discard_sent / wan_discard_received

cpu_usage / max_bit_rate

clients_count

client_1_ip, client_1_mac, client_1_hostname ... (до 5 клиентов)

⚙️ Используемые методы API
Метод	Назначение
GetListOfCPEs	Получение списка всех CPE
GetParameterValues	CPU, модель, версия, клиенты, Wi-Fi stats, WAN common
LanPortsStatistics	Ошибки на LAN-портах
GetWifiDetails	Статус 5 ГГц

WanInterface	WAN-трафик и ошибки
