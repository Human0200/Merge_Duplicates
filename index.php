<?php
require_once 'SendBitrix.php';

$data = $_GET;
file_put_contents('data.json', json_encode($data, JSON_PRETTY_PRINT | FILE_APPEND));
$phoneNumber = trim($data['phone']); //$data['phone']; 

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
    file_put_contents('result.json', json_encode($result, JSON_PRETTY_PRINT));
    if ($result && isset($result['result']['CONTACT'])) {
        return $result['result']['CONTACT'];
    }

    return [];
}

/**
 * Получение информации о контакте
 */
function getContactInfo($contactId)
{
    $method = 'crm.contact.get';
    $params = [
        'id' => $contactId
    ];

    $result = sendBitrixRequest($method, $params);

    if ($result && isset($result['result'])) {
        return $result['result'];
    }

    return false;
}

/**
 * Обновление контакта
 */
function updateContact($contactId, $fields)
{
    $method = 'crm.contact.update';
    $params = array_merge(['ID' => $contactId], $fields);

    $result = sendBitrixRequest($method, $params);

    if ($result && isset($result['result'])) {
        return $result['result'];
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


    // 2. Получаем информацию о каждом контакте
    foreach ($contactIds as $contactId) {
        echo "Получаем информацию о контакте ID: $contactId\n";

        $contactInfo = getContactInfo($contactId);

        if (!$contactInfo) {
            echo "  Не удалось получить информацию о контакте\n";
            continue;
        }

        $assignedById = $contactInfo['ASSIGNED_BY_ID'] ?? null;
        $ufCrm123 = $contactInfo['UF_CRM_1765885674704'] ?? null;

        echo "  ASSIGNED_BY_ID: $assignedById\n";
        echo "  UF_CRM_1765885674704: " . ($ufCrm123 ? $ufCrm123 : 'пусто') . "\n\n";

        $contacts[$contactId] = [
            'ASSIGNED_BY_ID' => $assignedById,
            'UF_CRM_1765885674704' => $ufCrm123
        ];

        // Запоминаем контакт с заполненным UF_CRM_1765885674704
        if (!empty($ufCrm123) && $assignedByWithUfCrm === null) {
            $nameContactWithUfCrm = $contactInfo['NAME'];
            $secondNameContactWithUfCrm = $contactInfo['SECOND_NAME'];
            $lastNameContactWithUfCrm = $contactInfo['LAST_NAME'];
            $ufCrmcontactWithUfCrm = $ufCrm123;
            $assignedByWithUfCrm = $assignedById;
            $contactWithUfCrm = $contactId;
        }
    }

    // 3. Если найден контакт с заполненным UF_CRM_1765885674704
    if ($assignedByWithUfCrm !== null) {
        echo "Найден контакт с заполненным UF_CRM_1765885674704: ID $contactWithUfCrm\n";
        echo "ASSIGNED_BY_ID для обновления: $assignedByWithUfCrm\n\n";

        // 4. Обновляем контакты с пустым UF_CRM_1765885674704
        $updatedCount = 0;
        foreach ($contacts as $contactId => $contactData) {
            if (empty($contactData['UF_CRM_1765885674704']) && $contactData['ASSIGNED_BY_ID'] != $assignedByWithUfCrm) {
                echo "Обновляем контакт ID: $contactId\n";

                $updateResult = updateContact($contactId, [
                    'FIELDS' => [
                        'ASSIGNED_BY_ID' => $assignedByWithUfCrm,
                        'UF_CRM_1765885674704' => $ufCrmcontactWithUfCrm,
                        'NAME' => $nameContactWithUfCrm,
                        'SECOND_NAME' => $secondNameContactWithUfCrm,
                        'LAST_NAME' => $lastNameContactWithUfCrm
                    ]
                ]);

                if ($updateResult) {
                    echo "  Контакт успешно обновлен\n";
                    $updatedCount++;
                } else {
                    echo "  Ошибка при обновлении контакта\n";
                }
            }
            if (!empty($contactData['UF_CRM_1765885674704'])) {
                $updateResult = updateContact($contactId, [
                    'FIELDS' => [
                        'UF_CRM_1765887321474' => 'дубль',
                    ]
                ]);
            }
        }

        echo "\nИтого обновлено контактов: $updatedCount\n";

        // 5. Объединяем дубликаты
        $mergeResult = mergeContacts($contactIds, $contactWithUfCrm);
        file_put_contents('error.txt', "Ошибка при объединении дубликатов: " . json_encode($mergeResult) . "\n", FILE_APPEND);
        if ($mergeResult['STATUS'] == 'SUCCESS') {
            echo "Дубликаты успешно объединены\n";
        } else {

            print_r($contactWithUfCrm);
        }
    } else {
        echo "Не найден ни один контакт с заполненным UF_CRM_123\n";
        echo "Обновление не требуется\n";
    }
}

// Запускаем скрипт
main();
