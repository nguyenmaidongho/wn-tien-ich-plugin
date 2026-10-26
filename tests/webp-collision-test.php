<?php
/**
 * Kiểm tra lỗi trùng tên WebP (1.jpg / 1.png / 1.webp cạnh nhau), tốc độ hàng đợi và cách ly file dùng chung.
 *
 * CHỈ CHẠY TRÊN SITE TEST: script tạo attachment, bài viết và file trong uploads/wn-webp-test/, bật tùy chọn
 * xóa ảnh gốc trong lúc chạy, rồi dọn sạch và trả lại cấu hình cũ.
 *
 *   php tests/webp-collision-test.php /duong/dan/wordpress
 *   wp eval-file tests/webp-collision-test.php            (site đã bật plugin)
 *
 * Cần PHP có GD hỗ trợ WebP. Thoát mã 1 nếu có kiểm tra sai.
 */

if (PHP_SAPI !== 'cli') {
  exit; // Không chạy qua web.
}
if (!defined('ABSPATH')) {
  if (empty($argv[1]) || !is_file(rtrim($argv[1], '/\\') . '/wp-load.php')) {
    fwrite(STDERR, "Dùng: php tests/webp-collision-test.php /duong/dan/wordpress\n");
    exit(2);
  }
  $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
  define('WP_USE_THEMES', false);
  require rtrim($argv[1], '/\\') . '/wp-load.php';
}
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

if (!class_exists('WebNow_WebP_Batch') || !class_exists('WN_Media_Cleanup') || !function_exists('imagewebp')) {
  fwrite(STDERR, "Cần plugin Tiện ích WebNow đã nạp và GD có WebP.\n");
  exit(2);
}

$webp = WebNow_WebP_Batch::$instance ?: new WebNow_WebP_Batch();
$cleanup = new WN_Media_Cleanup();
$base = trailingslashit(wp_normalize_path(wp_get_upload_dir()['basedir']));
$sub = 'wn-webp-test';
$dir = $base . $sub . '/';
$created = ['attachments' => [], 'posts' => []];
$old_delete = get_option(WebNow_WebP_Batch::OPT_DELETE_ORIGINALS, '0');
$old_queue = get_option(WebNow_WebP_Batch::OPT_QUEUE, []);
$old_loose = get_option('webnow_webp_loose_map', []);
$fails = 0;

$colors = ['red' => [220, 20, 20], 'blue' => [20, 20, 220], 'green' => [20, 200, 20], 'yellow' => [230, 220, 20], 'cyan' => [20, 210, 220]];

function wn_t_check($ok, $label) {
  global $fails;
  echo ($ok ? '  OK   ' : '  SAI  ') . $label . "\n";
  if (!$ok) {
    $fails++;
  }
}

function wn_t_image($path, $color) {
  global $colors;
  $im = imagecreatetruecolor(400, 300);
  imagefill($im, 0, 0, imagecolorallocate($im, ...$colors[$color]));
  wp_mkdir_p(dirname($path));
  $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
  if ($ext === 'png') {
    imagepng($im, $path);
  } elseif ($ext === 'webp') {
    imagewebp($im, $path, 90);
  } else {
    imagejpeg($im, $path, 90);
  }
}

/** Màu chủ đạo của ảnh (đọc pixel giữa). */
function wn_t_color($path) {
  global $colors;
  $im = @imagecreatefromstring((string) @file_get_contents($path));
  if (!$im) {
    return 'không đọc được';
  }
  $c = imagecolorat($im, (int) (imagesx($im) / 2), (int) (imagesy($im) / 2));
  $rgb = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
  $best = '';
  $dist = PHP_INT_MAX;
  foreach ($colors as $name => $ref) {
    $d = abs($rgb[0] - $ref[0]) + abs($rgb[1] - $ref[1]) + abs($rgb[2] - $ref[2]);
    if ($d < $dist) {
      $dist = $d;
      $best = $name;
    }
  }
  return $best;
}

