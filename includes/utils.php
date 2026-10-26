<?php
function wn_plugin_url($path = '') {
  $url = plugins_url($path, WN_PLUGIN);
  if (is_ssl() && 'http:' == substr($url, 0, 5)) {
    $url = 'https:' . substr($url, 5);
  }
  return $url;
}

/** Xóa cache trang (WP Fastest Cache / LiteSpeed Cache) để thay đổi giao diện có hiệu lực ngay với khách. */
function wn_clear_page_cache() {
  do_action('wpfc_clear_all_cache', true);
  do_action('litespeed_purge_all');
}

/**
 * Ảnh JPG/PNG trong uploads được viết thẳng trong code (theme đang dùng, theme cha, mu-plugins), ví dụ
 * content_url('/uploads/2025/08/a.jpg') hay background: url(.../uploads/...). Link kiểu này không sửa
 * được qua database nên không được đổi tên/xóa ảnh gốc.
 *
 * @return array<string,string[]> đường dẫn tương đối trong uploads (2025/08/a.jpg) => ["theme/file.php:12", ...]
 */
function wn_hardcoded_upload_images() {
  static $found = null;
  if ($found !== null) {
    return $found;
  }
  $found = [];
  $dirs = array_unique(array_filter([get_stylesheet_directory(), get_template_directory(), defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : '']));
  $root = wp_normalize_path(trailingslashit(WP_CONTENT_DIR));
  $up = trailingslashit(wp_get_upload_dir()['basedir']);
  // "uploads/…/a.jpg", hoặc chỉ "2025/08/a.jpg" khi theme ghép qua hàm riêng (vd. xinda_upload_url('2025/08/a.jpg')).
  // Dạng thứ hai chỉ tính khi đúng là file trong uploads (còn file hoặc đã có bản .webp), tránh nhầm ảnh trong theme.
  $re = '#(uploads/|(?<![\w./-])(?=\d{4}/\d{2}/))([^\s\'"()<>?\#\\\\,;]+?\.(?:jpe?g|png))(?![a-z0-9])#i';
  foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
      continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
      if (!$f->isFile() || $f->getSize() > 2097152 || !preg_match('/\.(php|css|scss|js|html?|json|twig)$/i', $f->getFilename())) {
        continue;
      }
      $path = wp_normalize_path($f->getPathname());
      if (preg_match('#/(node_modules|vendor)/#', $path)) {
        continue;
      }
      $lines = @file($path);
      if (!$lines) {
        continue;
      }
      foreach ($lines as $i => $line) {
        if (!preg_match_all($re, $line, $m, PREG_SET_ORDER)) {
          continue;
        }
        foreach ($m as $hit) {
          $rel = rawurldecode($hit[2]);
          if ($hit[1] === '' && !is_file($up . $rel) && !is_file($up . preg_replace('/\.(jpe?g|png)$/i', '.webp', $rel))) {
            continue;
          }
          $found[$rel][] = str_replace($root, '', $path) . ':' . ($i + 1);
        }
      }
    }
  }
  ksort($found);
  return $found;
}

/** File ảnh (đường dẫn tuyệt đối) đang bị code tham chiếu cứng, kể cả bản .jpg.webp đi kèm. */
function wn_is_hardcoded_upload($abs) {
  $base = wp_normalize_path(trailingslashit(wp_get_upload_dir()['basedir']));
  $abs = wp_normalize_path((string) $abs);
  if (strpos($abs, $base) !== 0) {
    return false;
  }
  $rel = preg_replace('/\.(jpe?g|png)\.webp$/i', '.$1', substr($abs, strlen($base)));
  return isset(wn_hardcoded_upload_images()[$rel]);
}

/**
 * Attachment đang dùng 1 file trong uploads: làm file chính (_wp_attached_file), ảnh gốc trước khi scale,
 * một size hoặc bản sửa (_wp_attachment_backup_sizes). Metadata chỉ ghi tên file nên so thêm thư mục.
 * Khớp tên trong chuỗi serialize ("1-150x150.webp") nên có thể dư (coi là đang dùng) chứ không sót.
 *
 * @return int[] ID attachment, bỏ qua $exclude_id
 */
function wn_upload_file_owners($abs, $exclude_id = 0) {
  global $wpdb;
  $base = wp_normalize_path(trailingslashit(wp_get_upload_dir()['basedir']));
  $abs = wp_normalize_path((string) $abs);
  if (strpos($abs, $base) !== 0) {
    return [];
  }
  $rel = substr($abs, strlen($base));
  $dir = dirname($rel);
  $ids = [];
  $main = $wpdb->get_col($wpdb->prepare(
    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s AND post_id <> %d",
    $rel,
    (int) $exclude_id
  ));
  foreach ($main as $id) {
    $ids[(int) $id] = 1;
  }
  $rows = $wpdb->get_results($wpdb->prepare(
    "SELECT m.post_id, f.meta_value AS file FROM {$wpdb->postmeta} m
     JOIN {$wpdb->postmeta} f ON f.post_id = m.post_id AND f.meta_key = '_wp_attached_file'
     WHERE m.meta_key IN ('_wp_attachment_metadata', '_wp_attachment_backup_sizes') AND m.meta_value LIKE %s AND m.post_id <> %d",
    '%' . $wpdb->esc_like('"' . wp_basename($rel) . '"') . '%',
    (int) $exclude_id
  ));
  foreach ($rows as $r) {
    if (dirname((string) $r->file) === $dir) {
      $ids[(int) $r->post_id] = 1;
    }
  }
  return array_keys($ids);
}
