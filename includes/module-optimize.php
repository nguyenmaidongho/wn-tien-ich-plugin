<?php
/**
 * Tối ưu web (WebNow → Tối ưu web): trình hướng dẫn 4 bước Phân tích → Kế hoạch → Áp dụng → Kết quả.
 *
 * Gói kinh nghiệm tối ưu WordPress/WooCommerce trên hosting DirectAdmin + LiteSpeed:
 *  - Database: transient hết hạn, transient external_ip của WooCommerce, revision, dữ liệu mồ côi,
 *    dữ liệu sót của plugin đã gỡ, Action Scheduler cũ.
 *  - Cache: LiteSpeed Cache cho máy chủ LiteSpeed + cấu hình đã kiểm chứng.
 *  - Ảnh: nén lại ảnh nặng, tạo bản .webp đi kèm (ảnh.jpg.webp) và tự phục vụ qua .htaccess.
 *  - Cron: phát hiện WP-cron chết / bị chặn, chạy bù tác vụ quá hạn.
 *  - Bảo trì: quyền wp-config, file nén/SQL để lộ, tinh chỉnh nhẹ (heartbeat, emoji, revision).
 *
 * Mọi thay đổi database đều được sao lưu trước vào uploads/wn-optimize/ (chặn truy cập từ web).
 * Phần sửa lỗi nằm ở optimize-fixes.php.
 */
if (!defined('ABSPATH')) {
  exit;
}

final class WN_Optimize {
  const NONCE = 'wn_optimize';
  const OPT_LAST = 'wn_opt_last_scan';
  const OPT_BASELINE = 'wn_opt_baseline';
  const OPT_HISTORY = 'wn_opt_history';
  const OPT_TWEAKS = 'wn_opt_tweaks';
  const DIR = 'wn-optimize';
  const HEAVY_IMG = 307200;   // ảnh trên 300KB coi là nặng
  const REVISIONS_KEEP = 5;
  const BUDGET = 8;           // giây xử lý tối đa cho mỗi lượt AJAX

  public function __construct() {
    add_action('admin_enqueue_scripts', [$this, 'assets']);
    add_action('wp_ajax_wn_opt_scan', [$this, 'ajax_scan']);
    add_action('wp_ajax_wn_opt_apply', [$this, 'ajax_apply']);
    add_action('wp_ajax_wn_opt_finish', [$this, 'ajax_finish']);
  }

  /* ---------- Tinh chỉnh chạy thường trực (bật sau khi áp dụng) ---------- */

  public static function tweaks() {
    $t = get_option(self::OPT_TWEAKS, []);
    return is_array($t) ? $t : [];
  }

  public static function apply_runtime_tweaks() {
    $t = self::tweaks();
    if (!empty($t['revisions'])) {
      add_filter('wp_revisions_to_keep', function ($num) {
        return ($num < 0 || $num > self::REVISIONS_KEEP) ? self::REVISIONS_KEEP : $num;
      });
    }
    if (!empty($t['heartbeat'])) {
      // Ngoài trang quản trị không cần heartbeat; trong admin giãn ra 60 giây.
      add_action('wp_enqueue_scripts', function () {
        if (!is_user_logged_in()) {
          wp_deregister_script('heartbeat');
        }
      }, 100);
      add_filter('heartbeat_settings', function ($s) {
        $s['interval'] = 60;
        return $s;
      });
    }
    if (!empty($t['emoji'])) {
      remove_action('wp_head', 'print_emoji_detection_script', 7);
      remove_action('wp_print_styles', 'print_emoji_styles');
      remove_action('admin_print_scripts', 'print_emoji_detection_script');
      remove_action('admin_print_styles', 'print_emoji_styles');
      remove_filter('the_content_feed', 'wp_staticize_emoji');
      remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
      add_filter('emoji_svg_url', '__return_false');
    }
  }

  /* ---------- Giao diện ---------- */

  public function assets($hook) {
    if ($hook !== 'toplevel_page_' . WN_ADMIN_PAGE || ($_GET['tab'] ?? '') !== 'optimize') {
      return;
    }
    $dir = WN_PLUGIN_DIR . '/includes/admin/';
    wp_enqueue_style('wn-optimize', wn_plugin_url('/includes/admin/optimize.css'), ['wn-admin'], filemtime($dir . 'optimize.css'));
    wp_enqueue_script('wn-optimize', wn_plugin_url('/includes/admin/optimize.js'), ['jquery'], filemtime($dir . 'optimize.js'), true);
    wp_localize_script('wn-optimize', 'WN_OPT', [
      'ajax' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce(self::NONCE),
      'groups' => self::groups(),
      'fixes' => WN_Optimize_Fixes::all(),
      'history' => get_option(self::OPT_HISTORY, []),
      'site' => wp_parse_url(home_url(), PHP_URL_HOST),
      'home' => home_url('/'),
    ]);
  }

  public static function render() {
    ?>
    <div id="wn-opt" class="wn-opt" aria-live="polite">
      <noscript><p>Cần bật JavaScript để dùng công cụ tối ưu.</p></noscript>
    </div>
    <?php
  }

  /* ---------- AJAX ---------- */

  private function guard() {
    check_ajax_referer(self::NONCE, 'nonce');
    if (!current_user_can('manage_options')) {
      wp_send_json_error('Không có quyền.', 403);
    }
    @set_time_limit(120);
    wp_raise_memory_limit('admin');
  }

  public function ajax_scan() {
    $this->guard();
    $group = sanitize_key($_POST['group'] ?? '');
    if (!isset(self::groups()[$group])) {
      wp_send_json_error('Nhóm kiểm tra không tồn tại.', 400);
    }
    $checks = call_user_func([__CLASS__, 'scan_' . $group]);
    $last = get_option(self::OPT_LAST, []);
    $last = is_array($last) ? $last : [];
    $last[$group] = $checks;
    $last['_time'] = time();
    update_option(self::OPT_LAST, $last, false);
    wp_send_json_success(['group' => $group, 'checks' => $checks]);
  }

  public function ajax_apply() {
    $this->guard();
    $fix = sanitize_key($_POST['fix'] ?? '');
    $state = json_decode(wp_unslash($_POST['state'] ?? ''), true);
    if (!WN_Optimize_Fixes::exists($fix)) {
      wp_send_json_error('Bước tối ưu không tồn tại.', 400);
    }
    if ($fix === 'backup_db' && empty($state)) {
      // Lượt áp dụng mới: giữ kết quả phân tích hiện tại làm mốc "trước".
      update_option(self::OPT_BASELINE, get_option(self::OPT_LAST, []), false);
    }
    try {
      $out = WN_Optimize_Fixes::run($fix, is_array($state) ? $state : []);
    } catch (Throwable $e) {
      wp_send_json_error('Lỗi: ' . $e->getMessage());
    }
    wp_send_json_success($out);
  }

  /** Lưu lịch sử một lượt tối ưu (điểm trước/sau) để hiện ở màn hình đầu. */
  public function ajax_finish() {
    $this->guard();
    $before = (int) ($_POST['before'] ?? 0);
    $after = (int) ($_POST['after'] ?? 0);
    $history = get_option(self::OPT_HISTORY, []);
    $history = is_array($history) ? $history : [];
    array_unshift($history, [
      'time' => time(),
      'before' => max(0, min(100, $before)),
      'after' => max(0, min(100, $after)),
      'user' => wp_get_current_user()->display_name,
    ]);
    update_option(self::OPT_HISTORY, array_slice($history, 0, 10), false);
    wp_send_json_success();
  }

  /* ---------- Nhóm kiểm tra ---------- */