/** Tạo attachment; $hook = false giả lập ảnh cũ tải lên khi chưa có chuyển WebP. */
function wn_t_attachment($name, $color, $hook = false) {
  global $webp, $dir, $created;
  $path = $dir . $name;
  wn_t_image($path, $color);
  $type = wp_check_filetype($name)['type'];
  $id = wp_insert_attachment(['post_mime_type' => $type, 'post_title' => $name, 'post_status' => 'inherit'], $path);
  if (!$hook) {
    remove_filter('wp_generate_attachment_metadata', [$webp, 'on_metadata'], 99);
  }
  wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $path));
  if (!$hook) {
    add_filter('wp_generate_attachment_metadata', [$webp, 'on_metadata'], 99, 2);
  }
  $created['attachments'][] = $id;
  return $id;
}

/** Mọi file của attachment (full + size) => màu. */
function wn_t_files($id) {
  $file = wp_normalize_path(get_attached_file($id));
  $meta = wp_get_attachment_metadata($id);
  $out = [$file => wn_t_color($file)];
  foreach ((array) ($meta['sizes'] ?? []) as $s) {
    $p = dirname($file) . '/' . $s['file'];
    $out[$p] = wn_t_color($p);
  }
  return $out;
}

function wn_t_all_color($id, $color) {
  foreach (wn_t_files($id) as $p => $c) {
    if ($c !== $color) {
      return wp_basename($p) . " là $c";
    }
  }
  return true;
}

/** Nội dung bài đọc thẳng từ DB: plugin thay link bằng SQL nên cache bài trong request này đã cũ. */
function wn_t_content($id) {
  clean_post_cache($id);
  return (string) get_post_field('post_content', $id);
}

function wn_t_post($content) {
  global $created;
  $id = wp_insert_post(['post_title' => 'wn-webp-test', 'post_content' => $content, 'post_status' => 'publish']);
  $created['posts'][] = $id;
  return $id;
}

