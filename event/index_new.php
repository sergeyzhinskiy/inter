<?php
header('Content-Type: text/html; charset=UTF-8');
include '/includes/header_new.php';
require_once 'config.php';
checkAuth();

// Получаем список категорий
$categories = $pdo->query("SELECT * FROM categories")->fetchAll(PDO::FETCH_ASSOC);

// Получаем параметры фильтрации
$categoryFilter = isset($_GET['category']) ? (int)$_GET['category'] : null;
$promotedOnly = isset($_GET['promoted']) && $_GET['promoted'] == '1';
$cityFilter = isset($_GET['city']) ? sanitize($_GET['city']) : null;
$dateFilter = isset($_GET['date']) ? sanitize($_GET['date']) : null;
$showPastEvents = isset($_GET['show_past']) && $_GET['show_past'] == '1';

// Формируем SQL запрос
$sql = "SELECT e.*, c.name as category_name, u.username as organizer_name 
        FROM events e 
        JOIN categories c ON e.category_id = c.id 
        JOIN users u ON e.organizer_id = u.id 
        WHERE e.is_approved = 1";

$params = array();

if ($categoryFilter) {
    $sql .= " AND e.category_id = ?";
    $params[] = $categoryFilter;
}

if ($promotedOnly && in_array($_SESSION['user_role'], array('moderator', 'admin'))) {
    $sql .= " AND e.is_promoted = 1";
}

if ($cityFilter) {
    $sql .= " AND e.address LIKE ?";
    $params[] = "%$cityFilter%";
}

if ($dateFilter) {
    $sql .= " AND DATE(e.date_start) = ?";
    $params[] = $dateFilter;
}

// Фильтр по прошедшим/будущим мероприятиям
if (!$showPastEvents) {
    $sql .= " AND e.date_start >= CURDATE()";
}

// Добавляем сортировку
$sql .= " ORDER BY e.date_start ASC";

// Выполняем запрос
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем избранные мероприятия пользователя
$favorites = array();
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT event_id FROM favorites WHERE user_id = ?");
    $stmt->execute(array($_SESSION['user_id']));
    $favorites = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Статистика