  public static function groups() {
    return [
      'server' => ['title' => 'Máy chủ & PHP', 'icon' => 'dashicons-cloud', 'weight' => 1, 'say' => 'Kiểm tra phiên bản PHP, OPcache, máy chủ web'],
      'speed' => ['title' => 'Tốc độ phản hồi', 'icon' => 'dashicons-performance', 'weight' => 3, 'say' => 'Đo thời gian trang chủ phản hồi (có và không có cache)'],
      'cache' => ['title' => 'Bộ nhớ đệm', 'icon' => 'dashicons-database-view', 'weight' => 3, 'say' => 'Dò plugin cache, cấu hình LiteSpeed, nén gzip, cache trình duyệt'],
      'database' => ['title' => 'Database', 'icon' => 'dashicons-database', 'weight' => 2, 'say' => 'Đếm transient, revision, dữ liệu mồ côi, rác của plugin đã gỡ'],
      'images' => ['title' => 'Hình ảnh', 'icon' => 'dashicons-format-image', 'weight' => 2, 'say' => 'Quét thư mục uploads: ảnh nặng, độ phủ WebP'],
      'cron' => ['title' => 'Tác vụ nền (cron)', 'icon' => 'dashicons-clock', 'weight' => 2, 'say' => 'Tìm tác vụ quá hạn, thử gọi wp-cron.php'],
      'maintenance' => ['title' => 'Bảo trì & an toàn', 'icon' => 'dashicons-shield', 'weight' => 1, 'say' => 'Quyền wp-config, file sao lưu để lộ, cập nhật, tinh chỉnh nhẹ'],
    ];
  }

  /**
   * Một dòng kết quả. status: good | warn | bad | info.
   * fix: mã bước tự sửa được (xem WN_Optimize_Fixes), manual: hướng dẫn làm tay.
   */
  private static function check($id, $title, $status, $value, $detail = '', $extra = []) {
    return array_merge([
      'id' => $id,
      'title' => $title,
      'status' => $status,
      'value' => $value,
      'detail' => $detail,
      'fix' => '',
      'fix_default' => true,
      'manual' => '',
      'metric' => null,
    ], $extra);
  }

  /* Máy chủ & PHP */

  private static function scan_server() {
    $out = [];
    $php = PHP_VERSION;
    $status = version_compare($php, '8.1', '>=') ? 'good' : (version_compare($php, '7.4', '>=') ? 'warn' : 'bad');
    $out[] = self::check('php', 'Phiên bản PHP', $status, $php,
      $status === 'good' ? 'PHP mới, chạy nhanh và còn được vá bảo mật.' : 'PHP cũ chạy chậm hơn đáng kể và hết hạn vá bảo mật.',
      ['manual' => $status === 'good' ? '' : 'Nâng PHP lên 8.2+ trong DirectAdmin → Select PHP version (kiểm tra theme/plugin tương thích trước).']);

    $op = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
    $on = is_array($op) && !empty($op['opcache_enabled']);
    $out[] = self::check('opcache', 'OPcache', $on ? 'good' : 'bad', $on ? 'Đang bật' : 'Đang tắt',
      $on ? 'PHP giữ mã đã biên dịch trong RAM, không phải đọc lại file mỗi lần.' : 'Không có OPcache, mỗi lần tải trang PHP phải biên dịch lại hàng nghìn file.',
      ['manual' => $on ? '' : 'Bật extension opcache trong DirectAdmin → Select PHP version → Extensions.']);

    $soft = self::server_software();
    $plan = $soft['litespeed'] ? 'LiteSpeed: dùng LiteSpeed Cache, cache ngay ở tầng máy chủ, nhanh nhất.'
      : ($soft['apache'] ? 'Apache: WP Fastest Cache (trả HTML tĩnh qua .htaccess) + rule nén/cache trình duyệt trong .htaccess.'
      : ($soft['nginx'] ? 'Nginx: Cache Enabler (cache qua PHP drop-in) + đoạn cấu hình nginx gửi hosting cho gzip/cache/WebP.'
      : 'Máy chủ khác: Cache Enabler (không cần .htaccess); nén và cache trình duyệt cần cấu hình ở máy chủ.'));
    $out[] = self::check('server', 'Máy chủ web', 'info', $soft['label'], 'Cách tối ưu sẽ dùng — ' . $plan);

    $ext = wp_using_ext_object_cache();
    $can = extension_loaded('redis') || extension_loaded('memcached');
    $out[] = self::check('object_cache', 'Object cache', $ext ? 'good' : 'info', $ext ? 'Đang dùng' : 'Chưa có',
      $ext ? 'Truy vấn lặp lại được lấy từ bộ nhớ thay vì database.' : ($can
        ? 'PHP có sẵn extension ' . (extension_loaded('redis') ? 'redis' : 'memcached') . '. Nếu gói hosting có dịch vụ Redis thì bật thêm để trang chưa cache nhanh hơn.'
        : 'Hosting chưa hỗ trợ Redis/Memcached. Không bắt buộc khi đã có cache trang.'));
    return $out;
  }

  public static function server_software() {
    $s = (string) ($_SERVER['SERVER_SOFTWARE'] ?? '');
    $ls = stripos($s, 'litespeed') !== false || (defined('LSCWP_V') && !empty($_SERVER['X-LSCACHE']));
    $nginx = !$ls && stripos($s, 'nginx') !== false;
    // IIS/Caddy... không đọc .htaccess. Không rõ tên máy chủ thì dựa vào việc site có .htaccess.
    $apache = !$ls && !$nginx && (stripos($s, 'apache') !== false || ($s === '' && file_exists(ABSPATH . '.htaccess')));
    return [
      'label' => $s !== '' ? $s : 'Không rõ',
      'litespeed' => $ls,
      'nginx' => $nginx,
      'apache' => $apache,
    ];
  }

  /* Tốc độ phản hồi */

  /** Gọi trang của chính website và đo thời gian. */
  public static function fetch($url, $method = 'GET') {
    $t = microtime(true);
    $r = wp_remote_request($url, [
      'method' => $method,
      'timeout' => 30,
      'sslverify' => false,
      'redirection' => 3,
      'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) WebNowOptimizer/1.0',
      'headers' => ['Accept' => 'text/html,image/avif,image/webp,*/*', 'Accept-Encoding' => 'gzip, deflate'],
    ]);
    $time = microtime(true) - $t;
    if (is_wp_error($r)) {
      return ['ok' => false, 'error' => $r->get_error_message(), 'time' => $time];
    }
    $headers = wp_remote_retrieve_headers($r);
    return [
      'ok' => true,
      'code' => (int) wp_remote_retrieve_response_code($r),
      'time' => $time,
      'headers' => is_object($headers) ? $headers->getAll() : (array) $headers,
      'body' => (string) wp_remote_retrieve_body($r),
    ];
  }

  private static function fmt_s($sec) {
    return number_format($sec, 2, ',', '.') . 's';
  }