try {
  if (is_dir($dir)) {
    fwrite(STDERR, "Đã có $dir (lần chạy trước dừng giữa chừng?). Xóa thư mục đó rồi chạy lại.\n");
    exit(2);
  }
  wp_mkdir_p($dir);
  update_option(WebNow_WebP_Batch::OPT_DELETE_ORIGINALS, '1', false);
  $url = trailingslashit(wp_get_upload_dir()['baseurl']) . $sub . '/';

  echo "1. Chuyển hàng loạt: 1.png (xanh dương) rồi 1.jpg (đỏ) cùng thư mục, chưa đổi WebP\n";
  $b = wn_t_attachment('1.png', 'blue');
  $a = wn_t_attachment('1.jpg', 'red');
  $post = wn_t_post('<img src="' . $url . '1.jpg"><img src="' . $url . '1.png">');
  wn_t_check($webp->convert_attachment($b) === true && $webp->convert_attachment($a) === true, 'convert_attachment trả true');
  $fa = wp_basename(get_attached_file($a));
  $fb = wp_basename(get_attached_file($b));
  wn_t_check($fb === '1.webp' && $fa === '1-1.webp', "B = $fb, A = $fa (không trùng)");
  wn_t_check(wn_t_all_color($a, 'red') === true, 'mọi file của A vẫn đỏ: ' . var_export(wn_t_all_color($a, 'red'), true));
  wn_t_check(wn_t_all_color($b, 'blue') === true, 'mọi file của B vẫn xanh: ' . var_export(wn_t_all_color($b, 'blue'), true));
  $sizes_a = array_map('wp_basename', array_keys(wn_t_files($a)));
  wn_t_check(count(array_filter($sizes_a, function ($n) {
    return strpos($n, '1-1') === 0 && substr($n, -5) === '.webp';
  })) === count($sizes_a), 'tên size của A cùng gốc "1-1": ' . implode(', ', $sizes_a));
  wn_t_check(!is_file($dir . '1.jpg') && !is_file($dir . '1.png'), 'ảnh gốc của cả hai đã xóa (sau khi có WebP riêng)');
  $content = wn_t_content($post);
  wn_t_check(strpos($content, $sub . '/1-1.webp') !== false && strpos($content, $sub . '/1.webp') !== false && !preg_match('/\.(jpe?g|png)"/', $content), 'link trong bài: 1.jpg → 1-1.webp, 1.png → 1.webp');
  wn_t_check($webp->convert_attachment($a) === null, 'chạy lại A: không còn gì để đổi');

  echo "2. Đã có 4.webp (ảnh tải lên dạng WebP, xanh lơ), đổi 4.jpg (đỏ)\n";
  $c = wn_t_attachment('4.webp', 'cyan');
  $md5 = md5_file($dir . '4.webp');
  $d = wn_t_attachment('4.jpg', 'red');
  $webp->convert_attachment($d);
  wn_t_check(wp_basename(get_attached_file($d)) === '4-1.webp' && wn_t_all_color($d, 'red') === true, '4.jpg → ' . wp_basename(get_attached_file($d)) . ', vẫn đỏ');
  wn_t_check(md5_file($dir . '4.webp') === $md5 && wn_t_all_color($c, 'cyan') === true, '4.webp của ảnh kia không bị đụng');

  echo "3. Tải lên qua hook: 2.png (vàng) khi 2.jpg (xanh lá) chưa đổi WebP\n";
  $e = wn_t_attachment('2.jpg', 'green');
  wn_t_check(wp_unique_filename($dir, '2.png') === '2-1.png', 'wp_unique_filename("2.png") = ' . wp_unique_filename($dir, '2.png'));
  // Giả lập trường hợp tên vẫn trùng (vd. tải trước khi có bộ lọc tên): hook vẫn không được dùng nhầm file.
  $f = wn_t_attachment('3.png', 'yellow', true);
  wn_t_check(wp_basename(get_attached_file($f)) === '3.webp' && wn_t_all_color($f, 'yellow') === true, 'upload 3.png → 3.webp ngay lúc tải lên');
  $g = wn_t_attachment('3.jpg', 'green');
  $webp->delete_attachment_originals($f);
  wn_t_check(!is_file($dir . '3.png') && is_file($dir . '3.jpg'), 'cron xóa ảnh gốc 3.png, không đụng 3.jpg của ảnh khác');
  $webp->convert_attachment($g);
  wn_t_check(wp_basename(get_attached_file($g)) === '3-1.webp' && wn_t_all_color($g, 'green') === true && wn_t_all_color($f, 'yellow') === true, '3.jpg → ' . wp_basename(get_attached_file($g)) . ', hai ảnh giữ đúng màu');

  echo "4. File lẻ (không thuộc attachment)\n";
  wn_t_image($dir . '5.jpg', 'red');
  $p5 = wn_t_post('<img src="' . $url . '5.jpg">');
  wn_t_check($webp->replace_file_with_webp($dir . '5.jpg') === true && is_file($dir . '5.webp') && !is_file($dir . '5.jpg'), '5.jpg → 5.webp, xóa 5.jpg');
  wn_t_check(strpos(wn_t_content($p5), $sub . '/5.webp') !== false, 'link trong bài đổi sang 5.webp');
  $h = wn_t_attachment('6.webp', 'cyan');
  wn_t_image($dir . '6.jpg', 'red');
  $p6 = wn_t_post('<img src="' . $url . '6.jpg">');
  $webp->replace_file_with_webp($dir . '6.jpg');
  wn_t_check(is_file($dir . '6-1.webp') && wn_t_color($dir . '6-1.webp') === 'red' && wn_t_all_color($h, 'cyan') === true, '6.jpg → 6-1.webp, 6.webp của attachment giữ nguyên');
  wn_t_check(strpos(wn_t_content($p6), $sub . '/6-1.webp') !== false, 'link trong bài đổi sang 6-1.webp');

  echo "5. Hàng đợi: file thuộc attachment không bị đếm hai lần, tiến độ không quét lại uploads\n";
  $i = wn_t_attachment('7.jpg', 'blue');
  $stats = $webp->collect_stats(true);
  $files = array_column(array_filter($stats['queue'], function ($q) {
    return $q['type'] === 'file';
  }), 'path');
  $ids = array_column(array_filter($stats['queue'], function ($q) {
    return $q['type'] === 'attachment';
  }), 'id');
  wn_t_check(in_array($i, $ids, true), 'attachment 7.jpg có trong hàng đợi');
  wn_t_check(!array_filter($files, function ($p) use ($sub) {
    return strpos($p, '/' . $sub . '/7') !== false;
  }), 'file 7.jpg / 7-150x150.jpg... không nằm thêm trong hàng đợi file lẻ');
  update_option(WebNow_WebP_Batch::OPT_QUEUE, [['type' => 'attachment', 'id' => $i], ['type' => 'attachment', 'id' => $i]], false);
  $r1 = $webp->process_queue(1);
  wn_t_check($r1['done'] === false && array_keys($r1['stats']) === ['pending'], 'giữa chừng: stats chỉ có pending = ' . ($r1['stats']['pending'] ?? '?'));
  $r2 = $webp->process_queue(1);
  wn_t_check($r2['done'] === true && isset($r2['stats']['orphan_files']), 'xong: trả thống kê đầy đủ');

  echo "6. Dọn ảnh: cách ly ảnh không dùng có file dùng chung với ảnh đang dùng\n";
  $j = wn_t_attachment('8.jpg', 'green');
  $k = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => '8 (bản sao)', 'post_status' => 'inherit'], $dir . '8.jpg');
  $created['attachments'][] = $k;
  wp_update_attachment_metadata($k, wp_get_attachment_metadata($j));
  $shared_files = array_keys(wn_t_files($j));
  $call = function ($method, ...$args) use ($cleanup) {
    $m = new ReflectionMethod($cleanup, $method);
    if (PHP_VERSION_ID < 80100) {
      $m->setAccessible(true);
    }
    return $m->invoke($cleanup, ...$args);
  };
  wn_t_check($call('quarantine', $j) === true, 'cách ly ảnh J');
  wn_t_check(count(array_filter($shared_files, 'is_file')) === count($shared_files), 'mọi file J dùng chung với K vẫn ở uploads');
  wn_t_check($call('delete_forever', $j) === true && count(array_filter($shared_files, 'is_file')) === count($shared_files), 'xóa hẳn J vẫn giữ file của K');
  $created['attachments'] = array_diff($created['attachments'], [$j]);

  echo "7. Nút \"Sửa link JPG đã xóa\": link còn sót trỏ vào ảnh gốc đã mất\n";
  $rb = wn_t_attachment('r1.png', 'blue');
  $ra = wn_t_attachment('r1.jpg', 'red');
  $webp->convert_attachment($rb);
  $webp->convert_attachment($ra);
  // Link sót lại sau khi đổi (vd. dán tay, plugin lưu chỗ khác) — trong bài, meta dạng JSON và option.
  $pr = wn_t_post('<img src="' . $url . 'r1.jpg"><img src="' . $url . 'r2.jpg"><img src="' . $url . 'r3.jpg"><img src="' . $url . 'r4.jpg">');
  update_post_meta($pr, '_wn_test_json', wp_slash(wp_json_encode(['url' => $url . 'r1.jpg'])));
  update_option('wn_webp_test_opt', ['img' => $url . 'r1.jpg'], false);
  // r2: còn r2.png (ảnh khác) cạnh r2.webp → không biết r2.webp của ai.
  wn_t_image($dir . 'r2.webp', 'blue');
  wn_t_image($dir . 'r2.png', 'yellow');
  // r3: chỉ có r3.webp, không ảnh gốc nào khác cùng tên → nhận như trước.
  wn_t_image($dir . 'r3.webp', 'red');
  // r4: còn bản r4.jpg.webp (đỏ) nhưng r4.webp là ảnh WebP tải lên khác (xanh lơ).
  wn_t_attachment('r4.webp', 'cyan');
  wn_t_image($dir . 'r4.jpg.webp', 'red');
  // r5: r5.jpg.webp (đỏ) và r5.png (xanh) cùng tên, chưa có r5.webp → không tự chép thành r5.webp.
  wn_t_image($dir . 'r5.jpg.webp', 'red');
  wn_t_image($dir . 'r5.png', 'blue');
  $webp->repair_broken_raster_links();
  $content = wn_t_content($pr);
  wn_t_check(strpos($content, $sub . '/r1-1.webp"') !== false && strpos($content, $sub . '/r1.webp"') === false, 'r1.jpg → r1-1.webp (theo bản ghi), không phải r1.webp của ảnh xanh');
  wn_t_check(strpos($content, $sub . '/r2.jpg"') !== false, 'r2.jpg giữ nguyên (r2.webp có thể là của r2.png)');
  wn_t_check(strpos($content, $sub . '/r3.webp"') !== false, 'r3.jpg → r3.webp (không có ảnh nào khác cùng tên)');
  wn_t_check(strpos($content, $sub . '/r4-1.webp"') !== false && wn_t_color($dir . 'r4-1.webp') === 'red' && wn_t_color($dir . 'r4.webp') === 'cyan', 'r4.jpg → r4-1.webp chép từ r4.jpg.webp, r4.webp của ảnh khác giữ nguyên');
  wn_t_check(!file_exists($dir . 'r5.webp'), 'không tự tạo r5.webp từ r5.jpg.webp khi còn r5.png');
  wp_cache_flush();
  wn_t_check(strpos((string) get_post_meta($pr, '_wn_test_json', true), 'r1-1.webp') !== false, 'meta JSON: r1.jpg → r1-1.webp');
  $opt = get_option('wn_webp_test_opt');
  wn_t_check(strpos((string) ($opt['img'] ?? ''), $sub . '/r1-1.webp') !== false, 'option: r1.jpg → r1-1.webp');
  delete_option('wn_webp_test_opt');

  echo "8. Dọn ảnh: file a.webp / a.avif cùng tên cạnh ảnh không dùng\n";
  $q1 = wn_t_attachment('q1.jpg', 'green');
  wn_t_image($dir . 'q1.webp', 'blue');
  wn_t_image($dir . 'q1.png', 'blue');
  $q2 = wn_t_attachment('q2.jpg', 'green');
  wn_t_image($dir . 'q2.webp', 'green');
  $q3 = wn_t_attachment('q3.jpg', 'green');
  wn_t_image($dir . 'q3.webp', 'blue');
  // Bản ghi đổi WebP: q3.webp được đổi từ q3.png (đã xóa).
  $loose = get_option('webnow_webp_loose_map', []);
  update_option('webnow_webp_loose_map', array_merge(is_array($loose) ? $loose : [], [$sub . '/q3.png' => $sub . '/q3.webp']), false);
  $call('quarantine', $q1);
  $call('quarantine', $q2);
  $call('quarantine', $q3);
  wn_t_check(is_file($dir . 'q1.webp'), 'q1.webp giữ lại (còn q1.png cùng tên, không biết của ai)');
  wn_t_check(!is_file($dir . 'q2.webp'), 'q2.webp đi cùng q2.jpg vào cách ly (không có ảnh nào khác cùng tên)');
  wn_t_check(is_file($dir . 'q3.webp'), 'q3.webp giữ lại (bản ghi nói nó đổi từ q3.png)');
  foreach ([$q1, $q2, $q3] as $q) {
    $call('restore', $q);
  }
} finally {
  foreach ($created['posts'] as $id) {
    wp_delete_post($id, true);
  }
  foreach ($created['attachments'] as $id) {
    wp_delete_attachment($id, true);
  }
  if (is_dir($dir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
      $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
  }
  update_option(WebNow_WebP_Batch::OPT_DELETE_ORIGINALS, $old_delete, false);
  update_option(WebNow_WebP_Batch::OPT_QUEUE, $old_queue, false);
  update_option('webnow_webp_loose_map', $old_loose, false);
}

echo $fails ? "\n$fails kiểm tra SAI\n" : "\nTất cả kiểm tra đều đúng\n";
if ($fails && !defined('WP_CLI')) {
  exit(1);
}
