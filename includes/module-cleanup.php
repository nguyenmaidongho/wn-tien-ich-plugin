<?php
/**
 * Dọn ảnh không dùng (Media → Dọn ảnh không dùng).
 *
 * Quy trình an toàn 3 bước:
 *  1. Quét: đọc nội dung bài/trang, meta (Elementor, ACF, WooCommerce...), options, theme mods,
 *     bảng của plugin khác (Smart Slider...) và file trong theme để gom các ảnh đang được dùng.
 *  2. Cách ly: chuyển file của ảnh không dùng sang uploads/wn-quarantine/{id}/ và ẩn khỏi Thư viện.
 *     Web lỗi ảnh nào thì khôi phục ảnh đó.
 *  3. Xóa hẳn: xóa file trong khu cách ly và xóa attachment khỏi database.
 *
 * Quét theo kiểu bảo thủ: nghi ngờ đang dùng thì coi là đang dùng.
 */
if (!defined('ABSPATH')) {
  exit;
}

final class WN_Media_Cleanup {
  const STATUS = 'wn_quarantine';
  const META = '_wn_quarantine';
  const OPT_SCAN = 'wn_cleanup_scan';
  const OPT_RESULT = 'wn_cleanup_result';
  const DIR = 'wn-quarantine';
  const CHUNK = 400;
  const BATCH = 20;
  const IMG_EXT = 'jpe?g|png|gif|webp|avif|svg|bmp|ico|tiff?';
  const HINT_KEYS = '/logo|icon|image|img|background|bg|banner|thumb|photo|avatar|media|gallery|picture|attachment|cover|poster/i';

  public function __construct() {
    add_action('admin_menu', [$this, 'menu']);
    add_action('admin_enqueue_scripts', [$this, 'assets']);
    foreach (['scan_start', 'scan_step', 'quarantine', 'restore', 'delete', 'list_quarantine'] as $a) {
      add_action('wp_ajax_wn_cleanup_' . $a, [$this, 'ajax_' . $a]);
    }
  }

  /** Đăng ký luôn (kể cả khi tắt module) để ảnh đang cách ly vẫn ẩn đúng cách. */
  public static function register_status() {
    register_post_status(self::STATUS, [
      'label' => 'Đang cách ly',
      'public' => false,
      'internal' => true,
      'exclude_from_search' => true,
      'show_in_admin_all_list' => false,
      'show_in_admin_status_list' => false,
    ]);
  }

  public function menu() {
    add_media_page('Dọn ảnh không dùng', 'Dọn ảnh không dùng', 'manage_options', 'wn-media-cleanup', [$this, 'page']);
  }

  public function assets($hook) {
    if ($hook !== 'media_page_wn-media-cleanup') {
      return;
    }
    wp_enqueue_script('wn-media-cleanup', wn_plugin_url('/includes/media-cleanup.js'), ['jquery'], '1.0', true);
    wp_localize_script('wn-media-cleanup', 'WN_CLEANUP', [
      'ajax' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('wn_cleanup'),
      'batch' => self::BATCH,
    ]);
  }

  public function page() {
    $result = get_option(self::OPT_RESULT, []);
    $scanned = !empty($result['time']) ? wp_date('H:i d/m/Y', $result['time']) : '';
    ?>
    <div class="wrap wn-cleanup">
      <h1>Dọn ảnh không dùng</h1>
      <div class="notice notice-warning inline"><p>
        <strong>Nên sao lưu (database + uploads) trước khi xóa hẳn.</strong>
        Quy trình: <strong>Quét</strong> → <strong>Cách ly</strong> ảnh không dùng (ẩn khỏi Thư viện, chuyển file sang <code>uploads/<?php echo esc_html(self::DIR); ?>/</code>)
        → xem web vài ngày, ảnh nào bị thiếu thì <strong>Khôi phục</strong> → còn lại mới <strong>Xóa hẳn</strong>.
      </p></div>
      <style>
        .wn-cleanup table img{width:60px;height:60px;object-fit:cover;display:block}
        .wn-cleanup .wn-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0}
        .wn-cleanup progress{width:100%;max-width:780px;height:18px}
        .wn-cleanup .wn-list{max-height:620px;overflow:auto;margin-top:8px}
        .wn-cleanup td code{word-break:break-all}
      </style>

