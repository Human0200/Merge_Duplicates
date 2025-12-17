<?php
require_once 'SendBitrix.php';

$data = $_GET;
file_put_contents('data.json', json_encode($data, JSON_PRETTY_PRINT | FILE_APPEND));
$phoneNumber = trim($data['phone']);
$emailAddress = trim($data['email']);

/**
 * Поиск дубликатов контактов по телефону и email
 */
function findDuplicateContacts($phone = '', $email = '')
{
    $method = 'crm.duplicate.findbycomm';
    $allDuplicates = [];
    
    $phone = preg_split('/\s*,\s*/', trim($phone), -1, PREG_SPLIT_NO_EMPTY);
    $email = preg_split('/\s*,\s*/', trim($email), -1, PREG_SPLIT_NO_EMPTY);
    file_put_contents('debug.txt', "Ищу дубликаты по телефону: " . json_encode($phone) . " и email: " . json_encode($email) . "\n", FILE_APPEND);
    
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
 * Получение реквизитов контакта
 */
function getContactRequisites($contactId)
{
    $method = 'crm.requisite.list';
    $params = [
        'filter' => [
            'ENTITY_TYPE_ID' => 3, // 3 = контакт
            'ENTITY_ID' => $contactId
        ]
    ];

    $result = sendBitrixRequest($method, $params);

    if ($result && isset($result['result'])) {
        return $result['result'];
    }

    return [];
}

/**
 * Получение адресов реквизита
 */
function getRequisiteAddresses($requisiteId)
{
    $method = 'crm.address.list';
    $params = [
        'filter' => [
            'ENTITY_TYPE_ID' => 8, // 8 = реквизит
            'ENTITY_ID' => $requisiteId
        ]
    ];

    $result = sendBitrixRequest($method, $params);

    if ($result && isset($result['result'])) {
        return $result['result'];
    }

    return [];
}

/**
 * Добавление реквизита контакту
 */
function addRequisiteToContact($contactId, $requisiteData)
{
    $method = 'crm.requisite.add';
    
    $fields = [
        'ENTITY_TYPE_ID' => 3,
        'ENTITY_ID' => $contactId,
        'PRESET_ID' => $requisiteData['PRESET_ID'] ?? 1,
        'NAME' => $requisiteData['NAME'] ?? '',
        'RQ_INN' => $requisiteData['RQ_INN'] ?? '',
        'RQ_KPP' => $requisiteData['RQ_KPP'] ?? '',
        'RQ_OGRN' => $requisiteData['RQ_OGRN'] ?? '',
        'RQ_OGRNIP' => $requisiteData['RQ_OGRNIP'] ?? '',
        'RQ_OKPO' => $requisiteData['RQ_OKPO'] ?? '',
        'RQ_OKTMO' => $requisiteData['RQ_OKTMO'] ?? '',
        'RQ_BANK_NAME' => $requisiteData['RQ_BANK_NAME'] ?? '',
        'RQ_BIK' => $requisiteData['RQ_BIK'] ?? '',
        'RQ_ACC_NUM' => $requisiteData['RQ_ACC_NUM'] ?? '',
        'RQ_COR_ACC_NUM' => $requisiteData['RQ_COR_ACC_NUM'] ?? ''
    ];

    // Убираем пустые поля
    $fields = array_filter($fields, function($value) {
        return $value !== '';
    });

    $params = ['fields' => $fields];

    $result = sendBitrixRequest($method, $params);

    if ($result && isset($result['result'])) {
        return $result['result'];
    }

    return false;
}

/**
 * Добавление адреса к реквизиту
 */
function addAddressToRequisite($requisiteId, $addressData)
{
    $method = 'crm.address.add';
    
    $fields = [
        'TYPE_ID' => $addressData['TYPE_ID'] ?? 1,
        'ENTITY_TYPE_ID' => 8,
        'ENTITY_ID' => $requisiteId,
        'ADDRESS_1' => $addressData['ADDRESS_1'] ?? '',
        'ADDRESS_2' => $addressData['ADDRESS_2'] ?? '',
        'CITY' => $addressData['CITY'] ?? '',
        'POSTAL_CODE' => $addressData['POSTAL_CODE'] ?? '',
        'REGION' => $addressData['REGION'] ?? '',
        'PROVINCE' => $addressData['PROVINCE'] ?? '',
        'COUNTRY' => $addressData['COUNTRY'] ?? '',
        'COUNTRY_CODE' => $addressData['COUNTRY_CODE'] ?? ''
    ];

    $params = ['fields' => $fields];

    $result = sendBitrixRequest($method, $params);

    if ($result && isset($result['result'])) {
        return $result['result'];
    }

    return false;
}

/**
 * Перенос всех реквизитов с других контактов на главный
 */
function transferRequisitesToMainContact($mainContactId, $otherContactIds)
{
    echo "\n=== ПЕРЕНОС РЕКВИЗИТОВ ===\n";
    
    $transferredCount = 0;
    
    foreach ($otherContactIds as $contactId) {
        echo "Проверяем реквизиты контакта ID: $contactId\n";
        
        // Получаем реквизиты контакта
        $requisites = getContactRequisites($contactId);
        
        if (empty($requisites)) {
            echo "  У контакта нет реквизитов\n";
            continue;
        }
        
        echo "  Найдено реквизитов: " . count($requisites) . "\n";
        
        foreach ($requisites as $requisite) {
            echo "  Переносим реквизит ID: {$requisite['ID']}\n";
            
            // Добавляем реквизит главному контакту
            $newRequisiteId = addRequisiteToContact($mainContactId, $requisite);
            
            if ($newRequisiteId) {
                echo "    ✅ Реквизит успешно добавлен главному контакту (новый ID: $newRequisiteId)\n";
                $transferredCount++;
                
                // Переносим адреса этого реквизита
                $addresses = getRequisiteAddresses($requisite['ID']);
                
                if (!empty($addresses)) {
                    echo "    Найдено адресов: " . count($addresses) . "\n";
                    
                    foreach ($addresses as $address) {
                        $newAddressId = addAddressToRequisite($newRequisiteId, $address);
                        
                        if ($newAddressId) {
                            echo "      ✅ Адрес успешно добавлен (новый ID: $newAddressId)\n";
                        } else {
                            echo "      ❌ Ошибка добавления адреса\n";
                        }
                    }
                }
            } else {
                echo "    ❌ Ошибка добавления реквизита\n";
            }
        }
    }
    
    echo "\nВсего перенесено реквизитов: $transferredCount\n\n";
    
    return $transferredCount;
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

    foreach ($updates as $index => $update) {
        $contactId = $update['ID'];
        $fields = $update['FIELDS'];

        $cmdParams = [
            'ID' => $contactId,
            'FIELDS' => $fields
        ];

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

    // 1. Находим дубликаты
    $contactIds = findDuplicateContacts($phoneNumber, $emailAddress);

    if (empty($contactIds)) {
        echo "Контакты с таким номером телефона не найдены\n";
        return;
    }

    echo "Найдено контактов: " . count($contactIds) . "\n\n";

    // 2. Получаем информацию о всех контактах
    echo "Получаем информацию о всех контактах через batch...\n";
    $contactsInfoBatch = getContactsInfoBatch($contactIds);

    if (empty($contactsInfoBatch)) {
        echo "Не удалось получить информацию о контактах\n";
        return;
    }

    $contacts = [];
    $contactsWithFilledField = [];
    $contactsWithEmptyField = [];

    // 3. Обрабатываем данные
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

        if (!empty($ufCrm123)) {
            $contactsWithFilledField[] = $contactId;
        } else {
            $contactsWithEmptyField[] = $contactId;
        }
    }

    echo "\n=== АНАЛИЗ СИТУАЦИИ ===\n";
    echo "Контактов с заполненным UF_CRM_1765488342683: " . count($contactsWithFilledField) . "\n";
    echo "Контактов с пустым UF_CRM_1765488342683: " . count($contactsWithEmptyField) . "\n\n";

    // 4. СЦЕНАРИЙ 1: Конфликт
    if (count($contactsWithFilledField) > 1) {
        echo "⚠️ ОБНАРУЖЕН КОНФЛИКТ: Найдено " . count($contactsWithFilledField) . " контактов с заполненным полем!\n";
        echo "Все эти контакты будут помечены как 'дубль' и НЕ будут объединены.\n\n";

        $updates = [];

        foreach ($contactIds as $contactId) {
            echo "Помечаем контакт ID $contactId как 'дубль'\n";
            $updates[] = [
                'ID' => $contactId,
                'FIELDS' => [
                    'UF_CRM_1765894935249' => 'дубль'
                ]
            ];
        }

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
        return;
    }

    // 5. СЦЕНАРИЙ 2: Стандартный случай
    if (count($contactsWithFilledField) === 1) {
        $contactWithUfCrm = $contactsWithFilledField[0];
        $mainContact = $contacts[$contactWithUfCrm];

        echo "✅ Найден ОСНОВНОЙ контакт с заполненным UF_CRM_1765488342683: ID $contactWithUfCrm\n";
        echo "ASSIGNED_BY_ID для обновления: " . $mainContact['ASSIGNED_BY_ID'] . "\n\n";

        // ВАЖНО: Сначала переносим реквизиты ДО обновления полей
        echo "Шаг 1: Переносим реквизиты с других контактов на главный\n";
        transferRequisitesToMainContact($contactWithUfCrm, $contactsWithEmptyField);

        // Затем обновляем основные поля
        echo "Шаг 2: Обновляем основные поля контактов\n";
        $updates = [];

        foreach ($contactsWithEmptyField as $contactId) {
            echo "Готовим обновление для контакта ID: $contactId\n";

            $updates[] = [
                'ID' => $contactId,
                'FIELDS' => [
                    'ASSIGNED_BY_ID' => $mainContact['ASSIGNED_BY_ID'],
                    'UF_CRM_1765488342683' => $mainContact['UF_CRM_1765488342683'],
                    'NAME' => $mainContact['NAME'],
                    'SECOND_NAME' => $mainContact['SECOND_NAME'],
                    'LAST_NAME' => $mainContact['LAST_NAME'],
                    'TYPE_ID' => $mainContact['TYPE_ID'],

                ]
            ];
        }

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
        echo "\nШаг 3: Объединяем дубликаты...\n";
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