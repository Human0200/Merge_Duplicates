<?php
require_once 'SendBitrix.php';
$requisite = $_GET['requisites'] ?? '';
$title = $_GET['title'] ?? '';
file_put_contents('data.json', json_encode(['requisite' => $requisite], JSON_PRETTY_PRINT | FILE_APPEND));


/**
 * Поиск компаний по названию
 */
function findCompaniesByTitle($title)
{
    $companyIds = [];
    $start = 0;
    $limit = 50;

    echo "  Ищем компании с названием '$title'...\n";

    while (true) {
        $method = 'crm.company.list';
        $params = [
            'filter' => [
                'TITLE' => $title
            ],
            'select' => ['ID'],
            'start' => $start
        ];

        $result = sendBitrixRequest($method, $params);

        if ($result && isset($result['result']) && !empty($result['result'])) {
            foreach ($result['result'] as $company) {
                $companyIds[] = (int)$company['ID'];
            }

            $count = count($result['result']);
            $start += $count;

            echo "    Найдено: $count (всего: " . count($companyIds) . ")\n";

            if ($count < $limit) {
                break;
            }
        } else {
            break;
        }
    }

    return $companyIds;
}

/**
 * Поиск компаний по ИНН в штатных реквизитах (только для конкретного ИНН)
 */
function findCompaniesByRequisiteInn($inn)
{
    $companyIds = [];
    $start = 0;
    $limit = 50;

    echo "  Ищем компании с ИНН '$inn' в штатных реквизитах...\n";

    while (true) {
        $method = 'crm.requisite.list';
        $params = [
            'filter' => [
                'ENTITY_TYPE_ID' => 4, // 4 = компания
                'RQ_INN' => $inn
            ],
            'select' => ['ENTITY_ID'],
            'start' => $start
        ];

        $result = sendBitrixRequest($method, $params);

        if ($result && isset($result['result']) && !empty($result['result'])) {
            foreach ($result['result'] as $req) {
                $companyIds[] = (int)$req['ENTITY_ID'];
            }

            $count = count($result['result']);
            $start += $count;

            echo "    Найдено: $count (всего: " . count($companyIds) . ")\n";

            if ($count < $limit) {
                break;
            }
        } else {
            break;
        }
    }

    return array_unique($companyIds);
}

/**
 * Поиск компаний по пользовательскому полю ИНН
 */
function findCompaniesByCustomInn($inn)
{
    echo "  Ищем компании с ИНН '$inn' в пользовательском поле...\n";

    $method = 'crm.company.list';
    $params = [
        'filter' => [
            'UF_CRM_COMPANY_AMO_XKWPIZPNIZOXT' => $inn
        ],
        'select' => ['ID', 'UF_CRM_COMPANY_AMO_XKWPIZPNIZOXT']
    ];

    $result = sendBitrixRequest($method, $params);

    if ($result && isset($result['result'])) {
        echo "    Найдено: " . count($result['result']) . "\n";
        return $result['result'];
    }

    echo "    Найдено: 0\n";
    return [];
}

/**
 * Получение информации о нескольких компаниях через batch
 */
