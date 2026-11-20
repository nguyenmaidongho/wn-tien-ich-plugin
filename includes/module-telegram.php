<?php
/**
 * Thông báo Telegram — gửi tin qua bot khi có đơn hàng, bình luận, đánh giá, liên hệ...
 * Cấu hình: option `wn_tg_token`, `wn_tg_chats` (nhiều chat cách nhau dấu phẩy/xuống dòng),
 * `wn_tg_events` (mảng các sự kiện muốn nhận). Tin được gom lại và gửi ở cuối request
 * để không làm chậm lúc khách bấm đặt hàng/gửi bình luận.
 */

function wn_tg_events() {
  $events = [
    'order_new' => ['label' => 'Đơn hàng mới', 'icon' => 'dashicons-cart', 'default' => '1', 'needs' => 'woocommerce'],
    'order_status' => ['label' => 'Đơn đổi trạng thái', 'icon' => 'dashicons-update', 'default' => '0', 'needs' => 'woocommerce', 'hint' => 'Hủy, hoàn tiền, hoàn thành...'],
    'review' => ['label' => 'Đánh giá sản phẩm', 'icon' => 'dashicons-star-half', 'default' => '1', 'needs' => 'woocommerce'],
    'comment' => ['label' => 'Bình luận bài viết', 'icon' => 'dashicons-admin-comments', 'default' => '1'],
    'contact' => ['label' => 'Form liên hệ', 'icon' => 'dashicons-email-alt', 'default' => '1', 'needs' => 'cf7', 'hint' => 'Contact Form 7'],
    'user' => ['label' => 'Thành viên mới', 'icon' => 'dashicons-admin-users', 'default' => '0'],
  ];
  foreach ($events as $key => $e) {
    $needs = $e['needs'] ?? '';
    $events[$key]['available'] = !$needs
      || ($needs === 'woocommerce' && class_exists('WooCommerce'))
      || ($needs === 'cf7' && defined('WPCF7_VERSION'));
  }
  return $events;
}

function wn_tg_event_on($key) {
  $saved = get_option('wn_tg_events', null);
  if (!is_array($saved)) {
    return (wn_tg_events()[$key]['default'] ?? '0') === '1';
  }
  return in_array($key, $saved, true);
}

function wn_tg_chat_ids($raw = null) {
  $raw = $raw ?? get_option('wn_tg_chats', '');
  return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) $raw))));
}

function wn_tg_esc($text) {
  $text = html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  return htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
}

/** Gọi Bot API. Trả về mảng kết quả hoặc WP_Error. */
function wn_tg_api($token, $method, $body = []) {
  $res = wp_remote_post('https://api.telegram.org/bot' . $token . '/' . $method, [
    'timeout' => 8,
    'body' => $body,
  ]);
  if (is_wp_error($res)) {
    return $res;
  }
  $data = json_decode(wp_remote_retrieve_body($res), true);
  if (empty($data['ok'])) {
    return new WP_Error('wn_tg', $data['description'] ?? ('HTTP ' . wp_remote_retrieve_response_code($res)));
  }
  return $data['result'];
}

/** Gửi ngay một tin tới mọi chat. Trả về true hoặc WP_Error đầu tiên gặp phải. */
function wn_tg_send_now($text, $token = null, $chats = null) {
  $token = $token ?? get_option('wn_tg_token', '');
  $chats = $chats ?? wn_tg_chat_ids();
  if (!$token || !$chats) {
    return new WP_Error('wn_tg', 'Chưa nhập Bot token hoặc Chat ID');
  }
  if (mb_strlen($text) > 4000) {
    $text = mb_substr($text, 0, 4000) . '…';
  }
  $error = true;
  foreach ($chats as $chat) {
    $r = wn_tg_api($token, 'sendMessage', [
      'chat_id' => $chat,
      'text' => $text,
      'parse_mode' => 'HTML',
      'disable_web_page_preview' => 'true',
    ]);
    if (is_wp_error($r) && $error === true) {
      $error = new WP_Error('wn_tg', 'Chat ' . $chat . ': ' . $r->get_error_message());
    }
  }
  return $error;
}

/** Xếp tin vào hàng đợi, gửi khi request kết thúc (sau khi đã trả trang cho khách nếu được). */
function wn_tg_queue($event, $text) {
  static $queue = null;
  if (!wn_module_on('telegram') || !wn_tg_event_on($event)) {
    return;
  }
  if ($queue === null) {
    $queue = [];
    add_action('shutdown', function () use (&$queue) {
      if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
      } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
      }
      $msgs = $queue;
      $queue = [];
      foreach ($msgs as $msg) {
        wn_tg_send_now($msg);
      }
    }, 999);
  }
  $queue[] = $text;
}

