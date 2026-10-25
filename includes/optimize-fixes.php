<?php
/**
 * Các bước áp dụng của Tối ưu web. Mỗi bước chạy theo lát thời gian (WN_Optimize::BUDGET giây),
 * trả về state để trình duyệt gọi tiếp cho tới khi done.
 */
if (!defined('ABSPATH')) {
  exit;
}

final class WN_Optimize_Fixes {

  /** Thứ tự chạy cố định: sao lưu → database → cache → ảnh → còn lại → xóa cache. */
  public static function all() {
    return [
      'backup_db' => 'Sao lưu database',
      'db_transients' => 'Xóa transient hết hạn và bản ghi IP',
      'wc_geo_base' => 'WooCommerce: dùng địa chỉ cửa hàng thay cho định vị IP',
      'db_posts' => 'Dọn revision, nháp tự động, bình luận rác',
      'db_orphans' => 'Xóa dữ liệu mồ côi',
      'db_leftovers' => 'Xóa rác của plugin đã gỡ',
      'db_actions' => 'Dọn Action Scheduler cũ',
      'db_optimize' => 'Thu hồi dung lượng bảng',
      'lscache_install' => 'Chuyển sang LiteSpeed Cache',
      'lscache_tune' => 'Cấu hình LiteSpeed Cache',
      'page_cache_install' => 'Cài và cấu hình cache trang',
      'server_rules' => 'Bật nén gzip/brotli + cache trình duyệt (.htaccess)',
      'img_compress' => 'Nén lại ảnh nặng',
      'img_webp' => 'Chuyển ảnh sang WebP',
      'cron_run' => 'Chạy bù tác vụ nền quá hạn',
      'tweaks' => 'Bật tinh chỉnh nhẹ',
      'wpconfig_perms' => 'Khóa quyền wp-config.php',
      'clean_stray' => 'Cất file sao lưu/log để lộ',
      'purge' => 'Xóa cache để áp dụng thay đổi',
    ];
  }

  public static function exists($fix) {
    return isset(self::all()[$fix]);
  }

  public static function run($fix, array $state) {
    $deadline = microtime(true) + WN_Optimize::BUDGET;
    $res = call_user_func([__CLASS__, 'fix_' . $fix], $state, $deadline);
    return array_merge(['done' => true, 'progress' => 100, 'state' => [], 'log' => [], 'warn' => false], $res);
  }

  /* ---------- Tiện ích ---------- */

