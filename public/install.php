<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Migrator;

$errors = [];
$done = false;

/** Создаёт таблицы и применяет миграции. */
function install_schema(): void
{
    Migrator::migrate();
}

$connectionError = null;
try {
    Db::pdo();
} catch (Throwable $exception) {
    $connectionError = $exception->getMessage();
}

$installed = $connectionError === null && Db::tableExists('admin_users') && Auth::usersCount() > 0;

if ($connectionError === null && $_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    Csrf::check();

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');

    if (mb_strlen($username) < 3) {
        $errors[] = 'Логин должен быть не короче 3 символов.';
    }
    if (mb_strlen($password) < 8) {
        $errors[] = 'Пароль должен быть не короче 8 символов.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Пароли не совпадают.';
    }

    foreach (['uploads', 'tmp', 'logs'] as $dir) {
        if (!is_writable(APP_STORAGE . '/' . $dir)) {
            $errors[] = 'Нет прав на запись в storage/' . $dir . ' — задайте права 775 для этой папки.';
        }
    }

    if ($errors === []) {
        try {
            install_schema();
            if (Auth::usersCount() === 0) {
                Auth::createUser($username, $password);
            }
            $done = true;
        } catch (Throwable $exception) {
            $errors[] = 'Ошибка установки: ' . $exception->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Установка каталога</title>
<link rel="stylesheet" href="<?= e(asset('assets/css/admin.css')) ?>">
</head>
<body>
<main class="a-main a-main--narrow">
  <h1 class="a-title">Установка</h1>

  <?php if ($connectionError !== null): ?>
    <div class="alert alert--error">
      Нет подключения к базе данных.<br><?= e($connectionError) ?><br><br>
      Проверьте секцию <code>db</code> в файле <code>config.php</code>.
    </div>
  <?php elseif ($installed): ?>
    <div class="alert alert--success">Сервис уже установлен.</div>
    <p><a class="btn btn--primary" href="admin/index.php">Перейти в админ-панель</a></p>
    <p class="muted small">Для безопасности удалите файл <code>public/install.php</code>.</p>
  <?php elseif ($done): ?>
    <div class="alert alert--success">Готово! Таблицы созданы, администратор добавлен.</div>
    <p><a class="btn btn--primary" href="admin/index.php">Войти в админ-панель</a></p>
    <p class="muted small">Обязательно удалите файл <code>public/install.php</code>.</p>
  <?php else: ?>
    <?php foreach ($errors as $error): ?>
      <div class="alert alert--error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <form class="card" method="post" action="install.php">
      <?= Csrf::field() ?>
      <p class="card__hint">Будут созданы таблицы каталога и учётная запись администратора.</p>

      <label class="field">
        <span class="field__label">Логин администратора</span>
        <input class="input" type="text" name="username" required minlength="3"
               value="<?= e($_POST['username'] ?? 'admin') ?>" autocomplete="username">
      </label>

      <label class="field">
        <span class="field__label">Пароль</span>
        <input class="input" type="password" name="password" required minlength="8" autocomplete="new-password">
        <span class="field__hint">Минимум 8 символов.</span>
      </label>

      <label class="field">
        <span class="field__label">Повторите пароль</span>
        <input class="input" type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
      </label>

      <button class="btn btn--primary btn--block" type="submit">Установить</button>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