$totalEvents = count($events);
$futureEvents = count(array_filter($events, function($e) { 
    return strtotime($e['date_start']) >= time(); 
}));
$pastEvents = $totalEvents - $futureEvents;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Карта мероприятий</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <style>
        #map { height: 600px; width: 100%; }
        .balloon-content { max-width: 250px; }
        .balloon-content img { max-width: 100%; height: auto; }
        .past-event { opacity: 0.7; }
        .event-counter { font-weight: bold; }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-3">
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">Фильтры</div>
                    <div class="card-body">
                        <form id="filterForm" method="GET">
                            <div class="mb-3">
                                <label for="category" class="form-label">Категория</label>
                                <select class="form-select" id="category" name="category">
                                    <option value="">Все категории</option>
                                    <?php foreach ($categories as $category): ?>
                                    <option value="<?= $category['id'] ?>" 
                                        <?= $categoryFilter == $category['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($category['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <?php if (in_array($_SESSION['user_role'], array('moderator', 'admin'))): ?>
                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="promoted" name="promoted" value="1" 
                                    <?= $promotedOnly ? 'checked' : '' ?>>
                                <label class="form-check-label" for="promoted">Только рекламные</label>
                            </div>
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <label for="city" class="form-label">Город</label>
                                <input type="text" class="form-control" id="city" name="city" 
                                    value="<?= htmlspecialchars($cityFilter) ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="date" class="form-label">Дата</label>
                                <input type="date" class="form-control" id="date" name="date" 
                                    value="<?= htmlspecialchars($dateFilter) ?>">
                            </div>
                            
                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="show_past" name="show_past" value="1" 
                                    <?= $showPastEvents ? 'checked' : '' ?>>
                                <label class="form-check-label" for="show_past">Показывать прошедшие</label>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100">Применить фильтры</button>
                        </form>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header bg-info text-white">Статистика</div>
                    <div class="card-body">
                        <p>Всего: <span class="event-counter"><?= $totalEvents ?></span></p>
                        <p>Будущих: <span class="event-counter text-success"><?= $futureEvents ?></span></p>
                        <p>Прошедших: <span class="event-counter text-secondary"><?= $pastEvents ?></span></p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-9">
                <div class="card">
                    <div class="card-header bg-primary text-white">Карта мероприятий</div>
                    <div class="card-body p-0">
                         <div id="map" ></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно -->
    <div class="modal fade" id="eventModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="eventModalTitle"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <img src="" id="eventModalImage" class="img-fluid rounded mb-3">
                        </div>
                        <div class="col-md-6">
                            <p id="eventModalDescription"></p>
                            <p><strong>Категория:</strong> <span id="eventModalCategory"></span></p>
                            <p><strong>Дата:</strong> <span id="eventModalDate"></span></p>
                            <p><strong>Адрес:</strong> <span id="eventModalAddress"></span></p>
                            <p><strong>Организатор:</strong> <span id="eventModalOrganizer"></span></p>
                            <p><strong>Контакты:</strong> <span id="eventModalContacts"></span></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                    <?php if (isset($_SESSION['user_id'])): ?>
                    <button type="button" class="btn btn-primary" id="favoriteBtn">В избранное</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://api-maps.yandex.ru/2.1/?apikey=263f324d-f91e-4306-b49b-6d8ab7901f5f&lang=ru_RU"></script>
<script>
    ymaps.ready(initMap);
    
    function initMap() {
        // Создаем карту
        var myMap = new ymaps.Map("map", {
            center: [56.8587, 35.9176], // Координаты центра карты (Тверь)
            zoom: 12
        });
        
        // Данные мероприятий из PHP
        const eventsData = <?= json_encode($events) ?>;
        
        // Создаем коллекцию для меток
        var eventCollection = new ymaps.GeoObjectCollection();
        
        // Добавляем метки для каждого мероприятия
        eventsData.forEach(function(event) {
            if (!event.latitude || !event.longitude) return;
            
            // Определяем цвет иконки в зависимости от типа мероприятия
            var iconColor;
            var iconPreset;
            var isPast = new Date(event.date_start) < new Date();
            
            if (isPast) {
                iconColor = '#aaaaaa'; // Серый для прошедших
                iconPreset = 'islands#grayDotIconWithCaption';
            } else if (event.is_promoted) {
                iconColor = '#ff9900'; // Оранжевый для рекламных
                iconPreset = 'islands#orangeDotIconWithCaption';
            } else {
                iconColor = '#0095b6'; // Синий для обычных
                iconPreset = 'islands#blueDotIconWithCaption';
            }
            
            // Создаем содержимое балуна
            var balloonContent = [
                '<div style="padding: 10px; max-width: 300px;">',
                event.image_url ? '<img src="' + event.image_url + '" style="max-width:100%; margin-bottom:10px;">' : '',
                '<h5 style="margin-top:0;">' + event.title + '</h5>',
                '<p><strong>Дата:</strong> ' + formatDate(event.date_start) + '</p>',
                '<p><strong>Адрес:</strong> ' + event.address + '</p>',
                '<p>' + (event.description.length > 100 ? 
                    event.description.substring(0, 100) + '...' : event.description) + '</p>',
                '<button class="btn btn-sm btn-primary" onclick="showEventDetails(' + event.id + ')">',
                'Подробнее',
                '</button>',
                '</div>'
            ].join('');
            
            // Создаем метку
            var placemark = new ymaps.Placemark(
                [parseFloat(event.latitude), parseFloat(event.longitude)], 
                {
                    balloonContentHeader: event.title,
                    balloonContent: balloonContent,
                    balloonContentFooter: formatDate(event.date_start),
                    iconCaption: event.category_name
                }, 
                {
                    preset: iconPreset,
                    iconColor: iconColor,
                    balloonCloseButton: false,
                    hideIconOnBalloonOpen: false
                }
            );
            
            // Добавляем метку в коллекцию
            eventCollection.add(placemark);
        });
        
        // Добавляем коллекцию меток на карту
        myMap.geoObjects.add(eventCollection);
        
        // Автомасштабирование, если есть мероприятия
        if (eventCollection.getLength() > 0) {
            myMap.setBounds(eventCollection.getBounds(), {
                checkZoomRange: true,
                zoomMargin: 50
            });
        }
    }
    
    // Функция для форматирования даты
    function formatDate(dateString) {
        var options = { 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric', 
            hour: '2-digit', 
            minute: '2-digit' 
        };
        return new Date(dateString).toLocaleString('ru-RU', options);
    }
    
    // Функция для показа деталей мероприятия (оставляем вашу существующую реализацию)

    // Функция для показа деталей мероприятия
    function showEventDetails(eventId) {
        const event = eventsData.find(e => e.id == eventId);
        if (!event) return;
        
        // Заполняем модальное окно данными
        document.getElementById('eventModalTitle').textContent = event.title;
        document.getElementById('eventModalImage').src = event.image_url || '';
        document.getElementById('eventModalDescription').textContent = event.description;
        document.getElementById('eventModalCategory').textContent = event.category_name;
        document.getElementById('eventModalDate').textContent = formatDate(event.date_start);
        document.getElementById('eventModalAddress').textContent = event.address;
        document.getElementById('eventModalOrganizer').textContent = event.organizer_name;
        
        let contacts = [];
        if (event.contact_phone) contacts.push(`Тел: ${event.contact_phone}`);
        if (event.contact_email) contacts.push(`Email: ${event.contact_email}`);
        document.getElementById('eventModalContacts').textContent = contacts.join(', ');
        
        // Настраиваем кнопку избранного
        const favoriteBtn = document.getElementById('favoriteBtn');
        if (favoriteBtn) {
            const isFavorite = favorites.includes(event.id);
            favoriteBtn.textContent = isFavorite ? 'Удалить из избранного' : 'В избранное';
            favoriteBtn.className = isFavorite ? 'btn btn-danger' : 'btn btn-primary';
            favoriteBtn.onclick = function() {
                toggleFavorite(event.id, !isFavorite);
            };
        }
        
        // Показываем модальное окно
        const modal = new bootstrap.Modal(document.getElementById('eventModal'));
        modal.show();
    }
    
    // Функция для работы с избранным
    function toggleFavorite(eventId, addToFavorites) {
        fetch('toggle_favorite.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `event_id=${eventId}&action=${addToFavorites ? 'add' : 'remove'}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Ошибка: ' + (data.message || 'Неизвестная ошибка'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Ошибка соединения');
        });
    }
</script>
</body>
</html>