function wn_tg_site() {
  return wn_tg_esc(wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
}

function wn_tg_lines($title, $rows, $link = '', $link_label = 'Xem chi tiết') {
  $out = '<b>' . $title . '</b> · ' . wn_tg_site() . "\n";
  foreach ($rows as $label => $value) {
    if ($value === '' || $value === null) {
      continue;
    }
    $out .= "\n" . (is_int($label) ? $value : '<b>' . $label . ':</b> ' . $value);
  }
  if ($link) {
    $out .= "\n\n" . '<a href="' . esc_attr(esc_url_raw($link)) . '">' . $link_label . '</a>';
  }
  return $out;
}

function wn_tg_money($amount, $order) {
  return wn_tg_esc(wc_price($amount, ['currency' => $order->get_currency()]));
}

/* ---------- Đơn hàng ---------- */

function wn_tg_order_new($order) {
  $order = is_numeric($order) ? wc_get_order($order) : $order;
  if (!$order || $order->get_meta('_wn_tg_sent')) {
    return;
  }
  $order->update_meta_data('_wn_tg_sent', time());
  $order->save_meta_data();

  $items = [];
  foreach ($order->get_items() as $item) {
    $items[] = '• ' . wn_tg_esc($item->get_name()) . ' × ' . (int) $item->get_quantity();
  }
  $address = array_filter([$order->get_billing_address_1(), $order->get_billing_city(), $order->get_billing_state()]);
  wn_tg_queue('order_new', wn_tg_lines('🛒 Đơn hàng mới #' . $order->get_order_number(), [
    'Khách' => wn_tg_esc($order->get_formatted_billing_full_name()),
    'SĐT' => wn_tg_esc($order->get_billing_phone()),
    'Email' => wn_tg_esc($order->get_billing_email()),
    'Địa chỉ' => wn_tg_esc(implode(', ', $address)),
    'Sản phẩm' => $items ? "\n" . implode("\n", $items) : '',
    'Tổng' => wn_tg_money($order->get_total(), $order),
    'Thanh toán' => wn_tg_esc($order->get_payment_method_title()),
    'Ghi chú' => wn_tg_esc($order->get_customer_note()),
  ], $order->get_edit_order_url(), 'Mở đơn hàng'));
}
add_action('woocommerce_checkout_order_processed', 'wn_tg_order_new', 20);
add_action('woocommerce_store_api_checkout_order_processed', 'wn_tg_order_new', 20);

add_action('woocommerce_order_status_changed', function ($id, $from, $to, $order) {
  if ($from === 'pending' && in_array($to, ['processing', 'on-hold'], true)) {
    return; // Bước tự động ngay sau khi đặt hàng, đã báo bằng tin "Đơn hàng mới".
  }
  wn_tg_queue('order_status', wn_tg_lines('🔄 Đơn #' . $order->get_order_number() . ' đổi trạng thái', [
    'Trạng thái' => wn_tg_esc(wc_get_order_status_name($from)) . ' → <b>' . wn_tg_esc(wc_get_order_status_name($to)) . '</b>',
    'Khách' => wn_tg_esc($order->get_formatted_billing_full_name()),
    'Tổng' => wn_tg_money($order->get_total(), $order),
  ], $order->get_edit_order_url(), 'Mở đơn hàng'));
}, 20, 4);

/* ---------- Bình luận & đánh giá ---------- */

add_action('comment_post', function ($comment_id, $approved) {
  if ($approved === 'spam' || $approved === 'trash') {
    return;
  }
  $c = get_comment($comment_id);
  if (!$c || !in_array($c->comment_type, ['', 'comment', 'review'], true)) {
    return;
  }
  $post = get_post($c->comment_post_ID);
  $is_review = $c->comment_type === 'review' || ($post && $post->post_type === 'product');
  $rows = [
    ($is_review ? 'Sản phẩm' : 'Bài viết') => $post ? '<a href="' . esc_attr(esc_url_raw(get_permalink($post))) . '">' . wn_tg_esc(get_the_title($post)) . '</a>' : '',
    'Người gửi' => wn_tg_esc($c->comment_author) . ($c->comment_author_email ? ' (' . wn_tg_esc($c->comment_author_email) . ')' : ''),
  ];
  $rating = (int) get_comment_meta($comment_id, 'rating', true);
  if ($rating) {
    $rows['Đánh giá'] = str_repeat('⭐', min(5, $rating));
  }
  $rows[] = '<i>' . wn_tg_esc(wp_trim_words($c->comment_content, 80)) . '</i>';
  if (!$approved) {
    $rows[] = '⏳ Đang chờ duyệt';
  }
  wn_tg_queue(
    $is_review ? 'review' : 'comment',
    wn_tg_lines($is_review ? '⭐ Đánh giá mới' : '💬 Bình luận mới', $rows, admin_url('comment.php?action=editcomment&c=' . $comment_id), 'Duyệt / trả lời')
  );
}, 20, 2);

/* ---------- Contact Form 7 ---------- */

function wn_tg_cf7($form) {
  $sub = class_exists('WPCF7_Submission') ? WPCF7_Submission::get_instance() : null;
  if (!$sub) {
    return;
  }
  $rows = [];
  foreach ($sub->get_posted_data() as $name => $value) {
    if ($name[0] === '_' || $value === '' || $value === []) {
      continue;
    }
    $value = is_array($value) ? implode(', ', $value) : $value;
    $rows[wn_tg_esc($name)] = wn_tg_esc(wp_html_excerpt($value, 600, '…'));
  }
  $rows['Trang'] = wn_tg_esc($sub->get_meta('url'));
  wn_tg_queue('contact', wn_tg_lines('📩 Liên hệ mới: ' . wn_tg_esc($form->title()), $rows));
}
add_action('wpcf7_mail_sent', 'wn_tg_cf7');
// Máy chủ gửi email lỗi thì khách vẫn đã gửi form: vẫn báo để không sót liên hệ.
add_action('wpcf7_mail_failed', 'wn_tg_cf7');

/* ---------- Thành viên ---------- */

add_action('user_register', function ($user_id) {
  $u = get_userdata($user_id);
  if (!$u) {
    return;
  }
  wn_tg_queue('user', wn_tg_lines('👤 Thành viên mới', [
    'Tên' => wn_tg_esc($u->display_name),
    'Email' => wn_tg_esc($u->user_email),
    'Vai trò' => wn_tg_esc(implode(', ', $u->roles)),
  ], admin_url('user-edit.php?user_id=' . $user_id), 'Xem tài khoản'));
});

/* ---------- Quản trị ---------- */

function wn_tg_save_settings($post) {
  $token = trim(sanitize_text_field(wp_unslash($post['wn_tg_token'] ?? '')));
  if ($token !== '' && strpos($token, '•') === false) {
    update_option('wn_tg_token', $token, false);
  }
  update_option('wn_tg_chats', implode("\n", wn_tg_chat_ids(sanitize_textarea_field(wp_unslash($post['wn_tg_chats'] ?? '')))), false);
  $events = array_values(array_intersect(array_keys(wn_tg_events()), array_map('sanitize_key', (array) ($post['wn_tg_events'] ?? []))));
  update_option('wn_tg_events', $events, false);
}

/** Token đang dùng: lấy từ form nếu vừa gõ mới, không thì lấy bản đã lưu. */
function wn_tg_request_token() {
  $token = trim(sanitize_text_field(wp_unslash($_POST['token'] ?? '')));
  return ($token === '' || strpos($token, '•') !== false) ? get_option('wn_tg_token', '') : $token;
}

add_action('wp_ajax_wn_tg_test', function () {
  check_ajax_referer('wn_toggle_module');
  if (!current_user_can('manage_options')) {
    wp_send_json_error('Không có quyền', 403);
  }
  $chats = wn_tg_chat_ids(sanitize_textarea_field(wp_unslash($_POST['chats'] ?? '')));
  $user = wp_get_current_user();
  $r = wn_tg_send_now(wn_tg_lines('✅ Tin nhắn thử', [
    'Kết nối Telegram thành công. Từ giờ thông báo sẽ gửi về đây.',
    'Người gửi thử' => wn_tg_esc($user->display_name),
  ], home_url('/'), 'Mở website'), wn_tg_request_token(), $chats);
  is_wp_error($r) ? wp_send_json_error($r->get_error_message()) : wp_send_json_success('Đã gửi tin thử, kiểm tra Telegram nhé');
});

add_action('wp_ajax_wn_tg_find_chats', function () {
  check_ajax_referer('wn_toggle_module');
  if (!current_user_can('manage_options')) {
    wp_send_json_error('Không có quyền', 403);
  }
  $token = wn_tg_request_token();
  if (!$token) {
    wp_send_json_error('Nhập Bot token trước');
  }
  $updates = wn_tg_api($token, 'getUpdates', ['limit' => 100]);
  if (is_wp_error($updates)) {
    wp_send_json_error($updates->get_error_message());
  }
  $chats = [];
  foreach ($updates as $u) {
    $msg = $u['message'] ?? $u['channel_post'] ?? $u['my_chat_member'] ?? null;
    if (!empty($msg['chat']['id'])) {
      $chat = $msg['chat'];
      $name = $chat['title'] ?? trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''));
      $chats[(string) $chat['id']] = ['id' => (string) $chat['id'], 'name' => $name ?: ($chat['username'] ?? ''), 'type' => $chat['type'] ?? ''];
    }
  }
  if (!$chats) {
    wp_send_json_error('Chưa thấy ai nhắn bot. Mở bot trên Telegram, bấm Start (hoặc thêm bot vào nhóm rồi nhắn 1 câu), sau đó bấm lại.');
  }
  wp_send_json_success(array_values($chats));
});

