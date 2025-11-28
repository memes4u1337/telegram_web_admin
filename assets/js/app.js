
// ===== раскрытие групп меню и старые штуки =====
(function () {
  // раскрытие групп меню
  document.querySelectorAll('[data-toggle]').forEach(function (b) {
    b.addEventListener('click', function () {
      b.closest('.grp').classList.toggle('open');
    });
  });

  // автоскрытие тоста 
  var t = document.getElementById('toast');
  if (t) {
    setTimeout(function () {
      t.style.opacity = 0;
    }, 2200);
  }

  var cv = document.getElementById('lineChart');
  if (!cv) return;

  if (cv.getAttribute('data-real-chart') === '1') {
    return;
  }

  var ctx = cv.getContext('2d');
  var W = (cv.width = cv.clientWidth);
  var H = (cv.height = cv.getAttribute('height'));
  var pad = 26;
  var max = 100;

  var v = [], u = [];
  for (var i = 0; i < 14; i++) {
    v.push(35 + ((i * 13) % 60));
    u.push(20 + ((i * 17 + 7) % 60));
  }

  function X(i) {
    return pad + i * ((W - 2 * pad) / (v.length - 1));
  }
  function Y(val) {
    return H - pad - (val / max) * (H - 2 * pad);
  }

  ctx.clearRect(0, 0, W, H);
  ctx.strokeStyle = 'rgba(255,255,255,.06)';
  for (var y = pad; y <= H - pad; y += (H - 2 * pad) / 4) {
    ctx.beginPath();
    ctx.moveTo(pad, y);
    ctx.lineTo(W - pad, y);
    ctx.stroke();
  }

  ctx.lineWidth = 2.2;
  ctx.strokeStyle = '#9fb2ff';
  ctx.beginPath();
  v.forEach(function (val, i) {
    var x = X(i), y = Y(val);
    if (i === 0) ctx.moveTo(x, y);
    else ctx.lineTo(x, y);
  });
  ctx.stroke();

  ctx.strokeStyle = '#2adf96';
  ctx.beginPath();
  u.forEach(function (val, i) {
    var x = X(i), y = Y(val);
    if (i === 0) ctx.moveTo(x, y);
    else ctx.lineTo(x, y);
  });
  ctx.stroke();

  function dots(arr, color) {
    ctx.fillStyle = color;
    arr.forEach(function (val, i) {
      var x = X(i), y = Y(val);
      ctx.beginPath();
      ctx.arc(x, y, 2.5, 0, Math.PI * 2);
      ctx.fill();
    });
  }
  dots(v, '#9fb2ff');
  dots(u, '#2adf96');
})();

// ===== позиционирование плашки "Изменить тариф" =====
(function () {
  function placePlanPill() {
    var sidebar = document.querySelector('.sidebar');
    if (!sidebar) return;
    var card = sidebar.querySelector('.usercard.bottom');
    var pill = sidebar.querySelector('.plan-pill-wrapper');
    if (!card || !pill) return;

    var cardBottom = 10;
    var spacing = 12;
    var h = card.offsetHeight || 120;

    var needBottom = h + cardBottom + spacing;
    pill.style.bottom = needBottom + 'px';
    sidebar.style.setProperty('--pill-bottom', needBottom + 'px');
  }

  window.addEventListener('load', placePlanPill);
  window.addEventListener('resize', placePlanPill);

  setTimeout(placePlanPill, 150);
  setTimeout(placePlanPill, 500);
  setTimeout(placePlanPill, 1200);
})();