  private static function scan_speed() {
    $home = home_url('/');
    $out = [];
    // Đo 2 lần, lấy lần nhanh hơn để bớt nhiễu.
    $uncached = self::fetch(add_query_arg('wn_nocache', wp_rand(100000, 999999), $home));
    if ($uncached['ok']) {
      $again = self::fetch(add_query_arg('wn_nocache', wp_rand(100000, 999999), $home));
      if ($again['ok'] && $again['time'] < $uncached['time']) {
        $uncached = $again;
      }
    }
    if (!$uncached['ok'] || $uncached['code'] >= 400) {
      $why = $uncached['ok'] ? 'mã ' . $uncached['code'] : $uncached['error'];
      $out[] = self::check('ttfb_uncached', 'Phản hồi khi chưa có cache', 'info', 'Không đo được',
        'Máy chủ không cho website tự gọi chính nó (' . $why . '). Các bước còn lại vẫn chạy bình thường.');
      return $out;
    }
    $t = $uncached['time'];
    $status = $t < 0.6 ? 'good' : ($t < 1.2 ? 'warn' : 'bad');
    $out[] = self::check('ttfb_uncached', 'Phản hồi khi chưa có cache', $status, self::fmt_s($t),
      'Thời gian PHP + database dựng trang chủ từ đầu (đo từ chính máy chủ, chưa tính mạng của khách).',
      ['metric' => ['key' => 'ttfb_uncached', 'label' => 'Phản hồi chưa cache', 'value' => round($t, 3), 'unit' => 's', 'lower' => true]]);

    self::fetch($home); // lượt đầu để tạo cache
    $cached = self::fetch($home);
    $again = self::fetch($home);
    if ($again['ok'] && (!$cached['ok'] || $again['time'] < $cached['time'])) {
      $cached = $again;
    }
    if ($cached['ok']) {
      $c = $cached['time'];
      $status = $c < 0.3 ? 'good' : ($c < 0.8 ? 'warn' : 'bad');
      $out[] = self::check('ttfb_cached', 'Phản hồi khi đã có cache', $status, self::fmt_s($c),
        'Đây là tốc độ phần lớn khách truy cập nhận được.',
        ['metric' => ['key' => 'ttfb_cached', 'label' => 'Phản hồi có cache', 'value' => round($c, 3), 'unit' => 's', 'lower' => true]]);

      $html = $cached['body'];
      $scripts = preg_match_all('/<script[^>]+src=/i', $html);
      $styles = preg_match_all('/<link[^>]+rel=[\'"]stylesheet/i', $html);
      $imgs = preg_match_all('/<img\b/i', $html);
      $kb = strlen($html) / 1024;
      $heavy = $scripts > 40 || $styles > 25;
      $out[] = self::check('page_assets', 'Tài nguyên trang chủ', $heavy ? 'warn' : 'good',
        sprintf('%d JS · %d CSS · %d ảnh', $scripts, $styles, $imgs),
        sprintf('HTML %s KB. ', number_format_i18n($kb, 0)) . ($heavy ? 'Nhiều file JS/CSS: nên gộp/nén bằng plugin cache.' : 'Số lượng file hợp lý.'));
    }
    return $out;
  }

  /* Bộ nhớ đệm */

  private static function cache_plugins() {
    return [
      'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
      'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache',
      'wp-super-cache/wp-cache.php' => 'WP Super Cache',
      'cache-enabler/cache-enabler.php' => 'Cache Enabler',
      'comet-cache/comet-cache.php' => 'Comet Cache',
      'breeze/breeze.php' => 'Breeze',
      'hummingbird-performance/wp-hummingbird.php' => 'Hummingbird',
      'wp-rocket/wp-rocket.php' => 'WP Rocket',
      'swift-performance-lite/performance.php' => 'Swift Performance',
      'sg-cachepress/sg-cachepress.php' => 'SiteGround Optimizer',
    ];
  }

  public static function active_cache_plugins() {
    $active = (array) get_option('active_plugins', []);
    return array_intersect_key(self::cache_plugins(), array_flip($active));
  }

  /** Cấu hình LiteSpeed Cache đã kiểm chứng (giá trị mảng = danh sách, sẽ gộp với giá trị đang có). */
  public static function lscache_recommended() {
    return [
      'cache' => 1,
      'cache-browser' => 1,
      'cache-ttl_browser' => 31557600,
      'cache-ttl_pub' => 604800,
      'cache-ttl_frontpage' => 604800,
      'optm-css_min' => 1,
      'optm-js_min' => 1,
      'optm-html_min' => 1,
      'optm-emoji_rm' => 1,
      'optm-qs_rm' => 1,
      'optm-js_defer' => 1,
      'optm-js_defer_exc' => ['jquery.js', 'jquery.min.js'],
      'media-lazy' => 1,
      'media-lazy_cls_exc' => ['header_logo', 'header-logo', 'header-logo-dark', 'custom-logo', 'skip-lazy'],
      'media-iframe_lazy' => 1,
      'media-add_missing_sizes' => 1,
      'optm-ucss' => 0,
      'guest' => 0,
      'crawler' => 0,
      'img_optm-auto' => 0,
      'misc-heartbeat_front' => 1,
      'misc-heartbeat_front_ttl' => 0,
      'misc-heartbeat_back' => 1,
      'misc-heartbeat_back_ttl' => 60,
    ];
  }

  /** Tùy chọn dạng danh sách của LiteSpeed: lưu là mảng, chuỗi JSON hoặc chuỗi nhiều dòng tùy phiên bản. */
  public static function lscache_list($key) {
    $v = get_option('litespeed.conf.' . $key, []);
    if (is_string($v) && strpos(ltrim($v), '[') === 0) {
      $v = json_decode($v, true);
    }
    return is_array($v) ? array_values(array_filter(array_map('trim', $v))) : array_values(array_filter(array_map('trim', explode("\n", (string) $v))));
  }

  /** Các tùy chọn LiteSpeed đang khác cấu hình khuyến nghị. */
  public static function lscache_diff() {
    $diff = [];
    foreach (self::lscache_recommended() as $key => $want) {
      $have = get_option('litespeed.conf.' . $key, null);
      if (is_array($want)) {
        if (array_diff($want, self::lscache_list($key))) {
          $diff[] = $key;
        }
      } elseif ((string) (int) $have !== (string) $want) {
        $diff[] = $key;
      }
    }
    return $diff;
  }

  private static function detect_page_cache($res) {
    $h = array_change_key_case($res['headers'] ?? [], CASE_LOWER);
    $flat = function ($k) use ($h) {
      return isset($h[$k]) ? strtolower(is_array($h[$k]) ? implode(',', $h[$k]) : $h[$k]) : '';
    };
    if (strpos($flat('x-litespeed-cache'), 'hit') !== false) {
      return 'LiteSpeed Cache';
    }
    if (strpos($flat('cf-cache-status'), 'hit') !== false) {
      return 'Cloudflare';
    }
    if (strpos($flat('x-cache'), 'hit') !== false) {
      return 'Proxy cache';
    }
    $body = substr($res['body'] ?? '', -3000);
    $marks = [
      'WP Fastest Cache' => 'WP Fastest Cache',
      'Performance optimized by W3 Total Cache' => 'W3 Total Cache',
      'WP-Super-Cache' => 'WP Super Cache',
      'Cache Enabler' => 'Cache Enabler',
      'This website is like a Rocket' => 'WP Rocket',
    ];
    foreach ($marks as $needle => $name) {
      if (stripos($body, $needle) !== false) {
        return $name;
      }
    }
    return '';
  }

