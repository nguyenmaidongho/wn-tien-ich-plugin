(function ($) {
  var toastTimer;
  function toast(msg, isError) {
    var $t = $('#wn-toast');
    $t.text(msg).toggleClass('is-error', !!isError).addClass('is-show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { $t.removeClass('is-show'); }, 2600);
  }

  // Bật/tắt tính năng: lưu ngay, cập nhật mọi công tắc/thẻ cùng tính năng trên trang.
  $(document).on('change', '.wn-switch input[data-module]', function () {
    var $input = $(this);
    var key = $input.data('module');
    var on = $input.is(':checked');
    var $switch = $input.closest('.wn-switch').addClass('is-busy');

    $.post(WN_ADMIN.ajax, { action: 'wn_toggle_module', _ajax_nonce: WN_ADMIN.nonce, module: key, on: on ? '1' : '0' })
      .done(function (res) {
        if (!res || !res.success) {
          $input.prop('checked', !on);
          toast((res && res.data) || 'Không lưu được', true);
          return;
        }
        $('[data-card="' + key + '"]').toggleClass('is-on', on);
        $('[data-dot="' + key + '"]').toggleClass('is-on', on);
        $('input[data-module="' + key + '"]').prop('checked', on);
        toast((on ? 'Đã bật: ' : 'Đã tắt: ') + res.data.title);
      })
      .fail(function () {
        $input.prop('checked', !on);
        toast('Lỗi kết nối, thử lại sau', true);
      })
      .always(function () { $switch.removeClass('is-busy'); });
  });

  $(function () {
    $('.wn-color').wpColorPicker({
      change: function (e, ui) { $('.wn-media-preview').css('background', ui.color.toString()); }
    });

    $(document).on('click', '[data-media-pick]', function (e) {
      e.preventDefault();
      var $box = $(this).closest('.wn-media');
      var frame = wp.media({ title: 'Chọn ảnh', multiple: false, library: { type: 'image' } });
      frame.on('select', function () {
        var file = frame.state().get('selection').first().toJSON();
        $box.find('input[type=hidden]').val(file.id);
        $box.find('img').attr('src', file.url).prop('hidden', false);
        $box.find('.wn-media-preview span').prop('hidden', true);
        $box.find('[data-media-clear]').prop('hidden', false);
      });
      frame.open();
    });

    $(document).on('click', '[data-media-clear]', function (e) {
      e.preventDefault();
      var $box = $(this).closest('.wn-media');
      $box.find('input[type=hidden]').val('');
      $box.find('img').attr('src', '').prop('hidden', true);
      $box.find('.wn-media-preview span').prop('hidden', false);
      $(this).prop('hidden', true);
    });

    // data-show-if="tên=giá trị" hoặc "tên!=giá trị": ẩn/hiện trường phụ theo lựa chọn.
    var $conds = $('[data-show-if]');
    function applyConds() {
      $conds.each(function () {
        var m = $(this).data('show-if').match(/^(.+?)(!?=)(.*)$/);
        var val = $('[name="' + m[1] + '"]:checked').val();
        $(this).prop('hidden', m[2] === '=' ? val !== m[3] : val === m[3]);
      });
    }
    if ($conds.length) {
      $(document).on('change', 'input[type=radio]', applyConds);
      applyConds();
    }
  });

  // Cập nhật từ GitHub: kiểm tra ngầm khi mở trang, có bản mới thì hiện nút nhỏ cạnh số phiên bản.
  var upd = null;
  var $panel = $('#wn-upd');
  var $pill = $('.wn-upd-pill');

  function esc(s) {
    return $('<div>').text(s == null ? '' : String(s)).html();
  }

  function ago(iso) {
    var s = (Date.now() - new Date(iso).getTime()) / 1000;
    if (!iso || isNaN(s)) {
      return '';
    }
    if (s < 3600) {
      return Math.max(1, Math.round(s / 60)) + ' phút trước';
    }
    if (s < 86400) {
      return Math.round(s / 3600) + ' giờ trước';
    }
    return Math.round(s / 86400) + ' ngày trước';
  }

  function renderUpd(err) {
    if (!upd) {
      $panel.html('Đang kiểm tra…');
      return;
    }
    var r = upd.remote || {};
    var html;
    if (upd.available) {
      html = '<strong>Có bản mới v' + esc(r.version) + '</strong> <span class="wn-upd-meta">(đang dùng v' + esc(upd.local) + ')</span>' +
        (r.message ? '<div class="wn-upd-msg">' + esc(r.message) + '</div>' : '') +
        '<div class="wn-upd-meta">' + esc(ago(r.date)) + ' · <a href="' + esc(upd.compare) + '" target="_blank" rel="noopener">Xem thay đổi ↗</a></div>' +
        '<div class="wn-upd-links"><button type="button" class="wn-btn wn-btn-primary" data-upd-run>Cập nhật ngay</button>';
    } else if (r.error) {
      html = '<strong>Không kiểm tra được cập nhật</strong><div class="wn-upd-meta">' + esc(r.error) + '</div><div class="wn-upd-links">';
    } else {
      html = '<strong>Đang dùng bản mới nhất</strong> <span class="wn-upd-meta">v' + esc(upd.local) + '</span><div class="wn-upd-links">';
    }
    html += '<a href="#" data-upd-check>Kiểm tra lại</a>';
    if (upd.backup) {
      html += '<a href="#" data-upd-rollback>Khôi phục v' + esc(upd.backup.version) + '</a>';
    }
    html += '</div>';
    if (r.checked) {
      html += '<div class="wn-upd-meta">Kiểm tra lúc ' + new Date(r.checked * 1000).toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' }) + '</div>';
    }
    if (err) {
      html += '<div class="wn-upd-err">' + esc(err) + '</div>';
    }
    $panel.html(html);
    $pill.prop('hidden', !upd.available).find('span').text('Có bản mới v' + (r.version || ''));
  }

  function checkUpd(force) {
    if (!$panel.length || !WN_ADMIN.updateNonce) {
      return;
    }
    $.post(WN_ADMIN.ajax, { action: 'wn_update_check', nonce: WN_ADMIN.updateNonce, force: force ? 1 : 0 })
      .done(function (res) {
        if (res && res.success) {
          upd = res.data;
          renderUpd();
        }
      });
  }

  $(document).on('click', '[data-upd-toggle]', function () {
    $panel.prop('hidden', !$panel.prop('hidden'));
    renderUpd();
  });

  $(document).on('click', function (e) {
    if (!$panel.prop('hidden') && !$(e.target).closest('#wn-upd, [data-upd-toggle]').length) {
      $panel.prop('hidden', true);
    }
  });

  $(document).on('click', '[data-upd-check]', function (e) {
    e.preventDefault();
    upd = null;
    renderUpd();
    checkUpd(true);
  });

  function runUpd(action, $btn, label, doneText) {
    $btn.prop('disabled', true).text(label);
    $.post(WN_ADMIN.ajax, { action: action, nonce: WN_ADMIN.updateNonce })
      .done(function (res) {
        if (res && res.success) {
          toast(doneText + res.data.version + '. Đang tải lại…');
          setTimeout(function () { location.reload(); }, 1200);
        } else {
          renderUpd((res && res.data) || 'Không cập nhật được.');
        }
      })
      .fail(function (x) { renderUpd('Lỗi kết nối (' + x.status + '), thử lại sau.'); });
  }

  $(document).on('click', '[data-upd-run]', function () {
    runUpd('wn_update_run', $(this), 'Đang tải và kiểm tra…', 'Đã cập nhật lên v');
  });

  $(document).on('click', '[data-upd-rollback]', function (e) {
    e.preventDefault();
    if (confirm('Quay lại bản v' + upd.backup.version + '?')) {
      runUpd('wn_update_rollback', $(this), 'Đang khôi phục…', 'Đã khôi phục v');
    }
  });

  $(function () { checkUpd(false); });
})(jQuery);
