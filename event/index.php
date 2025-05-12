<?php
include 'header.php';
require_once 'config.php';
checkAuth();

// Получаем список категорий (ИСПРАВЛЕННЫЙ ЗАПРОС)
	$categories = $pdo->query("SELECT * FROM categories")->fetchAll(PDO::FETCH_ASSOC);

// Получаем параметры фильтрации (ИСПРАВЛЕННЫЙ КОД)
$categoryFilter = isset($_GET['category']) ? (int)$_GET['category'] : null;
$promotedOnly = isset($_GET['promoted']) && $_GET['promoted'] == '1';
$cityFilter = isset($_GET['city']) ? sanitize($_GET['city']) : null;
$dateFilter = isset($_GET['date']) ? sanitize($_GET['date']) : null;

// Формируем SQL запрос (ИСПРАВЛЕННЫЙ ЗАПРОС)
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

if ($promotedOnly && in_array($_SESSION(array('user_role')), array('moderator', 'admin'))) {
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

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем избранные мероприятия пользователя
$favorites = array();
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT event_id FROM favorites WHERE user_id = ?");
    $stmt->execute($_SESSION(array('user_id')));
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
    <script src="https://api-maps.yandex.ru/2.1/?apikey=<?= '263f324d-f91e-4306-b49b-6d8ab7901f5f' ?>&lang=ru_RU" type="text/javascript"></script>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="#">Карта мероприятий</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php">Карта</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="add_event.php">Добавить мероприятие</a>
                    </li>
                    <?php if (in_array($_SESSION(array('user_role')), array('moderator'), array('admin'))): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="moderation.php">Модерация</a>
                    </li>
                    <?php endif; ?>
                    <?php if ($_SESSION['user_role'] === 'admin'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="admin.php">Администрирование</a>
                    </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                            <?= htmlspecialchars($_SESSION['username']) ?> (<?= $_SESSION['user_role'] ?>)
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="profile.php">Профиль</a></li>
                            <li><a class="dropdown-item" href="favorites.php">Избранное</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php">Выйти</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

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
            
            <?php if (in_array($_SESSION(array('user_role')), array('moderator'), array('admin'))): ?>
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
            
            <button type="submit" class="btn btn-primary w-100">Применить фильтры</button>
        </form>
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
                    <button type="button" class="btn btn-primary" id="favoriteBtn">В избранное</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/map.js"></script>
    <script>
        // Передаем данные мероприятий в JavaScript
        const eventsData = <?= json_encode($events) ?>;
        const favorites = <?= json_encode($favorites) ?>;
        const userRole = '<?= $_SESSION['user_role'] ?>';
        
        // Инициализация карты после загрузки страницы
        document.addEventListener('DOMContentLoaded', function() {
            initMap(eventsData, favorites, userRole);
        });
    </script>
</body>
</html>