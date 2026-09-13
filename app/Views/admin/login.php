<?php
/** @var string|null $error */
use App\Support\Csrf;
?>
<form class="card login" method="post" action="index.php?page=login">
  <?= Csrf::field() ?>
  <?php if ($error !== null): ?>
    <div class="alert alert--error"><?= e($error) ?></div>
  <?php endif; ?>

  <label class="field">
    <span class="field__label">Логин</span>
    <input class="input" type="text" name="username" autocomplete="username" required autofocus>
  </label>

  <label class="field">
    <span class="field__label">Пароль</span>
    <input class="input" type="password" name="password" autocomplete="current-password" required>
  </label>

  <button class="btn btn--primary btn--block" type="submit">Войти</button>
</form>
