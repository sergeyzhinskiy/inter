<?php
header('Content-Type: text/html; charset=UTF-8');
include '/includes/header_new.php';
require_once 'config.php';
//require_once 'vendor/autoload.php'; // Подключаем автозагрузчик Composer для PHPMailer
checkAuth();
checkRole(array('organizer', 'admin'));

$categories = $pdo->query("SELECT * FROM categories")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = sanitize($_POST['title']);
    $description = sanitize($_POST['description']);
    $category_id = (int)$_POST['category_id'];
    $date_start = sanitize($_POST['date_start']);
    $date_end = !empty($_POST['date_end']) ? sanitize($_POST['date_end']) : null;
    $address = sanitize($_POST['address']);
    $contact_phone = sanitize($_POST['contact_phone']);
    $contact_email = sanitize($_POST['contact_email']);
    $image_url = !empty($_POST['image_url']) ? sanitize($_POST['image_url']) : null;
    $is_promoted = isset($_POST['is_promoted']) && $_POST['is_promoted'] == '1' ? 1 : 0;
    
	// Отладочный вывод
    error_log("Полученные координаты: lat=" . $_POST['latitude'] . ", lon=" . $_POST['longitude']);
    
    try {
        $stmt = $pdo->prepare("INSERT INTO events 
            (title, description, category_id, date_start, date_end, address, 
            latitude, longitude, organizer_id, is_approved, is_promoted, 
            image_url, contact_phone, contact_email) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
		   $latitude = isset($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $longitude = isset($_POST['longitude']) ? (float)$_POST['longitude'] : null;
    $organizer_id = ($_SESSION['user_role'] === 'admin') ? (int)$_POST['organizer_id'] : $_SESSION['user_id'];
    $is_approved = 0;
	
        error_log("Параметры запроса: " . print_r(array(
            $title, $description, $category_id, $date_start, $date_end, $address,
            $latitude, $longitude, $organizer_id, $is_approved, $is_promoted, 
            $image_url, $contact_phone, $contact_email
        ), true));
        
        $stmt->execute(array(
            $title, $description, $category_id, $date_start, $date_end, $address,
            $latitude, $longitude, $organizer_id, $is_approved, $is_promoted, 
            $image_url, $contact_phone, $contact_email
        ));
		$event_id = $pdo->lastInsertId();
    error_log("Мероприятие добавлено, ID: " . $event_id);
} catch (PDOException $e) {
    $error = "Ошибка при добавлении мероприятия: " . $e->getMessage();
    error_log($error);
}
    // Проверка обязательных полей
    if (empty($title) || empty($description) || empty($category_id) || empty($date_start) || empty($address)) {
        $error = "Пожалуйста, заполните все обязательные поля";
    } else {
        // Определяем organizer_id
        $organizer_id = ($_SESSION['user_role'] === 'admin') ? (int)$_POST['organizer_id'] : $_SESSION['user_id'];
        
        // Проверяем существование организатора
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'organizer'");
        $stmt->execute(array($organizer_id));
        
        if (!$stmt->fetch()) {
            $error = "Организатор не найден или не имеет нужных прав";
        } else {
            // Проверяем, доверенный ли организатор
            $isTrusted = false;
            if ($_SESSION['user_role'] === 'organizer') {
                $stmt = $pdo->prepare("SELECT is_trusted FROM users WHERE id = ?");
                $stmt->execute(array($_SESSION['user_id']));
                $isTrusted = $stmt->fetchColumn();
            }
            
            // Автоматическое одобрение для доверенных организаторов и админов
            $is_approved = ($isTrusted || $_SESSION['user_role'] === 'admin') ? 1 : 0;
            
            try {
                $stmt = $pdo->prepare("INSERT INTO events 
                    (title, description, category_id, date_start, date_end, address, 
                    organizer_id, is_approved, is_promoted, image_url, contact_phone, contact_email) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                $stmt->execute(array(
                    $title, $description, $category_id, $date_start, $date_end, $address,
                    $organizer_id, $is_approved, $is_promoted, $image_url, $contact_phone, $contact_email
                ));
                
                $event_id = $pdo->lastInsertId();
                
                // Получаем координаты адреса через Yandex Geocoder API
               // $coordinates = geocodeAddress($address);
                
                //if ($coordinates) {
                    // Обновляем мероприятие с координатами
                 //   $stmt = $pdo->prepare("UPDATE events SET latitude = ?, longitude = ? WHERE id = ?");
                 //   $stmt->execute(array($coordinates['lat'], $coordinates['lon'], $event_id));
                //}
                
                // Отправляем уведомления
               // sendNotifications($event_id, $title, $is_promoted, $is_approved);
                
               // $_SESSION['success'] = "Мероприятие успешно добавлено" . ($is_approved ? "" : " и ожидает модерации");
               // ob_end_clean(); // Очистите любой потенциальный вывод
                
              //  exit();
            } catch (PDOException $e) {
                $error = "Ошибка при добавлении мероприятия: " . $e->getMessage();
            }
       }
    }

    
	
// Функция для геокодирования адреса
function geocodeAddress($address) {
    $apiKey = '263f324d-f91e-4306-b49b-6d8ab7901f5f';
    $url = "https://geocode-maps.yandex.ru/2.1/?format=json&apikey=$apiKey&geocode=" . urlencode($address);
    
    $response = file_get_contents($url);
    if ($response === FALSE) return null;
    
    $data = json_decode($response, true);
    
    if (isset($data['response']['GeoObjectCollection']['featureMember'][0]['GeoObject']['Point']['pos'])) {
        $pos = $data['response']['GeoObjectCollection']['featureMember'][0]['GeoObject']['Point']['pos'];
        list($lon, $lat) = explode(' ', $pos);
        return array('lat' => $lat, 'lon' => $lon);
    }
    
    return null;
}

// Функция для отправки уведомлений
function sendNotifications($event_id, $event_title, $is_promoted, $is_approved) {
    global $pdo;
    
    // Получаем данные организатора
    $stmt = $pdo->prepare("SELECT username, email FROM users WHERE id = ?");
    $stmt->execute(array($_SESSION['user_id']));
    $organizer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $event_link = "http://" . $_SERVER['HTTP_HOST'] . "/moderation.php?event_id=$event_id";
    
    // Уведомление организатору
    if ($is_approved) {
        $subject = "Ваше мероприятие опубликовано";
        $message = "Ваше мероприятие \"$event_title\" было успешно опубликовано на нашей платформе.";
    } else {
        $subject = "Ваше мероприятие ожидает модерации";
        $message = "Ваше мероприятие \"$event_title\" было успешно отправлено на модерацию. Мы уведомим вас, когда оно будет опубликовано.";
    }
    
    sendEmail($organizer['email'], $subject, $message);
    
    // Уведомление модераторам (если требуется модерация)
    if (!$is_approved) {
        $subject = "Новое мероприятие на модерацию";
        $message = "Организатор " . $organizer['username'] . " добавил новое мероприятие \"$event_title\", которое требует модерации.\n\n";
        $message .= "Ссылка для модерации: $event_link";
        
        // Получаем email всех модераторов
        $moderators = $pdo->query("SELECT email FROM users WHERE role = 'moderator'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($moderators as $email) {
            sendEmail($email, $subject, $message);
        }
        
        // Отправляем уведомление в Telegram
        sendTelegramNotification("Новое мероприятие на модерацию: $event_title\n$event_link");
    }
    
    // Уведомление администраторам о платном размещении
    if ($is_promoted && !$is_approved) {
        $subject = "Требуется оплата за рекламное размещение";
        $message = "Организатор " . $organizer['username'] . " добавил мероприятие \"$event_title\" с запросом на рекламное размещение.\n\n";
        $message .= "Ссылка на мероприятие: $event_link\n\n";
        $message .= "После проверки и одобрения мероприятия, отправьте организатору ссылку для оплаты.";
        
        // Получаем email всех администраторов
        $admins = $pdo->query("SELECT email FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $email) {
            sendEmail($email, $subject, $message);
        }
        
        // Отправляем уведомление в Telegram
        sendTelegramNotification("Требуется оплата за рекламное размещение: $event_title\n$event_link");
    }
}

// Функция для отправки email через PHPMailer и Yandex SMTP
function sendEmail($to, $subject, $message) {
    //$mail = new PHPMailer\PHPMailer\PHPMailer(true);
    
    //try {
        // Настройки сервера
        //$mail->isSMTP();
        //$mail->Host = 'smtp.yandex.ru';
        //$mail->SMTPAuth = true;
        //$mail->Username = 'your-yandex-email@yandex.ru'; // Ваш Yandex email
        //$mail->Password = 'your-yandex-password'; // Пароль от почты или приложения
        //$mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        //$mail->Port = 465;
        //$mail->CharSet = 'UTF-8';
        
        // Отправитель и получатель
        //$mail->setFrom('your-yandex-email@yandex.ru', 'Events Map');
        //$mail->addAddress($to);
        
        // Содержание письма
        //$mail->isHTML(false);
        //$mail->Subject = $subject;
        //$mail->Body = $message;
        
        //$mail->send();
        //return true;
    //} catch (Exception $e) {
        //error_log("Ошибка отправки письма: {$mail->ErrorInfo}");
        //return false;
    }
}

// Функция для отправки уведомления в Telegram
function sendTelegramNotification($text) {
    $botToken = TELEGRAM_BOT_TOKEN;
    $chatId = TELEGRAM_CHAT_ID;
    
    $url = "https://api.telegram.org/bot$botToken/sendMessage";
    $data = array(
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML'
    );
    
    $options = array(
        'http' => array(
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data)
        )
    );
    
    $context = stream_context_create($options);
    @file_get_contents($url, false, $context);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Добавить мероприятие</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
	    <style>
        #map { height: 400px; width: 100%; margin-bottom: 20px; }
        .address-suggestions { 
            position: absolute; 
            background: white; 
            border: 1px solid #ddd; 
            z-index: 1000; 
            width: calc(100% - 30px); 
            max-height: 200px; 
            overflow-y: auto; 
        }
        .suggestion-item { 
            padding: 8px 12px; 
            cursor: pointer; 
        }
        .suggestion-item:hover { 
            background-color: #f5f5f5; 
        }
        .balloon-content { max-width: 250px; }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h4>Добавить новое мероприятие</h4>
                    </div>
                    <div class="card-body">
                        <?php if (isset($error)): ?>
                        <div class="alert alert-danger"><?= $error ?></div>
                        <?php endif; ?>
                        
                        <form method="POST" id="eventForm" onsubmit="console.log('Координаты при отправке:', 
    document.getElementById('latitude').value, 
    document.getElementById('longitude').value); return true;">>
                            <?php if ($_SESSION['user_role'] === 'admin'): ?>
                                <div class="mb-3">
                                    <label for="organizer_id" class="form-label">Организатор <span class="text-danger">*</span></label>
                                    <select class="form-select" id="organizer_id" name="organizer_id" required>
                                        <?php 
                                        $organizers = $pdo->query("SELECT id, username FROM users WHERE role = 'organizer' ORDER BY username")->fetchAll();
                                        foreach ($organizers as $organizer): ?>
                                            <option value="<?= $organizer['id'] ?>">
                                                <?= htmlspecialchars($organizer['username']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php else: ?>
                                <input type="hidden" name="organizer_id" value="<?= $_SESSION['user_id'] ?>">
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <label for="title" class="form-label">Название мероприятия <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="title" name="title" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">Описание <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="description" name="description" rows="4" required></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="category_id" class="form-label">Категория <span class="text-danger">*</span></label>
                                    <select class="form-select" id="category_id" name="category_id" required>
                                        <option value="">Выберите категорию</option>
                                        <?php foreach ($categories as $category): ?>
                                        <option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="image_url" class="form-label">Ссылка на изображение</label>
                                    <input type="url" class="form-control" id="image_url" name="image_url" placeholder="https://example.com/image.jpg">
                                    <small class="text-muted">Можно использовать бесплатные изображения с <a href="https://unsplash.com" target="_blank">Unsplash</a> или <a href="https://pixabay.com" target="_blank">Pixabay</a></small>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="date_start" class="form-label">Дата и время начала <span class="text-danger">*</span></label>
                                    <input type="datetime-local" class="form-control" id="date_start" name="date_start" required>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="date_end" class="form-label">Дата и время окончания (если есть)</label>
                                    <input type="datetime-local" class="form-control" id="date_end" name="date_end">
                                </div>
                            </div>
                            
                            <div class="mb-3">
        <label for="address" class="form-label">Адрес <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="address" name="address" required>
        <small class="text-muted">Начните вводить адрес и выберите из списка или укажите место на карте</small>
    </div>
    
    <div id="map"></div>
    <input type="text" id="latitude" name="latitude">
    <input type="text" id="longitude" name="longitude">
	<input type="text" id="12"><span id="span">coords[0].toFixed(6)</span></input>
	<input type="text">coords[1].toFixed(6)
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="contact_phone" class="form-label">Контактный телефон</label>
                                    <input type="tel" class="form-control" id="contact_phone" name="contact_phone">
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="contact_email" class="form-label">Контактный email</label>
                                    <input type="email" class="form-control" id="contact_email" name="contact_email">
                                </div>
                            </div>
                            
                            <?php if ($_SESSION['user_role'] === 'admin' || $_SESSION['user_role'] === 'organizer'): ?>
                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="is_promoted" name="is_promoted" value="1">
                                <label class="form-check-label" for="is_promoted">Рекламное размещение</label>
                                <small class="text-muted d-block">Платное размещение с выделением мероприятия на карте</small>
                            </div>
                            <?php endif; ?>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">Добавить мероприятие</button>
                                <a href="index.php" class="btn btn-secondary">Отмена</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://api-maps.yandex.ru/2.1/?apikey=263f324d-f91e-4306-b49b-6d8ab7901f5f&lang=ru_RU"></script>
    <script>
        ymaps.ready(initMap);
        
        let map, placemark;
        
        function initMap() {
            map = new ymaps.Map("map", {
                center: [56.8587, 35.9176], // Тверь по умолчанию
                zoom: 11
            });
            
            // Создаем блок для подсказок адреса
            const addressInput = document.getElementById('address');
            const suggestionsContainer = document.createElement('div');
            suggestionsContainer.className = 'address-suggestions';
            addressInput.parentNode.appendChild(suggestionsContainer);
            
            // Обработчик ввода адреса
            addressInput.addEventListener('input', function() {
                const query = this.value;
                
                if (query.length < 3) {
                    suggestionsContainer.style.display = 'none';
                    return;
                }
                
                ymaps.suggest(query).then(function(items) {
                    suggestionsContainer.innerHTML = '';
                    
                    if (items.length === 0) {
                        suggestionsContainer.style.display = 'none';
                        return;
                    }
                    
                    items.forEach(function(item) {
                        const div = document.createElement('div');
                        div.className = 'suggestion-item';
                        div.textContent = item.value;
                        
                        div.addEventListener('click', function() {
                            addressInput.value = item.value;
                            suggestionsContainer.style.display = 'none';
                            geocodeAddress(item.value);
                        });
                        
                        suggestionsContainer.appendChild(div);
                    });
                    
                    suggestionsContainer.style.display = 'block';
                });
            });
            
            // Обработчик клика по карте
            map.events.add('click', function(e) {
                const coords = e.get('coords');
                updateMarker(coords);
                getAddressByCoords(coords);
            });
            
            // Скрываем подсказки при клике вне блока
            document.addEventListener('click', function(e) {
                if (e.target !== addressInput) {
                    suggestionsContainer.style.display = 'none';
                }
            });
        }
        
        function updateMarker(coords) {
            // Удаляем предыдущую метку
            if (placemark) {
                map.geoObjects.remove(placemark);
            }
            
            // Создаем новую метку
            placemark = new ymaps.Placemark(coords, {
                balloonContent: `
                    <div class="balloon-content">
                        <h4>${document.getElementById('title').value || 'Название мероприятия'}</h4>
                        <p>${document.getElementById('description').value || 'Описание мероприятия'}</p>
                        <p>${document.getElementById('address').value || ''}</p>
                    </div>
                `,
                iconCaption: document.getElementById('title').value || 'Мероприятие'
            }, {
                preset: 'islands#blueDotIcon',
                balloonCloseButton: false
            });
            
            map.geoObjects.add(placemark);
            placemark.balloon.open();
            
            // Сохраняем координаты в скрытые поля формы
            document.getElementById('latitude').value = coords[0].toFixed(6);
            document.getElementById('longitude').value = coords[1].toFixed(6);
			    // Добавим отладочный вывод
    console.log("Устанавливаем координаты:", coords);
    document.getElementById('latitude').value = coords[0].toFixed(6);
    document.getElementById('longitude').value = coords[1].toFixed(6);
    
    // Проверим, что значения установились
    console.log("Значения полей:", 
        document.getElementById('latitude').value, 
        document.getElementById('longitude').value);
}

		
        
        function geocodeAddress(address) {
            ymaps.geocode(address, { results: 1 }).then(function(res) {
                const firstGeoObject = res.geoObjects.get(0);
                
                if (firstGeoObject) {
                    const coords = firstGeoObject.geometry.getCoordinates();
                    map.setCenter(coords, 15);
                    updateMarker(coords);
                    document.getElementById('address').value = firstGeoObject.getAddressLine();
                }
            });
        }
        
        function getAddressByCoords(coords) {
            ymaps.geocode(coords).then(function(res) {
                const firstGeoObject = res.geoObjects.get(0);
                
                if (firstGeoObject) {
                    document.getElementById('address').value = firstGeoObject.getAddressLine();
                    if (placemark) {
                        placemark.properties.set('balloonContent', `
                            <div class="balloon-content">
                                <h4>${document.getElementById('title').value || 'Название мероприятия'}</h4>
                                <p>${document.getElementById('description').value || 'Описание мероприятия'}</p>
                                <p>${firstGeoObject.getAddressLine()}</p>
                            </div>
                        `);
                    }
                }
            });
        }
        
        // Устанавливаем минимальную дату - сегодня
        document.addEventListener('DOMContentLoaded', function() {
            const now = new Date();
            const timezoneOffset = now.getTimezoneOffset() * 60000;
            const localISOTime = (new Date(now - timezoneOffset)).toISOString().slice(0, 16);
            document.getElementById('date_start').min = localISOTime;
            document.getElementById('date_end').min = localISOTime;
            
            document.getElementById('date_start').addEventListener('change', function() {
                document.getElementById('date_end').min = this.value;
            });
        });
</script>
</body>
</html>