<?php
/**
 * Trang quản trị "WebNow" (menu riêng ở thanh bên trái admin).
 * Tab Tính năng: bật/tắt lưu ngay bằng AJAX. Các tab còn lại: form thường, lưu qua admin-post.
 */

define('WN_ADMIN_PAGE', 'wn-tien-ich');

function wn_admin_url($tab = 'modules', $args = []) {
  return add_query_arg(array_merge(['page' => WN_ADMIN_PAGE, 'tab' => $tab], $args), admin_url('admin.php'));
}

function wn_admin_tabs() {
  return [
    'modules' => ['label' => 'Tính năng', 'icon' => 'dashicons-screenoptions'],
    'search' => ['label' => 'Tìm kiếm', 'icon' => 'dashicons-search'],
    'contact' => ['label' => 'Nút liên hệ', 'icon' => 'dashicons-phone'],
    'tet' => ['label' => 'Trang trí Tết', 'icon' => 'dashicons-star-filled'],
    'preload' => ['label' => 'Màn hình chờ', 'icon' => 'dashicons-update'],
  ];
}

add_action('admin_menu', function () {
  $icon = 'data:image/svg+xml;base64,' . base64_encode(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M3 3h3.2l7.6 9.6V3H17v14h-3.2L6.2 7.4V17H3z"/></svg>'
  );
  add_menu_page('Tiện ích WebNow', 'WebNow', 'manage_options', WN_ADMIN_PAGE, 'wn_render_admin_page', $icon, 3);
});

// Link cũ (Cài đặt > Cài đặt WebNow) chuyển về trang mới.
add_action('admin_init', function () {
  global $pagenow;
  if ($pagenow === 'options-general.php' && ($_GET['page'] ?? '') === WN_PLUGIN_SLUG) {
    wp_safe_redirect(wn_admin_url());
    exit;
  }
});

add_filter('plugin_action_links_' . WN_PLUGIN_BASENAME, function ($links) {
  array_unshift($links, '<a href="' . esc_url(wn_admin_url()) . '">Cài đặt</a>');
  return $links;
});

add_action('admin_enqueue_scripts', function ($hook) {
  if ($hook !== 'toplevel_page_' . WN_ADMIN_PAGE) {
    return;
  }
  $ver = filemtime(WN_PLUGIN_DIR . '/includes/admin/admin.css');
  wp_enqueue_style('wn-admin', wn_plugin_url('/includes/admin/admin.css'), [], $ver);
  wp_enqueue_script('wn-admin', wn_plugin_url('/includes/admin/admin.js'), ['jquery', 'wp-color-picker'], $ver, true);
  wp_enqueue_style('wp-color-picker');
  wp_enqueue_media();
  wp_localize_script('wn-admin', 'WN_ADMIN', [
    'ajax' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('wn_toggle_module'),
  ]);
});

/* ---------- Lưu ---------- */

add_action('wp_ajax_wn_toggle_module', function () {
  check_ajax_referer('wn_toggle_module');
  if (!current_user_can('manage_options')) {
    wp_send_json_error('Không có quyền', 403);
  }
  $key = sanitize_key($_POST['module'] ?? '');
  if (!isset(wn_modules()[$key])) {
    wp_send_json_error('Tính năng không tồn tại', 400);
  }
  $on = ($_POST['on'] ?? '') === '1';
  update_option('wn_module_' . $key, $on ? '1' : '0');
  if ($key === 'search' && $on && !file_exists(wn_search_index_path())) {
    wn_search_build_index();
  }
  if (in_array($key, ['search', 'tet', 'preload', 'fontawesome'], true)) {
    wn_clear_page_cache();
  }
  wp_send_json_success(['module' => $key, 'on' => $on, 'title' => wn_modules()[$key]['title']]);
});

