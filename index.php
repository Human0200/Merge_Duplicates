<?php
// Конфигурация
$webhookUrl = 'https://b24-27cw69.bitrix24.ru/rest/1/nrpvb1n6jq9bd2h5/';
$phoneNumber = '+79999999999'; // Пример номера телефона, можно заменить на получение из другого источника

/**
 * Функция для отправки запросов к Bitrix24 REST API
 */
function sendBitrixRequest($method, $params = []) {
    global $webhookUrl;
    
    $url = $webhookUrl . $method;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        echo 'Ошибка cURL: ' . curl_error($ch);
        curl_close($ch);
        return false;
    }
    
    curl_close($ch);
    
    if ($httpCode !== 200) {
        echo "HTTP ошибка: $httpCode\n";
        return false;
    }
    
    $result = json_decode($response, true);
    
    if (isset($result['error'])) {
        echo "Ошибка Bitrix24: " . $result['error_description'] . "\n";
        return false;
    }
    
    return $result;
}

/**
 * Поиск дубликатов контактов по телефону
 */
function findDuplicateContacts($phone) {
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
 * Получение информации о контакте
 */
function getContactInfo($contactId) {
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
function updateContact($contactId, $fields) {
    $method = 'crm.contact.update';
    $params = array_merge(['id' => $contactId], $fields);
    
    $result = sendBitrixRequest($method, $params);
    
    if ($result && isset($result['result'])) {
        return $result['result'];
    }
    
    return false;
}

/**
 * Основная логика скрипта
 */
function main() {
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
    $contactWithUfCrm = null;
    
    // 2. Получаем информацию о каждом контакте
    foreach ($contactIds as $contactId) {
        echo "Получаем информацию о контакте ID: $contactId\n";
        
        $contactInfo = getContactInfo($contactId);
        
        if (!$contactInfo) {
            echo "  Не удалось получить информацию о контакте\n";
            continue;
        }
        
        $assignedById = $contactInfo['ASSIGNED_BY_ID'] ?? null;
        $ufCrm123 = $contactInfo['UF_CRM_1765802383436'] ?? null;
        
        echo "  ASSIGNED_BY_ID: $assignedById\n";
        echo "  UF_CRM_1765802383436: " . ($ufCrm123 ? $ufCrm123 : 'пусто') . "\n\n";
        
        $contacts[$contactId] = [
            'ASSIGNED_BY_ID' => $assignedById,
            'UF_CRM_1765802383436' => $ufCrm123
        ];
        
        // Запоминаем контакт с заполненным UF_CRM_1765802383436
        if (!empty($ufCrm123) && $assignedByWithUfCrm === null) {
            $assignedByWithUfCrm = $assignedById;
            $contactWithUfCrm = $contactId;
        }
    }
    
    // 3. Если найден контакт с заполненным UF_CRM_1765802383436
    if ($assignedByWithUfCrm !== null) {
        echo "Найден контакт с заполненным UF_CRM_1765802383436: ID $contactWithUfCrm\n";
        echo "ASSIGNED_BY_ID для обновления: $assignedByWithUfCrm\n\n";
        
        // 4. Обновляем контакты с пустым UF_CRM_1765802383436
        $updatedCount = 0;
        foreach ($contacts as $contactId => $contactData) {
            if (empty($contactData['UF_CRM_1765802383436']) && $contactData['ASSIGNED_BY_ID'] != $assignedByWithUfCrm) {
                echo "Обновляем контакт ID: $contactId\n";
                
                $updateResult = updateContact($contactId, [
                    'ASSIGNED_BY_ID' => $assignedByWithUfCrm
                ]);
                
                if ($updateResult) {
                    echo "  Контакт успешно обновлен\n";
                    $updatedCount++;
                } else {
                    echo "  Ошибка при обновлении контакта\n";
                }
            }
        }
        
        echo "\nИтого обновлено контактов: $updatedCount\n";
    } else {
        echo "Не найден ни один контакт с заполненным UF_CRM_123\n";
        echo "Обновление не требуется\n";
    }
}

// Запускаем скрипт
main();
?>