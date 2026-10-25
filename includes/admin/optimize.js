/* Tối ưu web: Phân tích → Kế hoạch → Áp dụng → Kết quả. */
(function ($) {
  var C = window.WN_OPT;
  var root = document.getElementById('wn-opt');
  if (!root || !C) {
    return;
  }

  var GROUPS = C.groups;
  var FIXES = C.fixes;
  var DB_FIXES = ['db_transients', 'wc_geo_base', 'db_posts', 'db_orphans', 'db_leftovers', 'db_actions', 'db_optimize'];
  var CIRC = 2 * Math.PI * 84;
  var W = { good: 100, warn: 55, bad: 15 };
  var SYM = { good: '✓', warn: '!', bad: '✕', info: 'i' };
  var first = null; // kết quả phân tích ban đầu (mốc "trước")
  var running = false;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function sleep(ms) {
    return new Promise(function (r) { setTimeout(r, ms); });
  }

  function ajax(action, data) {
    return new Promise(function (resolve, reject) {
      $.post(C.ajax, $.extend({ action: action, nonce: C.nonce }, data || {}))
        .done(function (r) {
          if (r && r.success) {
            resolve(r.data);
          } else {
            reject(new Error((r && r.data) || 'Lỗi không rõ'));
          }
        })
        .fail(function (x) { reject(new Error('Lỗi kết nối (' + x.status + ')')); });
    });
  }

  /* ---------- Điểm ---------- */

  function scoreOf(scan) {
    var total = 0;
    var weights = 0;
    Object.keys(GROUPS).forEach(function (g) {
      var vals = (scan[g] || []).filter(function (c) { return W[c.status] !== undefined; }).map(function (c) { return W[c.status]; });
      if (!vals.length) {
        return;
      }
      var avg = vals.reduce(function (a, b) { return a + b; }, 0) / vals.length;
      total += avg * GROUPS[g].weight;
      weights += GROUPS[g].weight;
    });
    return weights ? Math.round(total / weights) : 0;
  }

  function allChecks(scan) {
    var out = [];
    Object.keys(GROUPS).forEach(function (g) { out = out.concat(scan[g] || []); });
    return out;
  }

  function metricsOf(scan) {
    var m = {};
    allChecks(scan).forEach(function (c) {
      if (c.metric) {
        m[c.metric.key] = c.metric;
      }
    });
    return m;
  }

  function colorFor(score) {
    return score >= 80 ? 'var(--o-good)' : score >= 50 ? 'var(--o-warn)' : 'var(--o-bad)';
  }

  /* ---------- Thành phần ---------- */

  function gauge(id, label, cls) {
    return '<div class="o-gauge ' + (cls || '') + '" id="' + id + '">' +
      '<div class="o-ring"></div><div class="o-ring"></div><div class="o-radar"></div>' +
      '<svg viewBox="0 0 200 200" aria-hidden="true"><circle class="tick" cx="100" cy="100" r="96"/>' +
      '<circle class="trk" cx="100" cy="100" r="84"/>' +
      '<circle class="val" cx="100" cy="100" r="84" stroke-dasharray="' + CIRC + '" stroke-dashoffset="' + CIRC + '"/></svg>' +
      '<div class="o-gauge-num"><b>–</b><span>' + esc(label) + '</span></div></div>';
  }

  function setGauge(id, score, color) {
    var el = document.getElementById(id);
    if (!el) {
      return;
    }
    el.style.setProperty('--o-c', color || colorFor(score));
    el.querySelector('.val').style.strokeDashoffset = CIRC * (1 - score / 100);
    var b = el.querySelector('b');
    var from = parseInt(b.textContent, 10) || 0;
    var start = performance.now();
    (function tick(now) {
      var p = Math.min(1, (now - start) / 1100);
      b.textContent = Math.round(from + (score - from) * (1 - Math.pow(1 - p, 3)));
      if (p < 1) {
        requestAnimationFrame(tick);
      }
    })(start);
  }

  function steps(active) {
    return '<div class="o-steps">' + ['Phân tích', 'Kế hoạch', 'Áp dụng', 'Kết quả'].map(function (l, i) {
      return '<div class="o-step ' + (i < active ? 'is-done' : i === active ? 'is-active' : '') + '"><i></i><span>' + l + '</span></div>';
    }).join('') + '</div>';
  }

  function consoleLog(box, text, cls) {
    if (!box) {
      return;
    }
    var caret = box.querySelector('.caret');
    if (caret) {
      caret.remove();
    }
    var d = document.createElement('div');
    d.innerHTML = '<span class="t">' + new Date().toTimeString().slice(0, 8) + '</span><span class="' + (cls || '') + '">' + esc(text) + '</span>';
    box.appendChild(d);
    var c = document.createElement('div');
    c.className = 'caret';
    box.appendChild(c);
    box.scrollTop = box.scrollHeight;
  }

  function checkRow(c, i) {
    return '<div class="o-check" style="animation-delay:' + (i * 70) + 'ms">' +
      '<span class="o-dot ' + c.status + '"></span>' +
      '<span class="o-check-title">' + esc(c.title) + '</span>' +
      '<span class="o-check-val">' + esc(c.value) + '</span>' +
      (c.detail ? '<span class="o-check-detail">' + esc(c.detail) + '</span>' : '') + '</div>';
  }

  function badges(checks) {
    var n = { good: 0, warn: 0, bad: 0 };
    checks.forEach(function (c) {
      if (n[c.status] !== undefined) {
        n[c.status]++;
      }
    });
    return ['good', 'warn', 'bad'].filter(function (k) { return n[k]; }).map(function (k) {
      return '<span class="o-badge ' + k + '">' + SYM[k] + ' ' + n[k] + '</span>';
    }).join('');
  }

  function groupCard(key) {
    var g = GROUPS[key];
    return '<div class="o-group is-queued" data-group="' + key + '">' +
      '<div class="o-group-head"><span class="o-group-icon dashicons ' + g.icon + '"></span>' +
      '<div class="o-group-text"><strong>' + esc(g.title) + '</strong><small>' + esc(g.say) + '</small></div>' +
      '<div class="o-group-badges"></div></div><div class="o-group-body"></div></div>';
  }

  function fmtMetric(m) {
    var v = Number(m.value);
    if (m.unit === 's') {
      return v.toFixed(2).replace('.', ',') + 's';
    }
    if (m.unit === 'MB') {
      return v.toFixed(1).replace('.', ',') + ' MB';
    }
    return Math.round(v).toLocaleString('vi-VN') + (m.unit ? (m.unit === '%' ? '%' : ' ' + m.unit) : '');
  }

  /* ---------- Màn 0: giới thiệu ---------- */

  function intro() {
    var last = (C.history || [])[0];
    var chips = Object.keys(GROUPS).map(function (k) {
      return '<span class="o-chip"><span class="dashicons ' + GROUPS[k].icon + '"></span>' + esc(GROUPS[k].title) + '</span>';
    }).join('');
    root.innerHTML = steps(-1) +
      '<div class="o-layout"><div class="o-side">' + gauge('o-g', 'Điểm tốc độ', 'is-idle') +
      '<div class="o-side-note">' + (last ? 'Điểm sau lần tối ưu gần nhất' : 'Chưa phân tích lần nào') + '</div></div>' +
      '<div><div class="o-title">Tăng tốc <em>' + esc(C.site) + '</em></div>' +
      '<p class="o-sub">Quét 7 nhóm: máy chủ, tốc độ, cache, database, ảnh, cron và bảo trì. Mất khoảng 30–60 giây và <strong>chưa thay đổi gì</strong> cho tới khi bạn duyệt kế hoạch. Database luôn được sao lưu trước khi dọn.</p>' +
      '<div class="o-chips">' + chips + '</div>' +
      '<div class="o-actions"><button type="button" class="o-btn" data-act="scan"><span class="dashicons dashicons-performance"></span> Bắt đầu phân tích</button></div>' +
      (last ? '<div class="o-history">Lần trước: <b>' + last.before + ' → ' + last.after + ' điểm</b> · ' +
        new Date(last.time * 1000).toLocaleString('vi-VN') + (last.user ? ' · ' + esc(last.user) : '') + '</div>' : '') +
      '</div></div>';
    if (last) {
      setTimeout(function () { setGauge('o-g', last.after); }, 200);
    }
  }

  /* ---------- Màn 1: phân tích ---------- */

  async function runScan(box, onGroup) {
    var scan = {};
    var keys = Object.keys(GROUPS);
    for (var i = 0; i < keys.length; i++) {
      var key = keys[i];
      if (onGroup) {
        onGroup(key, null);
      }
      consoleLog(box, '› ' + GROUPS[key].say + '…');
      try {
        var data = await ajax('wn_opt_scan', { group: key });
        scan[key] = data.checks;
        data.checks.forEach(function (c) {
          consoleLog(box, '  ' + SYM[c.status] + ' ' + c.title + ': ' + c.value, { good: 'ok', warn: 'wa', bad: 'er' }[c.status]);
        });
      } catch (e) {
        scan[key] = [{ id: key + '_error', title: GROUPS[key].title, status: 'info', value: 'Lỗi', detail: e.message }];
        consoleLog(box, '  ✕ ' + e.message, 'er');
      }
      if (onGroup) {
        onGroup(key, scan[key], scan);
      }
    }
    return scan;
  }

  async function analyze() {
    root.innerHTML = steps(0) +
      '<div class="o-layout"><div class="o-side">' + gauge('o-g', 'Điểm tốc độ', 'is-scanning') +
      '<div class="o-side-note" id="o-note">Đang quét…</div></div>' +
      '<div><div class="o-groups">' + Object.keys(GROUPS).map(groupCard).join('') + '</div>' +
      '<div class="o-console" id="o-console"><div class="caret"></div></div></div></div>';
    var box = document.getElementById('o-console');
    running = true;
    var scan = await runScan(box, function (key, checks, partial) {
      var card = root.querySelector('[data-group="' + key + '"]');
      if (!checks) {
        card.className = 'o-group is-running';
        card.querySelector('.o-group-body').innerHTML = '<div class="o-scanbar"></div>';
        card.querySelector('.o-group-badges').innerHTML = '<span class="o-spin"></span>';
        card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        return;
      }
      card.className = 'o-group is-done';
      card.querySelector('.o-group-badges').innerHTML = badges(checks);
      card.querySelector('.o-group-body').innerHTML = '<div class="o-checks">' + checks.map(checkRow).join('') + '</div>';
      setGauge('o-g', scoreOf(partial));
    });
    running = false;
    first = scan;
    var g = document.getElementById('o-g');
    g.classList.remove('is-scanning');
    document.getElementById('o-note').textContent = 'Phân tích xong';
    consoleLog(box, '✓ Hoàn tất. Điểm hiện tại: ' + scoreOf(scan) + '/100', 'ok');
    await sleep(900);
    plan(scan);
  }

  /* ---------- Màn 2: kế hoạch ---------- */

  function collectFixes(scan) {
    var map = {};
    allChecks(scan).forEach(function (c) {
      // Mục đang tốt không cần sửa; "Thu hồi dung lượng" chỉ có nghĩa khi có bước dọn database khác.
      if (!c.fix || !FIXES[c.fix] || c.status === 'good' || c.fix === 'db_optimize') {
        return;
      }
      map[c.fix] = map[c.fix] || { checks: [], def: false };
      map[c.fix].checks.push(c);
      map[c.fix].def = map[c.fix].def || !!c.fix_default;
    });
    var optimize = allChecks(scan).filter(function (c) { return c.fix === 'db_optimize'; })[0];
    if (optimize && Object.keys(map).some(function (k) { return DB_FIXES.indexOf(k) !== -1; })) {
      map.db_optimize = { checks: [optimize], def: true };
    }
    return Object.keys(FIXES).filter(function (k) { return map[k]; }).map(function (k) {
      return { key: k, label: FIXES[k], checks: map[k].checks, def: map[k].def };
    });
  }

  function plan(scan) {
    var score = scoreOf(scan);
    var checks = allChecks(scan);
    var count = { good: 0, warn: 0, bad: 0 };
    checks.forEach(function (c) {
      if (count[c.status] !== undefined) {
        count[c.status]++;
      }
    });
    var fixes = collectFixes(scan);
    var manual = checks.filter(function (c) { return c.manual || c.snippet || (c.command && c.status !== 'good'); });
    // Cùng một đoạn cấu hình (nginx) có thể gắn ở nhiều mục: chỉ hiện một lần.
    var shown = {};

    var fixHtml = fixes.map(function (f, i) {
      var main = f.checks[0];
      return '<label class="o-fix" style="animation-delay:' + (i * 50) + 'ms">' +
        '<input type="checkbox" value="' + f.key + '"' + (f.def ? ' checked' : '') + '><span class="o-fix-box"></span>' +
        '<span class="o-fix-text"><strong>' + esc(f.label) + '</strong>' +
        (main.detail ? '<small>' + esc(main.detail) + '</small>' : '') +
        '<span class="o-fix-tags">' + f.checks.map(function (c) {
          return '<span class="o-tag ' + c.status + '">' + esc(c.title) + ': ' + esc(c.value) + '</span>';
        }).join('') + '</span></span></label>';
    }).join('');

    var manualHtml = manual.map(function (c) {
      var snippet = c.snippet && !shown[c.snippet] ? c.snippet : '';
      shown[snippet] = true;
      if (!c.manual && !snippet && !c.command) {
        return '';
      }
      return '<div class="o-manual"><strong>' + esc(c.title) + ' · ' + esc(c.value) + '</strong>' +
        (c.manual ? '<p>' + esc(c.manual) + '</p>' : '') +
        (c.command ? '<div class="o-cmd"><code>' + esc(c.command) + '</code><button type="button" data-copy="' + esc(c.command) + '">Chép</button></div>' : '') +
        (snippet ? '<div class="o-snippet"><button type="button" data-copy="' + esc(snippet) + '">' + esc(c.snippet_label || 'Chép cấu hình') + '</button><pre>' + esc(snippet) + '</pre></div>' : '') +
        '</div>';
    }).join('');

    var detailHtml = Object.keys(GROUPS).map(function (g) {
      return '<div class="o-group is-done" style="margin-top:10px"><div class="o-group-head"><span class="o-group-icon dashicons ' + GROUPS[g].icon + '"></span>' +
        '<div class="o-group-text"><strong>' + esc(GROUPS[g].title) + '</strong></div><div class="o-group-badges">' + badges(scan[g] || []) + '</div></div>' +
        '<div class="o-checks">' + (scan[g] || []).map(checkRow).join('') + '</div></div>';
    }).join('');

    root.innerHTML = steps(1) +
      '<div class="o-layout"><div class="o-side">' + gauge('o-g', 'Điểm hiện tại') +
      '<div class="o-side-note">' + (fixes.length ? 'Áp dụng xong sẽ đo lại để so sánh trước/sau.' : 'Không còn gì để tự sửa.') + '</div></div>' +
      '<div><div class="o-title">' + (fixes.length ? 'Kế hoạch <em>tối ưu</em>' : 'Website đã <em>ngon</em>') + '</div>' +
      '<div class="o-summary"><div class="o-sum good"><b>' + count.good + '</b><span>Tốt</span></div>' +
      '<div class="o-sum warn"><b>' + count.warn + '</b><span>Cần cải thiện</span></div>' +
      '<div class="o-sum bad"><b>' + count.bad + '</b><span>Nghiêm trọng</span></div></div>' +
      (fixes.length ? '<div class="o-section">Tự động sửa · chọn mục muốn áp dụng</div><div class="o-fixes">' + fixHtml + '</div>' : '') +
      (manual.length ? '<div class="o-section">Cần làm tay</div>' + manualHtml : '') +
      '<details class="o-more"><summary>Xem toàn bộ kết quả phân tích</summary>' + detailHtml + '</details>' +
      '<div class="o-actions">' +
      (fixes.length ? '<button type="button" class="o-btn" data-act="apply"><span class="dashicons dashicons-controls-play"></span> <span data-count></span></button>' : '') +
      '<button type="button" class="o-btn o-btn-ghost" data-act="scan">Phân tích lại</button>' +
      (fixes.length ? '<span class="o-note"><span class="dashicons dashicons-backup"></span>Tự sao lưu database trước khi dọn</span>' : '') +
      '</div></div></div>';
    setTimeout(function () { setGauge('o-g', score); }, 100);
    updateCount();
  }

  function selectedFixes() {
    return Array.prototype.map.call(root.querySelectorAll('.o-fix input:checked'), function (i) { return i.value; });
  }

  function updateCount() {
    var n = selectedFixes().length;
    var btn = root.querySelector('[data-act="apply"]');
    if (btn) {
      btn.disabled = !n;
      btn.querySelector('[data-count]').textContent = n ? 'Áp dụng ' + n + ' tối ưu' : 'Chọn ít nhất 1 mục';
    }
  }

  /* ---------- Màn 3: áp dụng ---------- */

  async function apply(selected) {
    var seq = Object.keys(FIXES).filter(function (k) { return selected.indexOf(k) !== -1; });
    if (seq.some(function (k) { return DB_FIXES.indexOf(k) !== -1; })) {
      seq.unshift('backup_db');
    }
    seq.push('purge');

    root.innerHTML = steps(2) +
      '<div class="o-layout"><div class="o-side">' + gauge('o-g', 'Tiến độ', 'is-scanning') +
      '<div class="o-side-note">Đừng đóng trang này cho tới khi xong.</div></div>' +
      '<div><div class="o-title">Đang <em>áp dụng</em></div><div class="o-runlist" style="margin-top:18px">' +
      seq.map(function (k) {
        return '<div class="o-run is-queued" data-fix="' + k + '"><span class="o-run-icon">·</span><strong>' + esc(FIXES[k]) + '</strong>' +
          '<span class="o-run-note"></span><div class="o-bar"><i></i></div></div>';
      }).join('') + '</div><div class="o-console" id="o-console"><div class="caret"></div></div></div></div>';

    var box = document.getElementById('o-console');
    var gaugeEl = document.getElementById('o-g');
    gaugeEl.style.setProperty('--o-c', 'var(--o-red)');
    running = true;
    var aborted = false;

    for (var i = 0; i < seq.length; i++) {
      var fix = seq[i];
      var row = root.querySelector('[data-fix="' + fix + '"]');
      var bar = row.querySelector('.o-bar i');
      row.className = 'o-run is-running';
      row.querySelector('.o-run-icon').innerHTML = '<span class="o-spin" style="width:14px;height:14px"></span>';
      row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      consoleLog(box, '› ' + FIXES[fix] + '…');
      var state = {};
      var warn = false;
      try {
        for (;;) {
          var d = await ajax('wn_opt_apply', { fix: fix, state: JSON.stringify(state) });
          state = d.state || {};
          warn = warn || !!d.warn;
          bar.style.width = d.progress + '%';
          row.querySelector('.o-run-note').textContent = d.note || (d.done ? '' : d.progress + '%');
          (d.log || []).forEach(function (l) { consoleLog(box, '  ' + l, d.warn ? 'wa' : 'ok'); });
          setGauge('o-g', Math.round((i + d.progress / 100) * 100 / seq.length), 'var(--o-red)');
          if (d.done) {
            break;
          }
        }
        row.className = 'o-run ' + (warn ? 'is-warn' : 'is-done');
        row.querySelector('.o-run-icon').textContent = warn ? '!' : '✓';
      } catch (e) {
        row.className = 'o-run is-error';
        row.querySelector('.o-run-icon').textContent = '✕';
        consoleLog(box, '  ✕ ' + e.message, 'er');
        if (fix === 'backup_db') {
          consoleLog(box, 'Dừng lại: chưa sao lưu được database thì không dọn.', 'er');
          aborted = true;
          break;
        }
      }
      bar.style.width = '100%';
    }
    running = false;
    gaugeEl.classList.remove('is-scanning');
    if (aborted) {
      root.querySelector('.o-runlist').insertAdjacentHTML('afterend',
        '<div class="o-actions"><button type="button" class="o-btn o-btn-ghost" data-act="scan">Phân tích lại</button></div>');
      return;
    }
    setGauge('o-g', 100, 'var(--o-good)');
    consoleLog(box, '✓ Áp dụng xong. Đang đo lại…', 'ok');
    await sleep(1000);
    result();
  }

  /** So sánh một chỉ số trước/sau; chênh lệch nhỏ (nhiễu đo) coi như không đổi. */
  function compare(a, b) {
    var now = Number(a.value);
    var old = Number(b.value);
    var diff = Math.abs(now - old);
    var tiny = { s: 0.08, MB: 0.5, KB: 20, '%': 2 }[a.unit] || 0;
    if (diff <= tiny || (old && diff / old < 0.1)) {
      return '<span>≈ như cũ</span> · ';
    }
    var better = a.lower ? now < old : now > old;
    var text;
    if (a.unit === 's' && better && now > 0 && old / now >= 2) {
      text = 'nhanh hơn ' + Math.round(old / now) + ' lần';
    } else if (a.unit === '%') {
      text = (better ? '+' : '−') + Math.round(diff) + ' điểm %';
    } else {
      text = (better ? (a.lower ? 'giảm ' : 'tăng ') : (a.lower ? 'tăng ' : 'giảm ')) + Math.round(diff * 100 / (old || 1)) + '%';
    }
    return '<span class="' + (better ? 'up' : 'down') + '">' + (better ? '▲ ' : '▼ ') + text + '</span> · ';
  }

  /* ---------- Màn 4: kết quả ---------- */

  async function result() {
    root.innerHTML = steps(3) +
      '<div class="o-layout"><div class="o-side">' + gauge('o-g', 'Đang đo lại', 'is-scanning') +
      '<div class="o-side-note">Chạy lại toàn bộ phân tích để so sánh.</div></div>' +
      '<div><div class="o-title">Đo lại <em>sau tối ưu</em></div>' +
      '<div class="o-console" id="o-console"><div class="caret"></div></div></div></div>';
    running = true;
    var after = await runScan(document.getElementById('o-console'), function (k, checks, partial) {
      if (checks) {
        setGauge('o-g', scoreOf(partial));
      }
    });
    running = false;
    var before = first || after;
    var s0 = scoreOf(before);
    var s1 = scoreOf(after);
    var m0 = metricsOf(before);
    var m1 = metricsOf(after);

    var metrics = Object.keys(m1).map(function (k, i) {
      var a = m1[k];
      var b = m0[k];
      var change = b ? compare(a, b) : '';
      return '<div class="o-metric" style="animation-delay:' + (i * 80) + 'ms"><span>' + esc(a.label) + '</span><b>' + fmtMetric(a) + '</b>' +
        '<small>' + change + (b ? 'trước: ' + fmtMetric(b) : '') + '</small></div>';
    }).join('');

    var gain = s1 - s0;
    root.innerHTML = steps(4) +
      '<div style="text-align:center"><div class="o-title">' + (gain > 0 ? 'Web đã <em>ào ào</em> hơn' : 'Đã đo lại xong') + '</div>' +
      '<div class="o-versus" style="margin-top:22px">' + gauge('o-g0', 'Trước') + '<span class="o-arrow">→</span>' + gauge('o-g1', 'Sau', 'is-after') + '</div>' +
      (gain > 0 ? '<div class="o-gain">+' + gain + ' điểm</div>' : '') + '</div>' +
      '<div class="o-metrics">' + metrics + '</div>' +
      '<div class="o-actions" style="justify-content:center">' +
      '<a class="o-btn" href="' + esc(C.home) + '" target="_blank" rel="noopener"><span class="dashicons dashicons-external"></span> Xem website</a>' +
      '<button type="button" class="o-btn o-btn-ghost" data-act="plan-after">Xem chi tiết</button>' +
      '<button type="button" class="o-btn o-btn-ghost" data-act="scan">Phân tích lại</button></div>';
    root.dataset.after = JSON.stringify(after);
    setTimeout(function () {
      setGauge('o-g0', s0);
      setGauge('o-g1', s1);
    }, 150);
    if (gain > 0) {
      confetti();
    }
    ajax('wn_opt_finish', { before: s0, after: s1 }).catch(function () {});
    C.history = [{ time: Date.now() / 1000, before: s0, after: s1 }].concat(C.history || []);
    first = after;
  }

  function confetti() {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      return;
    }
    var colors = ['#ff3b4a', '#ff8a5c', '#2ee59d', '#7aa7ff', '#ffb547', '#ffffff'];
    var box = document.createElement('div');
    box.className = 'o-confetti';
    for (var i = 0; i < 70; i++) {
      var p = document.createElement('i');
      p.style.left = Math.random() * 100 + '%';
      p.style.background = colors[i % colors.length];
      p.style.animationDelay = Math.random() * 0.6 + 's';
      p.style.transform = 'rotate(' + Math.random() * 360 + 'deg)';
      box.appendChild(p);
    }
    root.appendChild(box);
    setTimeout(function () { box.remove(); }, 3600);
  }

  /* ---------- Sự kiện ---------- */

  $(root).on('click', '[data-act]', function () {
    var act = $(this).data('act');
    if (running) {
      return;
    }
    if (act === 'scan') {
      analyze();
    } else if (act === 'apply') {
      apply(selectedFixes());
    } else if (act === 'plan-after') {
      plan(JSON.parse(root.dataset.after || '{}'));
    }
  });

  $(root).on('change', '.o-fix input', updateCount);

  $(root).on('click', '[data-copy]', function () {
    var btn = this;
    var text = btn.getAttribute('data-copy');
    var done = function () {
      btn.textContent = 'Đã chép';
      setTimeout(function () { btn.textContent = 'Chép'; }, 1500);
    };
    if (navigator.clipboard) {
      navigator.clipboard.writeText(text).then(done);
    } else {
      var t = document.createElement('textarea');
      t.value = text;
      document.body.appendChild(t);
      t.select();
      document.execCommand('copy');
      t.remove();
      done();
    }
  });

  window.addEventListener('beforeunload', function (e) {
    if (running) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  intro();
})(jQuery);