add_action('admin_post_wn_save_settings', function () {
  if (!current_user_can('manage_options')) {
    wp_die('Không có quyền');
  }
  check_admin_referer('wn_save_settings');
  $tab = sanitize_key($_POST['tab'] ?? '');
  $args = ['saved' => 1];

  if ($tab === 'search') {
    $allowed = ['post', 'page', 'product'];
    $types = array_values(array_intersect($allowed, array_map('sanitize_key', (array) ($_POST['wn_search_post_types'] ?? []))));
    $types = $types ?: ['post'];
    $before = wn_search_post_types();
    update_option('wn_search_post_types', $types);
    update_option('wn_search_limit', max(1, min(50, (int) ($_POST['wn_search_limit'] ?? 10))));
    if ($types != $before || !empty($_POST['rebuild'])) {
      $args['rebuilt'] = wn_search_build_index();
    }
  } elseif ($tab === 'preload') {
    update_option(WN_PLUGIN_SLUG . '_logo_svg', (int) ($_POST['logo_svg'] ?? 0));
    update_option(WN_PLUGIN_SLUG . '_dashoffset', max(0, (int) ($_POST['dashoffset'] ?? 1000)));
    update_option(WN_PLUGIN_SLUG . '_background', sanitize_hex_color($_POST['background'] ?? '') ?: '');
  } elseif ($tab === 'tet') {
    wn_tet_save_settings($_POST);
  } elseif ($tab === 'contact') {
    wn_contact_save_settings($_POST);
  }
  wn_clear_page_cache();

  wp_safe_redirect(wn_admin_url($tab ?: 'modules', $args));
  exit;
});

/* ---------- Giao diện ---------- */

function wn_render_admin_page() {
  $tabs = wn_admin_tabs();
  $tab = sanitize_key($_GET['tab'] ?? 'modules');
  if (!isset($tabs[$tab])) {
    $tab = 'modules';
  }
  $on_count = count(array_filter(array_keys(wn_modules()), 'wn_module_on'));
  $plugin = get_file_data(WN_PLUGIN, ['Version' => 'Version']);
  ?>
  <div class="wrap wn-wrap">
    <h1 class="screen-reader-text">Tiện ích WebNow</h1>
    <div class="wn-hero">
      <div class="wn-hero-brand">
        <span class="wn-hero-logo">N</span>
        <div>
          <div class="wn-hero-title">Tiện ích WebNow <span class="wn-ver">v<?php echo esc_html($plugin['Version']); ?></span></div>
          <div class="wn-hero-sub">Gom các tính năng nhỏ vào một chỗ — bớt plugin, web nhẹ hơn.</div>
        </div>
      </div>
      <div class="wn-hero-stat"><strong><?php echo $on_count; ?></strong>/<?php echo count(wn_modules()); ?> tính năng đang bật</div>
    </div>

    <nav class="wn-tabs">
      <?php foreach ($tabs as $key => $t) :
        $mod = wn_modules()[$key] ?? null; ?>
        <a href="<?php echo esc_url(wn_admin_url($key)); ?>" class="wn-tab <?php echo $key === $tab ? 'is-active' : ''; ?>">
          <span class="dashicons <?php echo esc_attr($t['icon']); ?>"></span><?php echo esc_html($t['label']); ?>
          <?php if ($mod) : ?><i class="wn-dot <?php echo wn_module_on($key) ? 'is-on' : ''; ?>" data-dot="<?php echo esc_attr($key); ?>"></i><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <?php if (isset($_GET['saved'])) : ?>
      <div class="wn-alert is-success"><span class="dashicons dashicons-yes-alt"></span> Đã lưu thay đổi<?php
        if (isset($_GET['rebuilt'])) {
          echo ' và dựng lại chỉ mục tìm kiếm (' . (int) $_GET['rebuilt'] . ' mục)';
        } ?>.</div>
    <?php endif; ?>

    <?php call_user_func('wn_render_tab_' . $tab); ?>
  </div>
  <div class="wn-toast" id="wn-toast" role="status" aria-live="polite"></div>
  <?php
}