  /** uploads/wn-optimize/ có .htaccess chặn truy cập; tên file có chuỗi ngẫu nhiên phòng máy chủ Nginx. */
  public static function work_dir($sub = '') {
    $dir = WN_Optimize::uploads_base() . WN_Optimize::DIR . '/';
    if (!is_dir($dir)) {
      wp_mkdir_p($dir);
    }
    if (!file_exists($dir . '.htaccess')) {
      @file_put_contents($dir . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
      @file_put_contents($dir . 'index.php', "<?php // Silence is golden.\n");
    }
    if ($sub) {
      $dir .= trailingslashit($sub);
      wp_mkdir_p($dir);
    }
    return $dir;
  }

  public static function done_list($name) {
    $f = self::work_dir() . $name . '.json';
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : [];
    return is_array($d) ? $d : [];
  }

  private static function save_list($name, array $data) {
    file_put_contents(self::work_dir() . $name . '.json', wp_json_encode($data));
  }

  private static function rel($path) {
    return substr($path, strlen(WN_Optimize::uploads_base()));
  }

  private static function n($num) {
    return number_format_i18n($num);
  }

  /* ---------- Sao lưu ---------- */

  private static function backup_tables() {
    global $wpdb;
    $tables = [$wpdb->options, $wpdb->posts, $wpdb->postmeta, $wpdb->comments, $wpdb->commentmeta, $wpdb->term_relationships,
      $wpdb->prefix . 'actionscheduler_actions', $wpdb->prefix . 'actionscheduler_logs'];
    foreach (WN_Optimize::leftovers() as $l) {
      $tables = array_merge($tables, $l['tables']);
    }
    return array_values(array_filter(array_unique($tables), ['WN_Optimize', 'table_exists']));
  }

  private static function fix_backup_db($s, $deadline) {
    global $wpdb;
    if (empty($s['file'])) {
      $s = [
        'file' => self::work_dir('backups') . 'db-' . wp_date('Ymd-His') . '-' . wp_generate_password(8, false) . '.sql.gz',
        'tables' => self::backup_tables(),
        'i' => 0,
        'offset' => 0,
        'rows' => 0,
      ];
      $gz = gzopen($s['file'], 'wb6');
      gzwrite($gz, "-- WebNow Tối ưu web: sao lưu trước khi dọn database\n-- " . home_url() . ' ' . wp_date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
      gzclose($gz);
    }
    $gz = gzopen($s['file'], 'ab6');
    while ($s['i'] < count($s['tables']) && microtime(true) < $deadline) {
      $t = $s['tables'][$s['i']];
      if ($s['offset'] === 0) {
        $create = $wpdb->get_row("SHOW CREATE TABLE `$t`", ARRAY_N);
        gzwrite($gz, "\nDROP TABLE IF EXISTS `$t`;\n" . $create[1] . ";\n");
      }
      $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM `$t` LIMIT %d OFFSET %d", 500, $s['offset']), ARRAY_N);
      if ($rows) {
        $values = [];
        foreach ($rows as $row) {
          $values[] = '(' . implode(',', array_map(function ($v) use ($wpdb) {
            return $v === null ? 'NULL' : "'" . $wpdb->remove_placeholder_escape(esc_sql($v)) . "'";
          }, $row)) . ')';
        }
        gzwrite($gz, "INSERT INTO `$t` VALUES\n" . implode(",\n", $values) . ";\n");
        $s['rows'] += count($rows);
      }
      if (count($rows) < 500) {
        $s['i']++;
        $s['offset'] = 0;
      } else {
        $s['offset'] += 500;
      }
    }
    gzclose($gz);
    $done = $s['i'] >= count($s['tables']);
    $log = [];
    if ($done) {
      $log[] = sprintf('Đã sao lưu %d bảng (%s dòng, %s) → %s', count($s['tables']), self::n($s['rows']), size_format(filesize($s['file']), 1), self::rel($s['file']));
    }
    return ['done' => $done, 'progress' => (int) floor($s['i'] * 100 / max(1, count($s['tables']))), 'state' => $s, 'log' => $log];
  }

  /* ---------- Database ---------- */

  private static function fix_db_transients($s) {
    global $wpdb;
    $ip = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
      $wpdb->esc_like('_transient_external_ip_address_') . '%', $wpdb->esc_like('_transient_timeout_external_ip_address_') . '%'));
    $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}");
    delete_expired_transients(true);
    $after = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}");
    return ['log' => [sprintf('Xóa %s bản ghi IP của WooCommerce và %s dòng transient hết hạn.', self::n(intdiv((int) $ip, 2)), self::n($before - $after))]];
  }

  private static function fix_wc_geo_base() {
    if (!class_exists('WooCommerce')) {
      return ['log' => ['Không có WooCommerce, bỏ qua.']];
    }
    $old = get_option('woocommerce_default_customer_address');
    update_option('woocommerce_default_customer_address', 'base');
    return ['log' => ["Vị trí khách mặc định: {$old} → base (địa chỉ cửa hàng). Không còn gọi API định vị cho mỗi khách mới."]];
  }

  private static function fix_db_posts($s, $deadline) {
    global $wpdb;
    $s += ['stage' => 'rev', 'rev' => 0, 'drafts' => 0, 'comments' => 0];
    while (microtime(true) < $deadline && $s['stage'] !== 'done') {
      if ($s['stage'] === 'rev') {
        $parent = (int) $wpdb->get_var($wpdb->prepare(
          "SELECT post_parent FROM {$wpdb->posts} WHERE post_type = 'revision' GROUP BY post_parent HAVING COUNT(*) > %d LIMIT 1", WN_Optimize::REVISIONS_KEEP));
        if (!$parent) {
          $s['stage'] = 'drafts';
          continue;
        }
        $ids = $wpdb->get_col($wpdb->prepare(
          "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d ORDER BY post_date DESC, ID DESC", $parent));
        foreach (array_slice($ids, WN_Optimize::REVISIONS_KEEP) as $id) {
          if (wp_delete_post_revision($id)) {
            $s['rev']++;
          } else {
            // Không xóa được (đã hỏng) thì xóa thẳng để vòng lặp không kẹt.
            $wpdb->delete($wpdb->posts, ['ID' => $id]);
          }
        }
      } elseif ($s['stage'] === 'drafts') {
        $ids = $wpdb->get_col($wpdb->prepare(
          "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_date < %s LIMIT 100", gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS)));
        if (!$ids) {
          $s['stage'] = 'comments';
          continue;
        }
        foreach ($ids as $id) {
          wp_delete_post($id, true) ?: $wpdb->delete($wpdb->posts, ['ID' => $id]);
          $s['drafts']++;
        }
      } else {
        $ids = $wpdb->get_col($wpdb->prepare(
          "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved IN ('spam','trash') AND comment_date < %s LIMIT 200", gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)));
        if (!$ids) {
          $s['stage'] = 'done';
          continue;
        }
        foreach ($ids as $id) {
          wp_delete_comment($id, true) ?: $wpdb->delete($wpdb->comments, ['comment_ID' => $id]);
          $s['comments']++;
        }
      }
    }
    $done = $s['stage'] === 'done';
    $progress = ['rev' => 20, 'drafts' => 60, 'comments' => 80, 'done' => 100][$s['stage']];
    $log = $done ? [sprintf('Xóa %s revision (giữ %d bản mới nhất/bài), %s nháp tự động, %s bình luận rác.',
      self::n($s['rev']), WN_Optimize::REVISIONS_KEEP, self::n($s['drafts']), self::n($s['comments']))] : [];
    return ['done' => $done, 'progress' => $progress, 'state' => $s, 'log' => $log];
  }

  private static function fix_db_orphans() {
    global $wpdb;
    $pm = $wpdb->query("DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL");
    $cm = $wpdb->query("DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL");
    $tr = 0;
    $taxes = WN_Optimize::post_taxonomies();
    if ($taxes) {
      $in = "'" . implode("','", array_map('esc_sql', $taxes)) . "'";
      $tr = $wpdb->query("DELETE tr FROM {$wpdb->term_relationships} tr
        JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
        LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id
        WHERE p.ID IS NULL AND tt.taxonomy IN ($in)");
    }
    return ['log' => [sprintf('Xóa %s postmeta, %s commentmeta, %s liên kết danh mục mồ côi.', self::n($pm), self::n($cm), self::n($tr))]];
  }

  private static function fix_db_leftovers() {
    global $wpdb;
    $log = [];
    $sigs = WN_Optimize::leftover_signatures();
    foreach (WN_Optimize::leftovers() as $key => $l) {
      $opt = WN_Optimize::like_sql('option_name', $sigs[$key]['options']);
      $o = $opt ? $wpdb->query("DELETE FROM {$wpdb->options} WHERE $opt") : 0;
      $meta = WN_Optimize::like_sql('meta_key', $sigs[$key]['meta']);
      $m = $meta ? $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE $meta") : 0;
      foreach ($l['tables'] as $t) {
        $wpdb->query("DROP TABLE IF EXISTS `$t`");
      }
      $log[] = sprintf('%s: xóa %s option, %s meta, %d bảng%s.', $l['name'], self::n($o), self::n($m), count($l['tables']),
        $l['bytes'] ? ' (' . size_format($l['bytes'], 1) . ')' : '');
    }
    wp_cache_flush();
    return ['log' => $log ?: ['Không còn gì để dọn.']];
  }

  private static function fix_db_actions($s, $deadline) {
    global $wpdb;
    $s += ['deleted' => 0];
    $actions = $wpdb->prefix . 'actionscheduler_actions';
    $logs = $wpdb->prefix . 'actionscheduler_logs';
    if (!WN_Optimize::table_exists($actions)) {
      return ['log' => ['Không có bảng Action Scheduler.']];
    }
    $cut = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
    $done = false;
    while (microtime(true) < $deadline) {
      $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT action_id FROM {$actions} WHERE status IN ('complete','failed','canceled') AND scheduled_date_gmt < %s LIMIT 1000", $cut));
      if (!$ids) {
        $done = true;
        break;
      }
      $in = implode(',', array_map('intval', $ids));
      if (WN_Optimize::table_exists($logs)) {
        $wpdb->query("DELETE FROM {$logs} WHERE action_id IN ($in)");
      }
      $wpdb->query("DELETE FROM {$actions} WHERE action_id IN ($in)");
      $s['deleted'] += count($ids);
    }
    return ['done' => $done, 'progress' => $done ? 100 : 50, 'state' => $s,
      'log' => $done ? ['Xóa ' . self::n($s['deleted']) . ' tác vụ cũ hơn 30 ngày.'] : []];
  }

  private static function fix_db_optimize($s, $deadline) {
    global $wpdb;
    if (!isset($s['tables'])) {
      $before = WN_Optimize::db_size_mb();
      $s = ['tables' => array_values(array_filter([$wpdb->options, $wpdb->posts, $wpdb->postmeta, $wpdb->comments, $wpdb->commentmeta,
        $wpdb->term_relationships, $wpdb->prefix . 'actionscheduler_actions', $wpdb->prefix . 'actionscheduler_logs'], ['WN_Optimize', 'table_exists'])),
        'i' => 0, 'before' => $before];
    }
    while ($s['i'] < count($s['tables']) && microtime(true) < $deadline) {
      $wpdb->query("OPTIMIZE TABLE `{$s['tables'][$s['i']]}`");
      $s['i']++;
    }
    $done = $s['i'] >= count($s['tables']);
    return ['done' => $done, 'progress' => (int) floor($s['i'] * 100 / max(1, count($s['tables']))), 'state' => $s,
      'log' => $done ? [sprintf('Database: %s MB → %s MB.', number_format_i18n($s['before'], 1), number_format_i18n(WN_Optimize::db_size_mb(), 1))] : []];
  }

  /* ---------- Cache ---------- */

  private static function load_admin_includes() {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
  }

  private static function fix_lscache_install() {
    self::load_admin_includes();
    $log = [];
    $plugin = 'litespeed-cache/litespeed-cache.php';
    if (!is_dir(WP_PLUGIN_DIR . '/litespeed-cache')) {
      $skin = new WP_Ajax_Upgrader_Skin();
      $ok = (new Plugin_Upgrader($skin))->install('https://downloads.wordpress.org/plugin/litespeed-cache.latest-stable.zip');
      if (!$ok || is_wp_error($ok)) {
        $err = is_wp_error($ok) ? $ok->get_error_message() : implode(' ', (array) $skin->get_error_messages());
        return ['warn' => true, 'log' => ['Không cài được LiteSpeed Cache: ' . ($err ?: 'không ghi được thư mục plugins') . '. Hãy cài tay từ Plugin → Cài mới.']];
      }
      $log[] = 'Đã tải và cài LiteSpeed Cache từ wordpress.org.';
    }
    $others = WN_Optimize::active_cache_plugins();
    if ($others) {
      deactivate_plugins(array_keys($others));
      $log[] = 'Đã tắt (chưa xóa) ' . implode(', ', $others) . ' để không cache chồng nhau.';
    }
    if (!is_plugin_active($plugin)) {
      $r = activate_plugin($plugin);
      if (is_wp_error($r)) {
        return ['warn' => true, 'log' => array_merge($log, ['Không kích hoạt được LiteSpeed Cache: ' . $r->get_error_message()])];
      }
      $log[] = 'Đã kích hoạt LiteSpeed Cache.';
    }
    return ['log' => $log];
  }

  private static function fix_lscache_tune() {
    if (!class_exists('\LiteSpeed\Conf')) {
      return ['warn' => true, 'log' => ['LiteSpeed Cache chưa chạy, bỏ qua bước cấu hình.']];
    }
    $want = WN_Optimize::lscache_recommended();
    $vals = [];
    foreach ($want as $key => $v) {
      if (is_array($v)) {
        $v = implode("\n", array_values(array_unique(array_merge(WN_Optimize::lscache_list($key), $v))));
      }
      $vals[$key] = (string) $v;
    }
    if (class_exists('\LiteSpeed\Admin_Settings') && method_exists('\LiteSpeed\Admin_Settings', 'cls')) {
      \LiteSpeed\Admin_Settings::cls()->save([\LiteSpeed\Admin_Settings::ENROLL => array_keys($vals)] + $vals);
    } else {
      \LiteSpeed\Conf::cls()->update_confs($vals);
    }
    $left = WN_Optimize::lscache_diff();
    return ['warn' => (bool) $left, 'log' => [
      sprintf('Đã áp dụng %d tùy chọn: cache trang 7 ngày, cache trình duyệt 1 năm, nén CSS/JS/HTML, defer JS, lazy-load ảnh (trừ logo), giảm heartbeat.', count($vals)),
      $left ? 'Chưa nhận: ' . implode(', ', $left) : 'Mẹo: mở vài trang chính kiểm tra menu/slider sau khi defer JS.',
    ]];
  }

  /**
   * Cache trang cho máy chủ không phải LiteSpeed.
   * Apache: WP Fastest Cache (trả HTML tĩnh bằng .htaccess, không chạy PHP).
   * Nginx/khác: Cache Enabler (drop-in advanced-cache.php, không cần rewrite).
   * Lượt 1 cài + kích hoạt, lượt 2 (request mới, plugin đã nạp) mới cấu hình.
   */
  private static function fix_page_cache_install($s) {
    self::load_admin_includes();
    $soft = WN_Optimize::server_software();
    if (is_plugin_active('wp-fastest-cache/wpFastestCache.php')) {
      $slug = 'wp-fastest-cache';
    } elseif (is_plugin_active('cache-enabler/cache-enabler.php')) {
      $slug = 'cache-enabler';
    } else {
      $slug = $soft['apache'] ? 'wp-fastest-cache' : 'cache-enabler';
    }
    $file = $slug === 'wp-fastest-cache' ? 'wp-fastest-cache/wpFastestCache.php' : 'cache-enabler/cache-enabler.php';
    $name = $slug === 'wp-fastest-cache' ? 'WP Fastest Cache' : 'Cache Enabler';

    if (empty($s['stage'])) {
      $log = [];
      if (!is_dir(WP_PLUGIN_DIR . '/' . $slug)) {
        $skin = new WP_Ajax_Upgrader_Skin();
        $ok = (new Plugin_Upgrader($skin))->install("https://downloads.wordpress.org/plugin/{$slug}.latest-stable.zip");
        if (!$ok || is_wp_error($ok)) {
          $err = is_wp_error($ok) ? $ok->get_error_message() : implode(' ', (array) $skin->get_error_messages());
          return ['warn' => true, 'log' => ["Không cài được {$name}: " . ($err ?: 'không ghi được thư mục plugins') . '.']];
        }
        $log[] = "Đã tải và cài {$name} từ wordpress.org.";
      }
      if (!is_plugin_active($file)) {
        $r = activate_plugin($file);
        if (is_wp_error($r)) {
          return ['warn' => true, 'log' => array_merge($log, ["Không kích hoạt được {$name}: " . $r->get_error_message()])];
        }
        $log[] = "Đã kích hoạt {$name}.";
      }
      return ['done' => false, 'progress' => 50, 'state' => ['stage' => 'config'], 'log' => $log, 'note' => 'Đang cấu hình'];
    }

    return $slug === 'wp-fastest-cache' ? self::configure_wpfc() : self::configure_cache_enabler();
  }

  private static function configure_wpfc() {
    if (!class_exists('WpFastestCacheAdmin') && is_file(WP_PLUGIN_DIR . '/wp-fastest-cache/inc/admin.php')) {
      include_once WP_PLUGIN_DIR . '/wp-fastest-cache/inc/admin.php';
    }
    if (!class_exists('WpFastestCacheAdmin')) {
      return ['warn' => true, 'log' => ['WP Fastest Cache chưa nạp được, hãy bật Cache System trong trang cài đặt của nó.']];
    }
    $current = json_decode((string) get_option('WpFastestCache', '{}'), true) ?: [];
    $want = array_merge($current, [
      'wpFastestCacheStatus' => 'on',
      'wpFastestCacheNewPost' => 'on',
      'wpFastestCacheNewPost_type' => 'all',
      'wpFastestCacheUpdatePost' => 'on',
      'wpFastestCacheUpdatePost_type' => 'post',
      'wpFastestCacheMinifyHtml' => 'on',
      'wpFastestCacheMinifyCss' => 'on',
      'wpFastestCacheGzip' => 'on',
      'wpFastestCacheLBC' => 'on',
      'wpFastestCacheDisableEmojis' => 'on',
    ]);
    // saveOption() của WPFC đọc thẳng $_POST rồi tự ghi .htaccess.
    $saved_post = $_POST;
    $_POST = $want;
    (new WpFastestCacheAdmin())->saveOption();
    $_POST = $saved_post;
    $errors = array_filter((array) get_settings_errors('wpfc-notice'), function ($e) {
      return ($e['type'] ?? '') === 'error';
    });
    if ($errors) {
      return ['warn' => true, 'log' => ['WP Fastest Cache báo lỗi: ' . wp_strip_all_tags(reset($errors)['message'])]];
    }
    return ['log' => ['Đã bật WP Fastest Cache: cache trang, nén gzip, cache trình duyệt, minify HTML/CSS, tự xóa cache khi sửa bài.']];
  }

  private static function configure_cache_enabler() {
    if (!class_exists('Cache_Enabler')) {
      return ['warn' => true, 'log' => ['Cache Enabler chưa nạp được.']];
    }
    $settings = array_merge((array) get_option('cache_enabler', []), [
      'cache_expires' => 1,
      'cache_expiry_time' => 168,
      'clear_site_cache_on_saved_post' => 1,
      'clear_site_cache_on_changed_plugin' => 1,
      'compress_cache' => 1,
      'minify_html' => 1,
    ]);
    update_option('cache_enabler', $settings);
    Cache_Enabler::update();
    $ok = (defined('WP_CACHE') && WP_CACHE) || is_file(WP_CONTENT_DIR . '/advanced-cache.php');
    return ['warn' => !$ok, 'log' => [
      'Đã bật Cache Enabler: cache trang 7 ngày, nén sẵn bản gzip, minify HTML, tự xóa cache khi sửa bài.',
      $ok ? 'advanced-cache.php đã được tạo.' : 'Chưa ghi được WP_CACHE vào wp-config.php: thêm define(\'WP_CACHE\', true); bằng tay.',
    ]];
  }

  /** Apache: block "WebNow Speed" trong .htaccess gốc (gzip/brotli + cache trình duyệt). */
  private static function fix_server_rules() {
    self::load_admin_includes();
    $soft = WN_Optimize::server_software();
    if ($soft['nginx']) {
      return ['warn' => true, 'log' => ['Nginx không đọc .htaccess: gửi đoạn cấu hình trong mục "Cần làm tay" cho hosting.']];
    }
    $ht = get_home_path() . '.htaccess';
    if (!insert_with_markers($ht, 'WebNow Speed', WN_Optimize::apache_rules())) {
      return ['warn' => true, 'log' => ['Không ghi được .htaccess (kiểm tra quyền ghi file).']];
    }
    $home = WN_Optimize::fetch(home_url('/'));
    if (!$home['ok'] || $home['code'] >= 500) {
      // Rule làm hỏng site (máy chủ thiếu module lạ): gỡ ngay.
      insert_with_markers($ht, 'WebNow Speed', []);
      return ['warn' => true, 'log' => ['Máy chủ báo lỗi sau khi thêm rule, đã gỡ lại để an toàn.']];
    }
    $h = array_change_key_case($home['headers'], CASE_LOWER);
    $enc = (string) ($h['content-encoding'] ?? '');
    return ['warn' => !$enc, 'log' => [
      'Đã thêm rule nén + cache trình duyệt 1 năm cho CSS/JS/ảnh/font vào .htaccess.',
      $enc ? "Đã kiểm tra: trang chủ được nén {$enc}." : 'Máy chủ chưa bật mod_deflate/mod_brotli nên chưa nén được: nhờ hosting bật module.',
    ]];
  }

  /* ---------- Ảnh ---------- */

  /** Danh sách việc cần làm được dựng một lần rồi lưu ra file (thư viện ảnh có thể rất lớn). */
  private static function image_queue($name, callable $need) {
    $file = self::work_dir() . 'queue-' . $name . '.json';
    $items = [];
    WN_Optimize::each_upload_image(function ($path, $size) use (&$items, $need) {
      if ($need($path, $size)) {
        $items[] = self::rel($path);
      }
    });
    file_put_contents($file, wp_json_encode($items));
    return $file;
  }

  private static function load_queue($file) {
    $q = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
    return is_array($q) ? $q : [];
  }

  private static function fix_img_compress($s, $deadline) {
    $engine = WN_Optimize::image_engine();
    if (!$engine) {
      return ['warn' => true, 'log' => ['Máy chủ không có Imagick/GD, bỏ qua.']];
    }
    $doneList = self::done_list('compressed');
    if (empty($s['queue'])) {
      $s = ['queue' => self::image_queue('compress', function ($path, $size) use ($doneList) {
        return $size > WN_Optimize::HEAVY_IMG && ($doneList[self::rel($path)] ?? 0) !== $size;
      }), 'i' => 0, 'saved' => 0, 'count' => 0];
    }
    $q = self::load_queue($s['queue']);
    $base = WN_Optimize::uploads_base();
    $backup = self::work_dir('originals');
    while ($s['i'] < count($q) && microtime(true) < $deadline) {
      $rel = $q[$s['i']++];
      $path = $base . $rel;
      if (!is_file($path)) {
        continue;
      }
      $old = filesize($path);
      $blob = self::recompress($path, $engine);
      if ($blob !== null && strlen($blob) < $old * 0.9) {
        $copy = $backup . $rel;
        wp_mkdir_p(dirname($copy));
        if (!is_file($copy)) {
          copy($path, $copy);
        }
        $mtime = filemtime($path);
        file_put_contents($path, $blob);
        touch($path, $mtime);
        $s['saved'] += $old - strlen($blob);
        $s['count']++;
        $old = strlen($blob);
      }
      $doneList[$rel] = $old;
    }
    self::save_list('compressed', $doneList);
    $done = $s['i'] >= count($q);
    if ($done) {
      @unlink($s['queue']);
    }
    return ['done' => $done, 'progress' => (int) floor($s['i'] * 100 / max(1, count($q))), 'state' => $s,
      'log' => $done ? [sprintf('Nén lại %s/%s ảnh, giảm %s. Ảnh gốc lưu ở uploads/%s/originals/.', self::n($s['count']), self::n(count($q)), size_format($s['saved'], 1), WN_Optimize::DIR)] : [],
      'note' => sprintf('%s/%s ảnh', self::n($s['i']), self::n(count($q)))];
  }

  /** Nén lại ảnh giữ nguyên kích thước. null = không xử lý được (ảnh CMYK, hỏng...). */
  private static function recompress($path, $engine) {
    $png = (bool) preg_match('/\.png$/i', $path);
    try {
      if ($engine === 'imagick') {
        $im = new Imagick($path);
        if ($im->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
          return null;
        }
        $im->stripImage();
        if ($png) {
          $im->setOption('png:compression-level', '9');
          $im->setOption('png:compression-filter', '5');
        } else {
          $im->setImageCompression(Imagick::COMPRESSION_JPEG);
          $im->setImageCompressionQuality(82);
          $im->setInterlaceScheme(Imagick::INTERLACE_PLANE);
          $im->setSamplingFactors(['2x2', '1x1', '1x1']);
        }
        $blob = $im->getImagesBlob();
        $im->clear();
        return $blob;
      }
      $im = $png ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
      if (!$im) {
        return null;
      }
      ob_start();
      if ($png) {
        imagesavealpha($im, true);
        imagepng($im, null, 9);
      } else {
        imageinterlace($im, true);
        imagejpeg($im, null, 82);
      }
      if (PHP_VERSION_ID < 80000) {
        imagedestroy($im); // PHP 8+ tự giải phóng, 8.5 báo deprecated
      }
      return ob_get_clean();
    } catch (Throwable $e) {
      return null;
    }
  }

  private static function to_webp($path, $engine) {
    $png = (bool) preg_match('/\.png$/i', $path);
    try {
      if ($engine === 'imagick') {
        $im = new Imagick($path);
        if ($im->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
          $im->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        }
        $im->stripImage();
        $im->setImageFormat('webp');
        $im->setImageCompressionQuality(80);
        $im->setOption('webp:method', '4');
        if ($png) {
          $im->setOption('webp:alpha-quality', '90');
        }
        $blob = $im->getImageBlob();
        $im->clear();
        return $blob;
      }
      $im = $png ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
      if (!$im) {
        return null;
      }
      imagepalettetotruecolor($im);
      imagealphablending($im, true);
      imagesavealpha($im, true);
      ob_start();
      imagewebp($im, null, 80);
      if (PHP_VERSION_ID < 80000) {
        imagedestroy($im); // PHP 8+ tự giải phóng, 8.5 báo deprecated
      }
      return ob_get_clean();
    } catch (Throwable $e) {
      return null;
    }
  }

  private static function fix_img_webp($s, $deadline) {
    $engine = WN_Optimize::image_engine();
    if (!$engine) {
      return ['warn' => true, 'log' => ['Máy chủ không có Imagick/GD hỗ trợ WebP, bỏ qua.']];
    }
    if (WN_Optimize::webp_replace_mode()) {
      return self::webp_replace(WebNow_WebP_Batch::$instance, $s, $deadline);
    }
    $skip = self::done_list('webp-skip');
    if (empty($s['queue'])) {
      $s = ['queue' => self::image_queue('webp', function ($path) use ($skip) {
        $w = $path . '.webp';
        return !isset($skip[self::rel($path)]) && !(is_file($w) && filemtime($w) >= filemtime($path));
      }), 'i' => 0, 'made' => 0, 'saved' => 0];
    }
    $q = self::load_queue($s['queue']);
    $base = WN_Optimize::uploads_base();
    while ($s['i'] < count($q) && microtime(true) < $deadline) {
      $rel = $q[$s['i']++];
      $path = $base . $rel;
      if (!is_file($path)) {
        continue;
      }
      $blob = self::to_webp($path, $engine);
      $orig = filesize($path);
      if ($blob !== null && strlen($blob) > 0 && strlen($blob) < $orig) {
        file_put_contents($path . '.webp', $blob);
        $s['made']++;
        $s['saved'] += $orig - strlen($blob);
      } else {
        $skip[$rel] = 1;
      }
    }
    self::save_list('webp-skip', $skip);
    $done = $s['i'] >= count($q);
    $log = [];
    $warn = false;
    if ($done) {
      @unlink($s['queue']);
      $log[] = sprintf('Tạo %s ảnh WebP, nhẹ hơn tổng cộng %s so với ảnh gốc.', self::n($s['made']), size_format($s['saved'], 1));
      $rule = self::write_webp_rule();
      $log[] = $rule['log'];
      $warn = !$rule['ok'];
    }
    return ['done' => $done, 'progress' => (int) floor($s['i'] * 100 / max(1, count($q))), 'state' => $s, 'log' => $log, 'warn' => $warn,
      'note' => sprintf('%s/%s ảnh', self::n($s['i']), self::n(count($q)))];
  }

  /**
   * Đổi hẳn sang WebP bằng module Ảnh WebP: tạo .webp (cả các size) → sửa link trong DB → xóa JPG/PNG.
   * Ảnh có link viết cứng trong code được module bỏ qua (wn_is_hardcoded_upload).
   */
  private static function webp_replace($webp, $s, $deadline) {
    $skip = self::done_list('webp-skip');
    if (empty($s['queue'])) {
      // Đã chọn đổi hẳn sang WebP thì bật luôn xóa ảnh gốc, ảnh tải lên sau này cũng vậy.
      update_option(WebNow_WebP_Batch::OPT_DELETE_ORIGINALS, '1', false);
      $file = self::work_dir() . 'queue-webp-replace.json';
      file_put_contents($file, wp_json_encode($webp->collect_stats(true)['queue']));
      $s = ['queue' => $file, 'i' => 0, 'ok' => 0, 'fail' => 0];
    }
    $q = self::load_queue($s['queue']);
    while ($s['i'] < count($q) && microtime(true) < $deadline) {
      $item = $q[$s['i']++];
      if (($item['type'] ?? '') === 'attachment') {
        $r = $webp->convert_attachment((int) $item['id']);
      } else {
        $path = wp_normalize_path((string) ($item['path'] ?? ''));
        $rel = self::rel($path);
        if (isset($skip[$rel]) || !is_file($path)) {
          continue;
        }
        $r = $webp->replace_file_with_webp($path, true);
        if ($r === false) {
          $skip[$rel] = 1;
        }
      }
      if ($r === false) {
        $s['fail']++;
      } elseif ($r) {
        $s['ok']++;
      }
    }
    self::save_list('webp-skip', $skip);
    $done = $s['i'] >= count($q);
    $log = [];
    if ($done) {
      @unlink($s['queue']);
      $fixed = $webp->repair_broken_raster_links();
      $dropped = self::drop_replaced_backups();
      $log[] = sprintf('Đổi %s ảnh sang WebP, sửa link và xóa ảnh gốc JPG/PNG.', self::n($s['ok'])) . ($s['fail'] ? sprintf(' %s ảnh không đổi được, giữ nguyên.', self::n($s['fail'])) : '');
      if ($fixed) {
        $log[] = sprintf('Sửa thêm %s link ảnh cũ trỏ tới file đã xóa.', self::n($fixed));
      }
      if ($dropped) {
        $log[] = sprintf('Xóa %s bản sao lưu ảnh gốc không còn dùng (uploads/%s/originals/).', self::n($dropped), WN_Optimize::DIR);
      }
      $hard = count(wn_hardcoded_upload_images());
      if ($hard) {
        $log[] = sprintf('Giữ nguyên %s ảnh có link viết cứng trong code, xem danh sách ở mục "Cần làm tay".', self::n($hard));
      }
    }
    return ['done' => $done, 'progress' => (int) floor($s['i'] * 100 / max(1, count($q))), 'state' => $s, 'log' => $log, 'warn' => $done && $s['fail'] > 0,
      'note' => sprintf('%s/%s ảnh', self::n($s['i']), self::n(count($q)))];
  }

  /** Bản sao lưu của bước nén ảnh mà ảnh gốc đã được đổi sang WebP và xóa thì bỏ luôn. */
  private static function drop_replaced_backups() {
    $dir = self::work_dir('originals');
    $base = WN_Optimize::uploads_base();
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
      if (!$f->isFile() || !preg_match('/\.(jpe?g|png)$/i', $f->getFilename())) {
        continue;
      }
      $rel = substr(wp_normalize_path($f->getPathname()), strlen($dir));
      $webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $base . $rel);
      if (!is_file($base . $rel) && is_file($webp) && @unlink($f->getPathname())) {
        $n++;
      }
    }
    return $n;
  }

  /** Rule trả image.jpg.webp thay cho image.jpg khi trình duyệt hỗ trợ (Apache/LiteSpeed). */
  private static function write_webp_rule() {
    $soft = WN_Optimize::server_software();
    if ($soft['nginx']) {
      return ['ok' => false, 'log' => 'Máy chủ Nginx không đọc .htaccess: gửi đoạn cấu hình trong mục "Cần làm tay" cho hosting để trả .webp.'];
    }
    if (!WN_Optimize::webp_rule_present()) {
      self::load_admin_includes();
      $path = wp_parse_url(wp_get_upload_dir()['baseurl'], PHP_URL_PATH);
      $lines = [
        '<IfModule mod_rewrite.c>',
        'RewriteEngine On',
        'RewriteOptions Inherit',
        'RewriteBase ' . trailingslashit($path ?: '/wp-content/uploads'),
        'RewriteCond %{HTTP_ACCEPT} image/webp',
        'RewriteCond %{REQUEST_FILENAME}.webp -f',
        'RewriteRule ^(.+\.(?:jpe?g|png))$ $1.webp [T=image/webp,L]',
        '</IfModule>',
        '<IfModule mod_headers.c>',
        '<FilesMatch "\.(?i:jpe?g|png)(\.webp)?$">',
        'Header append Vary Accept',
        '</FilesMatch>',
        '</IfModule>',
        'AddType image/webp .webp',
      ];
      if (!insert_with_markers(WN_Optimize::uploads_base() . '.htaccess', 'WebNow WebP', $lines)) {
        return ['ok' => false, 'log' => 'Không ghi được uploads/.htaccess.'];
      }
    }
    // Thử thật: một ảnh có bản .webp phải trả về image/webp.
    $sample = '';
    WN_Optimize::each_upload_image(function ($p) use (&$sample) {
      if (!$sample && is_file($p . '.webp')) {
        $sample = $p;
      }
    }, microtime(true) + 3);
    if (!$sample) {
      return ['ok' => true, 'log' => 'Đã bật rule tự phục vụ WebP.'];
    }
    $url = trailingslashit(wp_get_upload_dir()['baseurl']) . self::rel($sample);
    $r = wp_remote_head($url, ['sslverify' => false, 'timeout' => 10, 'headers' => ['Accept' => 'image/avif,image/webp,*/*']]);
    $type = is_wp_error($r) ? '' : (string) wp_remote_retrieve_header($r, 'content-type');
    if (strpos($type, 'webp') !== false) {
      return ['ok' => true, 'log' => 'Đã kiểm tra: ' . basename($sample) . ' được trả về dạng WebP cho trình duyệt hỗ trợ.'];
    }
    return ['ok' => false, 'log' => 'Đã ghi rule nhưng máy chủ chưa trả WebP (' . ($type ?: 'không đo được') . '). Có thể máy chủ tắt mod_rewrite cho uploads.'];
  }

  /* ---------- Còn lại ---------- */

  private static function fix_cron_run($s, $deadline) {
    $s += ['ran' => 0];
    if (!function_exists('wp_get_ready_cron_jobs')) {
      return ['log' => ['WordPress quá cũ để chạy bù cron.']];
    }
    set_transient('doing_cron', sprintf('%.22F', microtime(true)));
    $more = false;
    foreach (wp_get_ready_cron_jobs() as $ts => $hooks) {
      foreach ($hooks as $hook => $keys) {
        foreach ($keys as $v) {
          if (microtime(true) > $deadline) {
            $more = true;
            break 3;
          }
          if ($v['schedule']) {
            wp_reschedule_event($ts, $v['schedule'], $hook, $v['args']);
          }
          wp_unschedule_event($ts, $hook, $v['args']);
          try {
            do_action_ref_array($hook, $v['args']);
          } catch (Throwable $e) {
            // Một tác vụ lỗi không được chặn các tác vụ khác.
          }
          $s['ran']++;
        }
      }
    }
    delete_transient('doing_cron');
    $log = [];
    if (!$more) {
      $log[] = 'Đã chạy bù ' . self::n($s['ran']) . ' tác vụ nền.';
      $log[] = (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)
        ? 'Kiểm tra dòng cron trong DirectAdmin → Cron Jobs dùng đúng PHP của web: ' . WN_Optimize::cron_command()
        : 'Để cron không chết lại: DirectAdmin → Cron Jobs, thêm: ' . WN_Optimize::cron_command();
    }
    return ['done' => !$more, 'progress' => $more ? 50 : 100, 'state' => $s, 'log' => $log, 'note' => self::n($s['ran']) . ' tác vụ'];
  }

  private static function fix_tweaks() {
    update_option(WN_Optimize::OPT_TWEAKS, ['revisions' => 1, 'heartbeat' => 1, 'emoji' => 1]);
    return ['log' => ['Bật: giới hạn ' . WN_Optimize::REVISIONS_KEEP . ' revision/bài, tắt heartbeat cho khách, bỏ script emoji.']];
  }

  private static function fix_wpconfig_perms() {
    $cfg = WN_Optimize::wpconfig_path();
    if (!$cfg) {
      return ['log' => ['Không tìm thấy wp-config.php.']];
    }
    // Chỉ khóa khi PHP chạy đúng user sở hữu file, nếu không có thể làm web không đọc được cấu hình.
    if (!function_exists('posix_geteuid') || fileowner($cfg) !== posix_geteuid()) {
      return ['warn' => true, 'log' => ['PHP không chạy bằng user sở hữu wp-config.php, không tự đổi quyền. Hãy đặt quyền 640 bằng File Manager.']];
    }
    $old = fileperms($cfg) & 0777;
    if (!@chmod($cfg, 0640) || !is_readable($cfg)) {
      @chmod($cfg, $old);
      return ['warn' => true, 'log' => ['Không đổi được quyền wp-config.php.']];
    }
    clearstatcache();
    return ['log' => [sprintf('wp-config.php: %o → 640.', $old)]];
  }

  private static function fix_clean_stray() {
    $dir = self::work_dir('stray');
    $log = [];
    foreach (WN_Optimize::stray_files() as $f) {
      $to = $dir . basename($f) . '.' . wp_generate_password(6, false);
      if (@rename($f, $to) || (@copy($f, $to) && @unlink($f))) {
        $log[] = 'Đã cất ' . str_replace(wp_normalize_path(ABSPATH), '', $f);
      }
    }
    $debug = WP_CONTENT_DIR . '/debug.log';
    if (is_file($debug) && filesize($debug) > 10 * 1048576) {
      $to = $dir . 'debug-' . wp_date('Ymd-His') . '.log';
      if (@rename($debug, $to)) {
        $log[] = 'Đã cất debug.log (' . size_format(filesize($to), 0) . ').';
      }
    }
    if ($log) {
      $log[] = 'Các file nằm ở uploads/' . WN_Optimize::DIR . '/stray/ (đã chặn truy cập từ web).';
    }
    return ['log' => $log ?: ['Không có file nào cần cất.']];
  }

  private static function fix_purge() {
    wn_clear_page_cache();
    wp_cache_flush();
    // Làm nóng lại cache trang chủ để khách đầu tiên (và lần đo lại) không phải chờ dựng trang.
    WN_Optimize::fetch(home_url('/'));
    WN_Optimize::fetch(home_url('/'));
    return ['log' => ['Đã xóa cache cũ và tạo lại cache trang chủ.']];
  }
}