      <h2>1. Quét ảnh không dùng</h2>
      <div class="wn-bar">
        <label>Bỏ qua ảnh tải lên trong <input type="number" id="wn-skip-days" min="0" max="365" value="7" style="width:60px"> ngày gần đây</label>
        <button type="button" class="button button-primary" id="wn-scan">Quét</button>
        <span id="wn-scan-status"><?php echo $scanned ? 'Lần quét gần nhất: ' . esc_html($scanned) . '.' : ''; ?></span>
      </div>
      <progress id="wn-scan-progress" value="0" max="100" hidden></progress>
      <div id="wn-unused"></div>

      <h2 style="margin-top:32px">2. Ảnh đang cách ly</h2>
      <div id="wn-quarantine"><p>Đang tải...</p></div>
    </div>
    <?php
  }

  /* ---------- AJAX ---------- */

  private function guard_ajax() {
    check_ajax_referer('wn_cleanup', 'nonce');
    if (!current_user_can('manage_options')) {
      wp_send_json_error('Không có quyền.', 403);
    }
  }

  public function ajax_scan_start() {
    $this->guard_ajax();
    global $wpdb;
    $steps = ['posts', 'postmeta', 'options', 'termmeta', 'usermeta', 'comments'];
    foreach ($this->extra_tables() as $table) {
      $steps[] = 'table:' . $table;
    }
    $steps[] = 'files';
    $steps[] = 'classify';
    $state = [
      'steps' => $steps,
      'step' => 0,
      'cursor' => 0,
      'ids' => [],
      'keys' => [],
      'files' => [],
      'skip_days' => max(0, min(365, (int) ($_POST['skip_days'] ?? 7))),
      'started' => time(),
    ];
    update_option(self::OPT_SCAN, $state, false);
    wp_send_json_success(['steps' => count($steps)]);
  }

  public function ajax_scan_step() {
    $this->guard_ajax();
    @set_time_limit(120);
    $state = get_option(self::OPT_SCAN);
    if (!is_array($state) || empty($state['steps'])) {
      wp_send_json_error('Chưa bắt đầu quét.');
    }
    $deadline = microtime(true) + 8;
    $result = null;
    while (microtime(true) < $deadline && $state['step'] < count($state['steps'])) {
      $step = $state['steps'][$state['step']];
      if ($step === 'classify') {
        $result = $this->classify($state);
        $state['step']++;
        break;
      }
      $done = $this->run_step($step, $state);
      if ($done) {
        $state['step']++;
        $state['cursor'] = 0;
      }
    }
    $finished = $state['step'] >= count($state['steps']);
    if ($finished) {
      delete_option(self::OPT_SCAN);
    } else {
      update_option(self::OPT_SCAN, $state, false);
    }
    $label = $finished ? 'Xong' : $this->step_label($state['steps'][$state['step']]);
    wp_send_json_success([
      'done' => $finished,
      'percent' => (int) floor($state['step'] * 100 / count($state['steps'])),
      'label' => $label,
      'result' => $finished ? $this->present_result($result) : null,
    ]);
  }

  public function ajax_quarantine() {
    $this->guard_ajax();
    $result = get_option(self::OPT_RESULT, []);
    $allowed = array_flip(array_map('intval', $result['unused'] ?? []));
    $done = [];
    $errors = [];
    foreach ($this->posted_ids() as $id) {
      if (!isset($allowed[$id])) {
        $errors[] = "#$id: không nằm trong kết quả quét gần nhất, hãy quét lại.";
        continue;
      }
      $r = $this->quarantine($id);
      if (is_wp_error($r)) {
        $errors[] = "#$id: " . $r->get_error_message();
      } else {
        $done[] = $id;
      }
    }
    if ($done) {
      $result['unused'] = array_values(array_diff($result['unused'], $done));
      update_option(self::OPT_RESULT, $result, false);
    }
    wp_send_json_success(['done' => $done, 'errors' => $errors]);
  }

  public function ajax_restore() {
    $this->guard_ajax();
    $done = [];
    $errors = [];
    foreach ($this->posted_ids() as $id) {
      $r = $this->restore($id);
      if (is_wp_error($r)) {
        $errors[] = "#$id: " . $r->get_error_message();
      } else {
        $done[] = $id;
      }
    }
    wp_send_json_success(['done' => $done, 'errors' => $errors]);
  }

  public function ajax_delete() {
    $this->guard_ajax();
    $done = [];
    $errors = [];
    foreach ($this->posted_ids() as $id) {
      $r = $this->delete_forever($id);
      if (is_wp_error($r)) {
        $errors[] = "#$id: " . $r->get_error_message();
      } else {
        $done[] = $id;
      }
    }
    wp_send_json_success(['done' => $done, 'errors' => $errors]);
  }

  public function ajax_list_quarantine() {
    $this->guard_ajax();
    $ids = get_posts([
      'post_type' => 'attachment',
      'post_status' => self::STATUS,
      'posts_per_page' => -1,
      'fields' => 'ids',
      'orderby' => 'ID',
      'order' => 'DESC',
    ]);
    $base = $this->quarantine_base();
    $items = [];
    foreach ($ids as $id) {
      $q = get_post_meta($id, self::META, true);
      $files = is_array($q) ? ($q['files'] ?? []) : [];
      $size = 0;
      $thumb = '';
      foreach ($files as $rel) {
        $path = $base . $id . '/' . $rel;
        if (is_file($path)) {
          $size += filesize($path);
          if (!$thumb && preg_match('/\.(' . self::IMG_EXT . ')$/i', $rel)) {
            $thumb = $this->quarantine_url($id, $rel);
          }
        }
      }
      $main = $files[0] ?? '';
      $items[] = [
        'id' => $id,
        'title' => get_the_title($id),
        'file' => $main,
        'thumb' => $thumb,
        'size' => size_format($size),
        'date' => !empty($q['time']) ? wp_date('d/m/Y', $q['time']) : '',
      ];
    }
    wp_send_json_success(['items' => $items]);
  }

  private function posted_ids() {
    $ids = isset($_POST['ids']) ? (array) $_POST['ids'] : [];
    return array_slice(array_unique(array_filter(array_map('absint', $ids))), 0, 100);
  }

  /* ---------- Quét ---------- */

  private function step_label($step) {
    $labels = [
      'posts' => 'nội dung bài/trang',
      'postmeta' => 'meta bài viết (Elementor, ACF, WooCommerce...)',
      'options' => 'cấu hình site & theme',
      'termmeta' => 'meta chuyên mục',
      'usermeta' => 'meta người dùng',
      'comments' => 'bình luận',
      'files' => 'file trong theme',
      'classify' => 'đối chiếu thư viện',
    ];
    if (strpos($step, 'table:') === 0) {
      return 'bảng ' . substr($step, 6);
    }
    return $labels[$step] ?? $step;
  }

  /** Bảng của plugin khác (Smart Slider, Revolution Slider, form...) có thể chứa link ảnh. */
  private function extra_tables() {
    global $wpdb;
    $core = array_flip(['posts', 'postmeta', 'options', 'termmeta', 'usermeta', 'users', 'terms', 'term_taxonomy',
      'term_relationships', 'comments', 'commentmeta', 'links']);
    // Bảng log/thống kê: to và không chứa ảnh nội dung.
    $skip = '/^(actionscheduler_|wc_admin_|wf|yoast_indexable|rank_math_analytics|redirection_404)|(_logs?|_sessions|_stats|_lookup|_cache)$/i';
    $tables = [];
    foreach ((array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%')) as $t) {
      $suffix = substr($t, strlen($wpdb->prefix));
      if (!isset($core[$suffix]) && !preg_match($skip, $suffix)) {
        $tables[] = $t;
      }
    }
    return apply_filters('wn_cleanup_extra_tables', $tables);
  }

  /** Chạy 1 lượt của bước; trả về true khi bước đã xong. */
  private function run_step($step, &$state) {
    global $wpdb;
    $c = (int) $state['cursor'];
    $n = self::CHUNK;

    switch ($step) {
      case 'posts':
        $rows = $wpdb->get_results($wpdb->prepare(
          "SELECT ID AS k, post_content AS a, post_excerpt AS b FROM {$wpdb->posts}
           WHERE ID > %d AND post_type NOT IN ('attachment','revision') AND post_status <> 'auto-draft'
           ORDER BY ID LIMIT %d", $c, $n), ARRAY_A);
        foreach ($rows as $r) {
          $this->scan_text($r['a'] . ' ' . $r['b'], $state, true);
        }
        break;

      case 'postmeta':
        $rows = $wpdb->get_results($wpdb->prepare(
          "SELECT m.meta_id AS k, m.meta_key AS name, m.meta_value AS v FROM {$wpdb->postmeta} m
           JOIN {$wpdb->posts} p ON p.ID = m.post_id
           WHERE m.meta_id > %d AND p.post_type NOT IN ('attachment','revision') AND p.post_status <> 'auto-draft'
           ORDER BY m.meta_id LIMIT %d", $c, $n), ARRAY_A);
        foreach ($rows as $r) {
          $this->scan_value($r['name'], $r['v'], $state, $r['name'][0] !== '_');
        }
        break;

      case 'options':
        $rows = $wpdb->get_results($wpdb->prepare(
          "SELECT option_id AS k, option_name AS name, option_value AS v FROM {$wpdb->options}
           WHERE option_id > %d AND option_name NOT LIKE '\\_transient\\_%%' AND option_name NOT LIKE '\\_site\\_transient\\_%%'
             AND option_name NOT IN (%s, %s, 'cron', 'webnow_webp_queue_v32')
           ORDER BY option_id LIMIT %d", $c, self::OPT_SCAN, self::OPT_RESULT, $n), ARRAY_A);
        foreach ($rows as $r) {
          $this->scan_value($r['name'], $r['v'], $state, false);
        }
        break;

      case 'termmeta':
      case 'usermeta':
        $table = $step === 'termmeta' ? $wpdb->termmeta : $wpdb->usermeta;
        $pk = $step === 'termmeta' ? 'meta_id' : 'umeta_id';
        $rows = $wpdb->get_results($wpdb->prepare(
          "SELECT $pk AS k, meta_key AS name, meta_value AS v FROM $table WHERE $pk > %d ORDER BY $pk LIMIT %d", $c, $n), ARRAY_A);
        foreach ($rows as $r) {
          $this->scan_value($r['name'], $r['v'], $state, false);
        }
        break;

      case 'comments':
        $rows = $wpdb->get_results($wpdb->prepare(
          "SELECT comment_ID AS k, comment_content AS a FROM {$wpdb->comments} WHERE comment_ID > %d ORDER BY comment_ID LIMIT %d", $c, $n), ARRAY_A);
        foreach ($rows as $r) {
          $this->scan_text($r['a'], $state, true);
        }
        break;

      case 'files':
        if (empty($state['files'])) {
          $state['files'] = $this->theme_files();
        }
        $slice = array_slice($state['files'], $c, 100);
        foreach ($slice as $f) {
          $this->scan_text((string) @file_get_contents($f), $state, false);
        }
        $state['cursor'] = $c + count($slice);
        if ($state['cursor'] >= count($state['files'])) {
          $state['files'] = [];
          return true;
        }
        return false;

      default: // table:xxx — quét mọi cột chữ, phân trang bằng OFFSET.
        $table = substr($step, 6);
        $cols = [];
        $order = '';
        foreach ((array) $wpdb->get_results("SHOW COLUMNS FROM `" . esc_sql($table) . "`", ARRAY_A) as $col) {
          if (preg_match('/char|text|blob|json/i', $col['Type'])) {
            $cols[] = '`' . esc_sql($col['Field']) . '`';
          }
          if (!$order && $col['Key'] === 'PRI') {
            $order = ' ORDER BY `' . esc_sql($col['Field']) . '`';
          }
        }
        if (!$cols) {
          return true;
        }
        $rows = $wpdb->get_results($wpdb->prepare(
          'SELECT ' . implode(',', $cols) . ' FROM `' . esc_sql($table) . '`' . $order . ' LIMIT %d OFFSET %d', $n, $c), ARRAY_A);
        foreach ((array) $rows as $r) {
          $this->scan_text(implode(' ', array_map('strval', $r)), $state, true);
        }
        $state['cursor'] = $c + count((array) $rows);
        return count((array) $rows) < $n;
    }

    if (!$rows) {
      return true;
    }
    $state['cursor'] = (int) end($rows)['k'];
    return count($rows) < $n;
  }

  /**
   * Giá trị meta/option. $numeric_ok: cho phép coi số trần là ID ảnh dù tên khóa không gợi ý
   * (dùng cho meta không bắt đầu bằng "_", vd trường ảnh ACF lưu ID).
   */
  private function scan_value($name, $value, &$state, $numeric_ok) {
    $value = (string) $value;
    if ($value === '') {
      return;
    }
    $hint = (bool) preg_match(self::HINT_KEYS, $name);
    if (preg_match('/^\d+(\s*,\s*\d+)*$/', $value)) {
      if ($hint || $numeric_ok) {
        foreach (preg_split('/\s*,\s*/', $value) as $id) {
          $state['ids'][(int) $id] = 1;
        }
      }
      return;
    }
    if (is_serialized($value)) {
      $data = @unserialize($value, ['allowed_classes' => false]);
      if ($data !== false || $value === 'b:0;') {
        $this->walk($data, $hint, $state);
      }
    }
    $this->scan_text($value, $state, true);
  }

  /** Duyệt mảng (theme_mods, widget, ACF gallery...): số nằm dưới khóa gợi ý ảnh thì coi là ID. */
  private function walk($data, $hint, &$state, $depth = 0) {
    if ($depth > 20) {
      return;
    }
    if (is_array($data) || is_object($data)) {
      foreach ((array) $data as $k => $v) {
        $this->walk($v, $hint || (is_string($k) && preg_match(self::HINT_KEYS, $k)), $state, $depth + 1);
      }
    } elseif ($hint && (is_int($data) || (is_string($data) && ctype_digit($data)))) {
      $state['ids'][(int) $data] = 1;
    } elseif ($hint && is_string($data) && preg_match('/^\d+(,\d+)+$/', $data)) {
      foreach (explode(',', $data) as $id) {
        $state['ids'][(int) $id] = 1;
      }
    }
  }

  /** Tìm đường dẫn file trong uploads và các dạng tham chiếu ID ảnh trong 1 đoạn chữ. */
  private function scan_text($text, &$state, $with_ids) {
    if ($text === '' || $text === null) {
      return;
    }
    $text = str_replace(['\\/', '\\\\/', '%2F', '%2f'], '/', $text);
    if (preg_match_all('#(?:uploads/|\$upload\$/)([^"\'\s<>()\\\\?\#,;|]+?\.(?:' . self::IMG_EXT . '))#i', $text, $m)) {
      foreach ($m[1] as $path) {
        $state['keys'][$this->key_of(rawurldecode($path))] = 1;
      }
    }
    if (!$with_ids) {
      return;
    }
    $patterns = [
      '/wp-image-(\d+)/',
      '/attachment[_-]id[=:"\s]+(\d+)/i',
      '/"(?:id|image_id|imageId|mediaId|attachment|attachment_id|thumbnail_id)"\s*:\s*"?(\d+)(?=["\s,}\]])/',
      '/\bids\s*=\s*["\']?([\d,\s]+)/',
      '/"ids"\s*:\s*\[([\d,\s"]+)\]/',
    ];
    foreach ($patterns as $p) {
      if (preg_match_all($p, $text, $m)) {
        foreach ($m[1] as $hit) {
          foreach (preg_split('/[\s,"]+/', $hit, -1, PREG_SPLIT_NO_EMPTY) as $id) {
            $state['ids'][(int) $id] = 1;
          }
        }
      }
    }
  }

  /** "2024/05/anh-1024x683.webp" → "2024/05/anh": bỏ size, -scaled, -rotated, hậu tố chỉnh sửa, đuôi file. */
  private function key_of($path) {
    $path = strtolower(ltrim(wp_normalize_path($path), '/'));
    $path = preg_replace('#^sites/\d+/#', '', $path);
    $path = preg_replace('/(\.[a-z0-9]{2,5})+$/', '', $path);
    do {
      $before = $path;
      $path = preg_replace('/-(\d+x\d+|scaled|rotated|e\d{13})$/', '', $path);
    } while ($path !== $before);
    return $path;
  }

  private function theme_files() {
    $dirs = array_unique([get_stylesheet_directory(), get_template_directory()]);
    if (defined('WPMU_PLUGIN_DIR') && is_dir(WPMU_PLUGIN_DIR)) {
      $dirs[] = WPMU_PLUGIN_DIR;
    }
    $files = [];
    foreach ($dirs as $dir) {
      $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
      foreach ($it as $f) {
        if ($f->isFile() && $f->getSize() < 2 * MB_IN_BYTES && preg_match('/\.(php|css|scss|js|json|html?|txt|xml)$/i', $f->getFilename())
          && strpos(wp_normalize_path($f->getPathname()), '/node_modules/') === false) {
          $files[] = $f->getPathname();
        }
      }
    }
    return $files;
  }

  private function classify($state) {
    global $wpdb;
    $rows = $wpdb->get_results(
      "SELECT p.ID, p.post_date_gmt, m.meta_value AS file FROM {$wpdb->posts} p
       LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
       WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type LIKE 'image/%'", ARRAY_A);
    $cutoff = time() - $state['skip_days'] * DAY_IN_SECONDS;
    $protected = array_flip(array_filter([
      (int) get_option('site_icon'),
      (int) get_theme_mod('custom_logo'),
      (int) get_option('woocommerce_placeholder_image'),
    ]));
    $unused = [];
    foreach ($rows as $r) {
      $id = (int) $r['ID'];
      if (isset($state['ids'][$id]) || isset($protected[$id])) {
        continue;
      }
      if ($r['file'] === null || $r['file'] === '' || isset($state['keys'][$this->key_of($r['file'])])) {
        continue;
      }
      if ($state['skip_days'] && strtotime($r['post_date_gmt'] . ' UTC') > $cutoff) {
        continue;
      }
      $unused[] = $id;
    }
    $result = ['time' => time(), 'total' => count($rows), 'unused' => $unused];
    update_option(self::OPT_RESULT, $result, false);
    return $result;
  }

  private function present_result($result) {
    $items = [];
    $bytes = 0;
    foreach ($result['unused'] as $id) {
      $size = 0;
      foreach ($this->attachment_files($id) as $path) {
        $size += (int) @filesize($path);
      }
      $bytes += $size;
      $items[] = [
        'id' => $id,
        'title' => get_the_title($id),
        'file' => (string) get_post_meta($id, '_wp_attached_file', true),
        'thumb' => (string) wp_get_attachment_image_url($id, 'thumbnail'),
        'edit' => get_edit_post_link($id, 'raw'),
        'size' => size_format($size),
        'date' => get_the_date('d/m/Y', $id),
      ];
    }
    return ['total' => $result['total'], 'items' => $items, 'bytes' => size_format($bytes)];
  }

  /* ---------- File của 1 attachment ---------- */

  private function uploads_base() {
    return trailingslashit(wp_normalize_path(wp_get_upload_dir()['basedir']));
  }

  private function quarantine_base() {
    return $this->uploads_base() . self::DIR . '/';
  }

  private function quarantine_url($id, $rel) {
    return trailingslashit(wp_get_upload_dir()['baseurl']) . self::DIR . '/' . $id . '/' . str_replace('%2F', '/', rawurlencode($rel));
  }

  /** Mọi file trên đĩa của attachment: bản gốc, -scaled, các size, bản sửa, bản .webp đi kèm. */
  private function attachment_files($id) {
    $base = $this->uploads_base();
    $file = get_attached_file($id, true);
    if (!$file) {
      return [];
    }
    $file = wp_normalize_path($file);
    $dir = trailingslashit(dirname($file));
    $names = [basename($file)];
    $meta = wp_get_attachment_metadata($id);
    if (is_array($meta)) {
      if (!empty($meta['original_image'])) {
        $names[] = $meta['original_image'];
      }
      foreach ((array) ($meta['sizes'] ?? []) as $s) {
        if (!empty($s['file'])) {
          $names[] = $s['file'];
        }
        foreach ((array) ($s['sources'] ?? []) as $src) {
          if (!empty($src['file'])) {
            $names[] = $src['file'];
          }
        }
      }
      foreach ((array) ($meta['sources'] ?? []) as $src) {
        if (!empty($src['file'])) {
          $names[] = $src['file'];
        }
      }
    }
    foreach ((array) get_post_meta($id, '_wp_attachment_backup_sizes', true) as $s) {
      if (!empty($s['file'])) {
        $names[] = $s['file'];
      }
    }
    $paths = [];
    foreach (array_unique($names) as $name) {
      $p = $dir . basename($name);
      foreach ([$p, $p . '.webp', preg_replace('/\.[a-z0-9]+$/i', '.webp', $p), preg_replace('/\.[a-z0-9]+$/i', '.avif', $p)] as $cand) {
        if (strpos($cand, $base) === 0 && strpos($cand, '/' . self::DIR . '/') === false && is_file($cand)) {
          $paths[$cand] = 1;
        }
      }
    }
    return array_keys($paths);
  }

  /* ---------- Cách ly / khôi phục / xóa ---------- */

  private function quarantine($id) {
    $post = get_post($id);
    if (!$post || $post->post_type !== 'attachment' || $post->post_status === self::STATUS) {
      return new WP_Error('invalid', 'Không phải ảnh hoặc đã cách ly.');
    }
    $base = $this->uploads_base();
    $dest_root = $this->quarantine_base() . $id . '/';
    if (!wp_mkdir_p($dest_root)) {
      return new WP_Error('mkdir', 'Không tạo được thư mục cách ly.');
    }
    $this->protect_dir();
    $moved = [];
    $shared = [];
    foreach ($this->attachment_files($id) as $src) {
      $rel = substr($src, strlen($base));
      // File mà attachment khác cũng trỏ tới (ảnh dùng chung file): chuyển đi là gãy ảnh đó, nên để nguyên.
      if (wn_upload_file_owners($src, $id)) {
        $shared[] = $rel;
        continue;
      }
      $dest = $dest_root . $rel;
      wp_mkdir_p(dirname($dest));
      if (!@rename($src, $dest)) {
        foreach ($moved as $r) {
          @rename($dest_root . $r, $base . $r);
        }
        return new WP_Error('move', 'Không chuyển được file ' . $rel . '.');
      }
      $moved[] = $rel;
    }
    // File chính đứng đầu để hiển thị.
    $main = (string) get_post_meta($id, '_wp_attached_file', true);
    usort($moved, function ($a, $b) use ($main) {
      return ($b === $main) <=> ($a === $main);
    });
    update_post_meta($id, self::META, ['time' => time(), 'files' => $moved, 'shared' => $shared, 'status' => $post->post_status]);
    $this->set_status($id, self::STATUS);
    clean_post_cache($id);
    return true;
  }

  private function restore($id) {
    $q = get_post_meta($id, self::META, true);
    if ($this->raw_status($id) !== self::STATUS || !is_array($q)) {
      return new WP_Error('invalid', 'Ảnh không ở trạng thái cách ly.');
    }
    $base = $this->uploads_base();
    $root = $this->quarantine_base() . $id . '/';
    foreach ($q['files'] as $rel) {
      if (is_file($root . $rel) && file_exists($base . $rel)) {
        return new WP_Error('exists', 'Đã có file khác trùng tên ở ' . $rel . '.');
      }
    }
    foreach ($q['files'] as $rel) {
      if (is_file($root . $rel)) {
        wp_mkdir_p(dirname($base . $rel));
        if (!@rename($root . $rel, $base . $rel)) {
          return new WP_Error('move', 'Không chuyển lại được file ' . $rel . '.');
        }
      }
    }
    $this->rmdir_tree($root);
    $this->set_status($id, $q['status'] ?: 'inherit');
    delete_post_meta($id, self::META);
    clean_post_cache($id);
    return true;
  }

  private function delete_forever($id) {
    if ($this->raw_status($id) !== self::STATUS) {
      return new WP_Error('invalid', 'Chỉ xóa hẳn được ảnh đang cách ly.');
    }
    $this->rmdir_tree($this->quarantine_base() . $id . '/');
    // File gốc đã không còn ở uploads nên WP chỉ dọn database (meta, liên kết term...).
    // Riêng file dùng chung với attachment khác (để lại lúc cách ly) thì WP vẫn xóa theo metadata: chặn lại.
    $keep = function ($file) use ($id) {
      return $file && wn_upload_file_owners($file, $id) ? '' : $file;
    };
    add_filter('wp_delete_file', $keep);
    $ok = wp_delete_attachment($id, true);
    remove_filter('wp_delete_file', $keep);
    if (!$ok) {
      return new WP_Error('delete', 'WordPress không xóa được attachment.');
    }
    return true;
  }

  /** get_post_status() trả "publish" cho mọi attachment nên phải đọc cột post_status gốc. */
  private function raw_status($id) {
    $post = get_post($id);
    return $post ? $post->post_status : '';
  }

  /** wp_update_post() luôn ép attachment về "inherit" nên phải ghi thẳng vào bảng posts. */
  private function set_status($id, $status) {
    global $wpdb;
    $wpdb->update($wpdb->posts, ['post_status' => $status], ['ID' => $id]);
  }

  private function rmdir_tree($dir) {
    $dir = wp_normalize_path($dir);
    if (strpos($dir, $this->quarantine_base()) !== 0 || !is_dir($dir)) {
      return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
      $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
  }

  private function protect_dir() {
    $dir = $this->quarantine_base();
    if (!file_exists($dir . 'index.php')) {
      @file_put_contents($dir . 'index.php', "<?php // Silence is golden.\n");
    }
  }
}

add_action('init', ['WN_Media_Cleanup', 'register_status']);
if (wn_module_on('media_cleanup')) {
  new WN_Media_Cleanup();
}
