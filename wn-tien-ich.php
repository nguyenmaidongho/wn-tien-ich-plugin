<?php

/**
 * Plugin Name: Tiện ích WebNow
 * Plugin URI: https://webnow.vn/
 * Description: Gom các tiện ích nhỏ vào một plugin: tối ưu web tự động, tìm kiếm nhanh, trang trí Tết, màn hình chờ, Font Awesome, ảnh WebP, dọn ảnh không dùng, tùy biến admin/đăng nhập.
 * Version: 1.5
 * Author: Sai Gon Web Co., Ltd
 * Author URI: https://webnow.vn/
 * Text Domain: wn
 */

if (!defined('ABSPATH')) {
  die('Cút!');
}

define('WN_PLUGIN', __FILE__);
define('WN_PLUGIN_SLUG', basename(dirname(__FILE__)));
define('WN_PLUGIN_BASENAME', plugin_basename(WN_PLUGIN));
define('WN_PLUGIN_NAME', trim(dirname(WN_PLUGIN_BASENAME), '/'));
define('WN_PLUGIN_DIR', untrailingslashit(dirname(WN_PLUGIN)));

require_once WN_PLUGIN_DIR . '/includes/utils.php';
require_once WN_PLUGIN_DIR . '/includes/modules.php';
require_once WN_PLUGIN_DIR . '/includes/loader.php';
if (is_admin()) {
  require_once WN_PLUGIN_DIR . '/includes/admin/admin-page.php';
}
