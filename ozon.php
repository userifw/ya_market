<?php
$apiUrlAttributes = 'https://api-seller.ozon.ru/v4/product/info/attributes';
$apiUrlImport = 'https://api-seller.ozon.ru/v3/product/import';
$apiUrlTaskInfo = 'https://api-seller.ozon.ru/v1/product/import/info';
$apiKey = '';
$clientId = '';

// Функция отправки запросов
function apiRequest($url, $data, $apiKey, $clientId)
{
    $headers = [
        'Content-Type: application/json',
        'Client-Id: ' . $clientId,
        'Api-Key: ' . $apiKey
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        echo 'Ошибка cURL: ' . curl_error($ch);
        curl_close($ch);
        return null;
    }

    curl_close($ch);
    return json_decode($response, true);
}

// Проверка входных данных
if (empty($_POST['product_data']['offer_id']) || empty($_POST['product_data']['length']) || empty($_POST['product_data']['width']) || empty($_POST['product_data']['height']) || empty($_POST['product_data']['weight'])) {
    echo 'нет данных в полном объёме';
    exit;
}

// Получение SKU из POST
$sku = $_POST['product_data']['offer_id'];

// Запрос атрибутов товара
$productAttributesData = [
    'filter' => [
        'offer_id' => [$sku]
    ],
    'limit' => 1
];
$productAttributesResponse = apiRequest($apiUrlAttributes, $productAttributesData, $apiKey, $clientId);

if (!isset($productAttributesResponse['result']) || empty($productAttributesResponse['result'])) {
    echo 'Ошибка: данные о товаре отсутствуют.';
    exit;
}

// Извлечение данных о товаре
$productDataFromResponse = $productAttributesResponse['result'][0];

// Подготовка атрибутов
$attributes = [];
foreach ($productDataFromResponse['attributes'] ?? [] as $attribute) {
    $attributeItem = [
        'complex_id' => $attribute['complex_id'] ?? 0,
        'id' => $attribute['id'],
        'values' => []
    ];

    foreach ($attribute['values'] ?? [] as $value) {
        $attributeItem['values'][] = [
            'dictionary_value_id' => $value['dictionary_value_id'] ?? null,
            'value' => $value['value'] ?? ''
        ];
    }

    $attributes[] = $attributeItem;
}



// Шаг 2: Формирование данных для импорта с type_id
$productDataForImport = [
    'items' => [
        [
            'attributes' => $attributes,
            'barcode' => $productDataFromResponse['barcode'] ?? '',
            'description_category_id' => $productDataFromResponse['description_category_id'] ?? 0,
            'type_id' => $productDataFromResponse['type_id'] ?? null, // Добавление type_id
            'new_description_category_id' => 0,
            'color_image' => '',
            'complex_attributes' => [],
            'currency_code' => $productDataFromResponse['currency_code'] ?? 'RUB',
            'depth' => $_POST['product_data']['length'] ?? 0,
            'dimension_unit' => $productDataFromResponse['dimensions']['unit'] ?? 'mm',
            'height' => $_POST['product_data']['height'] ?? 0,
            'images' => $productDataFromResponse['images'] ?? [],
            'images360' => $productDataFromResponse['images360'] ?? [],
            'name' => $productDataFromResponse['name'] ?? '',
            'offer_id' => $productDataFromResponse['offer_id'] ?? '',
            'old_price' => $productDataFromResponse['old_price'] ?? '',
            'pdf_list' => $productDataFromResponse['pdf_list'] ?? [],
            'price' => $productDataFromResponse['price'] ?? '100',
            'primary_image' => $productDataFromResponse['primary_image'] ?? '',
            'vat' => $productDataFromResponse['vat'] ?? '0.1',
            'weight' => $_POST['product_data']['weight'] ?? 0,
            'weight_unit' => 'g',
            'width' => $_POST['product_data']['width'] ?? 0
        ]
    ]
];

// Логирование данных для импорта
echo '<pre>';
echo "=== Данные для импорта ===\n";
print_r($productDataForImport);
echo "=== Конец данных ===\n";
echo '</pre>';

// Шаг 3: Отправка данных на импорт
$importResponse = apiRequest($apiUrlImport, $productDataForImport, $apiKey, $clientId);

// Логирование ответа импорта
echo '<pre>';
echo "=== Ответ импорта ===\n";
print_r($importResponse);
echo "=== Конец ответа ===\n";
echo '</pre>';

// Проверка результата
if (isset($importResponse['result'])) {
    echo 'Товар успешно обновлен!';
} else {
    echo 'Ошибка импорта товара: ';
    print_r($importResponse);
    exit;
}

// Task ID для проверки
$taskId = $importResponse['result']['task_id'] ?? null;

if ($taskId) {
    $taskInfoData = ['task_id' => $taskId];
    $taskInfoResponse = apiRequest($apiUrlTaskInfo, $taskInfoData, $apiKey, $clientId);

    echo '<pre>';
    echo "=== Данные по задаче ===\n";
    print_r($taskInfoResponse);
    echo "=== Конец данных ===\n";
    echo '</pre>';
}
?>
