/* Заказ покупателя. Хранится в браузере: сервису учётные записи не нужны,
   у каждого посетителя свой список. */
window.OrderStore = (function () {
  'use strict';

  var KEY = 'catalog_order_v1';

  function read() {
    try {
      var raw = window.localStorage.getItem(KEY);
      var items = raw ? JSON.parse(raw) : [];
      return Array.isArray(items) ? items : [];
    } catch (e) {
      return [];
    }
  }

  function write(items) {
    try {
      window.localStorage.setItem(KEY, JSON.stringify(items));
    } catch (e) {
      /* приватный режим или переполненное хранилище — заказ просто не сохранится */
    }
    notify(items);
    return items;
  }

  var listeners = [];

  function notify(items) {
    listeners.forEach(function (fn) {
      try { fn(items); } catch (e) { /* один обработчик не должен ломать остальные */ }
    });
  }

  function onChange(fn) {
    listeners.push(fn);
    /* Заказ мог измениться в соседней вкладке */
    window.addEventListener('storage', function (event) {
      if (event.key === KEY) { fn(read()); }
    });
  }

  function has(id) {
    return read().some(function (item) { return String(item.id) === String(id); });
  }

  function add(item) {
    var items = read();
    if (items.some(function (existing) { return String(existing.id) === String(item.id); })) {
      return items;
    }
    item.qty = 1;
    item.added_at = new Date().toISOString();
    items.push(item);
    return write(items);
  }

  /** Количество: не меньше одной штуки, убрать товар можно крестиком. */
  function setQty(id, qty) {
    var value = Math.max(1, Math.min(9999, parseInt(qty, 10) || 1));
    return write(read().map(function (item) {
      if (String(item.id) === String(id)) { item.qty = value; }
      return item;
    }));
  }

  function changeQty(id, delta) {
    var items = read();
    var current = 1;
    items.forEach(function (item) {
      if (String(item.id) === String(id)) { current = qtyOf(item); }
    });

    return setQty(id, current + delta);
  }

  /** У списков, собранных до появления количества, его нет — считаем за единицу. */
  function qtyOf(item) {
    var value = parseInt(item.qty, 10);
    return value > 0 ? value : 1;
  }

  /** Общее количество штук во всём списке. */
  function totalQty() {
    return read().reduce(function (sum, item) { return sum + qtyOf(item); }, 0);
  }

  function remove(id) {
    return write(read().filter(function (item) { return String(item.id) !== String(id); }));
  }

  function toggle(item) {
    return has(item.id) ? (remove(item.id), false) : (add(item), true);
  }

  function count() {
    return read().length;
  }

  /* Группировка по поставщику — в том виде, в каком показывает страница заказов */
  function bySupplier() {
    var groups = {};
    read().forEach(function (item) {
      var key = String(item.supplier_id || '0');
      if (!groups[key]) {
        groups[key] = { id: key, supplier: item.supplier || 'Без поставщика', items: [] };
      }
      groups[key].items.push(item);
    });

    return Object.keys(groups)
      .map(function (key) { return groups[key]; })
      .sort(function (a, b) { return a.supplier.localeCompare(b.supplier, 'ru'); });
  }

  function clearSupplier(supplierId) {
    return write(read().filter(function (item) {
      return String(item.supplier_id || '0') !== String(supplierId);
    }));
  }

  function clearAll() {
    return write([]);
  }

  return {
    read: read, has: has, add: add, remove: remove, toggle: toggle,
    count: count, bySupplier: bySupplier, qtyOf: qtyOf, totalQty: totalQty,
    setQty: setQty, changeQty: changeQty,
    clearSupplier: clearSupplier, clearAll: clearAll, onChange: onChange
  };
})();
