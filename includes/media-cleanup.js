jQuery(function ($) {
  const C = WN_CLEANUP;

  function post(action, data) {
    return $.post(C.ajax, $.extend({ action: 'wn_cleanup_' + action, nonce: C.nonce }, data || {}));
  }

  function esc(s) {
    return $('<div>').text(s == null ? '' : String(s)).html();
  }

  /** Bảng ảnh có ô chọn + các nút thao tác ở trên. */
  function renderTable($box, items, buttons, emptyText) {
    if (!items.length) {
      $box.html('<p>' + esc(emptyText) + '</p>');
      return;
    }
    let html = '<div class="wn-bar"><label><input type="checkbox" class="wn-all"> Chọn tất cả</label>';
    buttons.forEach(function (b) {
      html += '<button type="button" class="button ' + (b.cls || '') + '" data-act="' + b.act + '">' + esc(b.label) + '</button>';
    });
    html += '<span class="wn-msg"></span></div><div class="wn-list"><table class="widefat striped"><thead><tr>' +
      '<td class="check-column"></td><th>Ảnh</th><th>Tên / file</th><th>Dung lượng</th><th>Ngày</th></tr></thead><tbody>';
    items.forEach(function (it) {
      const name = it.edit ? '<a href="' + esc(it.edit) + '" target="_blank">' + esc(it.title || '#' + it.id) + '</a>' : esc(it.title || '#' + it.id);
      html += '<tr data-id="' + it.id + '"><th class="check-column"><input type="checkbox" value="' + it.id + '"></th>' +
        '<td>' + (it.thumb ? '<img loading="lazy" src="' + esc(it.thumb) + '" alt="">' : '') + '</td>' +
        '<td>' + name + '<br><code>' + esc(it.file) + '</code></td>' +
        '<td>' + esc(it.size) + '</td><td>' + esc(it.date) + '</td></tr>';
    });
    $box.html(html + '</tbody></table></div>');
  }

  $(document).on('change', '.wn-all', function () {
    $(this).closest('div').parent().find('tbody input[type=checkbox]').prop('checked', this.checked);
  });

  /** Gửi các ID đã chọn theo từng lô, xóa dòng đã xử lý xong. */
  function runBatches($box, action, doneText) {
    const ids = $box.find('tbody input:checked').map(function () { return this.value; }).get();
    const $msg = $box.find('.wn-msg');
    if (!ids.length) {
      $msg.text('Chưa chọn ảnh nào.');
      return $.Deferred().resolve().promise();
    }
    const $btns = $box.find('button').prop('disabled', true);
    const errors = [];
    let ok = 0;
    const dfd = $.Deferred();

    function next(i) {
      if (i >= ids.length) {
        $btns.prop('disabled', false);
        $msg.html(esc(doneText.replace('%d', ok)) + (errors.length ? '<br><span style="color:#b32d2e">' + errors.map(esc).join('<br>') + '</span>' : ''));
        dfd.resolve(ok);
        return;
      }
      const chunk = ids.slice(i, i + C.batch);
      $msg.text('Đang xử lý ' + Math.min(i + chunk.length, ids.length) + '/' + ids.length + '...');
      post(action, { ids: chunk }).done(function (res) {
        if (res && res.success) {
          ok += res.data.done.length;
          res.data.done.forEach(function (id) { $box.find('tr[data-id="' + id + '"]').remove(); });
          errors.push.apply(errors, res.data.errors);
        } else {
          errors.push('Lỗi: ' + (res && res.data ? res.data : 'không rõ'));
        }
        next(i + C.batch);
      }).fail(function () {
        errors.push('Lỗi kết nối, dừng lại.');
        next(ids.length);
      });
    }
    next(0);
    return dfd.promise();
  }

  /* ---------- Quét ---------- */

  const $status = $('#wn-scan-status');
  const $progress = $('#wn-scan-progress');
  const $unused = $('#wn-unused');

  function scanStep() {
    post('scan_step').done(function (res) {
      if (!res || !res.success) {
        $status.text('Quét lỗi: ' + (res && res.data ? res.data : 'không rõ'));
        $('#wn-scan').prop('disabled', false);
        return;
      }
      const d = res.data;
      $progress.val(d.percent);
      if (!d.done) {
        $status.text('Đang quét ' + d.label + '... ' + d.percent + '%');
        scanStep();
        return;
      }
      $('#wn-scan').prop('disabled', false);
      $progress.prop('hidden', true);
      const r = d.result;
      $status.text('Quét xong ' + r.total + ' ảnh: ' + r.items.length + ' ảnh không dùng (' + r.bytes + ').');
      renderTable($unused, r.items, [
        { act: 'quarantine', label: 'Cách ly ảnh đã chọn', cls: 'button-primary' }
      ], 'Không có ảnh nào không dùng.');
    }).fail(function () {
      $status.text('Lỗi kết nối khi quét.');
      $('#wn-scan').prop('disabled', false);
    });
  }

  $('#wn-scan').on('click', function () {
    $(this).prop('disabled', true);
    $unused.empty();
    $progress.val(0).prop('hidden', false);
    $status.text('Đang chuẩn bị...');
    post('scan_start', { skip_days: $('#wn-skip-days').val() }).done(function (res) {
      if (res && res.success) {
        scanStep();
      } else {
        $status.text('Không bắt đầu quét được.');
        $('#wn-scan').prop('disabled', false);
      }
    });
  });

  $unused.on('click', 'button[data-act=quarantine]', function () {
    runBatches($unused, 'quarantine', 'Đã cách ly %d ảnh. Hãy kiểm tra web, ảnh nào thiếu thì khôi phục ở mục 2.').done(loadQuarantine);
  });

  /* ---------- Cách ly ---------- */

  const $quarantine = $('#wn-quarantine');

  function loadQuarantine() {
    post('list_quarantine').done(function (res) {
      if (!res || !res.success) {
        $quarantine.html('<p>Không tải được danh sách.</p>');
        return;
      }
      renderTable($quarantine, res.data.items, [
        { act: 'restore', label: 'Khôi phục' },
        { act: 'delete', label: 'Xóa hẳn', cls: 'button-link-delete' }
      ], 'Chưa có ảnh nào đang cách ly.');
    });
  }

  $quarantine.on('click', 'button[data-act=restore]', function () {
    runBatches($quarantine, 'restore', 'Đã khôi phục %d ảnh về Thư viện.');
  });

  $quarantine.on('click', 'button[data-act=delete]', function () {
    const n = $quarantine.find('tbody input:checked').length;
    if (!n || !window.confirm('Xóa vĩnh viễn ' + n + ' ảnh? Không thể hoàn tác.')) return;
    runBatches($quarantine, 'delete', 'Đã xóa hẳn %d ảnh.');
  });

  loadQuarantine();
});
