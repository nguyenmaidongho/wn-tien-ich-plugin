<?php
/**
 * Danh sách tính năng bật/tắt được. Giá trị lưu ở option `wn_module_{key}` = '1' | '0';
 * chưa lưu lần nào thì dùng `default`.
 */
function wn_modules() {
  return [
    'optimize' => [
      'title' => 'Tối ưu web',
      'desc' => 'Phân tích tốc độ rồi tự áp dụng: dọn database, LiteSpeed Cache, nén ảnh + WebP, sửa cron. Sao lưu trước khi đụng vào dữ liệu.',
      'icon' => 'dashicons-performance',
      'default' => '1',
      'tab' => 'optimize',
    ],
    'search' => [
      'title' => 'Tìm kiếm nhanh',
      'desc' => 'Gợi ý kết quả ngay khi gõ, không tải lại trang. Thay plugin Search Popup ThunderBolt.',
      'icon' => 'dashicons-search',
      'default' => '1',
      'tab' => 'search',
    ],
    'contact' => [
      'title' => 'Nút liên hệ',
      'desc' => 'Nút nổi gọi điện, Zalo, Messenger, WhatsApp, chỉ đường. Thay plugin Button Contact VR.',
      'icon' => 'dashicons-phone',
      'default' => '1',
      'tab' => 'contact',
    ],
    'tet' => [
      'title' => 'Trang trí Tết',
      'desc' => 'Câu đối, hoa đào/mai rơi, pháo hoa; hẹn ngày tự bật/tắt. Thay plugin DevVN Trang trí Tết.',
      'icon' => 'dashicons-star-filled',
      'default' => '0',
      'tab' => 'tet',
    ],
    'preload' => [
      'title' => 'Màn hình chờ',
      'desc' => 'Hiệu ứng vẽ logo SVG khi trang đang tải.',
      'icon' => 'dashicons-update',
      'default' => '0',
      'tab' => 'preload',
    ],
    'fontawesome' => [
      'title' => 'Font Awesome',
      'desc' => 'Nạp bộ icon Font Awesome 6 cho giao diện. Thay plugin Font Awesome.',
      'icon' => 'dashicons-flag',
      'default' => '1',
    ],
    'webp' => [
      'title' => 'Ảnh WebP',
      'desc' => 'Tự đổi ảnh JPG/PNG tải lên sang WebP và có công cụ chuyển hàng loạt ảnh cũ (Media → WebP Batch Optimizer). Thay plugin WebNow WebP Batch.',
      'icon' => 'dashicons-format-image',
      'default' => '1',
    ],
    'media_cleanup' => [
      'title' => 'Dọn ảnh không dùng',
      'desc' => 'Quét ảnh trong Thư viện không còn dùng ở đâu, cách ly để kiểm tra rồi mới xóa hẳn (Media → Dọn ảnh không dùng).',
      'icon' => 'dashicons-trash',
      'default' => '0',
      'page' => 'upload.php?page=wn-media-cleanup',
    ],
    'login_branding' => [
      'title' => 'Trang đăng nhập WebNow',
      'desc' => 'Logo, màu sắc và favicon thương hiệu ở trang đăng nhập & admin.',
      'icon' => 'dashicons-admin-network',
      'default' => '1',
    ],
    'hide_wp_logo' => [
      'title' => 'Ẩn logo WordPress',
      'desc' => 'Bỏ logo và menu WordPress ở góc trái thanh admin bar.',
      'icon' => 'dashicons-wordpress',
      'default' => '1',
    ],
    'hide_flatsome_notice' => [
      'title' => 'Ẩn thông báo Flatsome',
      'desc' => 'Ẩn banner nhắc đăng ký bản quyền của theme Flatsome trong admin.',
      'icon' => 'dashicons-hidden',
      'default' => '1',
    ],
    'plugin_warning' => [
      'title' => 'Nhắc plugin cần thiết',
      'desc' => 'Cảnh báo trong admin nếu Rank Math hoặc plugin cache (WP Fastest Cache / LiteSpeed Cache) bị tắt.',
      'icon' => 'dashicons-warning',
      'default' => '0',
    ],
    'hide_core_updates' => [
      'title' => 'Ẩn thông báo cập nhật',
      'desc' => 'Ẩn thông báo có bản mới của WordPress/plugin/theme.',
      'icon' => 'dashicons-update-alt',
      'default' => '0',
      'warn' => 'Không khuyến khích: dễ bỏ lỡ bản vá bảo mật.',
    ],
    'classic_editor' => [
      'title' => 'Classic Editor',
      'desc' => 'Dùng trình soạn thảo cổ điển thay cho Gutenberg.',
      'icon' => 'dashicons-edit',
      'default' => '0',
      'warn' => ($ce = wn_classic_editor_conflict()) ? "Plugin $ce đang bật và cũng chuyển sang trình soạn thảo cổ điển — không cần bật thêm ở đây." : '',
    ],
  ];
}

/** Tên plugin khác đang ép dùng Classic Editor (nếu có). */
function wn_classic_editor_conflict() {
  static $found = null;
  if ($found !== null) {
    return $found;
  }
  $known = [
    'classic-editor/' => 'Classic Editor',
    'disable-gutenberg/' => 'Disable Gutenberg',
    'no-gutenberg/' => 'No Gutenberg',
    'foxtool/' => 'Foxtool',
  ];
  $found = '';
  foreach ((array) get_option('active_plugins', []) as $p) {
    foreach ($known as $prefix => $name) {
      if (str_starts_with($p, $prefix)) {
        $found = $name;
        break 2;
      }
    }
  }
  return $found;
}

function wn_module_on($key) {
  $modules = wn_modules();
  $default = $modules[$key]['default'] ?? '0';
  return get_option('wn_module_' . $key, $default) === '1';
}