function getCompaniesInfoBatch($companyIds)
{
    if (empty($companyIds)) {
        return [];
    }

    $method = 'batch';
    $cmd = [];

    // Формируем команды для batch-запроса
    foreach ($companyIds as $companyId) {
        $cmd["company_$companyId"] = "crm.company.get?id=$companyId";
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
 * Получение реквизитов нескольких компаний через batch
 */
function getCompaniesRequisitesBatch($companyIds)
{
    if (empty($companyIds)) {
        return [];
    }

    $method = 'batch';
    $cmd = [];

    // Формируем команды для batch-запроса
    foreach ($companyIds as $companyId) {
        // Формируем параметры фильтра для каждой компании
        $filter = http_build_query([
            'filter' => [
                'ENTITY_TYPE_ID' => 4,
                'ENTITY_ID' => $companyId
            ],
            'select' => ['RQ_INN']
        ]);

        $cmd["requisite_$companyId"] = "crm.requisite.list?$filter";
    }

    $params = [
        'halt' => 0,
        'cmd' => $cmd
    ];

    $result = sendBitrixRequest($method, $params);

    if ($result && isset($result['result']['result'])) {
        $requisites = [];

        // Обрабатываем результаты
        foreach ($result['result']['result'] as $key => $data) {
            $companyId = str_replace('requisite_', '', $key);

            if (!empty($data) && isset($data[0]['RQ_INN'])) {
                $requisites[$companyId] = $data[0]['RQ_INN'];
            } else {
                $requisites[$companyId] = null;
            }
        }

        return $requisites;
    }

    return [];
}

/**
 * Обновление нескольких компаний через batch
 */
function updateCompaniesBatch($updates)
{
    if (empty($updates)) {
        return true;
    }

    $method = 'batch';
    $cmd = [];

    // Формируем команды для batch-запроса
    foreach ($updates as $index => $update) {
        $companyId = $update['ID'];
        $fields = $update['FIELDS'];

        // Формируем массив параметров для каждой команды
        $cmdParams = [
            'ID' => $companyId,
            'FIELDS' => $fields
        ];

        // Преобразуем параметры в строку запроса
        $queryString = http_build_query($cmdParams);
        $cmd["update_$index"] = "crm.company.update?$queryString";
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
 * Группировка компаний по ИНН (оптимизированная версия)
 */
function groupCompaniesByInn()
{
    global $requisite;
    global $title;

    echo "Поиск дубликатов по ИНН: " . ($requisite ? "'$requisite'" : 'ВСЕ (не рекомендуется)') . "\n\n";

    $innGroups = [];

    if ($requisite !== '') {
        // СЛУЧАЙ 1: Ищем по КОНКРЕТНОМУ ИНН
        echo "🔍 Ищем компании с ИНН: $requisite\n\n";

        // 1. Ищем в штатных реквизитах
        $companiesFromRequisites = findCompaniesByRequisiteInn($requisite);

        $companiesFromTitles = findCompaniesByTitle($title);

        // 2. Ищем в пользовательском поле
        $companiesFromCustomField = findCompaniesByCustomInn($requisite);
        $customFieldIds = array_column($companiesFromCustomField, 'ID');

        // 3. Объединяем результаты
        $allCompanyIds = array_unique(array_merge($companiesFromRequisites, $customFieldIds, $companiesFromTitles));

        if (!empty($allCompanyIds)) {
            $innGroups[$requisite] = $allCompanyIds;
        }
    } else {
        // СЛУЧАЙ 2: Ищем ВСЕ дубликаты (только если действительно нужно)
        // Эта функция требует перезаписи для эффективности
        echo "⚠️  ВНИМАНИЕ: Поиск ВСЕХ дубликатов отключен для производительности!\n";
        echo "📋 Используйте параметр ?requisites=ИНН для поиска конкретного дубликата\n";
        echo "Пример: script.php?requisites=7712345678\n\n";
        return [];
    }

    // Фильтруем - оставляем только группы с дубликатами (2+ компании)
    $duplicateGroups = array_filter($innGroups, function ($group) {
        return count($group) > 1;
    });

    if (empty($duplicateGroups) && $requisite !== '') {
        echo "\n✅ Дубликатов для ИНН '$requisite' не найдено\n";
        echo "Найдено компаний: " . (isset($innGroups[$requisite]) ? count($innGroups[$requisite]) : 0) . "\n";
    } elseif (!empty($duplicateGroups)) {
        echo "\n✅ Найдено дубликатов: " . count($duplicateGroups) . " группа(ы)\n";
    }

    return $duplicateGroups;
}

/**
 * Обработка группы дубликатов
 */
function processDuplicateGroup($inn, $companyIds)
{
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "ОБРАБОТКА ГРУППЫ\n";
    echo "ИНН: $inn\n";
    echo "Компаний: " . count($companyIds) . "\n";
    echo str_repeat("=", 50) . "\n\n";

    // Получаем информацию о всех компаниях через batch
    echo "📋 Получаем информацию о компаниях...\n";
    $companiesInfoBatch = getCompaniesInfoBatch($companyIds);

    if (empty($companiesInfoBatch)) {
        echo "❌ Не удалось получить информацию о компаниях\n";
        return;
    }

    // Получаем реквизиты всех компаний через batch
    echo "📋 Получаем реквизиты компаний...\n";
    $requisitesBatch = getCompaniesRequisitesBatch($companyIds);

    $companies = [];
    $mainCompanyId = null;
    $mainCompanyData = null;
    $companiesWithCode1C = 0;

    // Обрабатываем полученные данные
    foreach ($companyIds as $companyId) {
        $companyKey = "company_$companyId";

        if (!isset($companiesInfoBatch[$companyKey])) {
            echo "  ⚠️  Не удалось получить информацию о компании ID: $companyId\n";
            continue;
        }

        $companyInfo = $companiesInfoBatch[$companyKey];

        $code1C = $companyInfo['UF_CRM_1765544033'] ?? null; // КОД 1С
        $assignedById = $companyInfo['ASSIGNED_BY_ID'] ?? null;
        $title = $companyInfo['TITLE'] ?? '';
        $requisiteInn = $requisitesBatch[$companyId] ?? null;
        $customInn = $companyInfo['UF_CRM_COMPANY_AMO_XKWPIZPNIZOXT'] ?? null; // Исправлено: правильное поле

        echo "\n🏢 Компания ID: $companyId\n";
        echo "   Название: $title\n";
        echo "   Ответственный: $assignedById\n";
        echo "   ИНН (реквизиты): " . ($requisiteInn ? $requisiteInn : 'пусто') . "\n";
        echo "   ИНН (поле): " . ($customInn ? $customInn : 'пусто') . "\n";
        echo "   КОД 1С: " . ($code1C ? $code1C : 'пусто') . "\n";

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
                echo "   ✅ Выбрана как главная (есть КОД 1С)\n";
            }
        }
    }

    // Проверяем условие: если у всех дублей заполнено поле КОД 1С - не объединяем
    if ($companiesWithCode1C > 1) {
        echo "\n⚠️  У нескольких компаний в группе заполнено поле КОД 1С\n";
        echo "   Компаний с КОД 1С: $companiesWithCode1C из " . count($companies) . "\n";

        // Формируем batch для пометки всех компаний как "дубль"
        $updates = [];
        foreach ($companies as $companyId => $companyData) {
            $updates[] = [
                'ID' => $companyId,
                'FIELDS' => [
                    'UF_CRM_1765894984899' => 'дубль'  // Помечаем все компании как дубли
                ]
            ];
        }

        echo "📝 Помечаем все компании как 'дубль'...\n";
        $updateResult = updateCompaniesBatch($updates);

        if ($updateResult) {
            echo "✅ Все компании помечены как 'дубль'\n";
        } else {
            echo "❌ Ошибка при пометке компаний\n";
        }

        echo "🛑 Объединение не выполняется (у нескольких компаний есть КОД 1С)\n";
        return; // Выходим, больше ничего не делаем
    }

    // Если не найдена компания с КОД 1С
    if ($mainCompanyId === null) {
        echo "\n⚠️  Не найдена ни одна компания с заполненным полем КОД 1С\n";

        // Можно добавить логику выбора главной компании по другим критериям
        // Например: самая старая компания, или с наибольшим количеством сделок
        echo "🛑 Объединение не выполняется\n";
        return;
    }

    echo "\n✅ ГЛАВНАЯ КОМПАНИЯ: ID $mainCompanyId\n";
    echo "   Название: {$mainCompanyData['TITLE']}\n";
    echo "   КОД 1С: {$mainCompanyData['CODE_1C']}\n";
    echo "   Ответственный: {$mainCompanyData['ASSIGNED_BY_ID']}\n\n";

    // Формируем batch для обновления компаний
    $updates = [];
    $companiesToMerge = [];

    foreach ($companies as $companyId => $companyData) {
        if ($companyId == $mainCompanyId) {
            continue;
        }

        if (empty($companyData['CODE_1C'])) {
            echo "📝 Готовим обновление для компании ID: $companyId\n";

            $updates[] = [
                'ID' => $companyId,
                'FIELDS' => [
                    'ASSIGNED_BY_ID' => $mainCompanyData['ASSIGNED_BY_ID'],
                    'UF_CRM_1765544033' => $mainCompanyData['CODE_1C'], // КОД 1С
                    'TITLE' => $mainCompanyData['TITLE']
                ]
            ];

            $companiesToMerge[] = $companyId;
        } else {
            // Если у компании тоже есть КОД 1С, но она не главная - не объединяем
            echo "⚠️  Компания ID: $companyId имеет свой КОД 1С, пропускаем\n";
        }
    }

    // Выполняем batch-обновление
    if (!empty($updates)) {
        echo "\n🔄 Выполняем обновление " . count($updates) . " компаний...\n";
        $updateResult = updateCompaniesBatch($updates);

        if ($updateResult) {
            echo "✅ Компании успешно обновлены\n";
        } else {
            echo "❌ Ошибка при обновлении компаний\n";
            return;
        }
    } else {
        echo "\nℹ️  Нет компаний для обновления\n";
    }

    // Объединяем дубликаты (только те, что были обновлены)
    if (!empty($companiesToMerge)) {
        echo "\n🔄 Объединяем дубликаты...\n";
        $mergeResult = mergeCompanies($companiesToMerge, $mainCompanyId);

        if ($mergeResult && isset($mergeResult['STATUS']) && $mergeResult['STATUS'] == 'SUCCESS') {
            echo "✅ Дубликаты успешно объединены\n";
            echo "   Сохранена компания: ID $mainCompanyId\n";
            echo "   Объединено компаний: " . count($companiesToMerge) . "\n";
        } else {
            echo "❌ Ошибка при объединении:\n";
            print_r($mergeResult);
        }
    } else {
        echo "\nℹ️  Нет компаний для объединения\n";
    }
}

/**
 * Основная логика скрипта
 */
function main()
{
    global $requisite;

    echo "\n" . str_repeat("=", 60) . "\n";
    echo "СКРИПТ ОБЪЕДИНЕНИЯ ДУБЛИКАТОВ КОМПАНИЙ В BITRIX24\n";
    echo str_repeat("=", 60) . "\n\n";

    // Проверяем наличие параметра
    if ($requisite === '') {
        echo "❌ Параметр 'requisites' не указан!\n\n";
        echo "📋 ИСПОЛЬЗОВАНИЕ:\n";
        echo "   script.php?requisites=ИНН_КОМПАНИИ\n\n";
        echo "📝 ПРИМЕРЫ:\n";
        echo "   script.php?requisites=7712345678\n";
        echo "   script.php?requisites=1234567890\n\n";
        echo "💡 Для массовой обработки создайте список ИНН и обрабатывайте по одному\n";
        return;
    }

    // Валидация ИНН (базовая проверка)
    if (!preg_match('/^\d{10,12}$/', $requisite)) {
        file_put_contents('error.txt', "Неверный формат ИНН: '$requisite'\n", FILE_APPEND);
        echo "❌ Неверный формат ИНН: '$requisite'\n";
        echo "   ИНН должен содержать 10 или 12 цифр\n";
        return;
    }

    echo "🔍 Поиск дубликатов для ИНН: $requisite\n";
    echo str_repeat("-", 60) . "\n\n";

    // Получаем группы дубликатов
    $duplicateGroups = groupCompaniesByInn();

    if (empty($duplicateGroups)) {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "ОБРАБОТКА ЗАВЕРШЕНА - ДУБЛИКАТОВ НЕ НАЙДЕНО\n";
        echo str_repeat("=", 60) . "\n";
        return;
    }

    // Обрабатываем каждую группу
    foreach ($duplicateGroups as $inn => $companyIds) {
        processDuplicateGroup($inn, $companyIds);
        echo "\n" . str_repeat("-", 60) . "\n";
    }

    echo "\n" . str_repeat("=", 60) . "\n";
    echo "✅ ОБРАБОТКА УСПЕШНО ЗАВЕРШЕНА\n";
    echo str_repeat("=", 60) . "\n";
}

// Запускаем скрипт
main();
