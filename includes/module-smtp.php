<?php
/**
 * Gửi email qua SMTP — mọi email của WordPress (wp_mail: Contact Form 7, WooCommerce, quên mật khẩu...)
 * đi qua máy chủ SMTP đã nhập thay vì hàm mail() của hosting, để thư không rơi vào spam.
 * Cấu hình: option `wn_smtp` (mảng). Mật khẩu có thể đặt trong wp-config.php: define('WN_SMTP_PASS', '...');
 */

function wn_smtp_defaults() {
  return ['host' => '', 'port' => 587, 'secure' => 'tls', 'user' => '', 'pass' => '', 'from_email' => '', 'from_name' => ''];
}

function wn_smtp_settings() {
  $s = get_option('wn_smtp', []);
  $s = array_merge(wn_smtp_defaults(), is_array($s) ? $s : []);
  if (defined('WN_SMTP_PASS') && WN_SMTP_PASS) {
    $s['pass'] = (string) WN_SMTP_PASS;
  }
  return $s;
}

/** Đọc cài đặt từ form. Ô mật khẩu để trống hoặc còn dấu • nghĩa là giữ mật khẩu đã lưu. */
function wn_smtp_from_post($in) {
  $old = wn_smtp_settings();
  $pass = (string) wp_unslash($in['wn_smtp_pass'] ?? '');
  $secure = sanitize_key($in['wn_smtp_secure'] ?? 'tls');
  return [
    'host' => preg_replace('/[^a-z0-9.\-]/i', '', (string) wp_unslash($in['wn_smtp_host'] ?? '')),
    'port' => max(1, min(65535, (int) ($in['wn_smtp_port'] ?? 587))),
    'secure' => in_array($secure, ['tls', 'ssl', 'none'], true) ? $secure : 'tls',
    'user' => trim(sanitize_text_field(wp_unslash($in['wn_smtp_user'] ?? ''))),
    'pass' => ($pass === '' || strpos($pass, '•') !== false) ? $old['pass'] : $pass,
    'from_email' => sanitize_email(wp_unslash($in['wn_smtp_from_email'] ?? '')),
    'from_name' => trim(sanitize_text_field(wp_unslash($in['wn_smtp_from_name'] ?? ''))),
  ];
}

function wn_smtp_save_settings($in) {
  $s = wn_smtp_from_post($in);
  if (defined('WN_SMTP_PASS') && WN_SMTP_PASS) {
    $s['pass'] = ''; // Mật khẩu nằm trong wp-config.php, không chép vào database.
  }
  update_option('wn_smtp', $s, false);
}

/** Gắn cấu hình SMTP vào PHPMailer. */
function wn_smtp_apply($mailer, $s) {
  $mailer->isSMTP();
  $mailer->Host = $s['host'];
  $mailer->Port = (int) $s['port'];
  $mailer->SMTPSecure = $s['secure'] === 'none' ? '' : $s['secure'];
  $mailer->SMTPAutoTLS = $s['secure'] !== 'none';
  $mailer->SMTPAuth = $s['user'] !== '';
  $mailer->Username = $s['user'];
  $mailer->Password = $s['pass'];
  $mailer->Timeout = 15;

  // Đa số máy chủ SMTP (Gmail, Zoho...) chỉ nhận thư có "Từ" trùng tài khoản đăng nhập.
  $from = $s['from_email'] ?: (is_email($s['user']) ? $s['user'] : '');
  $name = $s['from_name'] ?: $mailer->FromName;
  try {
    if ($from) {
      $mailer->setFrom($from, $name, false);
    } elseif ($s['from_name']) {
      $mailer->FromName = $s['from_name'];
    }
  } catch (Exception $e) {
    // Địa chỉ "Từ" không hợp lệ: giữ nguyên người gửi mặc định của WordPress.
  }
}

add_action('phpmailer_init', function ($mailer) {
  if (!wn_module_on('smtp')) {
    return;
  }
  $s = wn_smtp_settings();
  if ($s['host']) {
    wn_smtp_apply($mailer, $s);
  }
}, 999);

/* ---------- Quản trị ---------- */

add_action('wp_ajax_wn_smtp_test', function () {
  check_ajax_referer('wn_toggle_module');
  if (!current_user_can('manage_options')) {
    wp_send_json_error('Không có quyền', 403);
  }
  $to = sanitize_email(wp_unslash($_POST['to'] ?? ''));
  if (!is_email($to)) {
    wp_send_json_error('Nhập email nhận thư thử');
  }
  $s = wn_smtp_from_post($_POST);
  if (!$s['host']) {
    wp_send_json_error('Nhập máy chủ SMTP trước');
  }
  // Gửi bằng giá trị đang gõ trong form (chưa cần bấm Lưu, chưa cần bật tính năng).
  add_action('phpmailer_init', function ($mailer) use ($s) {
    wn_smtp_apply($mailer, $s);
  }, 1000);
  $error = '';
  add_action('wp_mail_failed', function ($e) use (&$error) {
    $error = $e->get_error_message();
  });
  $sent = wp_mail(
    $to,
    'Thư thử SMTP · ' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
    "Kết nối SMTP thành công.\n\nMáy chủ: {$s['host']}:{$s['port']}\nWebsite: " . home_url('/') . "\nThời gian: " . wp_date('H:i d/m/Y')
  );
  $sent ? wp_send_json_success('Đã gửi thư thử tới ' . $to . ', kiểm tra hộp thư (cả mục Spam) nhé')
    : wp_send_json_error('Không gửi được: ' . ($error ?: 'máy chủ SMTP từ chối, kiểm tra lại thông tin'));
});