/** Công tắc bật/tắt dùng chung. */
function wn_switch($key) {
  $on = wn_module_on($key);
  printf(
    '<label class="wn-switch"><input type="checkbox" data-module="%s" %s><span></span><em class="screen-reader-text">%s</em></label>',
    esc_attr($key),
    checked($on, true, false),
    esc_html(wn_modules()[$key]['title'])
  );
}

/** Thanh trạng thái ở đầu các tab có tính năng tương ứng. */
function wn_module_bar($key) {
  $m = wn_modules()[$key];
  ?>
  <div class="wn-modbar <?php echo wn_module_on($key) ? 'is-on' : ''; ?>" data-card="<?php echo esc_attr($key); ?>">
    <span class="wn-card-icon dashicons <?php echo esc_attr($m['icon']); ?>"></span>
    <div class="wn-modbar-text">
      <strong><?php echo esc_html($m['title']); ?></strong>
      <span class="wn-state-on">Đang bật trên website</span><span class="wn-state-off">Đang tắt — cài đặt bên dưới chưa có hiệu lực</span>
    </div>
    <?php wn_switch($key); ?>
  </div>
  <?php
}

function wn_render_tab_modules() {
  ?>
  <div class="wn-grid">
    <?php foreach (wn_modules() as $key => $m) : ?>
      <div class="wn-card <?php echo wn_module_on($key) ? 'is-on' : ''; ?>" data-card="<?php echo esc_attr($key); ?>">
        <span class="wn-card-icon dashicons <?php echo esc_attr($m['icon']); ?>"></span>
        <div class="wn-card-body">
          <div class="wn-card-head">
            <h3><?php echo esc_html($m['title']); ?></h3>
            <?php wn_switch($key); ?>
          </div>
          <p><?php echo esc_html($m['desc']); ?></p>
          <?php if (!empty($m['warn'])) : ?><p class="wn-warn"><span class="dashicons dashicons-info-outline"></span><?php echo esc_html($m['warn']); ?></p><?php endif; ?>
          <?php if (!empty($m['tab'])) : ?><a class="wn-card-link" href="<?php echo esc_url(wn_admin_url($m['tab'])); ?>">Cài đặt <span aria-hidden="true">→</span></a><?php endif; ?>
          <?php if (!empty($m['page']) && wn_module_on($key)) : ?><a class="wn-card-link" href="<?php echo esc_url(admin_url($m['page'])); ?>">Mở công cụ <span aria-hidden="true">→</span></a><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php
}

function wn_settings_form_open($tab) {
  echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wn-panel">';
  echo '<input type="hidden" name="action" value="wn_save_settings"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">';
  wp_nonce_field('wn_save_settings');
}

