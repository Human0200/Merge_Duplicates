<?php
require_once 'SendBitrix.php';

$data = $_GET;
file_put_contents('data.json', json_encode($data, JSON_PRETTY_PRINT | FILE_APPEND));
$phoneNumber = trim($data['phone']);
$emailAddress = trim($data['email']);

/**
 * Поиск дубликатов контактов по телефону
 */
function findDuplicateContacts($phone = '', $email = '')
{
    $method = 'crm.duplicate.findbycomm';
    $allDuplicates = [];
    
    // Очищаем значения
    $phone = preg_split('/\s*,\s*/', trim($phone), -1, PREG_SPLIT_NO_EMPTY);
    $email = preg_split('/\s*,\s*/', trim($email), -1, PREG_SPLIT_NO_EMPTY);
    file_put_contents('debug.txt', "Ищу дубликаты по телефону: " . json_encode($phone) . " и email: " . json_encode($email) . "\n", FILE_APPEND);
    
    // Ищем по телефону, если он указан
    if ($phone !== '') {
        $params = [
            'entity_type' => 'CONTACT',
            'type' => 'PHONE',
            'values' => $phone
        ];
        
        $result = sendBitrixRequest($method, $params);
        
        if ($result && isset($result['result']['CONTACT'])) {
            $allDuplicates = array_merge($allDuplicates, $result['result']['CONTACT']);
        }
    }
    
    // Ищем по email, если он указан
    if ($email !== '') {
        $params = [
            'entity_type' => 'CONTACT',
            'type' => 'EMAIL',
            'values' => $email
        ];
        
        $result = sendBitrixRequest($method, $params);
        
        if ($result && isset($result['result']['CONTACT'])) {
            $allDuplicates = array_merge($allDuplicates, $result['result']['CONTACT']);
        }
    }
    
    // Убираем дубликаты (на случай если контакт найден и по телефону и по email)
    $allDuplicates = array_unique($allDuplicates, SORT_REGULAR);
    
    file_put_contents('result.json', json_encode($allDuplicates, JSON_PRETTY_PRINT));
    
    return $allDuplicates;
}

/**
 * Получение информации о нескольких контактах через batch
 */
function getContactsInfoBatch($contactIds)
{
    if (empty($contactIds)) {
        return [];
    }

    $method = 'batch';
    $cmd = [];

    // Формируем команды для batch-запроса
    foreach ($contactIds as $contactId) {
        $cmd["contact_$contactId"] = "crm.contact.get?id=$contactId";
    }

    $params = [
        'halt' => 0,
        'cmd' => $cmd
    ];

    $result = sendBitrixRequest($method, $params);

    if ($result && isset($result['result']['result'])) {
        return $result['result']['result'];
    }

    return [];
}

/**
 * Обновление нескольких контактов через batch
 */
function updateContactsBatch($updates)
{
    if (empty($updates)) {
        return true;
    }

    $method = 'batch';
    $cmd = [];

    // Формируем команды для batch-запроса
    foreach ($updates as $index => $update) {
        $contactId = $update['ID'];
        $fields = $update['FIELDS'];

        // Формируем массив параметров для каждой команды
        $cmdParams = [
            'ID' => $contactId,
            'FIELDS' => $fields
        ];

        // Преобразуем параметры в строку запроса
        $queryString = http_build_query($cmdParams);
        $cmd["update_$index"] = "crm.contact.update?$queryString";
    }

    $params = [
        'halt' => 0,
        'cmd' => $cmd
    ];

    $result = sendBitrixRequest($method, $params);

    if ($result && isset($result['result']['result'])) {
        return $result['result']['result'];
    }

    return false;
}

/**
 * Объединение дубликатов
 */
function mergeContacts($contactIds, $firstvalue)
{
    if (empty($contactIds)) {
        return false;
    }

    array_unshift($contactIds, $firstvalue);

    $method = 'crm.entity.mergeBatch';
    $params = [
        "params" => [
            "entityTypeId" => 3,
            "entityIds" => $contactIds
        ]
    ];

    $result = sendBitrixRequest($method, $params);

    if ($result && isset($result['result'])) {
        return $result['result'];
    }

    return false;
}

/**
 * Основная логика скрипта
 */