  private static function scan_cache() {
    $out = [];
    $soft = self::server_software();
    $home = self::fetch(home_url('/'));
    $hit = $home['ok'] ? self::detect_page_cache($home) : '';
    $others = self::active_cache_plugins();
    $lscache = defined('LSCWP_V') || wn_plugin_active('litespeed-cache/litespeed-cache.php');

    $wpfc = wn_plugin_active('wp-fastest-cache/wpFastestCache.php');
    $ce = wn_plugin_active('cache-enabler/cache-enabler.php');
    if (!$soft['litespeed'] && !$hit && !$others && !$ce) {
      // Apache: WP Fastest Cache trả HTML tĩnh ngay bằng .htaccess. Nginx/khác: Cache Enabler (drop-in PHP, không cần rewrite).
      $name = $soft['apache'] ? 'WP Fastest Cache' : 'Cache Enabler';
      $out[] = self::check('page_cache', 'Cache trang', 'bad', 'Chưa có',
        'Mỗi lượt xem đều phải chạy PHP + database. Với máy chủ ' . ($soft['apache'] ? 'Apache' : 'Nginx') . ', cài và cấu hình ' . $name . ' là hợp nhất.',
        ['fix' => 'page_cache_install']);
    } elseif ($soft['litespeed'] && !$lscache) {
      $out[] = self::check('page_cache', 'Cache trang', $hit ? 'warn' : 'bad',
        $hit ? $hit : 'Chưa có',
        'Máy chủ là LiteSpeed nhưng chưa dùng LiteSpeed Cache. Cache ở tầng máy chủ nhanh hơn và tự xóa đúng trang khi sửa bài'
          . ($others ? '. Sẽ tắt (không xóa) ' . implode(', ', $others) . ' để tránh cache chồng nhau.' : '.'),
        ['fix' => 'lscache_install']);
    } elseif ($hit) {
      $out[] = self::check('page_cache', 'Cache trang', 'good', $hit, 'Trang được trả từ cache, không phải dựng lại mỗi lượt xem.');
    } elseif ($lscache) {
      $out[] = self::check('page_cache', 'Cache trang', 'warn', 'LiteSpeed Cache chưa trả HIT',
        'Plugin đã bật nhưng trang chủ chưa được cache (có thể chưa bật Enable Cache hoặc vừa xóa cache).', ['fix' => 'lscache_tune']);
    } else {
      $name = $wpfc ? 'WP Fastest Cache' : ($ce ? 'Cache Enabler' : implode(', ', $others));
      $out[] = self::check('page_cache', 'Cache trang', 'warn', $name . ' chưa trả cache',
        'Plugin cache đã bật nhưng trang chủ chưa được cache. Áp dụng cấu hình chuẩn và xóa cache cũ.',
        ['fix' => ($wpfc || $ce) ? 'page_cache_install' : '', 'manual' => ($wpfc || $ce) ? '' : 'Kiểm tra cài đặt của ' . $name . '.']);
    }

    if ($lscache || ($soft['litespeed'] && !$lscache)) {
      $diff = $lscache ? self::lscache_diff() : array_keys(self::lscache_recommended());
      $out[] = self::check('lscache_conf', 'Cấu hình LiteSpeed Cache', $diff ? 'warn' : 'good',
        $diff ? count($diff) . ' tùy chọn nên chỉnh' : 'Chuẩn',
        $diff ? 'Áp dụng bộ cấu hình đã kiểm chứng: nén CSS/JS/HTML, defer JS, lazy-load ảnh (trừ logo), cache trình duyệt 1 năm, giảm heartbeat. Nên xem lại giao diện sau khi áp dụng.' : 'Đã theo cấu hình khuyến nghị.',
        ['fix' => $diff ? 'lscache_tune' : '']);
    }

    if (count($others) > 1 || ($lscache && $others)) {
      $out[] = self::check('cache_conflict', 'Plugin cache chồng nhau', 'bad', implode(', ', $others),
        'Nhiều plugin cache cùng bật dễ gây lỗi hiển thị và cache sai.', ['fix' => $lscache ? 'lscache_install' : '', 'manual' => $lscache ? '' : 'Chỉ giữ lại một plugin cache.']);
    }

    // Cách sửa gzip/cache trình duyệt theo loại máy chủ.
    $server_fix = $soft['litespeed'] ? 'lscache_tune' : ($soft['apache'] ? 'server_rules' : '');
    $server_manual = $soft['nginx'] ? 'Nginx không đọc .htaccess: gửi đoạn cấu hình bên dưới cho hosting (thêm vào block server của website).' : '';
    $nginx = $soft['nginx'] ? ['snippet' => self::nginx_snippet()] : [];

    if ($home['ok']) {
      $h = array_change_key_case($home['headers'], CASE_LOWER);
      $enc = strtolower((string) ($h['content-encoding'] ?? ''));
      $out[] = self::check('compression', 'Nén gzip/brotli', $enc ? 'good' : 'warn', $enc ?: 'Không nén',
        $enc ? 'HTML được nén trước khi gửi.' : 'HTML gửi đi không nén, nặng gấp 3-5 lần.',
        $enc ? [] : ['fix' => $server_fix, 'manual' => $server_manual] + $nginx);

      $asset = self::first_static_asset($home['body']);
      if ($asset) {
        $a = self::fetch($asset, 'HEAD');
        $ah = $a['ok'] ? array_change_key_case($a['headers'], CASE_LOWER) : [];
        $cc = (string) ($ah['cache-control'] ?? '');
        $age = preg_match('/max-age=(\d+)/', $cc, $m) ? (int) $m[1] : 0;
        if (!$age && !empty($ah['expires'])) {
          $age = max(0, strtotime($ah['expires']) - time());
        }
        $days = (int) floor($age / DAY_IN_SECONDS);
        $out[] = self::check('browser_cache', 'Cache trình duyệt (CSS/JS/ảnh)', $days >= 30 ? 'good' : ($days >= 7 ? 'warn' : 'bad'),
          $days ? $days . ' ngày' : 'Không có',
          'Khách quay lại không phải tải lại file tĩnh.',
          $days >= 30 ? [] : ['fix' => $server_fix, 'manual' => $server_manual] + $nginx);
      }
    }
    return $out;
  }

  /** Rule .htaccess cho Apache: nén gzip/brotli + cache trình duyệt cho file tĩnh. */
  public static function apache_rules() {
    return [
      '<IfModule mod_brotli.c>',
      'AddOutputFilterByType BROTLI_COMPRESS text/html text/plain text/css text/xml text/javascript application/javascript application/json application/xml application/rss+xml image/svg+xml font/ttf font/otf',
      '</IfModule>',
      '<IfModule mod_deflate.c>',
      'AddOutputFilterByType DEFLATE text/html text/plain text/css text/xml text/javascript application/javascript application/x-javascript application/json application/xml application/rss+xml image/svg+xml font/ttf font/otf application/vnd.ms-fontobject',
      '</IfModule>',
      '<IfModule mod_expires.c>',
      'ExpiresActive On',
      'ExpiresByType image/jpeg "access plus 1 year"',
      'ExpiresByType image/png "access plus 1 year"',
      'ExpiresByType image/gif "access plus 1 year"',
      'ExpiresByType image/webp "access plus 1 year"',
      'ExpiresByType image/avif "access plus 1 year"',
      'ExpiresByType image/svg+xml "access plus 1 year"',
      'ExpiresByType image/x-icon "access plus 1 year"',
      'ExpiresByType video/mp4 "access plus 1 year"',
      'ExpiresByType text/css "access plus 1 year"',
      'ExpiresByType text/javascript "access plus 1 year"',
      'ExpiresByType application/javascript "access plus 1 year"',
      'ExpiresByType font/woff2 "access plus 1 year"',
      'ExpiresByType font/woff "access plus 1 year"',
      'ExpiresByType font/ttf "access plus 1 year"',
      'ExpiresByType application/pdf "access plus 1 month"',
      '</IfModule>',
      '<IfModule mod_headers.c>',
      '<FilesMatch "\.(?i:css|js|jpe?g|png|gif|webp|avif|svg|ico|woff2?|ttf|otf|mp4)$">',
      'Header set Cache-Control "public, max-age=31536000"',
      '</FilesMatch>',
      '</IfModule>',
    ];
  }

