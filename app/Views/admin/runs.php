<?php
/** @var list<array<string,mixed>> $runs */
/** @var int $sourceId */
/** @var list<array<string,mixed>> $sources */
?>
<form class="toolbar" method="get" action="index.php">
  <input type="hidden" name="page" value="runs">
  <label class="field field--inline">
    <span class="field__label">Источник</span>
    <select class="input" name="source_id" onchange="this.form.submit()">
      <option value="0">Все источники</option>
      <?php foreach ($sources as $source): ?>
        <option value="<?= (int) $source['id'] ?>" <?= $sourceId === (int) $source['id'] ? 'selected' : '' ?>>
          <?= e($source['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
</form>

<?php if ($runs === []): ?>
  <div class="card empty-state"><p>Импортов пока не было.</p></div>
<?php else: ?>
<div class="card table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th>Источник</th><th>Запуск</th><th>Начало</th><th>Длительность</th>
        <th>Статус</th><th class="num">Прочитано</th><th class="num">Импортировано</th>
        <th class="num">Пропущено</th><th class="num">Удалено</th><th>Сообщение</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($runs as $run): ?>
      <?php
        $duration = '—';
        if (!empty($run['finished_at'])) {
            $seconds = max(0, strtotime((string) $run['finished_at']) - strtotime((string) $run['started_at']));
            $duration = $seconds < 60 ? $seconds . ' с' : intdiv($seconds, 60) . ' мин ' . ($seconds % 60) . ' с';
        }
        $triggers = ['manual' => 'вручную', 'cron' => 'по расписанию', 'upload' => 'после загрузки'];
      ?>
      <tr>
        <td data-label="Источник"><?= e($run['source_name']) ?></td>
        <td data-label="Запуск"><?= e($triggers[$run['trigger_type']] ?? $run['trigger_type']) ?></td>
        <td data-label="Начало"><?= e(format_datetime($run['started_at'])) ?></td>
        <td data-label="Длительность"><?= e($duration) ?></td>
        <td data-label="Статус">
          <span class="pill pill--<?= $run['status'] === 'ok' ? 'ok' : ($run['status'] === 'error' ? 'error' : 'running') ?>">
            <?= $run['status'] === 'ok' ? 'Успешно' : ($run['status'] === 'error' ? 'Ошибка' : 'Выполняется') ?>
          </span>
        </td>
        <td data-label="Прочитано" class="num"><?= (int) $run['rows_read'] ?></td>
        <td data-label="Импортировано" class="num"><?= (int) $run['rows_imported'] ?></td>
        <td data-label="Пропущено" class="num"><?= (int) $run['rows_skipped'] ?></td>
        <td data-label="Удалено" class="num"><?= (int) $run['rows_deleted'] ?></td>
        <td data-label="Сообщение" class="small"><?= e($run['message']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
