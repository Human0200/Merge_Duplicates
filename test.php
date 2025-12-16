<?php
require_once 'SendBitrix.php';

$data = $_GET;
// file_put_contents('data.json', json_encode($data, JSON_PRETTY_PRINT));
$phoneNumber = $data['phone']; 

/**
 * Поиск дубликатов контактов по телефону
 */
function findDuplicateContacts($phone)
{
    $method = 'crm.duplicate.findbycomm';
    $params = [
        'entity_type' => 'CONTACT',
        'type' => 'PHONE',
        'values' => [$phone]
    ];

    $result = sendBitrixRequest($method, $params);

    if ($result && isset($result['result']['CONTACT'])) {
        return $result['result']['CONTACT'];
    }

    return [];
}

/**
 * Получение информации о контактах через batch-запрос
 */
function getContactsInfoBatch($contactIds)
{
    if (empty($contactIds)) {
        return [];
    }

    $cmd = [];
    foreach ($contactIds as $contactId) {
        $cmd["get_contact_{$contactId}"] = "crm.contact.get?id={$contactId}";
    }

    $params = [
        'halt' => 0,
        'cmd' => $cmd
    ];

    $result = sendBitrixRequest('batch', $params);

    $contacts = [];
    if ($result && isset($result['result']['result'])) {
        $batchResults = $result['result']['result'];
        
        foreach ($contactIds as $contactId) {
            $key = "get_contact_{$contactId}";
            if (isset($batchResults[$key]) && !empty($batchResults[$key])) {
                $contacts[$contactId] = $batchResults[$key];
            }
        }
    }

    return $contacts;
}

/**
 * Обновление контактов через batch-запрос
 */
function updateContactsBatch($updates)
{
    if (empty($updates)) {
        return [];
    }

    $cmd = [];
    foreach ($updates as $update) {
        $contactId = $update['ID'];
        $fields = $update['FIELDS'];
        
        // Кодируем параметры для URL
        $fieldsJson = urlencode(json_encode($fields));
        $cmd["update_contact_{$contactId}"] = "crm.contact.update?ID={$contactId}&FIELDS={$fieldsJson}";
    }

    $params = [
        'halt' => 0,
        'cmd' => $cmd
    ];

    $result = sendBitrixRequest('batch', $params);

    $results = [];
    if ($result && isset($result['result']['result'])) {
        $batchResults = $result['result']['result'];
        
        foreach ($updates as $update) {
            $contactId = $update['ID'];
            $key = "update_contact_{$contactId}";
            if (isset($batchResults[$key])) {
                $results[$contactId] = $batchResults[$key];
            }
        }
    }

    return $results;
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

    echo "Поиск контактов по телефону: $phoneNumber\n";

    // 1. Находим дубликаты контактов по телефону
    $contactIds = findDuplicateContacts($phoneNumber);

    if (empty($contactIds)) {
        echo "Контакты с таким номером телефона не найдены\n";
        return;
    }

    echo "Найдено контактов: " . count($contactIds) . "\n\n";

    $contacts = [];
    $assignedByWithUfCrm = null;
    $ufCrmcontactWithUfCrm = null;
    $contactWithUfCrm = null;
    $nameContactWithUfCrm = null;
    $secondNameContactWithUfCrm = null;
    $lastNameContactWithUfCrm = null;

    // 2. Получаем информацию о всех контактах через batch-запрос
    echo "Получаем информацию о контактах через batch-запрос...\n";
    $contactsInfo = getContactsInfoBatch($contactIds);
    
    if (empty($contactsInfo)) {
        echo "Не удалось получить информацию о контактах\n";
        return;
    }

    // 3. Анализируем полученные данные
    foreach ($contactsInfo as $contactId => $contactInfo) {
        echo "Контакте ID: $contactId\n";

        $assignedById = $contactInfo['ASSIGNED_BY_ID'] ?? null;
        $ufCrm123 = $contactInfo['UF_CRM_1765488342683'] ?? null;

        echo "  ASSIGNED_BY_ID: $assignedById\n";
        echo "  UF_CRM_1765488342683: " . ($ufCrm123 ? $ufCrm123 : 'пусто') . "\n\n";

        $contacts[$contactId] = [
            'ASSIGNED_BY_ID' => $assignedById,
            'UF_CRM_1765488342683' => $ufCrm123
        ];

        // Запоминаем контакт с заполненным UF_CRM_1765488342683
        if (!empty($ufCrm123) && $assignedByWithUfCrm === null) {
            $nameContactWithUfCrm = $contactInfo['NAME'];
            $secondNameContactWithUfCrm = $contactInfo['SECOND_NAME'];
            $lastNameContactWithUfCrm = $contactInfo['LAST_NAME'];
            $ufCrmcontactWithUfCrm = $ufCrm123;
            $assignedByWithUfCrm = $assignedById;
            $contactWithUfCrm = $contactId;
        }
    }

    // 4. Если найден контакт с заполненным UF_CRM_1765488342683
    if ($assignedByWithUfCrm !== null) {
        echo "Найден контакт с заполненным UF_CRM_1765488342683: ID $contactWithUfCrm\n";
        echo "ASSIGNED_BY_ID для обновления: $assignedByWithUfCrm\n\n";

        // 5. Формируем обновления для batch-запроса
        $updates = [];
        foreach ($contacts as $contactId => $contactData) {
            if (empty($contactData['UF_CRM_1765488342683']) && $contactData['ASSIGNED_BY_ID'] != $assignedByWithUfCrm) {
                echo "Добавляем контакт ID: $contactId для обновления\n";
                
                $updates[] = [
                    'ID' => $contactId,
                    'FIELDS' => [
                        'ASSIGNED_BY_ID' => $assignedByWithUfCrm,
                        'UF_CRM_1765488342683' => $ufCrmcontactWithUfCrm,
                        'NAME' => $nameContactWithUfCrm,
                        'SECOND_NAME' => $secondNameContactWithUfCrm,
                        'LAST_NAME' => $lastNameContactWithUfCrm
                    ]
                ];
            }
        }

        // 6. Выполняем batch-обновление
        if (!empty($updates)) {
            echo "\nВыполняем batch-обновление контактов...\n";
            $updateResults = updateContactsBatch($updates);
            
            $updatedCount = 0;
            foreach ($updateResults as $contactId => $result) {
                if ($result) {
                    echo "  Контакт ID $contactId успешно обновлен\n";
                    $updatedCount++;
                } else {
                    echo "  Ошибка при обновлении контакта ID $contactId\n";
                }
            }
            
            echo "\nИтого обновлено контактов: $updatedCount\n";
        } else {
            echo "Нет контактов для обновления\n";
        }

        // 7. Объединяем дубликаты
        $mergeResult = mergeContacts($contactIds, $contactWithUfCrm);

        if ($mergeResult && $mergeResult['STATUS'] == 'SUCCESS') {
            echo "Дубликаты успешно объединены\n";
        } else {
            print_r($mergeResult);
            print_r($contactWithUfCrm);
        }
    } else {
        echo "Не найден ни один контакт с заполненным UF_CRM_1765488342683\n";
        echo "Обновление не требуется\n";
    }
}

// Запускаем скрипт
main();