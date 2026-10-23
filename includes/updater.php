<?php
/**
 * Tự cập nhật từ GitHub (nhánh main của repo bên dưới).
 *
 * Không đi qua cơ chế cập nhật của WordPress (Foxtool và các plugin "ẩn cập nhật" chặn nó) và không
 * báo ở đâu khác: chỉ hiện một thông báo nhỏ cạnh số phiên bản trong trang WebNow.
 *
 * Có bản mới khi: số Version trên GitHub lớn hơn, hoặc cùng Version nhưng main có commit mới hơn bản
 * đang cài (sha đã ghi lúc cập nhật; cài tay thì so ngày commit với thời gian file plugin).
 *
 * Cập nhật: tải zip đúng commit → kiểm tra cú pháp mọi file PHP → đổi thư mục (giữ bản cũ) →
 * gọi thử code mới qua admin-ajax; lỗi thì tự trả lại bản cũ.
 */
if (!defined('ABSPATH')) {
  exit;
}

final class WN_Updater {
  const REPO = 'nguyenmaidongho/wn-tien-ich-plugin';
  const BRANCH = 'main';
  const NONCE = 'wn_update';
  const CACHE = 'wn_update_remote';          // transient: thông tin bản trên GitHub
  const OPT_INSTALLED = 'wn_update_installed'; // sha/version của bản cài bằng bộ cập nhật
  const OPT_BACKUP = 'wn_update_backup';       // bản trước để khôi phục

  public function __construct() {
    add_action('wp_ajax_wn_update_check', [$this, 'ajax_check']);
    add_action('wp_ajax_wn_update_run', [$this, 'ajax_run']);
    add_action('wp_ajax_wn_update_rollback', [$this, 'ajax_rollback']);
    // Gọi thử sau khi đổi code: chạy bằng code MỚI trong một request mới.
    add_action('wp_ajax_wn_update_ping', [__CLASS__, 'ping']);
    add_action('wp_ajax_nopriv_wn_update_ping', [__CLASS__, 'ping']);
  }

  public static function ping() {
    nocache_headers();
    echo 'wn-ok:' . self::local_version();
    exit;
  }

  /** Nhánh lấy bản cập nhật. Site staging/test đặt define('WN_UPDATE_BRANCH', 'ten-nhanh'); trong wp-config.php. */
  public static function branch() {
    return defined('WN_UPDATE_BRANCH') && WN_UPDATE_BRANCH ? (string) WN_UPDATE_BRANCH : self::BRANCH;
  }

  public static function local_version() {
    return (string) get_file_data(WN_PLUGIN, ['Version' => 'Version'])['Version'];
  }

