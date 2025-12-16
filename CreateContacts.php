<?php
require_once 'SendBitrix.php'; // Файл с исправленной функцией sendBitrixRequest

$data = $_GET;
$phoneNumber = $data['phone'] ?? '79123456789'; // номер для дублирования
$count = $data['count'] ?? 5; // количество дубликатов
$assignedByIds = [1, 2, 3]; // разные ответственные
$ufCrmValues = [
    'Первое значение UF_CRM',
    'Второе значение UF_CRM', 
    'Третье значение UF_CRM',
    'Четвертое значение UF_CRM',
    'Пятое значение UF_CRM',
    '', // пустое значение
    null // null значение
];

/**
 * Создание контактов через batch-запрос (исправленная версия)
 */
function createContactsBatch($contactsData)
{
    if (empty($contactsData)) {
        echo "Нет данных для создания контактов\n";
        return [];
    }

    $cmd = [];
    foreach ($contactsData as $index => $contact) {
        // Правильно формируем строку запроса
        $fieldsJson = urlencode(json_encode($contact['fields'], JSON_UNESCAPED_UNICODE));
        $paramsJson = urlencode(json_encode(['REGISTER_SONET_EVENT' => 'N']));
        
        $cmd["add_contact_{$index}"] = "crm.contact.add?fields={$fieldsJson}&params={$paramsJson}";
    }

    $params = [
        'halt' => 0,
        'cmd' => $cmd
    ];

    echo "Отправляю batch-запрос на создание " . count($contactsData) . " контактов...\n";
    
    $result = sendBitrixRequest('batch', $params);
    
    if (!$result) {
        echo "Ошибка при выполнении batch-запроса\n";
        return [];
    }
    
    echo "Batch-запрос выполнен, анализирую ответ...\n";
    
    $createdContacts = [];
    if (isset($result['result']['result'])) {
        $batchResults = $result['result']['result'];
        
        foreach ($contactsData as $index => $contact) {
            $key = "add_contact_{$index}";
            if (isset($batchResults[$key]) && !empty($batchResults[$key])) {
                echo "  Создан контакт ID: {$batchResults[$key]}\n";
                $createdContacts[] = [
                    'ID' => $batchResults[$key],
                    'NAME' => $contact['name'],
                    'ASSIGNED_BY_ID' => $contact['fields']['ASSIGNED_BY_ID'],
                    'UF_CRM' => $contact['fields']['UF_CRM_1765488342683'] ?? ''
                ];
            } else {
                echo "  Ошибка создания контакта {$index}\n";
                if (isset($batchResults[$key])) {
                    print_r($batchResults[$key]);
                }
            }
        }
    } else {
        echo "Некорректный ответ от batch API\n";
        print_r($result);
    }

    return $createdContacts;
}

/**
 * Основная логика создания дубликатов через batch
 */
