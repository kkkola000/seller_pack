<?php
/** @var list<array<string,mixed>> $sources */
/** @var int $totalProducts */
/** @var list<array<string,mixed>> $lastRuns */
use App\Models\SourceRepository;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Scheduler;
?>
<?php
/** @var array{ping:?array,minutes:?int,auto_sources:int} $scheduler */
$autoCount = (int) ($scheduler['auto_sources'] ?? 0);
$minutes = $scheduler['minutes'];

// Базовый адрес сервиса — чтобы показать рабочую ссылку для планировщика
$base = rtrim(str_replace('\\', '/', dirname(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')))), '/');
$scheme = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$cronUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'ВАШ_ДОМЕН') . $base . '/api/cron.php?token='
    . rawurlencode((string) Config::get('cron_token', 'ТОКЕН'));
$cronCommand = 'php ' . APP_ROOT . '/bin/import.php --due --quiet';

if ($autoCount > 0):
    if ($minutes === null): ?>
      <div class="alert alert--error">
        <b>Планировщик ни разу не обращался к сервису.</b><br>
        Источников с автообновлением: <?= $autoCount ?>, но задача на сервере, судя по всему, не настроена —
        сами по себе прайсы обновляться не будут. В Plesk: <i>Инструменты и настройки → Запланированные задачи</i>,
        раз в час, один из вариантов:
        <div class="cron-hint">
          <div><b>Запустить команду:</b> <code><?= e($cronCommand) ?></code></div>
          <div><b>или Получить URL:</b> <code><?= e($cronUrl) ?></code></div>
        </div>
        Проверить можно прямо сейчас: откройте ссылку выше в соседней вкладке — она вернёт JSON с результатом.
        <span class="muted small">Время сервиса: <?= e(date('d.m.Y H:i')) ?> (<?= e(date_default_timezone_get()) ?>).</span>
      </div>
    <?php elseif ($minutes > 180): ?>
      <div class="alert alert--warning">
        <b>Планировщик молчит <?= e(Scheduler::humanAgo($minutes)) ?>.</b>
        Проверьте, что задача в Plesk включена и путь в ней верный:
        <div class="cron-hint">
          <div><code><?= e($cronCommand) ?></code></div>
          <div><code><?= e($cronUrl) ?></code></div>
        </div>
      </div>
    <?php else: ?>
      <div class="alert alert--success">
        Планировщик работает: последний запуск <?= e(Scheduler::humanAgo($minutes)) ?>
        (<?= $scheduler['ping']['via'] === 'url' ? 'по ссылке' : 'командой' ?>),
        источников с автообновлением: <?= $autoCount ?>.
        <span class="muted small">Время сервиса: <?= e(date('d.m.Y H:i')) ?> (<?= e(date_default_timezone_get()) ?>).</span>
      </div>
    <?php endif;
endif; ?>

<div class="toolbar">
  <div class="toolbar__stats">
    <span class="stat"><b><?= count($sources) ?></b> источник(ов)</span>
    <span class="stat"><b><?= e(number_format($totalProducts, 0, ',', ' ')) ?></b> товаров в каталоге</span>
  </div>
  <div class="toolbar__actions">
    <form method="post" action="index.php?page=import-all" onsubmit="return confirm('Запустить импорт всех активных источников? Это может занять несколько минут.');">
      <?= Csrf::field() ?>
      <button class="btn btn--ghost" type="submit">Обновить все</button>
    </form>
    <a class="btn btn--primary" href="index.php?page=source-form">+ Новый источник</a>
  </div>
</div>

<?php if ($sources === []): ?>
  <div class="card empty-state">
    <p><b>Источников пока нет.</b></p>
    <p>Добавьте первый прайс поставщика — файл Excel/CSV, YML-фид или ссылку на них.</p>
    <a class="btn btn--primary" href="index.php?page=source-form">+ Новый источник</a>
  </div>
<?php else: ?>
<div class="card table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th>Поставщик</th>
        <th>Тип</th>
        <th>Получение</th>
        <th>Последний импорт</th>
        <th>Статус</th>
        <th class="num">Товаров</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($sources as $source): ?>
      <?php [$statusClass, $statusLabel] = SourceRepository::statusLabel($source); ?>
      <tr>
        <td data-label="Поставщик">
          <a class="strong" href="index.php?page=source-form&id=<?= (int) $source['id'] ?>"><?= e($source['name']) ?></a>
          <?php if ($source['fetch_method'] === 'url'): ?>
            <div class="muted small ellipsis" title="<?= e($source['source_url']) ?>"><?= e($source['source_url']) ?></div>
          <?php elseif (!empty($source['original_filename'])): ?>
            <div class="muted small"><?= e($source['original_filename']) ?></div>
          <?php endif; ?>
        </td>
        <td data-label="Тип"><?= e(SourceRepository::TYPES[$source['type']] ?? $source['type']) ?></td>
        <td data-label="Получение">
          <?= $source['fetch_method'] === 'url' ? 'По ссылке' : 'Загрузка файла' ?>
          <?php if ((int) $source['auto_import'] === 1 && $source['fetch_method'] === 'url'): ?>
            <div class="muted small">авто, раз в <?= (int) $source['import_interval_minutes'] ?> мин</div>
            <?php $next = SourceRepository::nextRunAt($source); ?>
            <?php if ($next !== null): ?>
              <div class="muted small">следующее: <?= e($next) ?></div>
            <?php endif; ?>
          <?php endif; ?>
        </td>
        <td data-label="Последний импорт">
          <?= e(format_datetime($source['last_success_at'])) ?>
          <?php if (!empty($source['last_error'])): ?>
            <div class="muted small error-text" title="<?= e($source['last_error']) ?>"><?= e(mb_substr((string) $source['last_error'], 0, 90)) ?></div>
          <?php endif; ?>
        </td>
        <td data-label="Статус"><span class="pill pill--<?= e($statusClass) ?>"><?= e($statusLabel) ?></span></td>
        <td data-label="Товаров" class="num"><?= e(number_format((int) $source['products_count'], 0, ',', ' ')) ?></td>
        <td class="row-actions">
          <form method="post" action="index.php?page=import-run" class="inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) $source['id'] ?>">
            <button class="btn btn--small" type="submit" title="Запустить импорт сейчас">Импорт</button>
          </form>
          <a class="btn btn--small btn--ghost" href="index.php?page=source-preview&id=<?= (int) $source['id'] ?>">Проверка</a>
          <a class="btn btn--small btn--ghost" href="index.php?page=runs&source_id=<?= (int) $source['id'] ?>">Журнал</a>
          <form method="post" action="index.php?page=source-toggle" class="inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) $source['id'] ?>">
            <input type="hidden" name="active" value="<?= (int) $source['is_active'] === 1 ? 0 : 1 ?>">
            <button class="btn btn--small btn--ghost" type="submit">
              <?= (int) $source['is_active'] === 1 ? 'Отключить' : 'Включить' ?>
            </button>
          </form>
          <form method="post" action="index.php?page=source-delete" class="inline"
                onsubmit="return confirm('Удалить источник «<?= e($source['name']) ?>» и все его товары? Действие необратимо.');">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) $source['id'] ?>">
            <button class="btn btn--small btn--danger" type="submit">Удалить</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($lastRuns !== []): ?>
<h2 class="a-subtitle">Последние импорты</h2>
<div class="card table-wrap">
  <table class="table">
    <thead><tr><th>Источник</th><th>Начало</th><th>Результат</th><th class="num">Импортировано</th></tr></thead>
    <tbody>
    <?php foreach ($lastRuns as $run): ?>
      <tr>
        <td data-label="Источник"><?= e($run['source_name']) ?></td>
        <td data-label="Начало"><?= e(format_datetime($run['started_at'])) ?></td>
        <td data-label="Результат">
          <span class="pill pill--<?= $run['status'] === 'ok' ? 'ok' : ($run['status'] === 'error' ? 'error' : 'running') ?>">
            <?= $run['status'] === 'ok' ? 'Успешно' : ($run['status'] === 'error' ? 'Ошибка' : 'Выполняется') ?>
          </span>
          <div class="muted small"><?= e(mb_substr((string) $run['message'], 0, 120)) ?></div>
        </td>
        <td data-label="Импортировано" class="num"><?= (int) $run['rows_imported'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
