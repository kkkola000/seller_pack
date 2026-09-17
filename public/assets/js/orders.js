/* Страница «Заказы»: списки по поставщикам, скачивание и очистка. */
(function () {
  'use strict';

  var store = window.OrderStore;
  if (!store) { return; }

  var list = document.getElementById('js-orders-list');
  var totalLabel = document.getElementById('js-orders-total');
  var clearAllBtn = document.getElementById('js-clear-all');
  var hint = document.getElementById('js-orders-hint');

  function positionsLabel(count) {
    var mod10 = count % 10;
    var mod100 = count % 100;
    var word = 'позиций';
    if (mod10 === 1 && mod100 !== 11) { word = 'позиция'; }
    else if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) { word = 'позиции'; }
    return count + ' ' + word;
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /** Какие группы сейчас раскрыты — чтобы перерисовка их не схлопывала. */
  function openSupplierIds() {
    return Array.prototype.map.call(
      list.querySelectorAll('.order-group[open]'),
      function (node) { return node.getAttribute('data-supplier-id'); }
    );
  }

  function render() {
    var groups = store.bySupplier();
    var total = store.count();
    var opened = openSupplierIds();

    totalLabel.textContent = total === 0
      ? 'Список пуст'
      : positionsLabel(total) + ' · ' + store.totalQty() + ' шт.';
    clearAllBtn.classList.toggle('is-hidden', total === 0);
    hint.classList.toggle('is-hidden', total === 0);

    if (total === 0) {
      list.innerHTML = '<div class="empty">'
        + '<div class="empty__icon">📋</div>'
        + '<p class="empty__title">Список пуст</p>'
        + '<p class="empty__text">Добавляйте товары кнопкой «+» в каталоге — здесь они соберутся по поставщикам.</p>'
        + '<p><a class="btn btn--primary" href="index.php">Перейти в каталог</a></p>'
        + '</div>';
      return;
    }

    list.innerHTML = groups.map(function (group) {
      var rows = group.items.map(function (item) {
        var qty = store.qtyOf(item);
        return '<tr>'
          + '<td class="order-row__sku">' + escapeHtml(item.sku) + '</td>'
          + '<td class="order-row__name">' + escapeHtml(item.name) + '</td>'
          + '<td class="order-row__stock">' + escapeHtml(item.stock) + '</td>'
          + '<td class="order-row__qty">'
          + '<div class="qty">'
          + '<button type="button" class="qty__btn js-qty-minus" data-id="' + escapeHtml(item.id) + '"'
          + (qty <= 1 ? ' disabled' : '') + ' aria-label="Уменьшить количество">−</button>'
          + '<input class="qty__value js-qty-input" type="text" inputmode="numeric" value="' + qty + '"'
          + ' data-id="' + escapeHtml(item.id) + '" aria-label="Количество">'
          + '<button type="button" class="qty__btn js-qty-plus" data-id="' + escapeHtml(item.id) + '"'
          + ' aria-label="Увеличить количество">+</button>'
          + '</div></td>'
          + '<td class="order-row__price">' + escapeHtml(item.price) + '</td>'
          + '<td class="order-row__action">'
          + '<button type="button" class="order-row__remove js-remove" data-id="' + escapeHtml(item.id)
          + '" title="Убрать из списка" aria-label="Убрать из списка">×</button>'
          + '</td></tr>';
      }).join('');

      /* Список свёрнут по умолчанию: <details> без атрибута open */
      return '<details class="order-group" data-supplier-id="' + escapeHtml(group.id) + '">'
        + '<summary class="order-group__summary">'
        + '<span class="order-group__name">' + escapeHtml(group.supplier) + '</span>'
        + '<span class="order-group__count">' + positionsLabel(group.items.length)
        + ' · ' + group.items.reduce(function (sum, item) { return sum + store.qtyOf(item); }, 0) + ' шт.</span>'
        + '</summary>'
        + '<div class="order-group__body">'
        + '<div class="table-scroll"><table class="order-table">'
        + '<thead><tr><th>Артикул</th><th>Товар</th><th>Наличие</th><th>Кол-во</th><th>Цена</th><th></th></tr></thead>'
        + '<tbody>' + rows + '</tbody></table></div>'
        + '<div class="order-group__actions">'
        + '<button type="button" class="btn btn--primary js-download">Скачать</button>'
        + '<button type="button" class="btn btn--ghost js-clear">Очистить</button>'
        + '</div></div></details>';
    }).join('');

    opened.forEach(function (id) {
      var group = list.querySelector('.order-group[data-supplier-id="' + id + '"]');
      if (group) { group.open = true; }
    });
  }

  /* ---------- Скачивание ---------- */

  function csvCell(value) {
    var text = String(value == null ? '' : value);
    return '"' + text.replace(/"/g, '""') + '"';
  }

  /* Имя файла делаем латиницей: часть браузеров молча отбрасывает кириллицу
     в атрибуте download и сохраняет файл как «download». Содержимое при этом
     остаётся на русском. */
  var TRANSLIT = {
    а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ё: 'e', ж: 'zh', з: 'z', и: 'i',
    й: 'y', к: 'k', л: 'l', м: 'm', н: 'n', о: 'o', п: 'p', р: 'r', с: 's', т: 't',
    у: 'u', ф: 'f', х: 'h', ц: 'c', ч: 'ch', ш: 'sh', щ: 'sch', ъ: '', ы: 'y', ь: '',
    э: 'e', ю: 'yu', я: 'ya'
  };

  function latin(value) {
    var source = String(value || '');
    var result = '';
    for (var i = 0; i < source.length; i++) {
      var char = source[i];
      var lower = char.toLowerCase();
      if (Object.prototype.hasOwnProperty.call(TRANSLIT, lower)) {
        var replacement = TRANSLIT[lower];
        result += char === lower ? replacement
          : replacement.charAt(0).toUpperCase() + replacement.slice(1);
      } else {
        result += char;
      }
    }

    return result.replace(/[^A-Za-z0-9._-]+/g, '-').replace(/^-+|-+$/g, '');
  }

  function download(group) {
    var lines = [['Артикул', 'Товар', 'Количество', 'Наличие', 'Цена', 'Поставщик'].map(csvCell).join(';')];
    group.items.forEach(function (item) {
      lines.push([item.sku, item.name, store.qtyOf(item), item.stock, item.price, group.supplier]
        .map(csvCell).join(';'));
    });

    /* BOM нужен, чтобы Excel открыл кириллицу правильно */
    var blob = new Blob(['﻿' + lines.join('\r\n') + '\r\n'], { type: 'text/csv;charset=utf-8;' });
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');
    var date = new Date().toISOString().slice(0, 10);
    var name = latin(group.supplier) || ('supplier-' + group.id);

    link.href = url;
    link.download = 'zakaz_' + name + '_' + date + '.csv';
    link.rel = 'noopener';
    document.body.appendChild(link);
    link.click();

    /* Ссылку и объект убираем не сразу: браузер читает имя файла уже после клика */
    window.setTimeout(function () {
      if (link.parentNode) { link.parentNode.removeChild(link); }
      URL.revokeObjectURL(url);
    }, 1500);
  }

  function groupById(id) {
    return store.bySupplier().filter(function (group) { return String(group.id) === String(id); })[0];
  }

  /* ---------- События ---------- */

  list.addEventListener('click', function (event) {
    var details = event.target.closest('.order-group');
    if (!details) { return; }
    var supplierId = details.getAttribute('data-supplier-id');

    if (event.target.closest('.js-download')) {
      var group = groupById(supplierId);
      if (group) { download(group); }
      return;
    }

    if (event.target.closest('.js-clear')) {
      var current = groupById(supplierId);
      var name = current ? current.supplier : 'этого поставщика';
      if (window.confirm('Очистить список по поставщику «' + name + '»?')) {
        store.clearSupplier(supplierId);
      }
      return;
    }

    var minus = event.target.closest('.js-qty-minus');
    if (minus) {
      store.changeQty(minus.getAttribute('data-id'), -1);
      return;
    }

    var plus = event.target.closest('.js-qty-plus');
    if (plus) {
      store.changeQty(plus.getAttribute('data-id'), 1);
      return;
    }

    var remove = event.target.closest('.js-remove');
    if (remove) {
      store.remove(remove.getAttribute('data-id'));
    }
  });

  /* Количество можно ввести руками */
  list.addEventListener('change', function (event) {
    var input = event.target.closest('.js-qty-input');
    if (input) {
      store.setQty(input.getAttribute('data-id'), input.value);
    }
  });

  clearAllBtn.addEventListener('click', function () {
    if (window.confirm('Очистить списки по всем поставщикам?')) {
      store.clearAll();
    }
  });

  store.onChange(render);
  render();
})();
