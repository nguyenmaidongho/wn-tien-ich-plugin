<?php

// Cho phép tải file SVG (dùng cho logo màn hình chờ).
add_filter('wp_check_filetype_and_ext', function ($data, $file, $filename, $mimes) {
  if (!$data['type']) {
    $check = wp_check_filetype($filename, $mimes);
    $ext = $check['ext'];
    $type = $check['type'];
    if ($type && 0 === strpos($type, 'image/') && 'svg' !== $ext) {
      $ext = false;
      $type = false;
    }
    $data = ['ext' => $ext, 'type' => $type, 'proper_filename' => $filename];
  }
  return $data;
}, 10, 4);

// Trang đăng nhập thương hiệu
add_action('login_head', function () {
  if (!wn_module_on('login_branding')) {
    return;
  }
  remove_action('login_head', 'wp_shake_js', 12);
  echo '<link rel="stylesheet" type="text/css" href="' . esc_url(wn_plugin_url('/css/login.css')) . '" />';
});

add_filter('login_headerurl', function ($url) {
  return wn_module_on('login_branding') ? 'https://webnow.vn' : $url;
});

add_filter('login_headertext', function ($text) {
  return wn_module_on('login_branding') ? 'WebNow.VN - Nay Code Mai Giao' : $text;
});

function wn_add_favicon() {
  if (wn_module_on('login_branding')) {
    echo '<link rel="shortcut icon" href="' . esc_url(wn_plugin_url('/images/admin-favicon.png')) . '" />';
  }
}
add_action('login_head', 'wn_add_favicon');
add_action('admin_head', 'wn_add_favicon');

add_action('admin_bar_menu', function ($wp_admin_bar) {
  if (wn_module_on('hide_wp_logo')) {
    $wp_admin_bar->remove_node('wp-logo');
  }
}, 999);

function wn_remove_core_updates() {
  global $wp_version;
  return (object) ['last_checked' => time(), 'version_checked' => $wp_version];
}
add_action('init', function () {
  if (wn_module_on('hide_core_updates')) {
    add_filter('pre_site_transient_update_core', 'wn_remove_core_updates');
    add_filter('pre_site_transient_update_plugins', 'wn_remove_core_updates');
    add_filter('pre_site_transient_update_themes', 'wn_remove_core_updates');
  }
});

add_filter('use_block_editor_for_post', function ($use) {
  return wn_module_on('classic_editor') ? false : $use;
});

add_action('admin_head', function () {
  if (wn_module_on('hide_flatsome_notice')) {
    echo '<style>div#flatsome-notice{display:none}</style>';
  }
});

add_action('admin_notices', function () {
  if (!wn_module_on('plugin_warning') || !current_user_can('activate_plugins')) {
    return;
  }
  $missing = [];
  if (!is_plugin_active('seo-by-rank-math/rank-math.php')) {
    $missing[] = '<a href="https://wordpress.org/plugins/seo-by-rank-math/">Rank Math SEO</a>';
  }
  if (!is_plugin_active('wp-fastest-cache/wpFastestCache.php')) {
    $missing[] = '<a href="https://wordpress.org/plugins/wp-fastest-cache/">WP Fastest Cache</a>';
  }
  if ($missing) {
    echo '<div class="notice notice-error"><p>Vui lòng cài đặt và kích hoạt plugin ' . implode(', ', $missing) . '.</p></div>';
  }
});

// Màn hình chờ: vẽ nét logo SVG rồi mờ dần khi trang tải xong (tối thiểu 3.5 giây).
add_action('wp_footer', function () {
  if (!wn_module_on('preload')) {
    return;
  }
  $logo_id = (int) get_option(WN_PLUGIN_SLUG . '_logo_svg');
  $path = $logo_id ? get_attached_file($logo_id) : '';
  if (!$path || !file_exists($path)) {
    return;
  }
  $dashoffset = (int) get_option(WN_PLUGIN_SLUG . '_dashoffset', 1000);
  $bg = sanitize_hex_color(get_option(WN_PLUGIN_SLUG . '_background')) ?: '#111111';
  ?>
  <div id="wn-preload"><?php echo file_get_contents($path); ?></div>
  <style>
    #wn-preload{background:<?php echo $bg; ?>;width:100vw;height:100vh;position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center}
    #wn-preload svg{width:40vw;max-width:360px}
    #wn-preload path{fill:transparent;stroke:#fff;stroke-width:1px;stroke-miterlimit:10;stroke-dasharray:<?php echo $dashoffset; ?>;stroke-dashoffset:<?php echo $dashoffset; ?>;animation:wnDraw 3.5s forwards}
    @keyframes wnDraw{80%{stroke-dashoffset:0;fill:transparent}100%{stroke-dashoffset:0;fill:#fff}}
  </style>
  <script>
    (function () {
      function hide() {
        var el = document.getElementById('wn-preload');
        if (!el) return;
        el.style.transition = 'opacity .4s';
        el.style.opacity = 0;
        setTimeout(function () { el.remove(); }, 400);
      }
      window.addEventListener('load', function () {
        var elapsed = performance.now() / 1000;
        setTimeout(hide, Math.max(0, 3.5 - elapsed) * 1000);
      });
    })();
  </script>
  <?php
});

require_once WN_PLUGIN_DIR . '/includes/module-search.php';
require_once WN_PLUGIN_DIR . '/includes/module-fontawesome.php';
require_once WN_PLUGIN_DIR . '/includes/module-tet.php';
require_once WN_PLUGIN_DIR . '/includes/module-contact.php';
require_once WN_PLUGIN_DIR . '/includes/module-webp.php';
require_once WN_PLUGIN_DIR . '/includes/module-cleanup.php';
