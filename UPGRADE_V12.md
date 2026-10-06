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

**Đăng nhập / đăng ký nhanh (không cần liên kết trước):** bấm **Đăng nhập với Google** (hoặc **Đăng ký với Google** ở trang tạo tài khoản). Nếu tài khoản Google chưa gắn với ARS, ARS chuyển tới **bước cuối** của từng vai trò, không cần mật khẩu:
- **Giáo viên:** chế độ đăng ký *Ai có link cũng đăng ký được* → tạo tài khoản và vào thẳng; *Cần mã mời* → nhập mã mời giáo viên; *Đóng đăng ký* → chỉ người đã có tài khoản liên kết được. Đổi chế độ ở menu **Giáo viên** (quản trị).
- **Học sinh:** nhập mã học sinh (+ mã tham gia lớp nếu chưa có trong danh sách). Mã đã có mật khẩu thì không tạo được bằng Google khác — đăng nhập bằng mật khẩu rồi liên kết.
- **Phụ huynh:** chỉ nhập mã mời thầy cô gửi.
- **Đã có tài khoản:** đăng nhập bằng mật khẩu một lần → ARS hỏi *“Liên kết Google … với tài khoản này?”* → **Liên kết**. ARS không tự gắn (an toàn cho máy tính dùng chung).
- Tài khoản tạo bằng Google có mật khẩu ngẫu nhiên. Muốn đặt mật khẩu: Cài đặt → Tài khoản → *Tạo mã khôi phục* → trang *Quên mật khẩu*.

**Cách cũ vẫn dùng được:**

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

## Bổ sung: học sinh đăng ký không cần mã lớp (migration 008)

```bash
mysql -u root -p ars < db/migrate_008_student_selfsignup.sql
```

- Trang **Tạo tài khoản mới** của học sinh: chỉ cần MSSV + họ tên + mật khẩu (hoặc Google/Facebook). *Mã tham gia lớp* không bắt buộc.
- Học sinh chưa có lớp vẫn đăng nhập được; trang **Tổng quan** hiện ô **Vào lớp học** để nhập mã 6 ký tự giáo viên cho. Học sinh đã có lớp vẫn vào thêm lớp khác ở cuối trang Tổng quan. Link `/hs/MÃLỚP` khi đã đăng nhập tự điền sẵn mã.
- An toàn: tài khoản tự tạo khi MSSV chưa có trong danh sách lớp nào bị đánh dấu *chưa xác minh* (mật khẩu mặc định = MSSV không áp dụng). Vào lớp bằng mã thì hết đánh dấu. Nếu giáo viên **tự thêm** MSSV đó vào danh sách, trang lớp hiện **⚠ tự tạo, cần kiểm tra** — hỏi học sinh; không phải thì bấm **đặt lại MK** (xoá tài khoản và cả liên kết Google/Facebook).