  /** Cấu hình gửi cho hosting khi máy chủ là Nginx (gzip, cache trình duyệt, trả WebP). */
  public static function nginx_snippet() {
    $uploads = trailingslashit(wp_parse_url(wp_get_upload_dir()['baseurl'], PHP_URL_PATH) ?: '/wp-content/uploads');
    return implode("\n", [
      'gzip on;',
      'gzip_comp_level 5;',
      'gzip_min_length 256;',
      'gzip_vary on;',
      'gzip_types text/plain text/css text/xml application/javascript application/json application/xml application/rss+xml image/svg+xml font/ttf font/otf;',
      '',
      'location ~* \.(?:css|js|gif|ico|svg|woff2?|ttf|otf|mp4)$ {',
      '    expires 1y;',
      '    add_header Cache-Control "public, max-age=31536000";',
      '    access_log off;',
      '}',
      '',
      '# Trả ảnh.jpg.webp (do plugin tạo) cho trình duyệt hỗ trợ WebP',
      'map $http_accept $wn_webp { default ""; "~*image/webp" ".webp"; }  # đặt trong block http',
      'location ~* ^' . $uploads . '.+\.(?:jpe?g|png)$ {',
      '    add_header Vary Accept;',
      '    expires 1y;',
      '    try_files $uri$wn_webp $uri =404;',
      '}',
    ]);
  }

  private static function first_static_asset($html) {
    $host = wp_parse_url(home_url(), PHP_URL_HOST);
    if (preg_match_all('/(?:href|src)=[\'"]([^\'"]+\.(?:css|js)(?:\?[^\'"]*)?)[\'"]/i', $html, $m)) {
      foreach ($m[1] as $url) {
        $url = html_entity_decode($url);
        if (strpos($url, '//') === 0) {
          $url = (is_ssl() ? 'https:' : 'http:') . $url;
        }
        if (wp_parse_url($url, PHP_URL_HOST) === $host) {
          return $url;
        }
      }
    }
    return '';
  }

  /* Database */

  public static function autoload_values() {
    return "('yes','on','auto-on','auto')";
  }

  public static function db_size_mb() {
    global $wpdb;
    $like = $wpdb->esc_like($wpdb->prefix) . '%';
    return (float) $wpdb->get_var($wpdb->prepare(
      'SELECT ROUND(SUM(data_length + index_length) / 1048576, 2) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE %s', $like));
  }

