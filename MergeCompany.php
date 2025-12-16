<?php
require_once 'SendBitrix.php';

/**
 * Поиск дубликатов компаний по штатным реквизитам (ИНН)
 */
function findDuplicateCompaniesByRequisites()
{
    $method = 'crm.requisite.list';
    $params = [
        'filter' => [
            'ENTITY_TYPE_ID' => 4, // 4 = компания
            '!RQ_INN' => ''
        ],
        'select' => ['ENTITY_ID', 'RQ_INN']
    ];

    $result = sendBitrixRequest($method, $params);

    if ($result && isset($result['result'])) {
        return $result['result'];
    }

    return [];
}

/**
 * Поиск компаний по пользовательскому полю ИНН
 */
function findCompaniesByCustomInn($inn)
{
    $method = 'crm.company.list';
    $params = [
        'filter' => [
            'UF_CRM_COMPANY_AMO_XKWPIZPNIZOXT' => $inn
        ],
        'select' => ['ID', 'UF_CRM_COMPANY_AMO_XKWPIZPNIZOXT']
    ];

    $result = sendBitrixRequest($method, $params);

    if ($result && isset($result['result'])) {
        return $result['result'];
    }

    return [];
}

/**
 * Получение информации о компании
 */
function getCompanyInfo($companyId)
{
    $method = 'crm.company.get';
    $params = [
        'id' => $companyId
    ];

    $result = sendBitrixRequest($method, $params);

    if ($result && isset($result['result'])) {
        return $result['result'];
    }

    return false;
}

/**
 * Получение реквизитов компании
 */
function getCompanyRequisites($companyId)
{
    $method = 'crm.requisite.list';
    $params = [
        'filter' => [
            'ENTITY_TYPE_ID' => 4,
            'ENTITY_ID' => $companyId
        ],
        'select' => ['RQ_INN']
    ];

    $result = sendBitrixRequest($method, $params);

    if ($result && isset($result['result']) && !empty($result['result'])) {
        return $result['result'][0]['RQ_INN'] ?? null;
    }

    return null;
}

/**
 * Обновление компании
 */
function updateCompany($companyId, $fields)
{
    $method = 'crm.company.update';
    $params = array_merge(['ID' => $companyId], $fields);

    $result = sendBitrixRequest($method, $params);

    if ($result && isset($result['result'])) {
        return $result['result'];
    }

    return false;
}

/**
 * Объединение дубликатов компаний
 */
function mergeCompanies($companyIds, $mainCompanyId)
{
    if (empty($companyIds)) {
        return false;
    }

    array_unshift($companyIds, $mainCompanyId);

    $method = 'crm.entity.mergeBatch';
    $params = [
        "params" => [
            "entityTypeId" => 4, // 4 = компания
            "entityIds" => $companyIds
        ]
    ];

    $result = sendBitrixRequest($method, $params);

    if ($result && isset($result['result'])) {
        return $result['result'];
    }

    return false;
}

/**
 * Группировка компаний по ИНН
 */
function groupCompaniesByInn()
{
    echo "Получаем список всех компаний с реквизитами...\n";
    
    $requisites = findDuplicateCompaniesByRequisites();
    $innGroups = [];

    // Группируем компании по ИНН из штатных реквизитов
    foreach ($requisites as $req) {
        $inn = $req['RQ_INN'];
        $companyId = $req['ENTITY_ID'];
        
        if (!isset($innGroups[$inn])) {
            $innGroups[$inn] = [];
        }
        
        if (!in_array($companyId, $innGroups[$inn])) {
            $innGroups[$inn][] = $companyId;
        }
    }

    // Дополнительно проверяем совпадения между штатными реквизитами и пользовательским полем
    echo "Проверяем совпадения с пользовательским полем ИНН...\n";
    
    foreach ($innGroups as $inn => $companyIds) {
        $companiesWithCustomInn = findCompaniesByCustomInn($inn);
        
        foreach ($companiesWithCustomInn as $company) {
            if (!in_array($company['ID'], $companyIds)) {
                $innGroups[$inn][] = $company['ID'];
            }
        }
    }

    // Оставляем только группы с дубликатами (более одной компании)
    $duplicateGroups = array_filter($innGroups, function($group) {
        return count($group) > 1;
    });

    return $duplicateGroups;
}

/**
 * Обработка группы дубликатов
 */
