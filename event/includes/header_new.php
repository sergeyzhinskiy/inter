<?php
require_once 'config.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Карта мероприятий</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php">Карта мероприятий</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>" href="index_new.php">Карта</a>
                    </li>
                    <?php if (isset($_SESSION['user_id'])): ?>
    <li class="nav-item">
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'add_event.php' ? 'active' : '' ?>" href="add_event.php">Добавить мероприятие</a>
    </li>
    <?php if (in_array($_SESSION['user_role'], array('moderator', 'admin'))): ?>
    <li class="nav-item">
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'moderation.php' ? 'active' : '' ?>" href="moderation.php">Модерация</a>
    </li>
    <?php endif; ?>
    <?php if ($_SESSION['user_role'] === 'admin'): ?>
    <li class="nav-item">
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'admin.php' ? 'active' : '' ?>" href="admin.php">Администрирование</a>
    </li>
    <?php endif; ?>
<?php endif; ?>
                </ul>
                <ul class="navbar-nav">
                    <?php if (isset($_SESSION['user_id'])): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                            <?= htmlspecialchars($_SESSION['username']) ?> (<?= $_SESSION['user_role'] ?>)
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="profile.php">Профиль</a></li>
                            <li><a class="dropdown-item" href="favorites.php">Избранное</a></li>
                            <?php if ($_SESSION['user_role'] === 'organizer'): ?>
                            <li><a class="dropdown-item" href="my_events.php">Мои мероприятия</a></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php">Выйти</a></li>
                        </ul>
                    </li>
                    <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link" href="login.php">Вход</a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-4">