function wn_tg_render_settings() {
  $token = get_option('wn_tg_token', '');
  $masked = $token ? substr($token, 0, 6) . str_repeat('•', 12) . substr($token, -4) : '';
  $ready = $token && wn_tg_chat_ids();
  ?>
  <div class="wn-field">
    <label class="wn-field-label" for="wn-tg-token">Bot token<small>Nhắn <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a> trên Telegram → gõ <code>/newbot</code> → đặt tên → copy token dán vào đây.</small></label>
    <div>
      <input id="wn-tg-token" type="text" name="wn_tg_token" value="<?php echo esc_attr($masked); ?>" placeholder="123456789:AAH..." class="wn-input" autocomplete="off" spellcheck="false">
      <?php if ($token) : ?><p class="wn-hint is-ok"><span class="dashicons dashicons-lock"></span>Đã lưu token. Để nguyên nếu không đổi.</p><?php endif; ?>
    </div>
  </div>
  <div class="wn-field">
    <label class="wn-field-label" for="wn-tg-chats">Gửi tới (Chat ID)<small>Mở bot vừa tạo, bấm <b>Start</b> (hoặc thêm bot vào nhóm và nhắn 1 câu), rồi bấm “Tìm Chat ID”. Nhiều người/nhóm thì mỗi ID một dòng.</small></label>
    <div>
      <textarea id="wn-tg-chats" name="wn_tg_chats" rows="3" class="wn-input" placeholder="123456789&#10;-1001234567890" spellcheck="false"><?php echo esc_textarea(get_option('wn_tg_chats', '')); ?></textarea>
      <div class="wn-actions wn-actions-tight">
        <button type="button" class="wn-btn" data-tg="find"><span class="dashicons dashicons-search"></span> Tìm Chat ID</button>
        <button type="button" class="wn-btn" data-tg="test"><span class="dashicons dashicons-format-chat"></span> Gửi tin thử</button>
      </div>
      <div class="wn-tg-found" hidden></div>
    </div>
  </div>
  <div class="wn-field">
    <div class="wn-field-label">Nhận thông báo khi<small>Tick những việc muốn được báo.</small></div>
    <div class="wn-chips">
      <?php foreach (wn_tg_events() as $key => $e) : ?>
        <label class="wn-chip<?php echo $e['available'] ? '' : ' is-disabled'; ?>" <?php echo $e['available'] ? '' : 'title="Chưa cài plugin cần thiết"'; ?>>
          <input type="checkbox" name="wn_tg_events[]" value="<?php echo esc_attr($key); ?>" <?php checked(wn_tg_event_on($key)); ?> <?php disabled(!$e['available']); ?>>
          <span><i class="dashicons <?php echo esc_attr($e['icon']); ?>"></i><?php echo esc_html($e['label']); ?><?php if (!empty($e['hint'])) : ?> <em><?php echo esc_html($e['hint']); ?></em><?php endif; ?></span>
        </label>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if (!$ready) : ?>
    <p class="wn-hint is-off"><span class="dashicons dashicons-info-outline"></span>Chưa đủ token và Chat ID — chưa gửi được thông báo.</p>
  <?php endif;
}