function processDuplicateGroup($inn, $companyIds)
{
    echo "\n========================================\n";
    echo "Обработка группы с ИНН: $inn\n";
    echo "Найдено компаний: " . count($companyIds) . "\n";
    echo "========================================\n\n";

    $companies = [];
    $mainCompanyId = null;
    $mainCompanyData = null;
    $companiesWithCode1C = 0;

    // Получаем информацию о каждой компании
    foreach ($companyIds as $companyId) {
        echo "Получаем информацию о компании ID: $companyId\n";

        $companyInfo = getCompanyInfo($companyId);

        if (!$companyInfo) {
            echo "  Не удалось получить информацию о компании\n";
            continue;
        }

        $code1C = $companyInfo['UF_CRM_1765869083297'] ?? null;
        $assignedById = $companyInfo['ASSIGNED_BY_ID'] ?? null;
        $title = $companyInfo['TITLE'] ?? '';
        $requisiteInn = getCompanyRequisites($companyId);
        $customInn = $companyInfo['UF_CRM_COMPANY_AMO_XKWPIZPNIZOXT'] ?? null;

        echo "  Название: $title\n";
        echo "  ASSIGNED_BY_ID: $assignedById\n";
        echo "  Реквизиты ИНН: " . ($requisiteInn ? $requisiteInn : 'пусто') . "\n";
        echo "  Пользовательское поле ИНН: " . ($customInn ? $customInn : 'пусто') . "\n";
        echo "  КОД 1С: " . ($code1C ? $code1C : 'пусто') . "\n\n";

        $companies[$companyId] = [
            'info' => $companyInfo,
            'CODE_1C' => $code1C,
            'ASSIGNED_BY_ID' => $assignedById,
            'TITLE' => $title,
            'REQUISITE_INN' => $requisiteInn,
            'CUSTOM_INN' => $customInn
        ];

        // Подсчитываем компании с заполненным КОД 1С
        if (!empty($code1C)) {
            $companiesWithCode1C++;
            
            // Запоминаем первую компанию с заполненным КОД 1С как главную
            if ($mainCompanyId === null) {
                $mainCompanyId = $companyId;
                $mainCompanyData = $companies[$companyId];
            }
        }
    }

    // Проверяем условие: если у всех дублей заполнено поле КОД 1С - не объединяем
    if ($companiesWithCode1C == count($companies)) {
        echo "⚠️ У всех компаний в группе заполнено поле КОД 1С\n";
        echo "Объединение не выполняется\n";
        return;
    }

    // Если не найдена компания с КОД 1С
    if ($mainCompanyId === null) {
        echo "⚠️ Не найдена ни одна компания с заполненным полем КОД 1С\n";
        echo "Объединение не выполняется\n";
        return;
    }

    echo "✓ Найдена главная компания с КОД 1С: ID $mainCompanyId\n";
    echo "  Название: {$mainCompanyData['TITLE']}\n";
    echo "  КОД 1С: {$mainCompanyData['CODE_1C']}\n";
    echo "  ASSIGNED_BY_ID для обновления: {$mainCompanyData['ASSIGNED_BY_ID']}\n\n";

    // Обновляем компании с пустым КОД 1С
    $updatedCount = 0;
    $companiesToMerge = [];

    foreach ($companies as $companyId => $companyData) {
        if ($companyId == $mainCompanyId) {
            continue;
        }

        if (empty($companyData['CODE_1C'])) {
            echo "Обновляем компанию ID: $companyId\n";

            $updateResult = updateCompany($companyId, [
                'FIELDS' => [
                    'ASSIGNED_BY_ID' => $mainCompanyData['ASSIGNED_BY_ID'],
                    'UF_CRM_1765869083297' => $mainCompanyData['CODE_1C'],
                    'TITLE' => $mainCompanyData['TITLE']
                ]
            ]);

            if ($updateResult) {
                echo "  ✓ Компания успешно обновлена\n";
                $updatedCount++;
                $companiesToMerge[] = $companyId;
            } else {
                echo "  ✗ Ошибка при обновлении компании\n";
            }
        } else {
            // Если у компании тоже есть КОД 1С, но она не главная - не объединяем
            echo "⚠️ Компания ID: $companyId имеет свой КОД 1С, пропускаем\n";
        }
    }

    echo "\n✓ Итого обновлено компаний: $updatedCount\n";

    // Объединяем дубликаты (только те, что были обновлены)
    if (!empty($companiesToMerge)) {
        echo "\nОбъединяем дубликаты...\n";
        $mergeResult = mergeCompanies($companiesToMerge, $mainCompanyId);

        if ($mergeResult && isset($mergeResult['STATUS']) && $mergeResult['STATUS'] == 'SUCCESS') {
            echo "✓ Дубликаты успешно объединены\n";
        } else {
            echo "✗ Ошибка при объединении:\n";
            print_r($mergeResult);
        }
    } else {
        echo "\n⚠️ Нет компаний для объединения\n";
    }
}

/**
 * Основная логика скрипта
 */
function main()
{
    echo "===========================================\n";
    echo "СКРИПТ ОБЪЕДИНЕНИЯ ДУБЛИКАТОВ КОМПАНИЙ\n";
    echo "===========================================\n\n";

    // Получаем группы дубликатов
    $duplicateGroups = groupCompaniesByInn();

    if (empty($duplicateGroups)) {
        echo "✓ Дубликаты компаний не найдены\n";
        return;
    }

    echo "Найдено групп дубликатов: " . count($duplicateGroups) . "\n\n";

    // Обрабатываем каждую группу
    foreach ($duplicateGroups as $inn => $companyIds) {
        processDuplicateGroup($inn, $companyIds);
    }

    echo "\n===========================================\n";
    echo "ОБРАБОТКА ЗАВЕРШЕНА\n";
    echo "===========================================\n";
}

// Запускаем скрипт
main();