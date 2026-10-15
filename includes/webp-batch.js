jQuery(function ($) {
  let total = 0;

  function refreshStats() {
    return $.post(WEBNOW.ajax, {
      action: 'webnow_webp_stats',
      nonce: WEBNOW.nonce
    }).done(function (res) {
      if (!res || !res.success) return;
      const d = res.data;
      $('#wn-total').text(d.attachments);
      $('#wn-ready').text(d.webp_attachments);
      $('#wn-missing').text(d.pending);
      $('#wn-orphans').text(d.orphan_files);
    });
  }

  $('#webnow-refresh').on('click', function () {
    $('#webnow-status').text('Đang thống kê...');
    refreshStats().always(function () {
      $('#webnow-status').text('Đã cập nhật thống kê.');
    });
  });

  $('#wn-save').on('click', function () {
    $.post(WEBNOW.ajax, {
      action: 'webnow_webp_save',
      nonce: WEBNOW.nonce,
      quality: $('#wn-quality').val(),
      delete_originals: $('#wn-delete').is(':checked') ? 1 : 0
    }).done(function () {
      $('#webnow-status').text('Đã lưu cấu hình.');
    });
  });

  $('#webnow-repair').on('click', function () {
    const $btn = $(this).prop('disabled', true);
    $('#webnow-status').text('Đang sửa link JPG đã mất trong database...');
    $.post(WEBNOW.ajax, {
      action: 'webnow_webp_repair_links',
      nonce: WEBNOW.nonce
    }).done(function (res) {
      if (res && res.success) {
        $('#webnow-status').text('Đã sửa ' + (res.data.fixed || 0) + ' link/metadata. Purge cache rồi F5.');
        refreshStats();
      } else {
        $('#webnow-status').text('Sửa link thất bại.');
      }
      $btn.prop('disabled', false);
    }).fail(function () {
      $('#webnow-status').text('Lỗi AJAX khi sửa link.');
      $btn.prop('disabled', false);
    });
  });

  $('#webnow-start').on('click', function () {
    const $btn = $(this).prop('disabled', true);
    $('#webnow-status').text('Đang chuẩn bị hàng đợi (attachment + file lẻ)...');
    $('#webnow-progress').val(0);

    $.post(WEBNOW.ajax, {
      action: 'webnow_webp_prepare',
      nonce: WEBNOW.nonce
    }).done(function (res) {
      if (!res || !res.success) {
        $('#webnow-status').text('Lỗi chuẩn bị queue.');
        $btn.prop('disabled', false);
        return;
      }
      total = res.data.total || 0;
      if (!total) {
        $('#webnow-status').text('Không còn JPG/PNG cần thay.');
        $('#webnow-progress').val(100);
        $btn.prop('disabled', false);
        refreshStats();
        return;
      }
      run($btn);
    }).fail(function () {
      $('#webnow-status').text('Lỗi AJAX khi chuẩn bị.');
      $btn.prop('disabled', false);
    });
  });

  function run($btn) {
    $.post(WEBNOW.ajax, {
      action: 'webnow_webp_process',
      nonce: WEBNOW.nonce
    }).done(function (res) {
      const left = res.left || 0;
      const done = Math.max(0, total - left);
      const percent = total ? Math.min(100, Math.round((done / total) * 100)) : 100;
      $('#webnow-progress').val(percent);
      $('#webnow-status').text(
        'Đã xử lý ' + done + '/' + total +
        ' (ok ' + (res.ok || 0) + ', skip ' + (res.skipped || 0) + ', fail ' + (res.fail || 0) + ') — còn ' + left
      );

      if (!res.done) {
        run($btn);
      } else {
        $('#webnow-status').text('Hoàn tất: ảnh đã thay bằng file WebP thật. Nên purge cache LiteSpeed.');
        $btn.prop('disabled', false);
        refreshStats();
      }
    }).fail(function () {
      $('#webnow-status').text('Lỗi AJAX — bấm lại để tiếp tục phần còn lại.');
      $btn.prop('disabled', false);
    });
  }
});
