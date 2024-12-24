<?php
// Настройки
$apiKey = 'ACMA:5yuXEEDp7MD5oM7h7XiIA0WuXHNbT';
$businessId = 9343; // Укажите ваш идентификатор бизнеса
$apiUrl = "https://api.partner.market.yandex.ru/businesses/$businessId/offer-mappings/update"; // Исправленный URL для обновления данных
$fetchUrl = "https://api.partner.market.yandex.ru/businesses/$businessId/offer-mappings"; // URL для получения товаров

// Разрешённые артикулы (если массив не пустой, обновляем только их)
$allowedArticles = ["004-00001", "004-00002"]; // Пример списка разрешённых артикулов

// Подключение к базе данных
function getDatabaseConnection() {
    $pdo = new PDO('mysql:host=localhost;dbname=devdb', 'dev', 'o2eqFS');
    return $pdo;
}

// Получение данных из базы по списку артикулов
function findAllInDatabase($articles) {
    if (empty($articles)) {
        return []; // Если нет артикулов, возвращаем пустой массив
    }

    $pdo = getDatabaseConnection();
    //$placeholders = rtrim(str_repeat('?,', count($articles)), ','); // Генерация placeholders
    $placeholders = implode("','", $articles);
    $query = "SELECT article, length, width, height, weight FROM dimension_and_weight WHERE article IN ('$placeholders')";
    //$stmt = $pdo->prepare("SELECT article, length, width, height, weight FROM dimension_and_weight WHERE article IN ($placeholders)");
    //echo $query;
    $stmt = $pdo->prepare($query);
    $stmt->execute($articles); // Выполняем запрос с массивом значений
    return $stmt->fetchAll(PDO::FETCH_ASSOC); // Возвращаем результат
}

// Получение 100 товаров из API с учетом пагинации
function fetchOffers($fetchUrl, $apiKey, $pageToken = null, $offset = null) {
    $postData = [
        "limit" => 100 // Лимит товаров
    ];
    if ($pageToken) {
        $postData["page_token"] = $pageToken;
    }
    if ($offset !== null) {
        $postData["offset"] = $offset;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $fetchUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Api-Key: $apiKey",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        return $data;
    } else {
        echo "Ошибка получения товаров: $httpCode\n";
        echo $response;
        return null;
    }
}

// Пагинация
$pageToken = $_GET['page_token'] ?? null;
$offset = $_GET['offset'] ?? null;
$fetchResult = fetchOffers($fetchUrl, $apiKey, $pageToken, $offset);

if (!$fetchResult) {
    exit("Не удалось получить данные о товарах.");
}

$marketOffers = $fetchResult['result']['offerMappings'] ?? [];
$nextPageToken = $fetchResult['result']['paging']['nextPageToken'] ?? null;

// Если API поддерживает offset
$nextOffset = $offset !== null ? $offset + 100 : 100;

$articles = array_map(fn($offer) => $offer['offer']['offerId'], $marketOffers);
if (!empty($allowedArticles)) {
    $articles = array_intersect($articles, $allowedArticles);
    //echo  'погнали тест'; print_r($articles);
}

// Получение данных из базы данных для сравнения
$dbRecords = findAllInDatabase($articles);

$offerMappings = [];

foreach ($dbRecords as $dbRecord) {
    $article = $dbRecord['article'];

    // Находим соответствующее предложение из Маркета
    $marketData = array_filter($marketOffers, fn($offer) => $offer['offer']['offerId'] === $article);
    $marketData = reset($marketData)['offer']['weightDimensions'] ?? [];

    // Формируем массив для обновления, используя данные из Маркета, если значения в БД равны 0
    $weightDimensions = [];
    $weightDimensions['length'] = $dbRecord['length'] != 0 ? $dbRecord['length'] : $marketData['length'] ?? 0;
    $weightDimensions['width'] = $dbRecord['width'] != 0 ? $dbRecord['width'] : $marketData['width'] ?? 0;
    $weightDimensions['height'] = $dbRecord['height'] != 0 ? $dbRecord['height'] : $marketData['height'] ?? 0;
    $weightDimensions['weight'] = $dbRecord['weight'] != 0 ? $dbRecord['weight'] : $marketData['weight'] ?? 0;

    $offerMappings[] = [
        'offer' => [
            'offerId' => $article,
            'weightDimensions' => $weightDimensions
        ]
    ];
}

if (!empty($offerMappings)) {
    $updateData = [
        'offerMappings' => $offerMappings
    ];

    // Функция для отправки запроса в API
    function sendUpdateToMarket($url, $apiKey, $postData) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Api-Key: $apiKey",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return json_decode($response, true);
        } else {
            echo "Ошибка: $httpCode\n";
            echo $response;
            return null;
        }
    }

    // Отправка данных в Маркет
    $response = sendUpdateToMarket($apiUrl, $apiKey, $updateData);
    print_r($response);
    if ($response) {
        echo "Ответ API:
";
        echo '<pre>';

        print_r($response);
        echo "Данные для товаров успешно обновлены!";
    } else {
        echo "Ошибка обновления данных для товаров.";
    }
} else {
    echo "Нет данных для обновления.";
}

$url = ((!empty($_SERVER['HTTPS'])) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
$url = explode('?', $url);
$url = $url[0];

// Показать ссылку на следующую страницу, если есть
if ($nextPageToken || count($marketOffers) === 100) {
    echo "<a href='$url?page_token=$nextPageToken'>Перейти к следующей странице</a>";
}

echo $query.' fetchResult:'.count($fetchResult['result']['offerMappings']).' dbRecords:'.count($dbRecords).'<pre>#####';
print_r($dbRecords);
print_r($fetchResult);

// echo "Запрос в API:\n";
// print_r($postData);

// echo "Ответ API:\n";
// print_r($fetchResult);
?>
