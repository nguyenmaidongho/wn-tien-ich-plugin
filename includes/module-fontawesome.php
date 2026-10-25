<?php
/**
 * Thay plugin "Font Awesome" (chính thức, fontawesome.com) — plugin đó chỉ làm đúng
 * 1 việc là nhúng 2 file CSS bản v6.1.2 từ use.fontawesome.com kèm tải không chặn
 * render (media="print" + onload). Giữ nguyên URL/handle/integrity hash để icon
 * không đổi, không cần active lại plugin gốc.
 */
add_action('wp_enqueue_scripts', function () {
  if (!wn_module_on('fontawesome')) {
    return;
  }
  wp_enqueue_style('font-awesome-official-css', 'https://use.fontawesome.com/releases/v6.1.2/css/all.css', [], null);
  wp_enqueue_style('font-awesome-official-v4shim-css', 'https://use.fontawesome.com/releases/v6.1.2/css/v4-shims.css', [], null);
}, 5);

add_filter('style_loader_tag', function ($tag, $handle) {
  $meta = [
    'font-awesome-official-css' => 'sha384-fZCoUih8XsaUZnNDOiLqnby1tMJ0sE7oBbNk2Xxf5x8Z4SvNQ9j83vFMa/erbVrV',
    'font-awesome-official-v4shim-css' => 'sha384-iW7MVRJO9Fj06GFbRcMqdZBcVQhjBWlVXUjtY7XCppA+DZUoHBQ7B8VB+EjXUkPV',
  ];
  if (!isset($meta[$handle])) {
    return $tag;
  }
  // Tải không chặn render: media="print" rồi đổi thành "all" khi tải xong.
  // data-no-optimize: LiteSpeed (bật CSS Minify) bỏ qua thẻ này. Nếu không, nó thay href bằng
  // bản minify trên host nhưng giữ integrity của file CDN → sai hash, trình duyệt chặn CSS.
  $tag = str_replace(" media='all'", " media='print' onload=\"this.media='all'\" integrity=\"{$meta[$handle]}\" crossorigin=\"anonymous\" data-no-optimize=\"1\"", $tag);
  return $tag;
}, 10, 2);