function wn_smtp_render_settings() {
  $s = wn_smtp_settings();
  $in_config = defined('WN_SMTP_PASS') && WN_SMTP_PASS;
  $masked = $s['pass'] !== '' ? str_repeat('•', 12) : '';
  ?>
  <div class="wn-field">
    <label class="wn-field-label" for="wn-smtp-host">Máy chủ SMTP<small>Gmail: <code>smtp.gmail.com</code> · Zoho: <code>smtp.zoho.com</code> · Email theo tên miền: thường là <code>mail.tenmien.com</code>.</small></label>
    <input id="wn-smtp-host" type="text" name="wn_smtp_host" value="<?php echo esc_attr($s['host']); ?>" placeholder="smtp.gmail.com" class="wn-input" autocomplete="off" spellcheck="false">
  </div>
  <div class="wn-field">
    <div class="wn-field-label">Mã hóa &amp; cổng<small>TLS đi với cổng 587, SSL đi với cổng 465.</small></div>
    <div>
      <div class="wn-chips">
        <?php foreach (['tls' => ['TLS', 587], 'ssl' => ['SSL', 465], 'none' => ['Không mã hóa', 25]] as $v => $l) : ?>
          <label class="wn-chip"><input type="radio" name="wn_smtp_secure" value="<?php echo esc_attr($v); ?>" data-smtp-port="<?php echo (int) $l[1]; ?>" <?php checked($s['secure'], $v); ?>><span><?php echo esc_html($l[0]); ?></span></label>
        <?php endforeach; ?>
      </div>
      <p class="wn-hint"><label for="wn-smtp-port">Cổng</label>&nbsp;<input id="wn-smtp-port" type="number" min="1" max="65535" name="wn_smtp_port" value="<?php echo (int) $s['port']; ?>" class="wn-input wn-input-sm"></p>
    </div>
  </div>
  <div class="wn-field">
    <label class="wn-field-label" for="wn-smtp-user">Tài khoản<small>Thường là địa chỉ email đầy đủ.</small></label>
    <input id="wn-smtp-user" type="text" name="wn_smtp_user" value="<?php echo esc_attr($s['user']); ?>" placeholder="ten@tenmien.com" class="wn-input" autocomplete="off" spellcheck="false">
  </div>
  <div class="wn-field">
    <label class="wn-field-label" for="wn-smtp-pass">Mật khẩu<small>Gmail phải dùng <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">mật khẩu ứng dụng</a> (16 ký tự), không dùng mật khẩu đăng nhập.</small></label>
    <div>
      <?php if ($in_config) : ?>
        <p class="wn-hint is-ok"><span class="dashicons dashicons-lock"></span>Đang lấy mật khẩu từ <code>WN_SMTP_PASS</code> trong wp-config.php.</p>
      <?php else : ?>
        <input id="wn-smtp-pass" type="text" name="wn_smtp_pass" value="<?php echo esc_attr($masked); ?>" class="wn-input" autocomplete="off" spellcheck="false">
        <?php if ($masked) : ?><p class="wn-hint is-ok"><span class="dashicons dashicons-lock"></span>Đã lưu mật khẩu. Để nguyên nếu không đổi.</p><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="wn-field">
    <label class="wn-field-label" for="wn-smtp-from-email">Email người gửi<small>Để trống thì dùng luôn tài khoản ở trên (nên để trống với Gmail).</small></label>
    <input id="wn-smtp-from-email" type="text" name="wn_smtp_from_email" value="<?php echo esc_attr($s['from_email']); ?>" placeholder="<?php echo esc_attr($s['user'] ?: 'ten@tenmien.com'); ?>" class="wn-input" spellcheck="false">
  </div>
  <div class="wn-field">
    <label class="wn-field-label" for="wn-smtp-from-name">Tên người gửi<small>Để trống thì giữ tên do từng email đặt (thường là tên website).</small></label>
    <input id="wn-smtp-from-name" type="text" name="wn_smtp_from_name" value="<?php echo esc_attr($s['from_name']); ?>" placeholder="<?php echo esc_attr(wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)); ?>" class="wn-input">
  </div>
  <div class="wn-field">
    <label class="wn-field-label" for="wn-smtp-to">Gửi thư thử<small>Thử ngay với thông tin đang nhập, chưa cần bấm Lưu.</small></label>
    <div>
      <input id="wn-smtp-to" type="text" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" class="wn-input" spellcheck="false">
      <div class="wn-actions wn-actions-tight">
        <button type="button" class="wn-btn" data-smtp-test><span class="dashicons dashicons-email-alt"></span> Gửi thư thử</button>
      </div>
    </div>
  </div>
  <?php if (!$s['host']) : ?>
    <p class="wn-hint is-off"><span class="dashicons dashicons-info-outline"></span>Chưa nhập máy chủ SMTP — email vẫn gửi theo cách mặc định của hosting.</p>
  <?php endif;
}