function main()
{
    global $phoneNumber;
    global $emailAddress;

    echo "Поиск контактов по телефону: $phoneNumber\n";

    // 1. Находим дубликаты контактов по телефону
    $contactIds = findDuplicateContacts($phoneNumber, $emailAddress);

    if (empty($contactIds)) {
        echo "Контакты с таким номером телефона не найдены\n";
        return;
    }

    echo "Найдено контактов: " . count($contactIds) . "\n\n";

    // 2. Получаем информацию о всех контактах одним batch-запросом
    echo "Получаем информацию о всех контактах через batch...\n";
    $contactsInfoBatch = getContactsInfoBatch($contactIds);

    if (empty($contactsInfoBatch)) {
        echo "Не удалось получить информацию о контактах\n";
        return;
    }

    $contacts = [];
    $contactsWithFilledField = []; // Контакты с заполненным полем
    $contactsWithEmptyField = [];  // Контакты с пустым полем

    // 3. Обрабатываем полученные данные и разделяем на группы
    foreach ($contactIds as $contactId) {
        $contactKey = "contact_$contactId";

        if (!isset($contactsInfoBatch[$contactKey])) {
            echo "  Не удалось получить информацию о контакте ID: $contactId\n";
            continue;
        }

        $contactInfo = $contactsInfoBatch[$contactKey];

        $assignedById = $contactInfo['ASSIGNED_BY_ID'] ?? null;
        $ufCrm123 = $contactInfo['UF_CRM_1765488342683'] ?? null;

        echo "Контакт ID: $contactId\n";
        echo "  ASSIGNED_BY_ID: $assignedById\n";
        echo "  UF_CRM_1765488342683: " . ($ufCrm123 ? $ufCrm123 : 'пусто') . "\n\n";

        $contacts[$contactId] = [
            'ASSIGNED_BY_ID' => $assignedById,
            'UF_CRM_1765488342683' => $ufCrm123,
            'NAME' => $contactInfo['NAME'],
            'SECOND_NAME' => $contactInfo['SECOND_NAME'],
            'LAST_NAME' => $contactInfo['LAST_NAME']
        ];

        // Разделяем контакты на группы
        if (!empty($ufCrm123)) {
            $contactsWithFilledField[] = $contactId;
        } else {
            $contactsWithEmptyField[] = $contactId;
        }
    }

    echo "\n=== АНАЛИЗ СИТУАЦИИ ===\n";
    echo "Контактов с заполненным UF_CRM_1765488342683: " . count($contactsWithFilledField) . "\n";
    echo "Контактов с пустым UF_CRM_1765488342683: " . count($contactsWithEmptyField) . "\n\n";

    // 4. СЦЕНАРИЙ 1: Несколько контактов с заполненным полем = КОНФЛИКТ
    if (count($contactsWithFilledField) > 1) {
        echo "⚠️ ОБНАРУЖЕН КОНФЛИКТ: Найдено " . count($contactsWithFilledField) . " контактов с заполненным полем!\n";
        echo "Все эти контакты будут помечены как 'дубль' и НЕ будут объединены.\n\n";

        $updates = [];

        // Помечаем ВСЕ контакты с заполненным полем как дубли
        foreach ($contactIds as $contactId) {
            echo "Помечаем контакт ID $contactId как 'дубль'\n";
            $updates[] = [
                'ID' => $contactId,
                'FIELDS' => [
                    'UF_CRM_1765894935249' => 'дубль'
                ]
            ];
        }

        // Выполняем batch-обновление
        if (!empty($updates)) {
            echo "\nВыполняем batch-обновление " . count($updates) . " контактов...\n";
            $updateResult = updateContactsBatch($updates);

            if ($updateResult) {
                echo "✅ Все конфликтные контакты помечены как 'дубль'\n";
            } else {
                echo "❌ Ошибка при пометке контактов\n";
            }
        }

        echo "\n⛔ ОБЪЕДИНЕНИЕ НЕ ВЫПОЛНЯЕТСЯ из-за конфликта!\n";
        echo "Необходимо вручную разобраться с дублями.\n";
        return;
    }

    // 5. СЦЕНАРИЙ 2: Один контакт с заполненным полем = СТАНДАРТНЫЙ СЛУЧАЙ
    if (count($contactsWithFilledField) === 1) {
        $contactWithUfCrm = $contactsWithFilledField[0];
        $mainContact = $contacts[$contactWithUfCrm];

        echo "✅ Найден ОСНОВНОЙ контакт с заполненным UF_CRM_1765488342683: ID $contactWithUfCrm\n";
        echo "ASSIGNED_BY_ID для обновления: " . $mainContact['ASSIGNED_BY_ID'] . "\n\n";

        $updates = [];

        // Обновляем только контакты с ПУСТЫМ полем
        foreach ($contactsWithEmptyField as $contactId) {
            echo "Готовим обновление для контакта ID: $contactId\n";

            $updates[] = [
                'ID' => $contactId,
                'FIELDS' => [
                    'ASSIGNED_BY_ID' => $mainContact['ASSIGNED_BY_ID'],
                    'UF_CRM_1765488342683' => $mainContact['UF_CRM_1765488342683'],
                    'NAME' => $mainContact['NAME'],
                    'SECOND_NAME' => $mainContact['SECOND_NAME'],
                    'LAST_NAME' => $mainContact['LAST_NAME']
                ]
            ];
        }

        // Выполняем batch-обновление
        if (!empty($updates)) {
            echo "\nВыполняем batch-обновление " . count($updates) . " контактов...\n";
            $updateResult = updateContactsBatch($updates);

            if ($updateResult) {
                echo "✅ Контакты успешно обновлены\n";
            } else {
                echo "❌ Ошибка при обновлении контактов\n";
            }
        }

        // Объединяем дубликаты
        echo "\nОбъединяем дубликаты...\n";
        $mergeResult = mergeContacts($contactIds, $contactWithUfCrm);
        file_put_contents('error.txt', "Результат объединения дубликатов: " . json_encode($mergeResult) . "\n", FILE_APPEND);

        if (isset($mergeResult['STATUS']) && $mergeResult['STATUS'] == 'SUCCESS') {
            echo "✅ Дубликаты успешно объединены\n";
        } else {
            echo "❌ Ошибка при объединении дубликатов\n";
            print_r($mergeResult);
        }
        return;
    }

    // 6. СЦЕНАРИЙ 3: Нет контактов с заполненным полем
    echo "ℹ️ Не найден ни один контакт с заполненным UF_CRM_1765488342683\n";
    echo "Обновление и объединение не требуется\n";
}

// Запускаем скрипт
main();
