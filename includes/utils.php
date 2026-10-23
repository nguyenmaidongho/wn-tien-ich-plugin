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