  public static function expired_transients() {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->options} WHERE (option_name LIKE %s OR option_name LIKE %s) AND option_value < %d",
      $wpdb->esc_like('_transient_timeout_') . '%', $wpdb->esc_like('_site_transient_timeout_') . '%', time()));
  }

  public static function external_ip_transients() {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
      $wpdb->esc_like('_transient_external_ip_address_') . '%'));
  }

  public static function old_actions_count() {
    global $wpdb;
    $table = $wpdb->prefix . 'actionscheduler_actions';
    if (!self::table_exists($table)) {
      return 0;
    }
    return (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$table} WHERE status IN ('complete','failed','canceled') AND scheduled_date_gmt < %s",
      gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)));
  }

  public static function table_exists($table) {
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
  }

  /** Taxonomy chỉ gắn với bài viết (an toàn để dọn quan hệ mồ côi). */
  public static function post_taxonomies() {
    $types = get_post_types();
    $out = [];
    foreach (get_taxonomies([], 'objects') as $tax) {
      $objects = (array) $tax->object_type;
      if ($objects && !array_diff($objects, $types)) {
        $out[] = $tax->name;
      }
    }
    return $out;
  }

  public static function orphan_counts() {
    global $wpdb;
    $pm = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL");
    $cm = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL");
    $taxes = self::post_taxonomies();
    $tr = 0;
    if ($taxes) {
      $in = "'" . implode("','", array_map('esc_sql', $taxes)) . "'";
      $tr = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
        JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
        LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id
        WHERE p.ID IS NULL AND tt.taxonomy IN ($in)");
    }
    return ['postmeta' => $pm, 'commentmeta' => $cm, 'term_relationships' => $tr];
  }

  public static function post_junk_counts() {
    global $wpdb;
    $over = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COALESCE(SUM(c - %d), 0) FROM (SELECT COUNT(*) c FROM {$wpdb->posts} WHERE post_type = 'revision' GROUP BY post_parent HAVING c > %d) t",
      self::REVISIONS_KEEP, self::REVISIONS_KEEP));
    $drafts = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_date < %s", gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS)));
    $comments = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved IN ('spam','trash') AND comment_date < %s", gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)));
    return ['revisions' => $over, 'auto_drafts' => $drafts, 'comments' => $comments];
  }

  /** Dấu vết của các plugin phổ biến (chỉ coi là rác khi plugin không còn trong thư mục plugins). */
  public static function leftover_signatures() {
    return [
      'yoast' => ['name' => 'Yoast SEO', 'dirs' => ['wordpress-seo', 'wordpress-seo-premium'], 'options' => ['wpseo', 'yoast_'], 'tables' => ['yoast_'], 'meta' => ['_yoast_wpseo_']],
      'litespeed' => ['name' => 'LiteSpeed Cache', 'dirs' => ['litespeed-cache'], 'options' => ['litespeed'], 'tables' => ['litespeed_'], 'meta' => ['_litespeed_']],
      'elementor' => ['name' => 'Elementor', 'dirs' => ['elementor', 'elementor-pro'], 'options' => ['elementor_', '_elementor_', 'widget_elementor-library'], 'tables' => ['e_'], 'meta' => ['_elementor_']],
      'wpfc' => ['name' => 'WP Fastest Cache', 'dirs' => ['wp-fastest-cache'], 'options' => ['WpFastestCache', 'wpfc-', 'wpfc_'], 'tables' => [], 'meta' => []],
      'w3tc' => ['name' => 'W3 Total Cache', 'dirs' => ['w3-total-cache'], 'options' => ['w3tc_'], 'tables' => [], 'meta' => []],
      'smush' => ['name' => 'Smush', 'dirs' => ['wp-smushit', 'wp-smush-pro'], 'options' => ['wp-smush-', 'wp_smush_', 'skip-smush-setup'], 'tables' => ['smush_'], 'meta' => ['wp-smpro-smush-data', 'wp-smush-']],
      'wpsmtp' => ['name' => 'WP SMTP', 'dirs' => ['wp-smtp'], 'options' => [], 'tables' => ['wpsmtp_'], 'meta' => []],
      'rankmath' => ['name' => 'Rank Math', 'dirs' => ['seo-by-rank-math', 'seo-by-rank-math-pro'], 'options' => ['rank_math_', 'rank-math-'], 'tables' => ['rank_math_'], 'meta' => ['rank_math_']],
    ];
  }

  /** @return array<string,array{name:string,options:int,tables:string[],meta:int,bytes:int}> */
  public static function leftovers() {
    global $wpdb;
    $found = [];
    foreach (self::leftover_signatures() as $key => $sig) {
      $installed = false;
      foreach ($sig['dirs'] as $dir) {
        if (is_dir(WP_PLUGIN_DIR . '/' . $dir)) {
          $installed = true;
        }
      }
      if ($installed) {
        continue;
      }
      $opt = self::like_sql('option_name', $sig['options']);
      $options = $opt ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE $opt") : 0;
      $meta_sql = self::like_sql('meta_key', $sig['meta']);
      $meta = $meta_sql ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE $meta_sql") : 0;
      $tables = [];
      $bytes = 0;
      foreach ($sig['tables'] as $prefix) {
        $rows = $wpdb->get_results($wpdb->prepare(
          'SELECT table_name AS t, data_length + index_length AS b FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE %s',
          $wpdb->esc_like($wpdb->prefix . $prefix) . '%'));
        foreach ($rows as $r) {
          $tables[] = $r->t;
          $bytes += (int) $r->b;
        }
      }
      if ($options || $meta || $tables) {
        $found[$key] = ['name' => $sig['name'], 'options' => $options, 'tables' => $tables, 'meta' => $meta, 'bytes' => $bytes];
      }
    }
    return $found;
  }

  /** "col LIKE 'a%' OR col LIKE 'b%'" (mục không có ký tự đặc biệt LIKE nào được escape). */
  public static function like_sql($col, $prefixes) {
    global $wpdb;
    $parts = [];
    foreach ($prefixes as $p) {
      $parts[] = $wpdb->prepare("$col LIKE %s", $wpdb->esc_like($p) . '%');
    }
    return $parts ? '(' . implode(' OR ', $parts) . ')' : '';
  }

  private static function scan_database() {
    global $wpdb;
    $out = [];
    $size = self::db_size_mb();
    $out[] = self::check('db_size', 'Dung lượng database', $size > 200 ? 'warn' : 'info', number_format_i18n($size, 1) . ' MB', '',
      ['metric' => ['key' => 'db_size', 'label' => 'Database', 'value' => $size, 'unit' => 'MB', 'lower' => true]]);

    $auto = (int) $wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN " . self::autoload_values());
    $kb = $auto / 1024;
    $out[] = self::check('autoload', 'Options tự nạp (autoload)', $kb < 400 ? 'good' : ($kb < 900 ? 'warn' : 'bad'), number_format_i18n($kb, 0) . ' KB',
      'Phần dữ liệu WordPress đọc ở MỌI lượt tải trang. Dưới 400 KB là tốt.',
      ['metric' => ['key' => 'autoload', 'label' => 'Autoload', 'value' => round($kb), 'unit' => 'KB', 'lower' => true]]);

    $ext = self::external_ip_transients();
    $geo = get_option('woocommerce_default_customer_address', '');
    if (class_exists('WooCommerce') && (in_array($geo, ['geolocation', 'geolocation_ajax'], true) || $ext)) {
      $bad = $ext > 100;
      $out[] = self::check('wc_geo', 'WooCommerce định vị khách theo IP', $bad ? 'bad' : 'warn', number_format_i18n($ext) . ' bản ghi IP',
        'Với mỗi IP khách mới, WooCommerce gọi API bên ngoài rồi lưu thêm một bản ghi: vừa phình database vừa làm chậm trang chưa cache. Shop bán trong nước nên dùng địa chỉ mặc định của cửa hàng.',
        ['fix' => 'wc_geo_base', 'fix_default' => $bad || $geo !== 'base']);
    }

    $exp = self::expired_transients();
    $out[] = self::check('transients', 'Transient hết hạn', $exp > 2000 ? 'bad' : ($exp > 200 ? 'warn' : 'good'), number_format_i18n($exp),
      $exp > 200 ? 'Dữ liệu tạm đã hết hạn nhưng chưa được xóa (thường do WP-cron không chạy).' : 'Không đáng kể.',
      ['fix' => $exp || $ext ? 'db_transients' : '']);

    $junk = self::post_junk_counts();
    $junk_total = array_sum($junk);
    $out[] = self::check('post_junk', 'Revision, nháp tự động, bình luận rác', $junk_total > 300 ? 'warn' : 'good', number_format_i18n($junk_total),
      sprintf('%s revision vượt mức %d bản/bài · %s nháp tự động cũ · %s bình luận spam/thùng rác.',
        number_format_i18n($junk['revisions']), self::REVISIONS_KEEP, number_format_i18n($junk['auto_drafts']), number_format_i18n($junk['comments'])),
      ['fix' => $junk_total ? 'db_posts' : '']);

    $orph = self::orphan_counts();
    $orph_total = array_sum($orph);
    $out[] = self::check('orphans', 'Dữ liệu mồ côi', $orph_total > 1000 ? 'warn' : 'good', number_format_i18n($orph_total),
      'Meta và liên kết danh mục trỏ tới bài/bình luận không còn tồn tại.',
      ['fix' => $orph_total ? 'db_orphans' : '']);

    $left = self::leftovers();
    if ($left) {
      $mb = array_sum(array_column($left, 'bytes')) / 1048576;
      $names = array_column($left, 'name');
      $out[] = self::check('leftovers', 'Rác của plugin đã gỡ', $mb > 5 ? 'bad' : 'warn', implode(', ', $names),
        sprintf('%s option, %s bảng (%s MB), %s meta không còn plugin nào dùng.',
          number_format_i18n(array_sum(array_column($left, 'options'))), number_format_i18n(count(array_merge(...array_column($left, 'tables')))),
          number_format_i18n($mb, 1), number_format_i18n(array_sum(array_column($left, 'meta')))),
        ['fix' => 'db_leftovers']);
    } else {
      $out[] = self::check('leftovers', 'Rác của plugin đã gỡ', 'good', 'Không có', 'Không thấy dữ liệu sót của các plugin phổ biến đã gỡ.');
    }

    $actions = self::old_actions_count();
    if ($actions) {
      $out[] = self::check('actions', 'Action Scheduler cũ', $actions > 5000 ? 'warn' : 'good', number_format_i18n($actions),
        'Tác vụ đã chạy xong/thất bại hơn 30 ngày (WooCommerce).', ['fix' => 'db_actions']);
    }

    $out[] = self::check('db_optimize', 'Thu hồi dung lượng bảng', 'info', 'Sau khi dọn',
      'Chạy OPTIMIZE TABLE cho các bảng chính để trả lại dung lượng đã xóa.', ['fix' => 'db_optimize']);
    return $out;
  }

  /* Hình ảnh */

  public static function image_engine() {
    if (extension_loaded('imagick') && class_exists('Imagick') && in_array('WEBP', Imagick::queryFormats('WEBP'), true)) {
      return 'imagick';
    }
    if (function_exists('imagewebp') && function_exists('imagecreatefromjpeg')) {
      return 'gd';
    }
    return '';
  }

  public static function uploads_base() {
    return trailingslashit(wp_normalize_path(wp_get_upload_dir()['basedir']));
  }

  /** Duyệt ảnh JPG/PNG trong uploads (bỏ qua thư mục của plugin). */
  public static function each_upload_image(callable $cb, $deadline = 0) {
    $base = self::uploads_base();
    if (!is_dir($base)) {
      return true;
    }
    $skip = '#/(' . self::DIR . '|wn-quarantine|wc-logs|woocommerce_uploads|cache)/#';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
      if ($deadline && microtime(true) > $deadline) {
        return false;
      }
      if (!$f->isFile() || !preg_match('/\.(jpe?g|png)$/i', $f->getFilename())) {
        continue;
      }
      $path = wp_normalize_path($f->getPathname());
      if (preg_match($skip, '/' . substr($path, strlen($base)))) {
        continue;
      }
      $cb($path, $f->getSize());
    }
    return true;
  }

  public static function webp_rule_present() {
    $ht = self::uploads_base() . '.htaccess';
    return is_file($ht) && strpos((string) file_get_contents($ht), '# BEGIN WebNow WebP') !== false;
  }

  private static function scan_images() {
    $out = [];
    $engine = self::image_engine();
    $out[] = self::check('img_engine', 'Thư viện xử lý ảnh', $engine ? 'good' : 'bad', $engine ? ($engine === 'imagick' ? 'ImageMagick' : 'GD') . ' + WebP' : 'Không hỗ trợ WebP',
      $engine ? 'Máy chủ nén ảnh và tạo WebP được.' : 'PHP thiếu Imagick/GD có WebP nên không tự nén ảnh được.',
      ['manual' => $engine ? '' : 'Bật extension imagick (hoặc gd có webp) trong DirectAdmin → Select PHP version.']);

    // Ảnh đã thử mà không nhỏ hơn được thì coi như đã xử lý, không báo lại mãi.
    $compressed = WN_Optimize_Fixes::done_list('compressed');
    $webp_skip = WN_Optimize_Fixes::done_list('webp-skip');
    $base_len = strlen(self::uploads_base());
    $stat = ['total' => 0, 'bytes' => 0, 'webp' => 0, 'heavy' => 0, 'heavy_bytes' => 0];
    $complete = self::each_upload_image(function ($path, $size) use (&$stat, $compressed, $webp_skip, $base_len) {
      $rel = substr($path, $base_len);
      $stat['total']++;
      $stat['bytes'] += $size;
      if (is_file($path . '.webp') || isset($webp_skip[$rel])) {
        $stat['webp']++;
      }
      if ($size > self::HEAVY_IMG && ($compressed[$rel] ?? 0) !== $size) {
        $stat['heavy']++;
        $stat['heavy_bytes'] += $size;
      }
    }, microtime(true) + 20);
    $note = $complete ? '' : ' (thư viện quá lớn, mới quét một phần)';

    $pct = $stat['total'] ? round($stat['webp'] * 100 / $stat['total']) : 100;
    $soft = self::server_software();
    $out[] = self::check('img_webp', 'Ảnh có bản WebP', $pct >= 90 ? 'good' : ($pct >= 50 ? 'warn' : 'bad'),
      $pct . '% (' . number_format_i18n($stat['webp']) . '/' . number_format_i18n($stat['total']) . ')',
      'Tạo bản .webp đi kèm từng ảnh (giữ nguyên ảnh gốc, không đổi link trong bài). Trình duyệt hỗ trợ sẽ tự nhận WebP, nhẹ hơn 30-70%.' . $note,
      ['fix' => $engine && $stat['webp'] < $stat['total'] ? 'img_webp' : '', 'metric' => ['key' => 'img_webp', 'label' => 'Ảnh có WebP', 'value' => $pct, 'unit' => '%', 'lower' => false],
        'manual' => $soft['nginx'] ? 'Máy chủ Nginx không đọc .htaccess: gửi đoạn cấu hình bên dưới cho hosting để trả file .webp khi trình duyệt hỗ trợ.' : '',
        'snippet' => $soft['nginx'] ? self::nginx_snippet() : '']);

    $out[] = self::check('img_heavy', 'Ảnh nặng trên 300 KB', $stat['heavy'] > 20 ? 'warn' : 'good',
      number_format_i18n($stat['heavy']) . ' ảnh · ' . size_format($stat['heavy_bytes'], 1),
      'Nén lại chất lượng 82, bỏ metadata, giữ nguyên kích thước. Ảnh gốc được sao lưu trước khi ghi đè.',
      ['fix' => $engine && $stat['heavy'] ? 'img_compress' : '']);

    $rule = self::webp_rule_present();
    if ($stat['webp'] || $rule) {
      $out[] = self::check('webp_rule', 'Tự phục vụ WebP', $rule ? 'good' : 'warn', $rule ? 'Đã bật' : 'Chưa bật',
        $rule ? 'Rule trong uploads/.htaccess trả .webp cho trình duyệt hỗ trợ.' : 'Đã có file .webp nhưng máy chủ chưa trả về cho khách.',
        ['fix' => $rule || !$engine ? '' : 'img_webp']);
    }
    return $out;
  }

  /* Cron */

  public static function overdue_crons() {
    $crons = _get_cron_array();
    $count = 0;
    $oldest = 0;
    foreach ((array) $crons as $ts => $hooks) {
      if ($ts < time() - HOUR_IN_SECONDS) {
        $count += count($hooks);
        $oldest = $oldest ? min($oldest, $ts) : $ts;
      }
    }
    return ['count' => $count, 'oldest' => $oldest];
  }

  /**
   * PHP CLI cùng phiên bản với web. Cron chạy sai phiên bản sẽ chết âm thầm khi plugin đòi PHP mới
   * (vd. Contact Form 7 6.2 cần PHP 8.3 nhưng cron gọi PHP 8.2).
   */
  public static function php_cli_path() {
    $ver = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
    // LiteSpeed: bản CLI luôn nằm cạnh lsphp (alt-php CloudLinux, DirectAdmin). Không kiểm tra
    // is_file vì open_basedir thường chặn đọc ngoài thư mục home.
    if (defined('PHP_BINARY') && PHP_BINARY && basename(PHP_BINARY) === 'lsphp') {
      return dirname(PHP_BINARY) . '/php';
    }
    $candidates = [];
    if (defined('PHP_BINARY') && PHP_BINARY && preg_match('/php-cgi[\d.]*$/', PHP_BINARY)) {
      $candidates[] = dirname(PHP_BINARY) . '/php';
    }
    $candidates[] = "/opt/alt/php{$ver}/usr/bin/php";   // CloudLinux PHP Selector
    $candidates[] = "/usr/local/php{$ver}/bin/php";     // DirectAdmin
    $candidates[] = "/opt/cpanel/ea-php{$ver}/root/usr/bin/php"; // cPanel
    foreach ($candidates as $c) {
      if (@is_file($c)) {
        return $c;
      }
    }
    return (defined('PHP_BINARY') && PHP_BINARY && basename(PHP_BINARY) === 'php') ? PHP_BINARY : 'php';
  }

  public static function cron_command() {
    return '*/5 * * * * ' . self::php_cli_path() . ' -q ' . wp_normalize_path(ABSPATH) . 'wp-cron.php >/dev/null 2>&1';
  }

  private static function scan_cron() {
    $out = [];
    $over = self::overdue_crons();
    $disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
    $since = $over['oldest'] ? 'từ ' . wp_date('d/m/Y H:i', $over['oldest']) : '';
    $status = $over['count'] === 0 ? 'good' : ($over['oldest'] < time() - DAY_IN_SECONDS ? 'bad' : 'warn');
    $out[] = self::check('cron_overdue', 'Tác vụ nền quá hạn', $status,
      $over['count'] ? number_format_i18n($over['count']) . ' tác vụ' : 'Không có',
      $over['count'] ? 'Cron đã ngừng chạy ' . $since . ': không dọn dữ liệu tạm, không gửi email hẹn giờ, không hủy đơn treo...' : 'Các tác vụ nền đang chạy đúng giờ.',
      ['fix' => $over['count'] ? 'cron_run' : '', 'metric' => ['key' => 'cron_overdue', 'label' => 'Cron quá hạn', 'value' => $over['count'], 'unit' => '', 'lower' => true]]);

    $res = self::fetch(add_query_arg('doing_wp_cron', 'wn-check', site_url('wp-cron.php')), 'POST');
    // Hết thời gian chờ nghĩa là cron đang chạy, không phải bị chặn.
    $blocked = (!$res['ok'] && stripos((string) $res['error'], 'timed out') === false) || ($res['ok'] && $res['code'] >= 400);
    if ($disabled) {
      $out[] = self::check('cron_mode', 'Cách chạy cron', $over['count'] ? 'bad' : 'good', 'Cron hệ thống',
        $over['count'] ? 'DISABLE_WP_CRON đang bật nhưng cron hệ thống không chạy hoặc đang lỗi (hay gặp nhất: cron gọi PHP khác phiên bản với web, plugin mới đòi PHP cao hơn nên bị lỗi nghiêm trọng).' : 'WP-cron tắt, chạy bằng cron của máy chủ: ổn định nhất.',
        ['manual' => $over['count'] ? 'Kiểm tra DirectAdmin → Cron Jobs: dòng cron phải dùng đúng PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . ' như web.' : '', 'command' => $over['count'] ? self::cron_command() : '']);
    } elseif ($blocked) {
      $out[] = self::check('cron_mode', 'Cách chạy cron', 'bad', 'wp-cron.php bị chặn' . ($res['ok'] ? ' (' . $res['code'] . ')' : ''),
        'Máy chủ/tường lửa chặn wp-cron.php nên WP-cron không tự chạy được. Cần cron thật của máy chủ.',
        ['manual' => 'DirectAdmin → Cron Jobs, thêm dòng bên dưới; sau đó thêm define(\'DISABLE_WP_CRON\', true); vào wp-config.php.', 'command' => self::cron_command()]);
    } else {
      $out[] = self::check('cron_mode', 'Cách chạy cron', 'good', 'WP-cron',
        'WP-cron chạy theo lượt truy cập. Site đông khách nên chuyển sang cron hệ thống.', ['command' => self::cron_command()]);
    }
    return $out;
  }

  /* Bảo trì & an toàn */

  public static function wpconfig_path() {
    if (file_exists(ABSPATH . 'wp-config.php')) {
      return ABSPATH . 'wp-config.php';
    }
    $up = dirname(ABSPATH) . '/wp-config.php';
    return file_exists($up) ? $up : '';
  }

  /** File nén/SQL để lộ ở thư mục web có thể bị tải về. */
  public static function stray_files() {
    $dirs = [ABSPATH, WP_CONTENT_DIR . '/', WP_PLUGIN_DIR . '/', get_theme_root() . '/'];
    $files = [];
    foreach (array_unique(array_map('wp_normalize_path', $dirs)) as $dir) {
      foreach ((array) glob($dir . '*.{zip,tar,gz,tgz,rar,7z,sql,bak}', GLOB_BRACE) as $f) {
        if ($f && is_file($f)) {
          $files[] = wp_normalize_path($f);
        }
      }
    }
    return $files;
  }

  public static function update_blockers() {
    global $wp_filter;
    $names = [];
    foreach (['pre_site_transient_update_plugins', 'pre_site_transient_update_core', 'pre_site_transient_update_themes'] as $hook) {
      if (empty($wp_filter[$hook])) {
        continue;
      }
      foreach ($wp_filter[$hook]->callbacks as $cbs) {
        foreach ($cbs as $cb) {
          $names[] = self::callback_owner($cb['function']);
        }
      }
    }
    return array_values(array_unique(array_filter($names)));
  }

  private static function callback_owner($fn) {
    try {
      if (is_string($fn) && strpos($fn, '::') === false) {
        $ref = new ReflectionFunction($fn);
      } elseif (is_array($fn)) {
        $ref = new ReflectionMethod($fn[0], $fn[1]);
      } elseif ($fn instanceof Closure) {
        $ref = new ReflectionFunction($fn);
      } else {
        return 'không rõ';
      }
      $file = wp_normalize_path((string) $ref->getFileName());
      $plugins = wp_normalize_path(WP_PLUGIN_DIR) . '/';
      if (strpos($file, $plugins) === 0) {
        return strtok(substr($file, strlen($plugins)), '/');
      }
      return strpos($file, wp_normalize_path(ABSPATH . WPINC)) === 0 ? 'plugin dùng hàm có sẵn của WP' : basename(dirname($file));
    } catch (Throwable $e) {
      return 'không rõ';
    }
  }

  private static function scan_maintenance() {
    $out = [];
    $cfg = self::wpconfig_path();
    // Windows không có quyền kiểu Unix (file nào cũng báo 666).
    if ($cfg && DIRECTORY_SEPARATOR === '/') {
      $perm = fileperms($cfg) & 0777;
      $open = (bool) ($perm & 0002);
      $out[] = self::check('wpconfig_perms', 'Quyền file wp-config.php', $open ? 'bad' : 'good', sprintf('%o', $perm),
        $open ? 'Ai trên máy chủ cũng ghi được vào file chứa mật khẩu database.' : 'An toàn.',
        ['fix' => $open ? 'wpconfig_perms' : '']);
    }

    $stray = self::stray_files();
    $debug = WP_CONTENT_DIR . '/debug.log';
    $debug_mb = is_file($debug) ? filesize($debug) / 1048576 : 0;
    if ($stray || $debug_mb > 10) {
      $list = array_map(function ($f) {
        return str_replace(wp_normalize_path(ABSPATH), '', $f);
      }, $stray);
      if ($debug_mb > 10) {
        $list[] = 'wp-content/debug.log (' . number_format_i18n($debug_mb, 0) . ' MB)';
      }
      $out[] = self::check('stray', 'File sao lưu / log để lộ', 'bad', count($list) . ' file',
        'Ai biết tên file là tải được. Sẽ chuyển vào thư mục được bảo vệ (không xóa): ' . implode(', ', array_slice($list, 0, 6)) . (count($list) > 6 ? '...' : ''),
        ['fix' => 'clean_stray']);
    } else {
      $out[] = self::check('stray', 'File sao lưu / log để lộ', 'good', 'Không có', 'Không thấy file .zip/.sql để lộ trong thư mục web.');
    }

    $debug_on = defined('WP_DEBUG') && WP_DEBUG && (!defined('WP_DEBUG_DISPLAY') || WP_DEBUG_DISPLAY);
    if ($debug_on) {
      $out[] = self::check('debug', 'Chế độ debug', 'warn', 'Đang bật', 'Lỗi PHP có thể hiện ra cho khách thấy và làm chậm trang.',
        ['manual' => 'Đặt define(\'WP_DEBUG\', false); trong wp-config.php.']);
    }

    $blockers = self::update_blockers();
    $data = function_exists('wp_get_update_data') ? wp_get_update_data() : ['counts' => ['plugins' => 0, 'themes' => 0, 'wordpress' => 0]];
    $n = (int) $data['counts']['plugins'] + (int) $data['counts']['themes'] + (int) $data['counts']['wordpress'];
    $out[] = self::check('updates', 'Cập nhật', $blockers ? 'info' : ($n ? 'warn' : 'good'),
      $blockers ? 'Đang bị chặn kiểm tra' : ($n ? $n . ' bản cập nhật' : 'Mới nhất'),
      $blockers ? 'Có code chặn WordPress kiểm tra cập nhật (' . implode(', ', $blockers) . '). Nên định kỳ cập nhật bằng WP-CLI để nhận bản vá bảo mật.' : ($n ? 'Cập nhật thường kèm cải thiện tốc độ và bản vá bảo mật.' : ''),
      ['manual' => $n && !$blockers ? 'Bảng tin → Cập nhật (sao lưu trước khi cập nhật bản lớn).' : '']);

    $t = self::tweaks();
    $todo = array_diff(['revisions', 'heartbeat', 'emoji'], array_keys(array_filter($t)));
    $out[] = self::check('tweaks', 'Tinh chỉnh nhẹ', $todo ? 'warn' : 'good', $todo ? count($todo) . '/3 chưa bật' : 'Đã bật',
      'Giới hạn ' . self::REVISIONS_KEEP . ' revision/bài, tắt heartbeat ngoài trang quản trị, bỏ script emoji.',
      ['fix' => $todo ? 'tweaks' : '']);
    return $out;
  }
}

/** is_plugin_active() chỉ có sẵn trong admin. */
function wn_plugin_active($plugin) {
  return in_array($plugin, (array) get_option('active_plugins', []), true);
}

require_once WN_PLUGIN_DIR . '/includes/optimize-fixes.php';

if (wn_module_on('optimize')) {
  WN_Optimize::apply_runtime_tweaks();
  if (is_admin()) {
    new WN_Optimize();
  }
}