function wn_render_tab_search() {
  wn_module_bar('search');
  $path = wn_search_index_path();
  $exists = file_exists($path);
  $count = $exists ? count(json_decode(file_get_contents($path), true) ?: []) : 0;
  $selected = wn_search_post_types();
  ?>
  <div class="wn-stats">
    <div class="wn-stat"><span>Số mục trong chỉ mục</span><strong><?php echo $exists ? number_format_i18n($count) : '—'; ?></strong></div>
    <div class="wn-stat"><span>Dung lượng</span><strong><?php echo $exists ? size_format(filesize($path), 1) : '—'; ?></strong></div>
    <div class="wn-stat"><span>Cập nhật lần cuối</span><strong><?php echo $exists ? esc_html(human_time_diff(filemtime($path)) . ' trước') : 'Chưa có'; ?></strong></div>
  </div>

  <?php wn_settings_form_open('search'); ?>
    <div class="wn-field">
      <div class="wn-field-label">Tìm trong<small>Chọn loại nội dung hiện trong kết quả. Đổi xong sẽ tự dựng lại chỉ mục.</small></div>
      <div class="wn-chips">
        <?php foreach (['post' => ['Bài viết', 'dashicons-admin-post'], 'page' => ['Trang', 'dashicons-admin-page'], 'product' => ['Sản phẩm', 'dashicons-cart']] as $type => $info) :
          if ($type === 'product' && !post_type_exists('product')) {
            continue;
          }
          $n = wp_count_posts($type)->publish ?? 0; ?>
          <label class="wn-chip">
            <input type="checkbox" name="wn_search_post_types[]" value="<?php echo esc_attr($type); ?>" <?php checked(in_array($type, $selected, true)); ?>>
            <span><i class="dashicons <?php echo esc_attr($info[1]); ?>"></i><?php echo esc_html($info[0]); ?> <em><?php echo number_format_i18n($n); ?></em></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="wn-field">
      <label class="wn-field-label" for="wn-limit">Số kết quả tối đa<small>Cho mỗi loại nội dung.</small></label>
      <input id="wn-limit" type="number" name="wn_search_limit" min="1" max="50" value="<?php echo (int) get_option('wn_search_limit', 10); ?>" class="wn-input wn-input-sm">
    </div>
    <div class="wn-actions">
      <button type="submit" class="wn-btn wn-btn-primary">Lưu thay đổi</button>
      <button type="submit" name="rebuild" value="1" class="wn-btn"><span class="dashicons dashicons-image-rotate"></span> Dựng lại chỉ mục</button>
    </div>
  </form>
  <?php
}

function wn_render_tab_contact() {
  wn_module_bar('contact');
  wn_settings_form_open('contact');
  wn_contact_render_settings();
  echo '<div class="wn-actions"><button type="submit" class="wn-btn wn-btn-primary">Lưu thay đổi</button></div></form>';
}

function wn_render_tab_tet() {
  wn_module_bar('tet');
  wn_settings_form_open('tet');
  wn_tet_render_settings();
  echo '<div class="wn-actions"><button type="submit" class="wn-btn wn-btn-primary">Lưu thay đổi</button></div></form>';
}

function wn_render_tab_preload() {
  wn_module_bar('preload');
  $logo_id = (int) get_option(WN_PLUGIN_SLUG . '_logo_svg');
  $logo_url = $logo_id ? wp_get_attachment_url($logo_id) : '';
  $bg = get_option(WN_PLUGIN_SLUG . '_background') ?: '#111111';
  wn_settings_form_open('preload');
  ?>
    <div class="wn-field">
      <div class="wn-field-label">Logo SVG<small>Logo dạng nét (path) để có hiệu ứng vẽ.</small></div>
      <div class="wn-media">
        <div class="wn-media-preview" style="background:<?php echo esc_attr($bg); ?>">
          <img src="<?php echo esc_url($logo_url); ?>" alt="" <?php echo $logo_url ? '' : 'hidden'; ?>>
          <span <?php echo $logo_url ? 'hidden' : ''; ?>>Chưa chọn logo</span>
        </div>
        <input type="hidden" name="logo_svg" value="<?php echo $logo_id ?: ''; ?>">
        <button type="button" class="wn-btn" data-media-pick>Chọn logo</button>
      </div>
    </div>
    <div class="wn-field">
      <label class="wn-field-label" for="wn-dash">Độ dài nét<small>stroke-dashoffset: tăng nếu nét vẽ bị đứt sớm.</small></label>
      <input id="wn-dash" type="number" name="dashoffset" min="0" value="<?php echo (int) get_option(WN_PLUGIN_SLUG . '_dashoffset', 1000); ?>" class="wn-input wn-input-sm">
    </div>
    <div class="wn-field">
      <div class="wn-field-label">Màu nền</div>
      <input type="text" name="background" value="<?php echo esc_attr($bg); ?>" class="wn-color">
    </div>
    <div class="wn-actions"><button type="submit" class="wn-btn wn-btn-primary">Lưu thay đổi</button></div>
  </form>
  <?php
}