  /** Bản mới nhất trên GitHub. Cache 6 giờ (lỗi mạng: 15 phút) vì API cho 60 lượt/giờ mỗi IP. */
  public static function remote($force = false) {
    $cached = get_transient(self::CACHE);
    if (!$force && is_array($cached)) {
      return $cached;
    }
    $info = ['sha' => '', 'version' => '', 'message' => '', 'date' => '', 'error' => '', 'checked' => time()];
    $r = wp_remote_get('https://api.github.com/repos/' . self::REPO . '/commits/' . rawurlencode(self::branch()), [
      'timeout' => 15,
      'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'WebNow-Updater'],
    ]);
    if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) {
      $j = json_decode(wp_remote_retrieve_body($r), true);
      $info['sha'] = (string) ($j['sha'] ?? '');
      $info['message'] = trim(strtok((string) ($j['commit']['message'] ?? ''), "\n"));
      $info['date'] = (string) ($j['commit']['committer']['date'] ?? '');
    }
    // Số phiên bản đọc từ file chính (raw.githubusercontent không tính vào giới hạn API).
    $raw = wp_remote_get('https://raw.githubusercontent.com/' . self::REPO . '/' . ($info['sha'] ?: self::branch()) . '/wn-tien-ich.php', ['timeout' => 15]);
    if (!is_wp_error($raw) && wp_remote_retrieve_response_code($raw) === 200
      && preg_match('/^[ \t\/*#@]*Version:\s*(\S+)/mi', wp_remote_retrieve_body($raw), $m)) {
      $info['version'] = $m[1];
    }
    if (!$info['version']) {
      $info['error'] = is_wp_error($raw) ? $raw->get_error_message() : 'Không đọc được phiên bản trên GitHub.';
    }
    set_transient(self::CACHE, $info, $info['error'] ? 15 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS);
    return $info;
  }

  /** sha của bản đang chạy, nếu bản này do bộ cập nhật cài. */
  private static function installed_sha() {
    $inst = get_option(self::OPT_INSTALLED, []);
    return is_array($inst) && ($inst['version'] ?? '') === self::local_version() ? (string) ($inst['sha'] ?? '') : '';
  }

  public static function status($force = false) {
    $remote = self::remote($force);
    $local = self::local_version();
    $sha = self::installed_sha();
    $available = false;
    if ($remote['version']) {
      $cmp = version_compare($remote['version'], $local);
      if ($cmp > 0) {
        $available = true;
      } elseif ($cmp === 0 && $remote['sha']) {
        // Cùng Version: có commit mới trên main. Cài tay (chưa biết sha) thì so ngày commit với
        // thời gian file plugin (git archive / zip GitHub giữ thời gian commit; git clone mới hơn).
        $available = $sha ? $sha !== $remote['sha'] : ($remote['date'] && strtotime($remote['date']) > filemtime(WN_PLUGIN) + 60);
      }
    }
    $backup = get_option(self::OPT_BACKUP, []);
    $has_backup = is_array($backup) && !empty($backup['dir']) && is_dir($backup['dir']);
    return [
      'local' => $local,
      'remote' => $remote,
      'available' => $available,
      'compare' => $sha && $remote['sha']
        ? 'https://github.com/' . self::REPO . '/compare/' . $sha . '...' . $remote['sha']
        : 'https://github.com/' . self::REPO . '/commits/' . self::branch(),
      'backup' => $has_backup ? ['version' => $backup['version'], 'time' => $backup['time']] : null,
    ];
  }

  /* ---------- AJAX ---------- */

  private function guard() {
    check_ajax_referer(self::NONCE, 'nonce');
    if (!current_user_can('manage_options')) {
      wp_send_json_error('Không có quyền.', 403);
    }
    @set_time_limit(300);
  }

  public function ajax_check() {
    $this->guard();
    wp_send_json_success(self::status(!empty($_POST['force'])));
  }

  public function ajax_run() {
    $this->guard();
    $status = self::status(true);
    $remote = $status['remote'];
    if (!$remote['version']) {
      wp_send_json_error('Không kết nối được GitHub: ' . $remote['error']);
    }
    $ref = $remote['sha'] ?: self::branch();
    $prev_sha = self::installed_sha(); // đọc trước khi đổi thư mục (sau đó file plugin đã là bản mới)
    $work = WP_CONTENT_DIR . '/upgrade/wn-update-' . wp_generate_password(8, false);
    try {
      $new = self::download($ref, $work);
      self::validate($new);
      $backup = self::swap($new);
    } catch (Throwable $e) {
      self::rmdir_tree($work);
      wp_send_json_error($e->getMessage());
    }
    self::rmdir_tree($work);

    $old = get_option(self::OPT_BACKUP, []);
    if (is_array($old) && !empty($old['dir']) && $old['dir'] !== $backup) {
      self::rmdir_tree($old['dir']); // chỉ giữ một bản trước
    }
    update_option(self::OPT_BACKUP, ['dir' => $backup, 'version' => $status['local'], 'sha' => $prev_sha, 'time' => time()], false);
    update_option(self::OPT_INSTALLED, ['sha' => $remote['sha'], 'version' => $remote['version'], 'time' => time()], false);
    delete_transient(self::CACHE);
    wn_clear_page_cache();
    wp_send_json_success(['version' => $remote['version'], 'message' => $remote['message']]);
  }

  public function ajax_rollback() {
    $this->guard();
    $b = get_option(self::OPT_BACKUP, []);
    if (!is_array($b) || empty($b['dir']) || !is_dir($b['dir'])) {
      wp_send_json_error('Không còn bản trước để khôi phục.');
    }
    try {
      $current = self::swap($b['dir']);
    } catch (Throwable $e) {
      wp_send_json_error($e->getMessage());
    }
    self::rmdir_tree($current);
    delete_option(self::OPT_BACKUP);
    update_option(self::OPT_INSTALLED, ['sha' => (string) ($b['sha'] ?? ''), 'version' => (string) $b['version'], 'time' => time()], false);
    delete_transient(self::CACHE);
    wn_clear_page_cache();
    wp_send_json_success(['version' => $b['version']]);
  }

  /* ---------- Các bước cập nhật ---------- */

  /** Tải zip đúng commit, giải nén, trả về thư mục chứa wn-tien-ich.php. */
  private static function download($ref, $work) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $zip = download_url('https://codeload.github.com/' . self::REPO . '/zip/' . rawurlencode($ref), 120);
    if (is_wp_error($zip)) {
      throw new RuntimeException('Không tải được bản mới: ' . $zip->get_error_message());
    }
    wp_mkdir_p($work);
    $ok = false;
    if (class_exists('ZipArchive')) {
      $z = new ZipArchive();
      if ($z->open($zip) === true) {
        $ok = $z->extractTo($work);
        $z->close();
      }
    } else {
      WP_Filesystem();
      $ok = unzip_file($zip, $work) === true;
    }
    @unlink($zip);
    if (!$ok) {
      throw new RuntimeException('Không giải nén được bản mới.');
    }
    foreach ((array) glob($work . '/*', GLOB_ONLYDIR) as $dir) {
      if (is_file($dir . '/wn-tien-ich.php')) {
        return $dir;
      }
    }
    throw new RuntimeException('Gói tải về không có wn-tien-ich.php.');
  }

  /** Đúng plugin + mọi file PHP đúng cú pháp với phiên bản PHP đang chạy. */
  private static function validate($dir) {
    $head = get_file_data($dir . '/wn-tien-ich.php', ['Name' => 'Plugin Name', 'Version' => 'Version']);
    if ($head['Name'] !== 'Tiện ích WebNow' || !$head['Version']) {
      throw new RuntimeException('Gói tải về không phải plugin Tiện ích WebNow.');
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
      if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
        try {
          token_get_all((string) file_get_contents($f->getPathname()), TOKEN_PARSE);
        } catch (ParseError $e) {
          throw new RuntimeException('Bản mới lỗi cú pháp ở ' . substr($f->getPathname(), strlen($dir) + 1) . ' dòng ' . $e->getLine() . ', đã hủy cập nhật.');
        }
      }
    }
  }

  /**
   * Đưa $new vào chỗ thư mục plugin, thư mục hiện tại chuyển ra wp-content/upgrade/.
   * Gọi thử code mới; lỗi thì đổi lại như cũ. Trả về đường dẫn thư mục cũ.
   */
  private static function swap($new) {
    $dir = WN_PLUGIN_DIR;
    $old = WP_CONTENT_DIR . '/upgrade/wn-backup-' . self::local_version() . '-' . wp_date('Ymd-His') . '-' . wp_generate_password(6, false);
    wp_mkdir_p(dirname($old));
    if (!@rename($dir, $old)) {
      throw new RuntimeException('Không đổi được thư mục plugin (kiểm tra quyền ghi thư mục plugins).');
    }
    if (!@rename($new, $dir)) {
      @rename($old, $dir);
      throw new RuntimeException('Không chép được bản mới vào thư mục plugin, đã giữ nguyên bản cũ.');
    }
    self::flush_opcache($dir);

    $ping = wp_remote_get(add_query_arg(['action' => 'wn_update_ping', 't' => time()], admin_url('admin-ajax.php')), [
      'timeout' => 30,
      'sslverify' => false,
      'headers' => ['Cache-Control' => 'no-cache'],
    ]);
    $body = is_wp_error($ping) ? '' : (string) wp_remote_retrieve_body($ping);
    if (strpos($body, 'wn-ok:') === false) {
      // Code mới làm hỏng WordPress: trả lại bản cũ ngay.
      $failed = $old . '-failed';
      @rename($dir, $failed);
      @rename($old, $dir);
      self::flush_opcache($dir);
      self::rmdir_tree($failed);
      $why = is_wp_error($ping) ? $ping->get_error_message() : 'mã ' . wp_remote_retrieve_response_code($ping);
      throw new RuntimeException('Bản mới chạy lỗi (' . $why . '), đã tự khôi phục bản cũ.');
    }
    return $old;
  }

  private static function flush_opcache($dir) {
    if (!function_exists('opcache_invalidate')) {
      return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
      if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
        @opcache_invalidate($f->getPathname(), true);
      }
    }
  }

  private static function rmdir_tree($dir) {
    if (!$dir || !is_dir($dir)) {
      return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
      $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
  }
}

new WN_Updater();
