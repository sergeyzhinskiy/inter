<?php
header('Content-Type: text/html; charset=UTF-8');

// Ваш API ключ Яндекс.Карт
$yandex_maps_api_key = '263f324d-f91e-4306-b49b-6d8ab7901f5f'; // Замените на ваш ключ

// Функция для геокодирования адреса (PHP 5 совместимая)
function geocodeAddress($address, $apiKey) {
    $url = "http://geocode-maps.yandex.ru/1.x/?format=json&geocode=".urlencode($address)."&key=".urlencode($apiKey);
    
    $options = array(
        'http' => array(
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'GET',
            'timeout' => 10
        )
    );
    
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        return array('error' => 'Ошибка при запросе к API');
    }
    
    $data = json_decode($response, true);
    if (!$data) {
        return array('error' => 'Ошибка декодирования JSON');
    }
    
    return $data;
}

// Тестовый адрес
$testAddress = "Тверь, проспект Чайковского, 26";
$result = geocodeAddress($testAddress, $yandex_maps_api_key);

// Вывод результатов
echo "<!DOCTYPE html><html><head><title>Тест геокодера (PHP 5)</title></head><body>";
echo "<h1>Результат геокодирования</h1>";

if (isset($result['error'])) {
    echo "<div style='color:red;'><strong>Ошибка:</strong> ".htmlspecialchars($result['error'])."</div>";
} else {
    // Обработка успешного ответа для API 1.x
    if (isset($result['response']['GeoObjectCollection']['featureMember'][0]['GeoObject']['Point']['pos'])) {
        $pos = $result['response']['GeoObjectCollection']['featureMember'][0]['GeoObject']['Point']['pos'];
        list($lon, $lat) = explode(' ', $pos);
        
        echo "<div style='color:green;'>Координаты найдены:</div>";
        echo "<p>Широта: $lat</p>";
        echo "<p>Долгота: $lon</p>";
        
        // JavaScript для отображения карты
        echo "<div id='map' style='width: 100%; height: 400px; margin-top: 20px;'></div>
        <script src='https://api-maps.yandex.ru/2.1/?apikey=$yandex_maps_api_key&lang=ru_RU'></script>
        <script>
            ymaps.ready(function() {
                var map = new ymaps.Map('map', {
                    center: [$lat, $lon],
                    zoom: 16
                });
                
                var placemark = new ymaps.Placemark([$lat, $lon], {
                    hintContent: '".addslashes($testAddress)."',
                    balloonContent: 'Адрес: ".addslashes($testAddress)."<br>Координаты: $lat, $lon'
                });
                
                map.geoObjects.add(placemark);
                placemark.balloon.open();
            });
        </script>";
    } else {
        echo "<div style='color:orange;'>Координаты не найдены в ответе</div>";
        echo "<pre>".htmlspecialchars(print_r($result, true))."</pre>";
    }
}

echo "</body></html>";
?>