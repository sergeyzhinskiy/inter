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
    $sql .= " AND e.date_start >= NOW()";
}

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
        .balloon-content {
            max-width: 300px;
        }
        .balloon-content img {
            max-width: 100%;
            height: auto;
            margin-bottom: 10px;
        }
        .past-event {
            opacity: 0.7;
        }
    </style>
    <script src="https://api-maps.yandex.ru/2.1/?apikey=263f324d-f91e-4306-b49b-6d8ab7901f5f&lang=ru_RU"></script>
</head>
<body>
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-3">
                <div class="card mb-4">
                    <div class="card-header">Фильтры</div>
                    <div class="card-body">
                        <form id="filterForm">
                            <div class="mb-3">
                                <label for="category" class="form-label">Категория</label>
                                <select class="form-select" id="category" name="category">
                                    <option value="">Все категории</option>
                                    <?php foreach ($categories as $category): ?>
                                    <option value="<?= $category['id'] ?>" <?= $categoryFilter == $category['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($category['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <?php if (in_array($_SESSION['user_role'], array('moderator', 'admin'))): ?>
                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="promoted" name="promoted" value="1" <?= $promotedOnly ? 'checked' : '' ?>>
                                <label class="form-check-label" for="promoted">Только рекламные</label>
                            </div>
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <label for="city" class="form-label">Город</label>
                                <input type="text" class="form-control" id="city" name="city" value="<?= htmlspecialchars($cityFilter) ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="date" class="form-label">Дата</label>
                                <input type="date" class="form-control" id="date" name="date" value="<?= htmlspecialchars($dateFilter) ?>">
                            </div>
                            
                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="show_past" name="show_past" value="1" <?= $showPastEvents ? 'checked' : '' ?>>
                                <label class="form-check-label" for="show_past">Показывать прошедшие</label>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100">Применить фильтры</button>
                        </form>
                    </div>
                </div>
                
                <!-- Блок с количеством мероприятий -->
                <div class="card mb-4">
                    <div class="card-header">Статистика</div>
                    <div class="card-body">
                        <p>Всего мероприятий: <span id="totalEvents"><?= count($events) ?></span></p>
                        <p>Будущих: <span id="futureEvents"><?= count(array_filter($events, function($e) { return strtotime($e['date_start']) >= time(); })) ?></span></p>
                        <p>Прошедших: <span id="pastEvents"><?= count(array_filter($events, function($e) { return strtotime($e['date_start']) < time(); })) ?></span></p>
                    </div>
                </div>
            </div>
            <div class="col-md-9">
                <div id="map" style="width: 100%; height: 600px;"></div>
            </div>
        </div>
    </div>

    <!-- Модальное окно с деталями мероприятия -->
    <div class="modal fade" id="eventModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="eventModalTitle"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <img src="" id="eventModalImage" class="img-fluid mb-3" style="max-height: 200px; width: 100%; object-fit: cover;">
                    <p id="eventModalDescription"></p>
                    <p><strong>Категория:</strong> <span id="eventModalCategory"></span></p>
                    <p><strong>Дата и время:</strong> <span id="eventModalDate"></span></p>
                    <p><strong>Адрес:</strong> <span id="eventModalAddress"></span></p>
                    <p><strong>Организатор:</strong> <span id="eventModalOrganizer"></span></p>
                    <p><strong>Контакты:</strong> <span id="eventModalContacts"></span></p>
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
    
    <script>
        // Передаем данные мероприятий в JavaScript
        const eventsData = <?= json_encode($events) ?>;
        const favorites = <?= json_encode($favorites) ?>;
        const userRole = '<?= $_SESSION['user_role'] ?>';
        
        // Функция для форматирования даты
        function formatDate(dateString) {
            const options = { 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric', 
                hour: '2-digit', 
                minute: '2-digit' 
            };
            return new Date(dateString).toLocaleString('ru-RU', options);
        }
        
        // Функция для проверки, прошло ли мероприятие
        function isEventPassed(dateString) {
            return new Date(dateString) < new Date();
        }
        
        // Инициализация карты
        function initMap() {
            const map = new ymaps.Map("map", {
                center: [56.8587, 35.9176], // Тверь по умолчанию
                zoom: 12
            });
            
            // Создаем коллекцию для хранения меток
            const eventCollection = new ymaps.GeoObjectCollection();
            
            // Перебираем все мероприятия и добавляем их на карту
            eventsData.forEach(event => {
                if (!event.latitude || !event.longitude) return;
                
                const isPassed = isEventPassed(event.date_start);
                const isFavorite = favorites.includes(event.id);
                const isPromoted = event.is_promoted;
                
                // Определяем иконку в зависимости от статуса мероприятия
                let iconColor;
                let iconCaption;
                
                if (isPassed) {
                    iconColor = 'gray'; // Серый для прошедших
                    iconCaption = 'Прошедшее';
                } else if (isPromoted) {
                    iconColor = 'orange'; // Оранжевый для рекламных
                    iconCaption = 'Рекламное';
                } else {
                    iconColor = 'blue'; // Синий для обычных будущих
                    iconCaption = '';
                }
                
                // Создаем HTML-содержимое для балуна
                const balloonContent = `
                    <div class="balloon-content ${isPassed ? 'past-event' : ''}">
                        ${event.image_url ? `<img src="${event.image_url}" alt="${event.title}" style="max-width:100%">` : ''}
                        <h4>${event.title}</h4>
                        <p>${event.description.length > 100 ? event.description.substring(0, 100) + '...' : event.description}</p>
                        <p><strong>Категория:</strong> ${event.category_name}</p>
                        <p><strong>Дата:</strong> ${formatDate(event.date_start)} ${isPassed ? '(прошедшее)' : ''}</p>
                        <p><strong>Адрес:</strong> ${event.address}</p>
                        <button class="btn btn-sm btn-primary mt-2" onclick="showEventDetails(${event.id})">Подробнее</button>
                    </div>
                `;
                
                // Создаем метку
                const placemark = new ymaps.Placemark(
                    [event.latitude, event.longitude],
                    {
                        balloonContent: balloonContent,
                        iconCaption: iconCaption,
                        eventId: event.id
                    },
                    {
                        preset: `islands#${iconColor}DotIcon`,
                        balloonCloseButton: false,
                        balloonContentLayout: ymaps.templateLayoutFactory.createClass(balloonContent)
                    }
                );
                
                // Добавляем метку в коллекцию
                eventCollection.add(placemark);
                
                // Обработчик клика по метке
                placemark.events.add('click', function() {
                    showEventDetails(event.id);
                });
            });
            
            // Добавляем коллекцию на карту
            map.geoObjects.add(eventCollection);
            
            // Если есть мероприятия, подгоняем карту чтобы показать их все
            if (eventsData.length > 0 && eventsData.some(e => e.latitude && e.longitude)) {
                map.setBounds(eventCollection.getBounds(), {
                    checkZoomRange: true
                });
            } else {
                // Если нет мероприятий или координат, показываем сообщение
                map.controls.add(new ymaps.control.Status({text: 'Нет мероприятий для отображения'}));
            }
        }
        
        // Функция для показа деталей мероприятия в модальном окне
        function showEventDetails(eventId) {
            const event = eventsData.find(e => e.id == eventId);
            if (!event) return;
            
            const isPassed = isEventPassed(event.date_start);
            const isFavorite = favorites.includes(event.id);
            
            // Заполняем модальное окно данными
            document.getElementById('eventModalTitle').textContent = event.title;
            document.getElementById('eventModalImage').src = event.image_url || '';
            document.getElementById('eventModalDescription').textContent = event.description;
            document.getElementById('eventModalCategory').textContent = event.category_name;
            document.getElementById('eventModalDate').textContent = formatDate(event.date_start) + (isPassed ? ' (прошедшее)' : '');
            document.getElementById('eventModalAddress').textContent = event.address;
            document.getElementById('eventModalOrganizer').textContent = event.organizer_name;
            document.getElementById('eventModalContacts').textContent = 
                (event.contact_phone ? `Тел: ${event.contact_phone}` : '') + 
                (event.contact_email ? `, Email: ${event.contact_email}` : '');
            
            // Настраиваем кнопку "В избранное"
            const favoriteBtn = document.getElementById('favoriteBtn');
            if (favoriteBtn) {
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
        
        // Функция для добавления/удаления из избранного
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
                    // Обновляем список избранного и перезагружаем страницу
                    location.reload();
                } else {
                    alert('Ошибка: ' + (data.message || 'Неизвестная ошибка'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Произошла ошибка при обновлении избранного');
            });
        }
        
        // Инициализация карты после загрузки страницы
        document.addEventListener('DOMContentLoaded', function() {
            ymaps.ready(initMap);
        });
    </script>
</body>
</html>