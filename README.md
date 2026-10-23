# Tiện ích WebNow

Plugin WordPress gom các tiện ích của [webnow.vn](https://webnow.vn) vào một chỗ: **tối ưu web tự động**, tìm kiếm nhanh, nút liên hệ, trang trí Tết, màn hình chờ, Font Awesome, ảnh WebP, dọn ảnh không dùng và tùy biến trang đăng nhập/admin. Mục tiêu là bớt plugin lẻ để web nhẹ hơn.

- Phiên bản hiện tại: **1.5**
- Tác giả: Sai Gon Web Co., Ltd
- Trang quản trị: menu **WebNow** ở thanh bên trái admin

---

## Mục lục

1. [Yêu cầu](#yêu-cầu)
2. [Cài đặt](#cài-đặt)
3. [Cập nhật plugin](#cập-nhật-plugin)
4. [Danh sách tính năng](#danh-sách-tính-năng)
5. [Tối ưu web](#tối-ưu-web)
6. [Các tính năng khác](#các-tính-năng-khác)
7. [Cài lên site đã có tính năng tương tự](#cài-lên-site-đã-có-tính-năng-tương-tự)
8. [Dữ liệu plugin lưu](#dữ-liệu-plugin-lưu)
9. [Cấu trúc mã nguồn](#cấu-trúc-mã-nguồn)
10. [Lịch sử phiên bản](#lịch-sử-phiên-bản)

---

## Yêu cầu

| | Tối thiểu | Đã chạy thực tế |
|---|---|---|
| PHP | 8.0 | 8.2, 8.4, 8.5 |
| WordPress | 5.8 | 6.8, 7.1 |
| WooCommerce | không bắt buộc | 9.6, 11.1, 11.2 |

Phần nén ảnh/WebP cần PHP có **Imagick** hoặc **GD** hỗ trợ WebP. Phần Tối ưu web tự báo nếu thiếu.

---

## Cài đặt

> **Quan trọng:** thư mục plugin phải tên là **`wn-tien-ich-main`**. Các site đang dùng đều cài theo tên này. Đặt tên khác thì WordPress coi là một plugin khác: bản cũ vẫn chạy song song và cài đặt không được dùng chung.

### Cách 1: trang quản trị WordPress

1. Trên GitHub bấm **Code → Download ZIP**. File tải về giải nén ra thư mục `tien-ich-webnow-plugin-main`.
2. Đổi tên thư mục thành `wn-tien-ich-main`, nén lại thành `wn-tien-ich-main.zip`.
3. Vào **Plugin → Cài mới → Tải plugin lên**, chọn file zip, kích hoạt.

Nếu site đặt `DISALLOW_FILE_MODS` (không có nút Tải plugin lên), dùng cách 2.

### Cách 2: SSH / WP-CLI

```bash
cd ~/public_html/wp-content/plugins
git clone --depth 1 https://github.com/nguyenmaidongho/tien-ich-webnow-plugin.git wn-tien-ich-main
rm -rf wn-tien-ich-main/.git
wp plugin activate wn-tien-ich-main
```

Nếu site **đã có sẵn** nút liên hệ, Font Awesome, chuyển WebP khi upload..., hãy đặt cấu hình module **trước khi kích hoạt**, để không bị trùng. Xem mục [Cài lên site đã có tính năng tương tự](#cài-lên-site-đã-có-tính-năng-tương-tự).

---

## Cập nhật plugin

> **Plugin KHÔNG tự kiểm tra cập nhật và KHÔNG tự cập nhật.** Plugin không nằm trên wordpress.org và chưa có bộ tự cập nhật từ GitHub, nên WordPress không biết có bản mới. Mỗi lần có phiên bản mới phải cập nhật tay theo một trong các cách dưới đây.

### Cập nhật qua SSH (khuyến nghị: an toàn, có bản lùi)

```bash
cd ~/public_html/wp-content/plugins
# 1. Tải bản mới vào thư mục tạm
rm -rf ~/tmp/wn-new && git clone -q --depth 1 https://github.com/nguyenmaidongho/tien-ich-webnow-plugin.git ~/tmp/wn-new && rm -rf ~/tmp/wn-new/.git
# 2. Kiểm tra cú pháp trước khi thay (phải không có lỗi)
for f in ~/tmp/wn-new/wn-tien-ich.php ~/tmp/wn-new/includes/*.php ~/tmp/wn-new/includes/admin/*.php; do php -l "$f" >/dev/null || echo "LỖI: $f"; done
# 3. Giữ bản cũ để lùi lại nếu cần, rồi thay
mv wn-tien-ich-main ~/tmp/wn-tien-ich-old && mv ~/tmp/wn-new wn-tien-ich-main
```

Lùi về bản cũ: `rm -rf wn-tien-ich-main && mv ~/tmp/wn-tien-ich-old wn-tien-ich-main`.

Cài đặt của plugin nằm trong database nên không mất khi thay thư mục. Phiên bản mới thêm module có `default = 1` thì module đó tự bật. Hãy đọc [Lịch sử phiên bản](#lịch-sử-phiên-bản) trước khi cập nhật.

### Cập nhật qua trang quản trị

**Plugin → Cài mới → Tải plugin lên** với file zip đã đổi tên thư mục (như phần Cài đặt). WordPress sẽ hỏi có thay bản đang cài không, chọn **Thay thế bản hiện tại**.

### Cập nhật các plugin/theme KHÁC của site

Tính năng [Tối ưu web](#tối-ưu-web) **chỉ báo** số bản cập nhật đang chờ và phát hiện plugin nào đang chặn kiểm tra cập nhật (ví dụ Foxtool có tùy chọn ẩn cập nhật). Nó **không tự cập nhật** plugin/theme, vì cập nhật bản lớn (WooCommerce, WordPress core) cần sao lưu và kiểm tra giao diện sau đó.

Cập nhật bằng WP-CLI, bỏ qua các plugin đang chặn kiểm tra cập nhật:

```bash
cd ~/public_html
wp --skip-plugins=foxtool,wn-tien-ich-main core update && wp core update-db
wp --skip-plugins=foxtool,wn-tien-ich-main plugin update --all
wp wc update            # nếu có WooCommerce: chạy cập nhật database
wp language core update && wp language plugin update --all
```

Plugin/theme trả phí (Flatsome, ACF Pro...) chỉ cập nhật được khi site có license.

---

## Danh sách tính năng

Bật/tắt từng tính năng ở **WebNow → Tính năng**. Thay đổi lưu ngay, không cần bấm Lưu.

| # | Tính năng | Mặc định | Thay cho plugin | Mở ở đâu |
|---|---|---|---|---|
| 1 | **Tối ưu web** | Bật | (mới) | WebNow → Tối ưu web |
| 2 | Tìm kiếm nhanh | Bật | Search Popup ThunderBolt | WebNow → Tìm kiếm |
| 3 | Nút liên hệ | Bật | Button Contact VR | WebNow → Nút liên hệ |
| 4 | Trang trí Tết | Tắt | DevVN - Trang trí Tết | WebNow → Trang trí Tết |
| 5 | Màn hình chờ | Tắt | | WebNow → Màn hình chờ |
| 6 | Font Awesome | Bật | Font Awesome (chính thức) | |
| 7 | Ảnh WebP | Bật | WebNow WebP Batch Optimizer | Media → WebP Batch Optimizer |
| 8 | Dọn ảnh không dùng | Tắt | | Media → Dọn ảnh không dùng |
| 9 | Trang đăng nhập WebNow | Bật | | |
| 10 | Ẩn logo WordPress | Bật | | |
| 11 | Ẩn thông báo Flatsome | Bật | | |
| 12 | Nhắc plugin cần thiết | Bật | | |
| 13 | Ẩn thông báo cập nhật | Tắt | | không khuyến khích |
| 14 | Classic Editor | Tắt | Classic Editor | |

Ngoài ra plugin luôn cho phép tải file **SVG** lên Thư viện (dùng cho logo màn hình chờ).

---

## Tối ưu web

Trình hướng dẫn 4 bước ở **WebNow → Tối ưu web**:

```
Phân tích  →  Kế hoạch  →  Áp dụng  →  Kết quả
 (chỉ đọc)    (bạn chọn)   (có sao lưu)  (đo lại, so sánh)
```

1. **Phân tích**: quét 7 nhóm và chấm điểm 0–100. Mất khoảng 30–60 giây. Bước này **chưa thay đổi gì**.
2. **Kế hoạch**: liệt kê các mục **tự sửa được** (tick để chọn, mặc định đã chọn mục nên làm) và các mục **cần làm tay** (có nút chép lệnh/cấu hình).
3. **Áp dụng**: chạy từng bước theo lượt 8 giây qua AJAX, nên không bị timeout trên hosting chia sẻ. Có thanh tiến độ và log chi tiết. **Đừng đóng trang khi đang chạy.**
4. **Kết quả**: chạy lại phân tích, so sánh điểm và từng chỉ số trước/sau. Lịch sử 10 lần gần nhất hiện ở màn hình đầu.

### Tự chọn cách tối ưu theo máy chủ

Plugin đọc `SERVER_SOFTWARE` để biết máy chủ là gì, rồi chọn cách hợp nhất cho môi trường đó:

| Máy chủ | Cache trang | Nén gzip/brotli + cache trình duyệt | Phục vụ WebP |
|---|---|---|---|
| **LiteSpeed** / OpenLiteSpeed | LiteSpeed Cache (tự cài + bộ cấu hình chuẩn) | LiteSpeed Cache + máy chủ | Rule trong `uploads/.htaccess` |
| **Apache** | WP Fastest Cache (tự cài + bật cache, gzip, cache trình duyệt, minify HTML/CSS) | Block `# BEGIN WebNow Speed` trong `.htaccess` gốc | Rule trong `uploads/.htaccess` |
| **Nginx** | Cache Enabler (cache qua `advanced-cache.php`, không cần rewrite) | Đoạn cấu hình nginx, chép gửi hosting | Đoạn cấu hình nginx |
| Khác (IIS, Caddy...) | Cache Enabler | Cấu hình ở máy chủ | Cấu hình ở máy chủ |

Khi chuyển sang LiteSpeed Cache, các plugin cache khác (WP Fastest Cache, W3 Total Cache, WP Super Cache, WP Rocket...) được **tắt chứ không xóa**.

### Các mục kiểm tra

| Nhóm | Kiểm tra | Tự sửa |
|---|---|---|
| **Máy chủ & PHP** | Phiên bản PHP, OPcache, loại máy chủ, object cache (Redis/Memcached) | (làm tay) |
| **Tốc độ phản hồi** | Thời gian trang chủ phản hồi khi chưa cache và khi có cache (đo 2 lần, lấy lần nhanh hơn), số file JS/CSS/ảnh | |
| **Bộ nhớ đệm** | Có cache trang không, cấu hình LiteSpeed Cache, plugin cache chồng nhau, nén gzip/brotli, thời hạn cache trình duyệt | ✔ |
| **Database** | Dung lượng, autoload, bản ghi IP của WooCommerce, transient hết hạn, revision/nháp/bình luận rác, dữ liệu mồ côi, rác của plugin đã gỡ, Action Scheduler cũ | ✔ |
| **Hình ảnh** | Thư viện xử lý ảnh, % ảnh có bản WebP, ảnh trên 300 KB, rule tự phục vụ WebP | ✔ |
| **Cron** | Tác vụ quá hạn, `wp-cron.php` có bị chặn không, `DISABLE_WP_CRON` | ✔ chạy bù |
| **Bảo trì & an toàn** | Quyền `wp-config.php`, file `.zip/.sql/.bak` để lộ, `debug.log` lớn, chế độ debug, cập nhật đang chờ / bị chặn, tinh chỉnh nhẹ | ✔ |

### Các bước tự sửa

Chạy theo đúng thứ tự dưới đây. Bước **Sao lưu database** tự thêm vào đầu khi có bất kỳ bước dọn database nào.

| Bước | Làm gì |
|---|---|
| Sao lưu database | Xuất các bảng sắp đụng tới (options, posts, postmeta, comments, commentmeta, term_relationships, Action Scheduler, bảng rác sắp xóa) ra file `.sql.gz`. **Không sao lưu được thì dừng, không dọn.** |
| Xóa transient hết hạn và bản ghi IP | Xóa transient đã hết hạn và toàn bộ `_transient_external_ip_address_*` của WooCommerce |
| WooCommerce: dùng địa chỉ cửa hàng | Đổi "Vị trí khách mặc định" từ định vị IP sang địa chỉ cửa hàng. Định vị IP khiến mỗi khách mới phải gọi API bên ngoài và tạo thêm 1 bản ghi. Shop bán trong nước không cần. |
| Dọn revision, nháp, bình luận rác | Giữ 5 revision mới nhất mỗi bài, xóa nháp tự động trên 7 ngày, bình luận spam/thùng rác trên 30 ngày |
| Xóa dữ liệu mồ côi | postmeta/commentmeta không còn bài/bình luận; liên kết danh mục trỏ tới bài đã xóa (chỉ với taxonomy của bài viết) |
| Xóa rác của plugin đã gỡ | Option, bảng, meta của Yoast, LiteSpeed Cache, Elementor, WP Fastest Cache, W3 Total Cache, Smush, WP SMTP, Rank Math. **Chỉ khi plugin đó không còn trong thư mục plugins.** |
| Dọn Action Scheduler cũ | Tác vụ đã xong/thất bại/hủy hơn 30 ngày |
| Thu hồi dung lượng bảng | `OPTIMIZE TABLE` các bảng chính |
| Chuyển sang LiteSpeed Cache | Cài từ wordpress.org, tắt plugin cache khác, kích hoạt (chỉ máy chủ LiteSpeed) |
| Cấu hình LiteSpeed Cache | Cache trang 7 ngày, cache trình duyệt 1 năm, minify CSS/JS/HTML, defer JS (trừ jQuery), lazy-load ảnh/iframe (trừ logo), bỏ emoji, bỏ query string, heartbeat giãn ra. Tắt UCSS/CCSS, crawler, guest mode, tối ưu ảnh qua QUIC.cloud. **Nên xem lại menu/slider sau khi bật defer JS.** |
| Cài và cấu hình cache trang | Apache: WP Fastest Cache. Nginx/khác: Cache Enabler. |
| Bật nén + cache trình duyệt (.htaccess) | Apache: thêm block `WebNow Speed`. Sau khi thêm sẽ thử gọi trang chủ; máy chủ báo lỗi thì tự gỡ ra. |
| Nén lại ảnh nặng | Ảnh JPG/PNG trên 300 KB: nén JPEG chất lượng 82 (progressive), PNG mức 9, bỏ metadata, **giữ nguyên kích thước**. Chỉ ghi đè khi nhẹ hơn ít nhất 10%. Ảnh CMYK bỏ qua. |
| Tạo ảnh WebP và tự phục vụ | Tạo `ảnh.jpg.webp` cạnh mỗi ảnh (chất lượng 80), chỉ giữ khi nhỏ hơn bản gốc. **Không đổi link trong bài**: máy chủ tự trả `.webp` cho trình duyệt hỗ trợ (kèm `Vary: Accept`). Sau khi bật sẽ thử thật một ảnh để xác nhận. |
| Chạy bù tác vụ nền | Chạy các sự kiện cron đã tới hạn, kèm dòng cron hệ thống gợi ý |
| Bật tinh chỉnh nhẹ | Giới hạn 5 revision/bài, tắt heartbeat với khách chưa đăng nhập (admin: 60 giây), bỏ script emoji. Chạy thường trực khi module bật. |
| Khóa quyền wp-config.php | Đặt 640, **chỉ khi PHP chạy bằng đúng user sở hữu file** (không thì có thể làm web không đọc được cấu hình). Không áp dụng trên Windows. |
| Cất file sao lưu/log để lộ | Chuyển (không xóa) file `.zip .tar .gz .tgz .rar .7z .sql .bak` ở thư mục gốc, wp-content, plugins, themes và `debug.log` trên 10 MB vào thư mục được bảo vệ |
| Xóa cache | Xóa cache trang (LiteSpeed, WP Fastest Cache) và object cache, rồi gọi trang chủ để tạo lại cache |

### Cron hệ thống: chú ý phiên bản PHP

Nếu `wp-cron.php` bị máy chủ chặn (lỗi 403) hoặc site đặt `DISABLE_WP_CRON`, cần cron của máy chủ (DirectAdmin → Cron Jobs). Plugin gợi ý sẵn dòng lệnh **dùng đúng phiên bản PHP của web**, ví dụ:

```
*/5 * * * * /opt/alt/php84/usr/bin/php -q /home/user/domains/example.com/public_html/wp-cron.php >/dev/null 2>&1
```

Cron gọi **sai phiên bản PHP** sẽ chết âm thầm khi một plugin đòi PHP mới hơn. Đã gặp thật: Contact Form 7 bản 6.2 cần PHP 8.3, trong khi cron gọi PHP 8.2. Thứ tự plugin đoán đường dẫn PHP:
1. File `php` nằm cạnh `lsphp` của web.
2. `/opt/alt/phpXY/usr/bin/php` (CloudLinux).
3. `/usr/local/phpXY/bin/php` (DirectAdmin).
4. `/opt/cpanel/ea-phpXY/root/usr/bin/php` (cPanel).

### Sao lưu và khôi phục

Mọi thứ nằm trong `wp-content/uploads/wn-optimize/`. Thư mục này có `.htaccess` chặn truy cập từ web, tên file sao lưu có chuỗi ngẫu nhiên (phòng máy chủ Nginx không đọc `.htaccess`).

| Đường dẫn | Nội dung |
|---|---|
| `backups/db-YYYYMMDD-HHMMSS-xxxx.sql.gz` | Bảng database trước khi dọn |
| `originals/...` | Ảnh gốc trước khi nén lại (giữ nguyên cấu trúc thư mục uploads) |
| `stray/...` | File sao lưu/log đã cất |
| `compressed.json`, `webp-skip.json` | Ảnh đã thử nén / thử WebP mà không nhỏ hơn, để lần quét sau không báo lại |

Khôi phục database (SSH):

```bash
gunzip -c ~/public_html/wp-content/uploads/wn-optimize/backups/db-....sql.gz | mysql -u DB_USER -p DB_NAME
```

Hoặc giải nén file `.sql.gz` rồi import bằng phpMyAdmin.

Khôi phục ảnh gốc (SSH):

```bash
cp -a ~/public_html/wp-content/uploads/wn-optimize/originals/. ~/public_html/wp-content/uploads/
```

Bỏ phục vụ WebP: xóa block `# BEGIN WebNow WebP … # END WebNow WebP` trong `uploads/.htaccess`. Các file `.webp` để lại không ảnh hưởng gì.

Bỏ rule nén/cache Apache: xóa block `# BEGIN WebNow Speed … # END WebNow Speed` trong `.htaccess` gốc.

### Giới hạn cần biết

- **Đo tốc độ là đo từ chính máy chủ** (site tự gọi trang chủ), chưa tính đường mạng tới khách. Hosting chặn site tự gọi chính nó thì mục tốc độ báo "Không đo được"; các mục khác vẫn chạy.
- Trang **chưa có cache** vẫn có thể trên 1 giây với WooCommerce + theme nặng (Flatsome). Đó là chi phí dựng trang của PHP. Phần lớn khách nhận trang đã cache.
- **Defer JS** có thể làm hỏng script viết inline gọi jQuery sớm. Kiểm tra menu mobile, slider, form sau khi áp dụng. Lỗi thì thêm file vào JS Excludes trong LiteSpeed Cache.
- Nhánh Nginx mới được kiểm tra qua code, chưa chạy trên máy chủ Nginx thật.
- Không tự cập nhật WordPress/plugin/theme (xem [Cập nhật plugin](#cập-nhật-plugin)).

---

## Các tính năng khác

### Tìm kiếm nhanh
Dựng sẵn một file JSON (`uploads/` + tên chỉ mục) chứa toàn bộ nội dung cần tìm. File này gửi một lần cho trình duyệt rồi lọc ngay bằng JS, không gọi máy chủ mỗi lần gõ phím. So với Search Popup ThunderBolt:
- Tự tạo lại chỉ mục khi file bị mất.
- Cập nhật mỗi khi **sửa** bài, không chỉ lúc xuất bản.

Cài đặt: loại nội dung (Bài viết / Trang / Sản phẩm), số kết quả tối đa mỗi loại, nút **Dựng lại chỉ mục**.

### Nút liên hệ
Nút nổi gọi điện, Zalo, Messenger, WhatsApp, chỉ đường Google Maps. Cài đặt: vị trí trái/phải, hiện nhãn, khoảng cách đáy trên mobile. Lần đầu bật sẽ lấy sẵn số/link từ Button Contact VR nếu site đang dùng plugin đó.

### Trang trí Tết
Câu đối, hoa đào/mai rơi, pháo hoa, nhạc; hẹn ngày tự bật/tắt. Dùng lại option `tet_options` của plugin DevVN nên giữ được cấu hình cũ. Hiệu ứng chạy bằng `requestAnimationFrame`, pháo hoa dừng hẳn khi hết giờ, nhạc phát ở lần chạm đầu tiên (trình duyệt chặn tự phát). Không hiện hiệu ứng nếu người xem bật "giảm chuyển động".

### Màn hình chờ
Vẽ nét logo SVG khi trang đang tải rồi mờ dần (tối thiểu 3,5 giây). Cài đặt: logo SVG (dạng nét/path), độ dài nét (`stroke-dashoffset`), màu nền.

### Font Awesome
Nhúng Font Awesome 6 giống hệt plugin chính thức (cùng URL, handle, integrity hash), tải không chặn hiển thị. Tắt nếu theme/plugin khác đã nạp Font Awesome.

### Ảnh WebP
Ảnh JPG/PNG tải lên tự đổi sang WebP. Công cụ **Media → WebP Batch Optimizer** chuyển hàng loạt ảnh cũ (cả bản gốc lẫn mọi kích thước): cập nhật database và metadata trước, rồi mới xóa ảnh gốc nếu bật tùy chọn đó (mặc định **giữ** ảnh gốc). Chất lượng chỉnh được 60–92 (mặc định 82). Có URL chạy bằng cron với khóa bí mật: `?webnow_webp_cron=<khóa>&n=5`.

Module này **đổi hẳn file sang `.webp`**. Tối ưu web thì tạo bản `.webp` **đi kèm** và giữ nguyên file gốc. Chọn một trong hai cách cho mỗi site. Nếu đã có plugin khác chuyển WebP khi upload (vd. Foxtool) thì tắt module này.

### Dọn ảnh không dùng
Ba bước an toàn:
1. **Quét:** đọc nội dung bài, meta (Elementor, ACF, WooCommerce...), options, theme mods, bảng của plugin khác và file theme, để biết ảnh nào đang dùng. Hơi nghi ngờ là coi như đang dùng.
2. **Cách ly:** chuyển file sang `uploads/wn-quarantine/` và ẩn khỏi Thư viện. Web thiếu ảnh nào thì **Khôi phục** ảnh đó.
3. **Xóa hẳn** những ảnh còn lại sau vài ngày theo dõi.

Nên sao lưu database + uploads trước khi xóa hẳn.

### Trang đăng nhập WebNow, Ẩn logo WordPress, Ẩn thông báo Flatsome
Logo/màu/favicon WebNow ở trang đăng nhập và admin; bỏ logo WordPress trên admin bar; ẩn banner nhắc đăng ký bản quyền của Flatsome.

### Nhắc plugin cần thiết
Báo lỗi trong admin nếu Rank Math SEO hoặc plugin cache (WP Fastest Cache **hoặc** LiteSpeed Cache) không bật.

### Ẩn thông báo cập nhật
Chặn WordPress hiện bản mới của core/plugin/theme. **Không khuyến khích**, dễ bỏ lỡ bản vá bảo mật.

### Classic Editor
Dùng trình soạn thảo cổ điển. Không cần bật nếu Classic Editor, Disable Gutenberg hoặc Foxtool đã làm việc này.

---

## Cài lên site đã có tính năng tương tự

Module có `default = 1` sẽ tự bật ngay khi kích hoạt plugin, kể cả khi site đã có tính năng đó (nút liên hệ của theme, Font Awesome do plugin khác nạp...). Hãy đặt option `wn_module_{tên}` **trước khi** kích hoạt hoặc cập nhật:

```bash
cd ~/public_html
# 1 = bật, 0 = tắt
for kv in optimize:1 search:0 contact:0 fontawesome:0 webp:0 tet:0 preload:0 media_cleanup:0 \
          login_branding:1 hide_wp_logo:1 hide_flatsome_notice:1 plugin_warning:1 hide_core_updates:0 classic_editor:0; do
  wp option update "wn_module_${kv%%:*}" "${kv##*:}"
done
```

Cách kiểm tra nhanh site đang có gì:
- Font Awesome: tìm `font-awesome` trong mã nguồn trang.
- Nút liên hệ: tìm `contact` trong id của các thẻ nổi.
- Ô tìm kiếm: tìm `searchform`.

---

## Dữ liệu plugin lưu

| Option | Ý nghĩa |
|---|---|
| `wn_module_{key}` | Bật/tắt từng tính năng (`'1'`/`'0'`) |
| `wn_search_post_types`, `wn_search_limit` | Cài đặt tìm kiếm |
| `wn_contact` | Cài đặt nút liên hệ |
| `tet_options` | Cài đặt trang trí Tết (dùng chung với plugin DevVN) |
| `wn-tien-ich-main_logo_svg`, `_dashoffset`, `_background` | Màn hình chờ |
| `webnow_webp_quality`, `webnow_webp_delete_originals`, `webnow_webp_cron_secret`, `webnow_webp_queue_v32` | Ảnh WebP |
| `wn_cleanup_scan`, `wn_cleanup_result` | Dọn ảnh không dùng |
| `wn_opt_last_scan`, `wn_opt_baseline` | Tối ưu web: kết quả phân tích gần nhất / mốc trước khi áp dụng |
| `wn_opt_history` | Tối ưu web: 10 lần tối ưu gần nhất (điểm trước/sau, người chạy) |
| `wn_opt_tweaks` | Tối ưu web: tinh chỉnh nhẹ đang bật (`revisions`, `heartbeat`, `emoji`) |

Thư mục trong uploads: `wn-optimize/` (Tối ưu web), `wn-quarantine/` (Dọn ảnh), file chỉ mục tìm kiếm.

Plugin không có `uninstall.php`. Gỡ plugin thì dữ liệu trên vẫn còn; muốn sạch hẳn thì xóa các option `wn_*` và thư mục `wn-optimize/` bằng tay.

---

## Cấu trúc mã nguồn

```
wn-tien-ich.php              Khai báo plugin, hằng số, nạp các file
includes/
  utils.php                  wn_plugin_url(), wn_clear_page_cache()
  modules.php                Danh sách tính năng + wn_module_on()
  loader.php                 Branding, ẩn logo, nhắc plugin, màn hình chờ; nạp các module
  module-optimize.php        Tối ưu web: giao diện, AJAX, các bước kiểm tra, tinh chỉnh thường trực
  optimize-fixes.php         Tối ưu web: các bước áp dụng (chạy theo lượt, có state)
  module-search.php          Tìm kiếm nhanh
  module-contact.php         Nút liên hệ
  module-tet.php             Trang trí Tết
  module-fontawesome.php     Font Awesome
  module-webp.php            Ảnh WebP + công cụ chuyển hàng loạt  (webp-batch.js)
  module-cleanup.php         Dọn ảnh không dùng                    (media-cleanup.js)
  admin/
    admin-page.php           Trang WebNow: các tab, lưu cài đặt
    admin.css, admin.js      Giao diện chung
    optimize.css, optimize.js  Giao diện trình hướng dẫn Tối ưu web
assets/tet/                  Ảnh, nhạc trang trí Tết
css/, images/                Trang đăng nhập, tìm kiếm, logo
```

Thêm tính năng mới:
1. Khai báo trong `wn_modules()` ở `modules.php` (`title`, `desc`, `icon`, `default`, tùy chọn thêm `tab`/`page`/`warn`).
2. Viết file `module-xxx.php`, chỉ chạy khi `wn_module_on('xxx')`.
3. `require_once` file đó trong `loader.php`.
4. Nếu cần tab cài đặt: thêm vào `wn_admin_tabs()` và viết hàm `wn_render_tab_xxx()`.

Thêm một mục kiểm tra/bước sửa cho Tối ưu web:
- Mục kiểm tra trả về `self::check($id, $title, $status, $value, $detail, ['fix' => 'ma_buoc'])` trong hàm `scan_{nhóm}()`. `$status` là `good`, `warn`, `bad` hoặc `info`.
- Bước sửa thêm vào `WN_Optimize_Fixes::all()` (thứ tự trong mảng = thứ tự chạy) và viết hàm `fix_ma_buoc($state, $deadline)`. Hàm trả về `['done' => bool, 'progress' => 0-100, 'state' => [...], 'log' => [...], 'warn' => bool]`.
- Việc dài thì kiểm tra `microtime(true) < $deadline`, lưu tiến độ vào `state` rồi trả `done => false`; trình duyệt sẽ gọi tiếp.

---

## Lịch sử phiên bản

### 1.5
- **Thêm Tối ưu web**: trình hướng dẫn Phân tích → Kế hoạch → Áp dụng → Kết quả, tự chọn cách tối ưu theo LiteSpeed/Apache/Nginx, sao lưu trước khi dọn. Module mặc định **bật** (chỉ hiện trong admin; chỉ thay đổi site khi bấm Áp dụng).
- `wn_clear_page_cache()` xóa cả cache LiteSpeed.
- "Nhắc plugin cần thiết" chấp nhận LiteSpeed Cache thay cho WP Fastest Cache.

### 1.4
- Thêm module **Ảnh WebP** (gộp từ WebNow WebP Batch Optimizer 3.2) và **Dọn ảnh không dùng**.
- Các module có `default = 1` mới: `webp`. Kiểm tra site có Foxtool/plugin WebP khác trước khi cập nhật.

### 1.0 – 1.3
- Tìm kiếm nhanh, nút liên hệ, trang trí Tết, màn hình chờ, Font Awesome, trang đăng nhập WebNow, ẩn logo WordPress, ẩn thông báo Flatsome, nhắc plugin cần thiết, ẩn thông báo cập nhật, Classic Editor; trang quản trị WebNow riêng.
