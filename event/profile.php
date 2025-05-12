<?php
require_once 'config.php';
checkAuth();

// Получаем данные текущего пользователя
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute(array($_SESSION['user_id']));
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Обработка формы обновления профиля
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $username = sanitize($_POST['username']);
    $email = sanitize($_POST['email']);
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    
    $errors = array();
    
    // Проверка уникальности username и email
    $stmt = $pdo->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
    $stmt->execute(array($username, $email, (array($_SESSION('user_id')))));
    if ($stmt->fetch()) {
        $errors[] = "Имя пользователя или email уже заняты";
    }
    
    // Если пытаются изменить пароль
    if (!empty($new_password)) {
        if (!password_verify($current_password, $user['password'])) {
            $errors[] = "Текущий пароль неверен";
        } elseif (strlen($new_password) < 6) {
            $errors[] = "Новый пароль должен быть не менее 6 символов";
        }
    }
    
    if (empty($errors)) {
        $data = array(
            'username' => $username,
            'email' => $email,
            'id' => $_SESSION['user_id']
        );
        
        $sql = "UPDATE users SET username = :username, email = :email";
        
        // Обновляем пароль, если он был изменен
        if (!empty($new_password)) {
            $sql .= ", password = :password";
            $data['password'] = password_hash($new_password, PASSWORD_DEFAULT);
        }
        
        $sql .= " WHERE id = :id";
        
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute($data)) {
            $_SESSION['success'] = "Профиль успешно обновлен";
            $_SESSION['username'] = $username;
            header("Location: profile.php");
            exit();
        } else {
            $errors[] = "Ошибка при обновлении профиля";
        }
    }
}

// Получаем мероприятия пользователя (для организаторов)
$user_events = array();
if ($_SESSION['user_role'] === 'organizer') {
    $stmt = $pdo->prepare("
        SELECT e.*, c.name as category_name 
        FROM events e 
        JOIN categories c ON e.category_id = c.id 
        WHERE e.organizer_id = ? 
        ORDER BY e.date_start DESC
        LIMIT 5
    ");
    $stmt->execute(array($_SESSION('user_id')));
    $user_events = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Профиль пользователя</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <?php include 'header.php'; ?>
    
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <h4>Мой профиль</h4>
                    </div>
                    <div class="card-body">
                        <?php if (isset($_SESSION['success'])): ?>
                        <div class="alert alert-success"><?= $_SESSION['success'] ?></div>
                        <?php unset($_SESSION['success']); endif; ?>
                        
                        <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <?php foreach ($errors as $error): ?>
                            <p><?= $error ?></p>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <div class="mb-3">
                                <label for="username" class="form-label">Имя пользователя</label>
                                <input type="text" class="form-control" id="username" name="username" 
                                       value="<?= htmlspecialchars($user['username']) ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?= htmlspecialchars($user['email']) ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="current_password" class="form-label">Текущий пароль</label>
                                <input type="password" class="form-control" id="current_password" name="current_password">
                                <small class="text-muted">Введите только если хотите изменить пароль</small>
                            </div>
                            
                            <div class="mb-3">
                                <label for="new_password" class="form-label">Новый пароль</label>
                                <input type="password" class="form-control" id="new_password" name="new_password">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Роль</label>
                                <input type="text" class="form-control" value="<?= $user['role'] ?>" disabled>
                            </div>
                            
                            <button type="submit" name="update_profile" class="btn btn-primary w-100">Обновить профиль</button>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <?php if ($_SESSION['user_role'] === 'organizer'): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h4>Мои мероприятия</h4>
                    </div>
                    <div class="card-body">
                        <?php if (empty($user_events)): ?>
                        <div class="alert alert-info">Вы еще не создали ни одного мероприятия</div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Название</th>
                                        <th>Категория</th>
                                        <th>Дата</th>
                                        <th>Статус</th>
                                        <th>Действия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($user_events as $event): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($event['title']) ?></td>
                                        <td><?= htmlspecialchars($event['category_name']) ?></td>
                                        <td><?= date('d.m.Y H:i', strtotime($event['date_start'])) ?></td>
                                        <td>
                                            <?php if (!$event['is_approved']): ?>
                                            <span class="badge bg-warning">На модерации</span>
                                            <?php elseif ($event['is_promoted'] && !$event['promotion_paid']): ?>
                                            <span class="badge bg-danger">Ожидает оплаты</span>
                                            <?php elseif ($event['is_promoted']): ?>
                                            <span class="badge bg-success">Рекламное</span>
                                            <?php else: ?>
                                            <span class="badge bg-primary">Опубликовано</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="index.php?event_id=<?= $event['id'] ?>" class="btn btn-sm btn-primary">Просмотр</a>
                                            <a href="edit_event.php?id=<?= $event['id'] ?>" class="btn btn-sm btn-secondary">Редактировать</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="text-end mt-2">
                                <a href="add_event.php" class="btn btn-success">Добавить мероприятие</a>
                                <a href="my_events.php" class="btn btn-outline-primary">Все мероприятия</a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-header">
                        <h4>Избранные мероприятия</h4>
                    </div>
                    <div class="card-body">
                        <?php $stmt = $pdo->prepare("
                            SELECT e.*, c.name as category_name 
                            FROM events e 
                            JOIN categories c ON e.category_id = c.id 
                            JOIN favorites f ON e.id = f.event_id 
                            WHERE f.user_id = ? AND e.is_approved = 1
                            ORDER BY e.date_start DESC
                            LIMIT 5
                        ");
                        $stmt->execute(array($_SESSION('user_id')));
                        $favorite_events = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        
                        <?php if (empty($favorite_events)): ?>
                        <div class="alert alert-info">У вас нет избранных мероприятий</div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Название</th>
                                        <th>Категория</th>
                                        <th>Дата</th>
                                        <th>Действия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($favorite_events as $event): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($event['title']) ?></td>
                                        <td><?= htmlspecialchars($event['category_name']) ?></td>
                                        <td><?= date('d.m.Y H:i', strtotime($event['date_start'])) ?></td>
                                        <td>
                                            <a href="index.php?event_id=<?= $event['id'] ?>" class="btn btn-sm btn-primary">Просмотр</a>
                                            <form method="POST" action="toggle_favorite.php" class="d-inline">
                                                <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
                                                <input type="hidden" name="action" value="remove">
                                                <button type="submit" class="btn btn-sm btn-danger">Удалить</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="text-end mt-2">
                                <a href="favorites.php" class="btn btn-outline-primary">Все избранные</a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>