function main()
{
    global $phoneNumber, $count, $assignedByIds, $ufCrmValues;
    
    echo "Создание дублирующихся контактов через batch\n";
    echo "Телефон: $phoneNumber\n";
    echo "Количество: $count\n\n";
    
    $faker = [
        'firstNames' => ['Иван', 'Петр', 'Сергей', 'Алексей', 'Дмитрий', 'Михаил', 'Андрей', 'Александр', 'Николай', 'Владимир'],
        'lastNames' => ['Иванов', 'Петров', 'Сидоров', 'Смирнов', 'Кузнецов', 'Попов', 'Лебедев', 'Козлов', 'Новиков', 'Морозов']
    ];
    
    $contactsData = [];
    
    // Подготавливаем данные для batch-запроса
    for ($i = 1; $i <= $count; $i++) {
        $firstName = $faker['firstNames'][array_rand($faker['firstNames'])];
        $lastName = $faker['lastNames'][array_rand($faker['lastNames'])];
        $assignedById = $assignedByIds[array_rand($assignedByIds)];
        $ufCrmValue = $ufCrmValues[array_rand($ufCrmValues)];
        
        $contactName = "{$firstName} {$lastName} #{$i}";
        
        echo "Подготавливаю контакт {$i}/{$count}: {$contactName}\n";
        echo "  Ответственный: {$assignedById}\n";
        echo "  UF_CRM: " . ($ufCrmValue ? $ufCrmValue : 'пусто') . "\n\n";
        
        $fields = [
            'NAME' => $firstName . ' #' . $i,
            'LAST_NAME' => $lastName,
            'ASSIGNED_BY_ID' => $assignedById,
            'PHONE' => [
                [
                    'VALUE' => $phoneNumber,
                    'VALUE_TYPE' => 'WORK'
                ]
            ]
        ];
        
        // Добавляем UF_CRM только если значение не пустое
        if (!empty($ufCrmValue)) {
            $fields['UF_CRM_1765488342683'] = $ufCrmValue;
        }
        
        $contactsData[] = [
            'name' => $contactName,
            'fields' => $fields
        ];
    }
    
    // Создаем контакты одним batch-запросом
    echo "Выполняю batch-запрос на создание контактов...\n";
    $createdContacts = createContactsBatch($contactsData);
    
    if (empty($createdContacts)) {
        echo "Не удалось создать контакты. Пробуем создать по одному...\n";
        
        // Альтернатива: создание контактов по одному
        foreach ($contactsData as $contact) {
            echo "Создаю контакт: {$contact['name']}\n";
            
            $method = 'crm.contact.add';
            $params = [
                'fields' => $contact['fields'],
                'params' => ['REGISTER_SONET_EVENT' => 'N']
            ];
            
            $result = sendBitrixRequest($method, $params);
            
            if ($result && isset($result['result'])) {
                $createdContacts[] = [
                    'ID' => $result['result'],
                    'NAME' => $contact['name'],
                    'ASSIGNED_BY_ID' => $contact['fields']['ASSIGNED_BY_ID'],
                    'UF_CRM' => $contact['fields']['UF_CRM_1765488342683'] ?? ''
                ];
                echo "  ✓ Создан контакт ID: {$result['result']}\n\n";
            } else {
                echo "  ✗ Ошибка создания контакта\n\n";
            }
            
            sleep(1); // Пауза между запросами
        }
    }
    
    // Выводим итоги
    echo "========== ИТОГИ ==========\n";
    echo "Всего создано контактов: " . count($createdContacts) . "\n";
    echo "С одним телефоном: {$phoneNumber}\n\n";
    
    if (!empty($createdContacts)) {
        echo "Список созданных контактов:\n";
        echo str_repeat('-', 60) . "\n";
        echo sprintf("%-5s | %-20s | %-10s | %-20s\n", 'ID', 'Имя', 'Ответств.', 'UF_CRM');
        echo str_repeat('-', 60) . "\n";
        
        foreach ($createdContacts as $contact) {
            $ufCrmDisplay = $contact['UF_CRM'] ? (strlen($contact['UF_CRM']) > 20 ? substr($contact['UF_CRM'], 0, 17) . '...' : $contact['UF_CRM']) : 'пусто';
            echo sprintf("%-5s | %-20s | %-10s | %-20s\n", 
                $contact['ID'], 
                substr($contact['NAME'], 0, 20),
                $contact['ASSIGNED_BY_ID'],
                $ufCrmDisplay
            );
        }
        
        echo str_repeat('-', 60) . "\n\n";
        
        // Сохраняем список созданных контактов в файл
        $filename = 'created_contacts_' . date('Y-m-d_H-i-s') . '.json';
        file_put_contents($filename, 
            json_encode([
                'phone' => $phoneNumber,
                'contacts' => $createdContacts,
                'created_at' => date('Y-m-d H:i:s')
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        
        echo "Список контактов сохранен в файл: {$filename}\n";
        echo "Теперь можно протестировать основной скрипт с телефоном: {$phoneNumber}\n";
    } else {
        echo "Не удалось создать ни одного контакта\n";
    }
}

// Запускаем скрипт создания
main();