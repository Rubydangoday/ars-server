# ARS v12 — đăng nhập bằng Google / Facebook

Dùng cho cả ba cổng: giáo viên, học sinh, phụ huynh. Mật khẩu ARS vẫn dùng song song.

## Nâng cấp

```bash
mysql -u root -p ars < db/migrate_007_oauth.sql      # chạy lại được
```

Cần `php-curl`. Cài mới: installer đã có migration 007.

## Cấu hình (quản trị server → Cài đặt → 🔐 Google / Facebook)

Google và Facebook chỉ chấp nhận địa chỉ **https có tên miền** (hoặc `localhost`). Bật trên server Cloud / link công khai; truy cập bằng `http://IP-LAN` sẽ không hiện nút.

Trang cài đặt hiện sẵn **Redirect URI** (VD `https://ars.truong.edu.vn/oauth.php`). Khai báo đúng từng ký tự:

- **Google**: Google Cloud Console → APIs & Services → OAuth consent screen (loại External, thêm tên app, email hỗ trợ) → Credentials → *Create OAuth client ID* → *Web application* → *Authorized redirect URIs* = Redirect URI. Chép Client ID và Client secret vào ARS. Phạm vi dùng: `openid email profile`.
- **Facebook**: developers.facebook.com → Create App → thêm sản phẩm *Facebook Login* → *Valid OAuth Redirect URIs* = Redirect URI. Chép App ID và App secret (Settings → Basic). Chuyển app sang chế độ *Live* để người ngoài nhóm phát triển đăng nhập được.

Secret được lưu trong bảng settings và không hiển thị lại (ô hiện ••••). Để trống ô secret khi lưu = giữ secret cũ; tích “Xoá secret” để tắt nhà cung cấp đó.

## Cách người dùng sử dụng

- **Liên kết** (giáo viên, học sinh, phụ huynh đã có tài khoản): đăng nhập bằng mật khẩu → *Cài đặt → Tài khoản* (giáo viên), *🔑 Đổi mật khẩu* (học sinh), cuối trang *Tiến độ của con* (phụ huynh) → **Liên kết Google / Facebook**. Lần sau bấm **Đăng nhập với Google / Facebook**.
- **Phụ huynh mới**: cổng phụ huynh → *Hoặc đăng ký nhanh* → nhập mã mời → **Đăng ký với Google / Facebook**. Không cần đặt mật khẩu. Phụ huynh đã đăng ký bằng Google dùng mã mời mới để thêm con/lớp khác.
- Học sinh phải đổi mật khẩu lần đầu trước khi liên kết. Giáo viên mới vẫn tạo tài khoản theo cách cũ (mã mời / Admin trường), rồi liên kết.
- Gỡ liên kết bất cứ lúc nào ở cùng trang.

## An toàn

- OAuth 2.0 authorization code; `state` ngẫu nhiên dùng một lần, hết hạn sau 10 phút, gắn với phiên trình duyệt. Google dùng thêm PKCE (S256); Facebook dùng `appsecret_proof`. Bắt đầu đăng nhập/liên kết bằng POST có CSRF.
- **Không tự ghép tài khoản theo email** (email Facebook có thể chưa xác minh → chiếm tài khoản). Nhận diện bằng mã người dùng của nhà cung cấp; mỗi tài khoản ARS liên kết tối đa một Google và một Facebook; một Google không gắn được vào hai tài khoản cùng loại.
- Đăng nhập qua Google vẫn kiểm tra như đăng nhập mật khẩu: giáo viên bị khoá không vào được, học sinh phải còn trong lớp. Phiên được đổi mã (`session_regenerate_id`).
- ARS chỉ đọc tên, email và mã người dùng; không lưu access token.

## Giới hạn

- Chưa thử với Google/Facebook thật trong môi trường này (đã kiểm bằng nhà cung cấp giả lập tuân theo cùng giao thức). Cần nghiệm thu trên tên miền HTTPS của trường.
- Facebook yêu cầu app ở chế độ Live và có thể yêu cầu xác minh doanh nghiệp/chính sách quyền riêng tư; Google yêu cầu trang chính sách quyền riêng tư khi công khai app ngoài tổ chức.
- Không có đăng ký giáo viên bằng Google (tránh mở đăng ký tự do).

## Tệp

`db/migrate_007_oauth.sql`, `web/lib/oauth.php`, `web/oauth.php`. Sửa: `index.php`, `settings.php`, `student/index.php`, `student/account.php`, `parent/index.php`, `parent/home.php`, `assets/style.css`, `deploy/install_ubuntu.sh`. Test: `tests/oauth_integration.py` (72 kiểm tra).
