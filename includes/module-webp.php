<?php
/**
 * Tự đổi ảnh tải lên sang WebP + công cụ chuyển hàng loạt ảnh cũ (Media → WebP Batch Optimizer).
 * Gộp từ plugin WebNow WebP Batch Optimizer 3.2.2.
 */
if (!defined('ABSPATH')) {
  exit;
}

// Nếu plugin WebNow WebP Batch bản riêng vẫn đang bật thì để nó chạy, tránh khai báo trùng.
$wn_webp_standalone = class_exists('WebNow_WebP_Batch');
if (!$wn_webp_standalone) {

final class WebNow_WebP_Batch {
  const OPT_QUEUE = 'webnow_webp_queue_v32';
  const OPT_QUALITY = 'webnow_webp_quality';
  const OPT_SECRET = 'webnow_webp_cron_secret';
  const OPT_DELETE_ORIGINALS = 'webnow_webp_delete_originals';
  const BATCH = 5;

  /** Bản đang chạy, để Tối ưu web gọi lại mà không đăng ký hook lần hai. */
  public static $instance = null;

  public function __construct() {
    self::$instance = $this;
    add_action('admin_menu', [$this, 'menu']);
    add_action('admin_enqueue_scripts', [$this, 'assets']);
    add_action('wp_ajax_webnow_webp_stats', [$this, 'ajax_stats']);
    add_action('wp_ajax_webnow_webp_prepare', [$this, 'ajax_prepare']);
    add_action('wp_ajax_webnow_webp_process', [$this, 'ajax_process']);
    add_action('wp_ajax_webnow_webp_save', [$this, 'ajax_save']);
    add_action('wp_ajax_webnow_webp_repair_links', [$this, 'ajax_repair_links']);
    add_action('init', [$this, 'maybe_cron_run'], 5);
    add_filter('wp_generate_attachment_metadata', [$this, 'on_metadata'], 99, 2);
    add_filter('wp_unique_filename', [$this, 'unique_webp_name'], 10, 3);
    add_action('wn_webp_delete_originals', [$this, 'delete_attachment_originals']);
  }

  private function quality(): int {
    $q = (int) get_option(self::OPT_QUALITY, 82);
    return max(60, min(92, $q ?: 82));
  }

  public function delete_originals(): bool {
    // Mặc định GIỮ ảnh gốc; chỉ xóa khi bật rõ ràng trong cài đặt.
    return (string) get_option(self::OPT_DELETE_ORIGINALS, '0') === '1';
  }

  private function secret(): string {
    $s = (string) get_option(self::OPT_SECRET, '');
    if ($s === '') {
      $s = wp_generate_password(24, false, false);
      update_option(self::OPT_SECRET, $s, false);
    }
    return $s;
  }

  public function menu() {
    add_media_page(
      'WebP Batch Optimizer',
      'WebP Batch Optimizer',
      'manage_options',
      'webnow-webp-batch',
      [$this, 'page']
    );
  }

  public function assets($hook) {
    if ($hook !== 'media_page_webnow-webp-batch') {
      return;
    }
    wp_enqueue_script(
      'webnow-webp-batch',
      wn_plugin_url('/includes/webp-batch.js'),
      ['jquery'],
      '3.2.1',
      true
    );
    wp_localize_script('webnow-webp-batch', 'WEBNOW', [
      'ajax' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('webnow_webp'),
    ]);
  }

  public function page() {
    $stats = $this->collect_stats();
    ?>
    <div class="wrap">
      <h1>WebNow WebP Batch Optimizer <small style="font-size:13px;font-weight:400">v3.2.1</small></h1>
      <p><strong>Thay file thật</strong> (full + mọi size) → <code>.webp</code>, <strong>cập nhật DB/metadata trước</strong>, rồi mới xóa JPG/PNG.</p>
      <p class="description" style="color:#b32d2e"><strong>v3.2 quan trọng:</strong> không xóa file gốc cho đến khi đã ghi Media + REPLACE URL trong posts/postmeta xong — tránh ảnh 404.</p>

      <table class="widefat striped" style="max-width:780px;margin:16px 0">
        <tbody>
          <tr><th>Attachment cần xử lý (kể cả size JPG sót)</th><td id="wn-total"><?php echo (int) $stats['attachments']; ?></td></tr>
          <tr><th>Attachment MIME webp</th><td id="wn-ready"><?php echo (int) $stats['webp_attachments']; ?></td></tr>
          <tr><th>Tổng trong hàng đợi</th><td id="wn-missing"><strong><?php echo (int) $stats['pending']; ?></strong></td></tr>
          <tr><th>File JPG/PNG lẻ trong uploads</th><td id="wn-orphans"><?php echo (int) $stats['orphan_files']; ?></td></tr>
        </tbody>
      </table>

      <p>
        <label>Chất lượng (60–92):
          <input type="number" id="wn-quality" min="60" max="92" value="<?php echo (int) $this->quality(); ?>" style="width:70px">
        </label>
        &nbsp;
        <label>
          <input type="checkbox" id="wn-delete" <?php checked($this->delete_originals()); ?>>
          Xóa JPG/PNG sau khi đã có WebP
        </label>
        <button type="button" class="button" id="wn-save">Lưu</button>
      </p>

      <p>
        <button id="webnow-start" class="button button-primary">Convert full + mọi size → WebP</button>
        <button id="webnow-repair" class="button">Sửa link JPG đã xóa (DB → WebP)</button>
        <button id="webnow-refresh" class="button">Làm mới thống kê</button>
      </p>
      <p id="webnow-status"></p>
      <progress id="webnow-progress" value="0" max="100" style="width:100%;max-width:780px;height:18px"></progress>
      <p class="description">Sau khi xong: purge LiteSpeed/CF cache, hard refresh trang sản phẩm (Ctrl+F5).</p>
    </div>
    <?php
  }

  /** Attachment còn JPG/PNG ở full hoặc bất kỳ size nào trong metadata. */
  public function attachment_needs_convert($attachment_id) {
    $file = get_attached_file($attachment_id);
    if ($file && preg_match('/\.(jpe?g|png)$/i', $file) && is_file($file)) {
      return true;
    }
    $meta = wp_get_attachment_metadata($attachment_id);
    if (!is_array($meta)) {
      return false;
    }
    if (!empty($meta['file']) && preg_match('/\.(jpe?g|png)$/i', $meta['file'])) {
      return true;
    }
    $dir = $file ? trailingslashit(dirname($file)) : '';
    if (!$dir && !empty($meta['file'])) {
      $dir = trailingslashit(dirname(path_join(wp_get_upload_dir()['basedir'], $meta['file'])));
    }
    // Ảnh gốc trước khi WP thu nhỏ thành -scaled: trước đây bị quét lại như file lẻ.
    if ($dir && !empty($meta['original_image']) && preg_match('/\.(jpe?g|png)$/i', $meta['original_image'])
      && is_file($dir . wp_basename($meta['original_image']))) {
      return true;
    }
    if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
      foreach ($meta['sizes'] as $size) {
        if (empty($size['file'])) {
          continue;
        }
        if (preg_match('/\.(jpe?g|png)$/i', $size['file'])) {
          // Still listed as jpg in meta — needs fix even if file already gone.
          return true;
        }
        if ($dir) {
          $path = $dir . $size['file'];
          if (preg_match('/\.(jpe?g|png)$/i', $path) && is_file($path)) {
            return true;
          }
        }
      }
    }
    return false;
  }

  /** @return array<string,int|array> */
  public function collect_stats($with_queue = false) {
    $all = get_posts([
      'post_type' => 'attachment',
      'post_mime_type' => 'image',
      'posts_per_page' => -1,
      'fields' => 'ids',
      'no_found_rows' => true,
    ]);

    $webp_ids = get_posts([
      'post_type' => 'attachment',
      'post_mime_type' => ['image/webp'],
      'posts_per_page' => -1,
      'fields' => 'ids',
      'no_found_rows' => true,
    ]);

    // Nạp meta một lần, không thì mỗi attachment tốn 2 truy vấn.
    update_meta_cache('post', $all);
    $pending_ids = [];
    foreach ($all as $id) {
      if ($this->attachment_needs_convert((int) $id)) {
        $pending_ids[] = (int) $id;
      }
    }

    $orphan_files = $this->find_orphan_raster_files();

    $out = [
      'attachments' => count($pending_ids),
      'webp_attachments' => count($webp_ids),
      'pending' => count($pending_ids),
      'orphan_files' => count($orphan_files),
    ];
    if ($with_queue) {
      $queue = [];
      foreach ($pending_ids as $id) {
        $queue[] = ['type' => 'attachment', 'id' => (int) $id];
      }
      foreach ($orphan_files as $path) {
        $queue[] = ['type' => 'file', 'path' => $path];
      }
      $out['queue'] = $queue;
      $out['pending'] = count($queue);
    }
    return $out;
  }

  /**
   * Mọi file (đường dẫn tương đối trong uploads) đang thuộc 1 attachment: file chính, ảnh gốc trước khi
   * scale, các size, bản sửa. Đọc theo từng đợt để site nhiều ảnh không tốn RAM.
   * @return array<string,int>
   */
  private function attachment_file_set() {
    global $wpdb;
    $set = [];
    $last = 0;
    do {
      $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT f.post_id, f.meta_value AS file, m.meta_value AS meta, b.meta_value AS backup
         FROM {$wpdb->postmeta} f
         LEFT JOIN {$wpdb->postmeta} m ON m.post_id = f.post_id AND m.meta_key = '_wp_attachment_metadata'
         LEFT JOIN {$wpdb->postmeta} b ON b.post_id = f.post_id AND b.meta_key = '_wp_attachment_backup_sizes'
         WHERE f.meta_key = '_wp_attached_file' AND f.post_id > %d
         ORDER BY f.post_id LIMIT 1000",
        $last
      ));
      foreach ($rows as $r) {
        $last = (int) $r->post_id;
        $file = ltrim(str_replace('\\', '/', (string) $r->file), '/');
        if ($file === '') {
          continue;
        }
        $set[$file] = 1;
        $prefix = dirname($file) === '.' ? '' : dirname($file) . '/';
        $names = [];
        $meta = maybe_unserialize($r->meta);
        if (is_array($meta)) {
          $names[] = $meta['original_image'] ?? '';
          foreach ((array) ($meta['sizes'] ?? []) as $s) {
            $names[] = is_array($s) ? ($s['file'] ?? '') : '';
          }
        }
        $backup = maybe_unserialize($r->backup);
        foreach (is_array($backup) ? $backup : [] as $s) {
          $names[] = is_array($s) ? ($s['file'] ?? '') : '';
        }
        foreach ($names as $n) {
          if (is_string($n) && $n !== '') {
            $set[$prefix . wp_basename($n)] = 1;
          }
        }
      }
    } while (count($rows) === 1000);
    return $set;
  }

  /** File JPG/PNG trong uploads KHÔNG thuộc attachment nào (file thuộc attachment đã nằm trong hàng đợi attachment). */
  private function find_orphan_raster_files() {
    $basedir = trailingslashit(wp_get_upload_dir()['basedir']);
    $files = [];
    if (!is_dir($basedir)) {
      return $files;
    }
    $owned = $this->attachment_file_set();
    $it = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($basedir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
      if (!$file->isFile()) {
        continue;
      }
      $path = wp_normalize_path($file->getPathname());
      // Ảnh cách ly (Dọn ảnh), bản sao lưu của Tối ưu web, file tải về của WooCommerce: không đụng vào.
      if (preg_match('#/(wn-quarantine|wn-optimize|wc-logs|woocommerce_uploads|cache)/#', '/' . substr($path, strlen($basedir)))) {
        continue;
      }
      if (!preg_match('/\.(jpe?g|png)$/i', $path)) {
        continue;
      }
      // Link viết cứng trong code theme: đổi/xóa là gãy ảnh, để Tối ưu web liệt kê cho sửa tay.
      if (wn_is_hardcoded_upload($path)) {
        continue;
      }
      if (isset($owned[substr($path, strlen(wp_normalize_path($basedir)))])) {
        continue;
      }
      // Giữ ảnh gốc mà đã có bản WebP thì không cần làm lại (bỏ qua không làm hỏng gì).
      // Bật xóa ảnh gốc thì vẫn đưa vào hàng đợi: replace_file_with_webp() chỉ dùng lại .webp có sẵn
      // khi chắc là của file này, rồi mới sửa link và xóa.
      $webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $path);
      if (!$this->delete_originals() && ($this->fresh_sidecar($path) || (is_file($webp) && filesize($webp) > 0))) {
        continue;
      }
      $files[] = $path;
    }
    return $files;
  }

  public function ajax_stats() {
    $this->guard_ajax();
    wp_send_json_success($this->collect_stats());
  }

  public function ajax_save() {
    $this->guard_ajax();
    $q = isset($_POST['quality']) ? (int) $_POST['quality'] : 82;
    update_option(self::OPT_QUALITY, max(60, min(92, $q)), false);
    update_option(self::OPT_DELETE_ORIGINALS, !empty($_POST['delete_originals']) ? '1' : '0', false);
    wp_send_json_success(['ok' => 1]);
  }

  public function ajax_prepare() {
    $this->guard_ajax();
    $stats = $this->collect_stats(true);
    update_option(self::OPT_QUEUE, $stats['queue'], false);
    wp_send_json_success([
      'total' => (int) $stats['pending'],
      'attachments' => (int) $stats['attachments'],
      'orphans' => (int) $stats['orphan_files'],
    ]);
  }

  public function ajax_process() {
    $this->guard_ajax();
    wp_send_json($this->process_queue(self::BATCH));
  }

  /**
   * Repair: JPG/PNG URL trong DB mà file đã mất, nhưng có .webp (hoặc sidecar) trên disk.
   */
  public function ajax_repair_links() {
    $this->guard_ajax();
    $fixed = $this->repair_broken_raster_links();
    wp_send_json_success(['fixed' => $fixed, 'stats' => $this->collect_stats(false)]);
  }

  public function repair_broken_raster_links() {
    global $wpdb;
    $basedir = trailingslashit(wp_get_upload_dir()['basedir']);
    $baseurl = trailingslashit(wp_get_upload_dir()['baseurl']);
    $fixed = 0;

    // First: materialize any missing real .webp from sidecars (fixes 404 when DB already points to .webp).
    $fixed += $this->materialize_all_sidecars();

    // Scan post_content for uploads/*.jpg|png
    $posts = $wpdb->get_results(
      "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content LIKE '%/wp-content/uploads/%' AND (post_content LIKE '%.jpg%' OR post_content LIKE '%.jpeg%' OR post_content LIKE '%.png%')"
    );
    foreach ($posts as $post) {
      $content = $post->post_content;
      $new = preg_replace_callback(
        '#((?:https?://[^/]+)?/wp-content/uploads/[^\"\'\s>]+)\.(jpe?g|png)(?!\.webp)#i',
        function ($m) use ($basedir, $baseurl, &$fixed) {
          $rel = $m[1];
          // strip domain to relative uploads path
          $pos = stripos($rel, '/wp-content/uploads/');
          if ($pos === false) {
            return $m[0];
          }
          $rel_path = substr($rel, $pos + strlen('/wp-content/uploads/')) . '.' . $m[2];
          $abs = $basedir . ltrim(str_replace('\\', '/', $rel_path), '/');
          $webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $abs);
          $sidecar = $abs . '.webp';

          if (!is_file($abs)) {
            if (!is_file($webp) && is_file($sidecar)) {
              @copy($sidecar, $webp);
            }
            if (is_file($webp)) {
              $fixed++;
              return preg_replace('/\.(jpe?g|png)$/i', '.webp', $m[0]);
            }
          } elseif (is_file($webp) || is_file($sidecar)) {
            // File jpg still exists OR webp exists — still normalize URL to .webp if webp ready
            if (!is_file($webp) && is_file($sidecar)) {
              @copy($sidecar, $webp);
            }
            if (is_file($webp)) {
              $fixed++;
              return preg_replace('/\.(jpe?g|png)$/i', '.webp', $m[0]);
            }
          }
          return $m[0];
        },
        $content
      );
      // Also normalize leftover .jpg.webp → .webp when real webp exists
      $new = preg_replace_callback(
        '#((?:https?://[^/]+)?/wp-content/uploads/[^\"\'\s>]+)\.(jpe?g|png)\.webp#i',
        function ($m) use ($basedir, &$fixed) {
          $pos = stripos($m[1], '/wp-content/uploads/');
          if ($pos === false) {
            return $m[0];
          }
          $rel = substr($m[1], $pos + strlen('/wp-content/uploads/')) . '.' . $m[2];
          $abs_jpg = $basedir . ltrim($rel, '/');
          $webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $abs_jpg);
          $sidecar = $abs_jpg . '.webp';
          if (!is_file($webp) && is_file($sidecar)) {
            @copy($sidecar, $webp);
          }
          if (is_file($webp)) {
            $fixed++;
            return preg_replace('/\.(jpe?g|png)\.webp$/i', '.webp', $m[0]);
          }
          return $m[0];
        },
        $new
      );
      if ($new !== $content) {
        $wpdb->update($wpdb->posts, ['post_content' => $new], ['ID' => $post->ID]);
      }
    }

    // Link nằm ngoài post_content: meta (Elementor, ACF), options, bảng Smart Slider...
    $fixed += $this->repair_refs_outside_content();

    // Fix attachment metadata still pointing to missing jpg when webp exists
    $ids = get_posts([
      'post_type' => 'attachment',
      'post_mime_type' => 'image',
      'posts_per_page' => -1,
      'fields' => 'ids',
      'no_found_rows' => true,
    ]);
    foreach ($ids as $id) {
      if ($this->attachment_needs_convert((int) $id)) {
        $r = $this->convert_attachment((int) $id);
        if ($r === true) {
          $fixed++;
        }
      }
    }

    return $fixed;
  }

  /** Bảng của plugin lưu link ảnh riêng (Smart Slider 3...). Thêm bảng khác qua filter. */
  private function extra_ref_tables() {
    global $wpdb;
    $tables = (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'nextend2_smartslider3_') . '%'));
    return apply_filters('webnow_webp_ref_tables', $tables);
  }

  /**
   * Sửa link JPG/PNG đã mất (có .webp thay thế) trong 1 chuỗi. Bắt cả dạng JSON "\/" (Elementor, Smart Slider)
   * và tiền tố "$upload$/" của Smart Slider.
   */
  private function fix_raster_refs($text, &$fixed) {
    if (!is_string($text) || $text === '' || !preg_match('/\.(jpe?g|png)/i', $text)) {
      return $text;
    }
    $basedir = trailingslashit(wp_get_upload_dir()['basedir']);
    return preg_replace_callback(
      '#((?:wp-content\\\\?/uploads|\$upload\$)\\\\?/)([^"\'\s<>()]+?)\.(jpe?g|png)(\.webp)?(?![a-z0-9])#i',
      function ($m) use ($basedir, &$fixed) {
        $rel = ltrim(str_replace('\/', '/', $m[2]), '/');
        $abs = $basedir . $rel . '.' . $m[3];
        $webp = $basedir . $rel . '.webp';
        if (!is_file($webp) && is_file($abs . '.webp')) {
          @copy($abs . '.webp', $webp);
        }
        // Chỉ đổi khi file gốc đã mất (hoặc link đang là .jpg.webp) và .webp có thật.
        if ((!is_file($abs) || !empty($m[4])) && is_file($webp)) {
          $fixed++;
          return $m[1] . $m[2] . '.webp';
        }
        return $m[0];
      },
      $text
    );
  }

  /** Như fix_raster_refs nhưng giữ đúng dữ liệu serialize (độ dài chuỗi thay đổi). */
  private function fix_raster_refs_value($value, &$fixed) {
    if (is_serialized($value)) {
      $data = @unserialize($value, ['allowed_classes' => false]);
      if ($data !== false && (is_array($data) || is_string($data))) {
        $before = $fixed;
        $data = $this->map_strings($data, function ($s) use (&$fixed) {
          return $this->fix_raster_refs($s, $fixed);
        });
        return $fixed > $before ? serialize($data) : $value;
      }
      return $value;
    }
    return $this->fix_raster_refs($value, $fixed);
  }

  private function map_strings($data, $fn) {
    if (is_array($data)) {
      foreach ($data as $k => $v) {
        $data[$k] = $this->map_strings($v, $fn);
      }
      return $data;
    }
    return is_string($data) ? $fn($data) : $data;
  }

  private function repair_refs_outside_content() {
    global $wpdb;
    $fixed = 0;
    $like = "(%s LIKE '%%.jp%%g%%' OR %s LIKE '%%.png%%')";

    $rows = $wpdb->get_results(
      "SELECT meta_id, meta_value FROM {$wpdb->postmeta}
       WHERE meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes')
         AND " . sprintf($like, 'meta_value', 'meta_value')
    );
    foreach ($rows as $r) {
      $new = $this->fix_raster_refs_value($r->meta_value, $fixed);
      if ($new !== $r->meta_value) {
        $wpdb->update($wpdb->postmeta, ['meta_value' => $new], ['meta_id' => $r->meta_id]);
      }
    }

    $rows = $wpdb->get_results(
      "SELECT option_id, option_value FROM {$wpdb->options}
       WHERE option_name NOT LIKE '\_%%transient\_%%' AND option_name <> '" . esc_sql(self::OPT_QUEUE) . "'
         AND " . sprintf($like, 'option_value', 'option_value')
    );
    foreach ($rows as $r) {
      $new = $this->fix_raster_refs_value($r->option_value, $fixed);
      if ($new !== $r->option_value) {
        $wpdb->update($wpdb->options, ['option_value' => $new], ['option_id' => $r->option_id]);
      }
    }

    foreach ($this->extra_ref_tables() as $table) {
      $pk = '';
      $cols = [];
      foreach ((array) $wpdb->get_results('SHOW COLUMNS FROM `' . esc_sql($table) . '`', ARRAY_A) as $col) {
        if ($col['Key'] === 'PRI' && !$pk) {
          $pk = $col['Field'];
        } elseif (preg_match('/char|text/i', $col['Type'])) {
          $cols[] = $col['Field'];
        }
      }
      if (!$pk || !$cols) {
        continue;
      }
      foreach ($cols as $col) {
        $c = '`' . esc_sql($col) . '`';
        $rows = $wpdb->get_results('SELECT `' . esc_sql($pk) . '` AS k, ' . $c . ' AS v FROM `' . esc_sql($table) . '` WHERE ' . sprintf($like, $c, $c));
        foreach ($rows as $r) {
          $new = $this->fix_raster_refs_value($r->v, $fixed);
          if ($new !== $r->v) {
            $wpdb->update($table, [$col => $new], [$pk => $r->k]);
          }
        }
      }
    }
    if ($fixed) {
      // Đã ghi thẳng DB nên cache option/meta cũ phải bỏ.
      wp_cache_flush();
    }
    return $fixed;
  }

  private function guard_ajax() {
    check_ajax_referer('webnow_webp', 'nonce');
    if (!current_user_can('manage_options')) {
      wp_send_json_error('forbidden', 403);
    }
  }

  public function maybe_cron_run() {
    if (empty($_GET['webnow_webp_cron'])) {
      return;
    }
    if (!hash_equals($this->secret(), (string) $_GET['webnow_webp_cron'])) {
      status_header(403);
      wp_die('Forbidden', 403);
    }
    $n = isset($_GET['n']) ? max(1, min(20, (int) $_GET['n'])) : 5;
    $queue = get_option(self::OPT_QUEUE, []);
    if (!is_array($queue) || !$queue) {
      $stats = $this->collect_stats(true);
      update_option(self::OPT_QUEUE, $stats['queue'], false);
    }
    nocache_headers();
    wp_send_json($this->process_queue($n));
  }

  public function process_queue($limit) {
    $queue = get_option(self::OPT_QUEUE, []);
    if (!is_array($queue)) {
      $queue = [];
    }
    $batch = array_splice($queue, 0, (int) $limit);
    update_option(self::OPT_QUEUE, $queue, false);

    $ok = 0;
    $fail = 0;
    $skipped = 0;
    foreach ($batch as $item) {
      if (!is_array($item) || empty($item['type'])) {
        $fail++;
        continue;
      }
      if ($item['type'] === 'attachment') {
        $r = $this->convert_attachment((int) $item['id']);
      } else {
        $r = $this->replace_file_with_webp((string) ($item['path'] ?? ''), true);
      }
      if ($r === true) {
        $ok++;
      } elseif ($r === null) {
        $skipped++;
      } else {
        $fail++;
      }
    }

    // collect_stats() duyệt cả thư mục uploads và mọi attachment: gọi sau mỗi lượt 5 ảnh thì site ~1.300 ảnh
    // chạy hàng giờ. Giữa chừng chỉ trả số còn lại, thống kê đầy đủ khi xong.
    return [
      'done' => empty($queue),
      'left' => count($queue),
      'ok' => $ok,
      'fail' => $fail,
      'skipped' => $skipped,
      'stats' => empty($queue) ? $this->collect_stats(false) : ['pending' => count($queue)],
    ];
  }

  /**
   * Convert full + ALL registered sizes. Works even if full is already .webp
   * but thumbnails still .jpg in metadata.
   * Order: create WebP → update Media/DB → only then delete JPG/PNG.
   */
  public function convert_attachment($attachment_id) {
    $attachment_id = (int) $attachment_id;
    if ($attachment_id < 1) {
      return false;
    }

    $file = get_attached_file($attachment_id);
    $meta = wp_get_attachment_metadata($attachment_id);
    if (!is_array($meta)) {
      $meta = [];
    }

    if (!$file && !empty($meta['file'])) {
      $file = path_join(wp_get_upload_dir()['basedir'], $meta['file']);
    }
    if (!$file) {
      return false;
    }
    $file = wp_normalize_path($file);
    $dir = trailingslashit(dirname($file));

    $replacements = [];
    $pending_delete = []; // ảnh gốc => file .webp thay nó; chỉ xóa SAU khi ghi DB
    // Khóa thay URL theo đường dẫn tương đối (2023/12/a.jpg) để không đụng file trùng tên ở thư mục khác
    // hay tên là chuỗi con của tên khác (24.jpg ⊂ x-1536x1024.jpg).
    $rel = function ($abs) {
      return $this->relative_upload_path($abs);
    };
    $changed = false;

    // File chính đã là .webp mà metadata còn ghi .jpg: chỉ sửa metadata.
    if (preg_match('/\.webp$/i', $file) && !empty($meta['file']) && preg_match('/\.(jpe?g|png)$/i', $meta['file'])) {
      $old_abs = wp_normalize_path(path_join(wp_get_upload_dir()['basedir'], $meta['file']));
      $replacements[$meta['file']] = $rel($file);
      $meta['file'] = $this->relative_upload_path($file);
      $pending_delete[$old_abs] = $file;
      $changed = true;
    }

    // Gom mọi file JPG/PNG của attachment rồi chọn tên .webp một lượt, để các size dùng chung một hậu tố.
    $sources = [];
    if (preg_match('/\.(jpe?g|png)$/i', $file)) {
      $sources[] = $file;
    }
    if (!empty($meta['original_image']) && preg_match('/\.(jpe?g|png)$/i', $meta['original_image'])) {
      $sources[] = $dir . wp_basename($meta['original_image']);
    }
    foreach ((array) ($meta['sizes'] ?? []) as $size) {
      if (!empty($size['file']) && preg_match('/\.(jpe?g|png)$/i', $size['file'])) {
        $sources[] = $dir . wp_basename($size['file']);
      }
    }
    $plan = $this->plan_webp_targets($sources, $attachment_id);
    $made = [];
    $make = function ($src) use ($plan, &$made) {
      if (!isset($made[$src])) {
        $made[$src] = isset($plan[$src]) ? $this->materialize_webp($src, $plan[$src]) : false;
      }
      return $made[$src];
    };

    // --- Full size ---
    if (preg_match('/\.(jpe?g|png)$/i', $file)) {
      $new_full = $make($file);
      if (is_string($new_full)) {
        $replacements[$rel($file)] = $rel($new_full);
        $meta['file'] = $this->relative_upload_path($new_full);
        $info = @getimagesize($new_full);
        if ($info) {
          $meta['width'] = $info[0];
          $meta['height'] = $info[1];
        }
        update_attached_file($attachment_id, $new_full);
        $pending_delete[$file] = $new_full;
        $file = $new_full;
        $changed = true;
      } elseif (is_file($file)) {
        return false;
      }
    }

    // --- Ảnh gốc trước khi WP thu nhỏ thành -scaled ---
    if (!empty($meta['original_image']) && preg_match('/\.(jpe?g|png)$/i', $meta['original_image'])) {
      $orig = $dir . wp_basename($meta['original_image']);
      $new_orig = $make($orig);
      if (is_string($new_orig)) {
        $replacements[$rel($orig)] = $rel($new_orig);
        $meta['original_image'] = wp_basename($new_orig);
        $pending_delete[$orig] = $new_orig;
        $changed = true;
      }
    }

    // --- All sizes ---
    if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
      foreach ($meta['sizes'] as $size_name => $size) {
        if (empty($size['file'])) {
          continue;
        }
        if (preg_match('/\.webp$/i', $size['file'])) {
          if (empty($size['mime-type']) || $size['mime-type'] !== 'image/webp') {
            $meta['sizes'][$size_name]['mime-type'] = 'image/webp';
            $changed = true;
          }
          continue;
        }
        if (!preg_match('/\.(jpe?g|png)$/i', $size['file'])) {
          continue;
        }
        $size_path = $dir . wp_basename($size['file']);
        $new_size = $make($size_path);
        if (!is_string($new_size)) {
          continue;
        }
        $replacements[$rel($size_path)] = $rel($new_size);
        $meta['sizes'][$size_name]['file'] = wp_basename($new_size);
        $meta['sizes'][$size_name]['mime-type'] = 'image/webp';
        $info = @getimagesize($new_size);
        if ($info) {
          $meta['sizes'][$size_name]['width'] = $info[0];
          $meta['sizes'][$size_name]['height'] = $info[1];
        }
        $pending_delete[$size_path] = $new_size;
        $changed = true;
      }
    }

    if (!$changed && !$replacements) {
      if (get_post_mime_type($attachment_id) !== 'image/webp' && $file && preg_match('/\.webp$/i', $file)) {
        wp_update_post(['ID' => $attachment_id, 'post_mime_type' => 'image/webp']);
        return true;
      }
      return null;
    }

    // 1) DB / Media FIRST
    wp_update_post([
      'ID' => $attachment_id,
      'post_mime_type' => 'image/webp',
    ]);
    wp_update_attachment_metadata($attachment_id, $meta);

    // Only rewrite URLs when every new basename actually exists on disk.
    $safe_replacements = [];
    foreach ($replacements as $old => $new) {
      if ($old === $new) {
        continue;
      }
      $candidate = trailingslashit(wp_get_upload_dir()['basedir']) . $new;
      if (!is_file($candidate) || filesize($candidate) < 1) {
        continue;
      }
      $safe_replacements[$old] = $new;
    }
    $replacements = $safe_replacements;

    foreach ($replacements as $old => $new) {
      // Dạng .jpg.webp trước: thay "a.jpg" trước sẽ biến "a.jpg.webp" thành "a.webp.webp".
      if (preg_match('/\.(jpe?g|png)$/i', $old)) {
        $this->replace_url_fragment($old . '.webp', $new);
      }
      $this->replace_url_fragment($old, $new);
    }

    // 2) Delete originals ONLY after DB updated AND target webp exists
    if ($this->delete_originals()) {
      foreach ($pending_delete as $raster => $webp) {
        if (!is_file($webp) || filesize($webp) < 1 || wn_is_hardcoded_upload($raster)) {
          continue;
        }
        // Attachment khác còn trỏ vào ảnh gốc này (ảnh dùng chung file) thì để lại.
        if (wn_upload_file_owners($raster, $attachment_id)) {
          continue;
        }
        foreach ([$raster, $raster . '.webp'] as $del) {
          if (is_file($del) && $del !== $webp) {
            @unlink($del);
          }
        }
      }
    }

    return true;
  }

  /**
   * Copy foo.jpg.webp → foo.webp whenever the real .webp is missing.
   */
  public function materialize_all_sidecars(): int {
    $basedir = trailingslashit(wp_get_upload_dir()['basedir']);
    if (!is_dir($basedir)) {
      return 0;
    }
    $copied = 0;
    $it = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($basedir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
      if (!$file->isFile()) {
        continue;
      }
      $path = wp_normalize_path($file->getPathname());
      if (!preg_match('/\.(jpe?g|png)\.webp$/i', $path)) {
        continue;
      }
      $target = preg_replace('/\.(jpe?g|png)\.webp$/i', '.webp', $path);
      if (is_file($target) && filesize($target) > 0) {
        continue;
      }
      if (@copy($path, $target) && is_file($target) && filesize($target) > 0) {
        $copied++;
      }
    }
    return $copied;
  }

  private function relative_upload_path($absolute) {
    $basedir = wp_normalize_path(trailingslashit(wp_get_upload_dir()['basedir']));
    $absolute = wp_normalize_path($absolute);
    if (strpos($absolute, $basedir) === 0) {
      return ltrim(substr($absolute, strlen($basedir)), '/');
    }
    return ltrim(str_replace('\\', '/', $absolute), '/');
  }

  /** Bản .jpg.webp đi kèm còn mới hơn ảnh gốc (hoặc ảnh gốc đã mất): là WebP của đúng file này. */
  private function fresh_sidecar($path) {
    $sidecar = $path . '.webp';
    if (!is_file($sidecar) || filesize($sidecar) < 1) {
      return false;
    }
    return !is_file($path) || filemtime($sidecar) >= filemtime($path);
  }

  /** File .webp có sẵn là bản đổi từ chính $path: giống hệt bản .jpg.webp đi kèm. */
  private function is_own_webp($path, $target) {
    $sidecar = $path . '.webp';
    return is_file($target) && $this->fresh_sidecar($path)
      && filesize($target) === filesize($sidecar) && md5_file($target) === md5_file($sidecar);
  }

  /** a.jpg → a.webp; có hậu tố thì chèn trước phần WP thêm vào: a-scaled.jpg → a-1-scaled.webp, a-150x150.jpg → a-1-150x150.webp. */
  private function webp_name($src, $suffix) {
    $name = pathinfo($src, PATHINFO_FILENAME);
    if ($suffix !== '') {
      $name = preg_replace('/^(.+?)((?:-scaled|-rotated)?(?:-\d+x\d+)?)$/', '${1}' . $suffix . '${2}', $name);
    }
    return trailingslashit(dirname($src)) . $name . '.webp';
  }

  /** $target dùng được cho $src: là bản WebP của chính nó, hoặc chưa có file và không attachment nào khác trỏ tới. */
  private function webp_target_free($src, $target, $attachment_id) {
    if ($this->is_own_webp($src, $target)) {
      return true;
    }
    if (file_exists($target) || file_exists($target . '.tmp')) {
      return false;
    }
    return !wn_upload_file_owners($target, $attachment_id);
  }

  /**
   * Chọn tên .webp cho các file JPG/PNG của 1 attachment ($attachment_id = 0: file lẻ).
   *
   * WP chỉ chống trùng tên theo từng đuôi, nên 1.jpg, 1.png và 1.webp có thể là 3 ảnh khác nhau nằm cạnh nhau.
   * Chỉ đổi đuôi 1.jpg → 1.webp thì có thể trỏ vào file của ảnh khác (rồi xóa mất ảnh gốc). Vì vậy chỉ dùng
   * lại file .webp có sẵn khi chắc chắn là bản đổi từ chính file đó (giống hệt bản .jpg.webp đi kèm); còn lại
   * thử hậu tố -1, -2... chung cho mọi file của attachment để tên các size vẫn đồng bộ (1-1.webp, 1-1-150x150.webp).
   * Ảnh gốc đã mất và không có bản đi kèm: chỉ nhận file .webp cùng tên khi không attachment nào khác dùng nó.
   *
   * @param string[] $sources đường dẫn tuyệt đối
   * @return array<string,string> ảnh gốc => file .webp (ảnh không đổi được thì không có trong mảng)
   */
  public function plan_webp_targets(array $sources, $attachment_id = 0) {
    $make = [];
    $lost = [];
    foreach (array_unique(array_map('wp_normalize_path', $sources)) as $src) {
      if (!preg_match('/\.(jpe?g|png)$/i', $src)) {
        continue;
      }
      if (is_file($src) || $this->fresh_sidecar($src)) {
        $make[] = $src;
      } else {
        $lost[] = $src;
      }
    }
    $plan = [];
    for ($i = 0; $make && !$plan && $i < 100; $i++) {
      $suffix = $i ? '-' . $i : '';
      $try = [];
      foreach ($make as $src) {
        $target = $this->webp_name($src, $suffix);
        if (in_array($target, $try, true) || !$this->webp_target_free($src, $target, $attachment_id)) {
          $try = [];
          break;
        }
        $try[$src] = $target;
      }
      $plan = $try;
    }
    foreach ($lost as $src) {
      $target = $this->webp_name($src, '');
      if (is_file($target) && filesize($target) > 0 && !in_array($target, $plan, true) && !wn_upload_file_owners($target, $attachment_id)) {
        $plan[$src] = $target;
      }
    }
    return $plan;
  }

  /**
   * Tạo file .webp cho $path tại $target (tên do plan_webp_targets() chọn; bỏ trống thì tự chọn như file lẻ).
   * Không bao giờ ghi đè hay dùng lại file .webp của ảnh khác. NEVER deletes originals (caller deletes after DB update).
   * Ảnh được mã hóa vào bản đi kèm a.jpg.webp rồi chép sang $target: bản đi kèm là bằng chứng file .webp là
   * của đúng ảnh này cho lần chạy sau, và bị xóa cùng ảnh gốc.
   * @return string|null|false new path | skip | fail
   */
  public function materialize_webp($path, $target = '') {
    $path = wp_normalize_path((string) $path);
    if ($path === '') {
      return false;
    }
    if (preg_match('/\.webp$/i', $path)) {
      return is_file($path) ? null : false;
    }
    if (!preg_match('/\.(jpe?g|png)$/i', $path)) {
      return null;
    }
    if ($target === '') {
      $target = $this->plan_webp_targets([$path])[$path] ?? '';
      if ($target === '') {
        return false;
      }
    }
    $target = wp_normalize_path($target);
    if ($this->is_own_webp($path, $target)) {
      return $target;
    }
    if (!is_file($path) && !$this->fresh_sidecar($path)) {
      // Ảnh gốc đã mất: chỉ nhận file .webp mà plan_webp_targets() đã xác nhận không ai khác dùng.
      return is_file($target) && filesize($target) > 0 ? $target : false;
    }
    if (file_exists($target)) {
      return false;
    }
    $sidecar = $path . '.webp';
    if (!$this->fresh_sidecar($path) && !$this->encode_webp($path, $sidecar)) {
      return false;
    }
    if (!@copy($sidecar, $target)) {
      @unlink($target);
      return false;
    }
    clearstatcache(true, $target);
    if (!is_file($target) || filesize($target) < 1) {
      @unlink($target);
      return false;
    }
    return $target;
  }

  /**
   * File JPG/PNG lẻ (hoặc ảnh gốc vừa tải lên): tạo .webp, thay link trong DB, rồi mới xóa ảnh gốc.
   * $target: tên .webp đã chọn sẵn (on_metadata); bỏ trống thì tự chọn.
   */
  public function replace_file_with_webp($path, $replace_refs = true, $target = '') {
    $path = wp_normalize_path((string) $path);
    $old_base = wp_basename($path);

    $new = $this->materialize_webp($path, $target);
    if ($new === false) {
      return false;
    }
    if ($new === null) {
      $new = $path; // already webp path passed in
    }

    $old_rel = $this->relative_upload_path($path);
    $new_rel = $this->relative_upload_path($new);

    // 1) DB first — theo đường dẫn tương đối, không chỉ tên file; dạng .jpg.webp trước.
    if ($replace_refs && preg_match('/\.(jpe?g|png)$/i', $old_base)) {
      $this->replace_url_fragment($old_rel . '.webp', $new_rel);
      $this->replace_url_fragment($old_rel, $new_rel);
    }

    // 2) Then delete originals — trừ khi attachment nào đó còn trỏ vào file này.
    if ($this->delete_originals() && !wn_is_hardcoded_upload($path) && $new !== $path && is_file($new) && !wn_upload_file_owners($path)) {
      if (is_file($path) && preg_match('/\.(jpe?g|png)$/i', $path)) {
        @unlink($path);
      }
      if (is_file($path . '.webp')) {
        @unlink($path . '.webp');
      }
    }

    return true;
  }

  private function encode_webp($source, $dest) {
    if (!function_exists('imagewebp')) {
      return false;
    }
    $info = @getimagesize($source);
    if (!$info || empty($info['mime'])) {
      return is_file($source) && @copy($source, $dest);
    }
    $img = null;
    if ($info['mime'] === 'image/jpeg') {
      $img = @imagecreatefromjpeg($source);
    } elseif ($info['mime'] === 'image/png') {
      $img = @imagecreatefrompng($source);
      if ($img) {
        imagepalettetotruecolor($img);
        imagealphablending($img, true);
        imagesavealpha($img, true);
      }
    } elseif ($info['mime'] === 'image/webp') {
      return @copy($source, $dest);
    } else {
      return false;
    }
    if (!$img) {
      return false;
    }
    $tmp = $dest . '.tmp';
    $ok = @imagewebp($img, $tmp, $this->quality());
    if (PHP_VERSION_ID < 80000) {
      imagedestroy($img); // PHP 8+ tự giải phóng, 8.5 báo deprecated
    }
    if (!$ok || !is_file($tmp)) {
      @unlink($tmp);
      return false;
    }
    if (is_file($dest)) {
      @unlink($dest);
    }
    if (!@rename($tmp, $dest)) {
      @unlink($tmp);
      return false;
    }
    return true;
  }

  /**
   * WP chỉ tránh trùng tên theo đuôi gốc: đã có "anh.webp" (từ "anh.png" trước đó, hoặc "anh.jpg"
   * đã xóa ảnh gốc) mà tải "anh.jpg" thì vẫn giữ tên đó. Đổi tên tới khi không đụng ảnh cùng tên nào.
   *
   * Bản trước chỉ nhìn file .webp nên không chặn được trường hợp ảnh cùng tên CHƯA đổi sang WebP:
   * có "1.jpg" (tải lên khi module tắt, hoặc đổi lỗi) rồi tải "1.png" thì "1.png" giữ tên, thành "1.webp";
   * lần chuyển hàng loạt sau đó đổi "1.jpg" lại dùng nhầm "1.webp" này. Giờ coi mọi ảnh cùng tên
   * (jpg/jpeg/png/webp, kể cả -scaled, -150x150) là trùng. Việc chọn tên .webp lúc đổi (plan_webp_targets)
   * mới là chốt chặn chính; bước này chỉ để tên file dễ nhìn hơn.
   */
  public function unique_webp_name($filename, $ext, $dir) {
    $e = pathinfo($filename, PATHINFO_EXTENSION);
    if (!preg_match('/^(jpe?g|png)$/i', $e)) {
      return $filename;
    }
    $dir = trailingslashit($dir);
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $taken = function ($n) use ($dir, $e) {
      foreach (array_unique([$e, 'jpg', 'jpeg', 'png', 'webp']) as $x) {
        if (file_exists($dir . $n . '.' . $x)) {
          return true;
        }
      }
      foreach (glob($dir . $n . '-*.webp', GLOB_NOSORT) ?: [] as $f) {
        if (preg_match('/^' . preg_quote($n, '/') . '-(?:scaled|rotated|\d+x\d+)\.webp$/', wp_basename($f))) {
          return true;
        }
      }
      return false;
    };
    if (!$taken($name)) {
      return $filename;
    }
    $i = 1;
    while ($taken($name . '-' . $i)) {
      $i++;
    }
    return $name . '-' . $i . '.' . $e;
  }

  public function on_metadata($metadata, $attachment_id) {
    $file = get_attached_file($attachment_id);
    if (!$file || !is_array($metadata)) {
      return $metadata;
    }
    $file = wp_normalize_path($file);
    $dir = trailingslashit(dirname($file));

    // Chọn tên .webp cho cả file chính, ảnh gốc trước khi scale và các size một lượt (không dùng nhầm file của ảnh khác).
    $sources = [];
    if (preg_match('/\.(jpe?g|png)$/i', $file)) {
      $sources[] = $file;
    }
    if (!empty($metadata['original_image']) && preg_match('/\.(jpe?g|png)$/i', $metadata['original_image'])) {
      $sources[] = $dir . wp_basename($metadata['original_image']);
    }
    foreach ((array) ($metadata['sizes'] ?? []) as $size) {
      if (!empty($size['file']) && preg_match('/\.(jpe?g|png)$/i', $size['file'])) {
        $sources[] = $dir . wp_basename($size['file']);
      }
    }
    $made = [];
    foreach ($this->plan_webp_targets($sources, $attachment_id) as $src => $target) {
      $new = $this->materialize_webp($src, $target);
      if (is_string($new)) {
        $made[$src] = $new;
      }
    }

    if (isset($made[$file])) {
      $metadata['file'] = $this->relative_upload_path($made[$file]);
      update_attached_file($attachment_id, $made[$file]);
      wp_update_post(['ID' => $attachment_id, 'post_mime_type' => 'image/webp']);
    }
    if (!empty($metadata['original_image']) && isset($made[$dir . wp_basename($metadata['original_image'])])) {
      $metadata['original_image'] = wp_basename($made[$dir . wp_basename($metadata['original_image'])]);
    }
    if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
      foreach ($metadata['sizes'] as $name => $size) {
        $src = empty($size['file']) ? '' : $dir . wp_basename($size['file']);
        if ($src !== '' && isset($made[$src])) {
          $metadata['sizes'][$name]['file'] = wp_basename($made[$src]);
          $metadata['sizes'][$name]['mime-type'] = 'image/webp';
        }
      }
    }

    // Xóa ảnh gốc sau 1 phút qua cron, không làm ngay: hook này cũng chạy khi tạo lại thumbnail cho ảnh cũ
    // đang có link trong bài, nên phải thay link trong DB trước (replace_file_with_webp) rồi mới xóa.
    if ($made && $this->delete_originals()) {
      $converted = [];
      foreach ($made as $src => $new) {
        $converted[$this->relative_upload_path($src)] = $this->relative_upload_path($new);
      }
      update_post_meta($attachment_id, '_wn_webp_originals', $converted);
      if (!wp_next_scheduled('wn_webp_delete_originals', [$attachment_id])) {
        wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'wn_webp_delete_originals', [$attachment_id]);
      }
    }
    return $metadata;
  }

  public function delete_attachment_originals($attachment_id) {
    $rels = get_post_meta($attachment_id, '_wn_webp_originals', true);
    delete_post_meta($attachment_id, '_wn_webp_originals');
    if (!is_array($rels) || !$this->delete_originals()) {
      return;
    }
    $base = trailingslashit(wp_get_upload_dir()['basedir']);
    foreach ($rels as $k => $v) {
      // Bản mới lưu "ảnh gốc => file .webp đã chọn"; bản cũ chỉ là danh sách ảnh gốc.
      $rel = is_string($k) ? $k : (string) $v;
      $target = is_string($k) ? $base . $v : '';
      if (is_file($base . $rel)) {
        $this->replace_file_with_webp($base . $rel, true, $target);
      }
    }
  }

  private function replace_url_fragment($old, $new) {
    global $wpdb;
    $old = (string) $old;
    $new = (string) $new;
    if ($old === '' || $old === $new) {
      return;
    }
    // Tên file có dấu/chữ Trung: trong bài có thể lưu dạng mã hóa %E4%B8%BB...
    if (preg_match('/[^\x20-\x7e]/', $old)) {
      $enc = function ($s) {
        return implode('/', array_map('rawurlencode', explode('/', $s)));
      };
      $this->replace_url_fragment($enc($old), $enc($new));
    }

    $wpdb->query($wpdb->prepare(
      "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s)",
      $old,
      $new
    ));
    $wpdb->query($wpdb->prepare(
      "UPDATE {$wpdb->posts} SET guid = REPLACE(guid, %s, %s) WHERE post_type = 'attachment'",
      $old,
      $new
    ));

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
      '%' . $wpdb->esc_like($old) . '%'
    ));
    foreach ($rows as $row) {
      $updated = $this->deep_replace($row->meta_value, $old, $new);
      if ($updated !== $row->meta_value) {
        $wpdb->update($wpdb->postmeta, ['meta_value' => $updated], ['meta_id' => $row->meta_id]);
      }
    }

    // Elementor/Smart Slider lưu JSON nên "/" thành "\/": thay luôn dạng đó.
    $old_json = str_replace('/', '\/', $old);
    if ($old_json !== $old) {
      $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
        '%' . $wpdb->esc_like($old_json) . '%'
      ));
      foreach ($rows as $row) {
        $updated = $this->deep_replace($row->meta_value, $old_json, str_replace('/', '\/', $new));
        if ($updated !== $row->meta_value) {
          $wpdb->update($wpdb->postmeta, ['meta_value' => $updated], ['meta_id' => $row->meta_id]);
        }
      }
    }
    foreach ($this->extra_ref_tables() as $table) {
      foreach ((array) $wpdb->get_results('SHOW COLUMNS FROM `' . esc_sql($table) . '`', ARRAY_A) as $col) {
        if (!preg_match('/char|text/i', $col['Type'])) {
          continue;
        }
        $c = '`' . esc_sql($col['Field']) . '`';
        foreach (array_unique([$old => $new, $old_json => str_replace('/', '\/', $new)], SORT_REGULAR) as $o => $n) {
          $wpdb->query($wpdb->prepare('UPDATE `' . esc_sql($table) . "` SET $c = REPLACE($c, %s, %s) WHERE $c LIKE %s", $o, $n, '%' . $wpdb->esc_like($o) . '%'));
        }
      }
    }

    $opts = $wpdb->get_results($wpdb->prepare(
      "SELECT option_id, option_value FROM {$wpdb->options} WHERE option_value LIKE %s",
      '%' . $wpdb->esc_like($old) . '%'
    ));
    foreach ($opts as $row) {
      $updated = $this->deep_replace($row->option_value, $old, $new);
      if ($updated !== $row->option_value) {
        $wpdb->update($wpdb->options, ['option_value' => $updated], ['option_id' => $row->option_id]);
      }
    }
  }

  private function deep_replace($value, $old, $new) {
    $data = maybe_unserialize($value);
    if ($data !== $value && (is_array($data) || is_object($data))) {
      $data = $this->recursive_str_replace($data, $old, $new);
      return maybe_serialize($data);
    }
    if (is_string($value)) {
      return str_replace($old, $new, $value);
    }
    return $value;
  }

  private function recursive_str_replace($data, $old, $new) {
    if (is_string($data)) {
      return str_replace($old, $new, $data);
    }
    if (is_array($data)) {
      foreach ($data as $k => $v) {
        $data[$k] = $this->recursive_str_replace($v, $old, $new);
      }
      return $data;
    }
    if (is_object($data)) {
      foreach ($data as $k => $v) {
        $data->$k = $this->recursive_str_replace($v, $old, $new);
      }
      return $data;
    }
    return $data;
  }
}

}

if (wn_module_on('webp') && !$wn_webp_standalone) {
  new WebNow_WebP_Batch();
}
