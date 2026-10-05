# ARS v11 — trường học, Cloud, thi đua, báo phụ huynh

Phát triển tiếp từ v10. Không đổi giao thức Remote và không cần đổi firmware.

```
TRONG LỚP: Remote ──BLE/RF──> ARS server local (máy GV, chạy offline) ──> Màn chiếu
                                        │  "Sync to Cloud" (cuối ngày / khi có Internet)
                                        ▼
KHI VỀ NHÀ: ARS server Cloud (cùng mã nguồn) ── Web GV (bài tập, chấm AI, báo cáo)
                                             ── Web HS (BTVN, ôn câu sai, AI tutor, thi đua)
                                             ── Web PH (tiến độ) + tin Zalo / SMS
```

## Nâng cấp

1. Sao lưu CSDL và thư mục uploads. Thay mã nguồn ở **cả server lớp học và server Cloud** (đồng bộ yêu cầu hai bên cùng phiên bản).
2. Chạy migration (chạy lại được nhiều lần):

   ```bash
   mysql -u root -p ars < db/migrate_006_school_cloud_rewards.sql
   sudo systemctl restart apache2 ars-ingest
   ```

   Cài mới: `deploy/install_ubuntu.sh` đã có migration 006.
3. Cần PHP cURL (`php-curl`) cho đồng bộ và Zalo/SMS.

## 1. Phân quyền tổ chức

- **Quản trị server** (menu *Trường*): tạo trường/trung tâm, gán giáo viên vào trường, cấp quyền **Admin trường**.
- **Admin trường** (Ban giám hiệu / chủ trung tâm, menu *Trường của tôi*): tạo tài khoản giáo viên của trường (mật khẩu tạm hiện một lần), khoá/mở, đặt lại mật khẩu, cấp/bỏ Admin trường; tạo **lớp hành chính** (VD 10A1) và gán **GV chủ nhiệm**; sửa lớp hành chính/môn của mọi lớp bộ môn; xem báo cáo (chỉ đọc) mọi lớp trong trường. Admin trường không đổi được tài khoản quản trị server.
- **GV bộ môn**: chủ lớp như trước. Trong trang lớp điền thêm *Môn học* và *Lớp hành chính*.
- **GV chủ nhiệm**: xem báo cáo (chỉ đọc) mọi lớp bộ môn cùng lớp hành chính, trang **tổng hợp lớp** (`cohort.php`: % bấm đúng và chuyên cần từng môn, xuất Excel), tạo mã mời phụ huynh và nhận xét. Không sửa lớp, đề hay buổi học của GV bộ môn.
- **Học sinh / phụ huynh**: như v10.

## 2. Đồng bộ Hybrid (Cloud)

- Server Cloud là một bản ARS khác (cùng mã nguồn) mở trên Internet, nên có HTTPS.
- Trên **Cloud**: giáo viên vào *Cloud → Nhận dữ liệu từ server lớp học*, tạo **mã đồng bộ** (mỗi máy lớp học một mã, thu hồi được).
- Trên **server lớp học**: *Cloud → Địa chỉ Cloud + mã đồng bộ → Lưu & kiểm tra kết nối*, rồi bấm **⬆ Sync to Cloud** khi có mạng.
- Dữ liệu gửi: lớp (tên, môn, lớp hành chính, khối), danh sách học sinh, bộ câu hỏi đã dùng, buổi đã kết thúc, điểm danh và đáp án cuối từng học sinh. Không gửi bài giảng, tệp, ghi âm, mật khẩu, nhật ký gói.
- Một chiều, idempotent: gửi lại không nhân đôi. Mỗi lần gửi các buổi chưa đồng bộ theo lô 20 buổi; đổi trạng thái *Công bố* ở server lớp học thì buổi đó được gửi lại. *Gửi lại toàn bộ* dùng khi khôi phục Cloud.
- Server lớp học là chuẩn cho lớp/học sinh: sửa danh sách ở server lớp học rồi đồng bộ (ghế bị xoá ở local cũng bị xoá trên Cloud). Bài tập về nhà, mã mời phụ huynh, nhận xét tạo trên Cloud. Nếu mất mạng, dữ liệu vẫn nằm ở server lớp học; bấm lại khi có mạng.
- Giới hạn: không đồng bộ ngược Cloud → local; buổi đang chạy không gửi; xoá lớp ở local không xoá trên Cloud.

## 3. Thi đua & đổi quà (menu *Thi đua*)

- Điểm thi đua: **10 điểm/câu bấm Remote đúng** (buổi đã kết thúc và công bố) + **tối đa 10 điểm/câu bài tập về nhà** theo điểm giáo viên đã công bố. Tách biệt với XP luyện tập của v9.
- Bảng xếp hạng **tuần / tháng / từ đầu năm**, đồng hạng, in/chiếu được. Giáo viên ẩn/hiện bảng với học sinh.
- Cửa hàng quà theo lớp: **Avatar**, **Huy hiệu** (nhận ngay) và **Quà ở lớp** (chờ giáo viên trao; từ chối thì hoàn điểm và số lượng). Có bộ quà mẫu. Giới hạn số lượng tuỳ chọn.
- Học sinh: tab **🏆 Thi đua** — điểm còn lại, hạng, đổi quà, chọn avatar (hiện trên thanh đầu trang). Điểm còn lại = tổng điểm tích luỹ − điểm đã đổi; chặn đổi quá điểm kể cả khi bấm nhiều lần cùng lúc.
- Phụ huynh thấy điểm thi đua và hạng của con.

## 4. Báo kết quả qua Zalo / SMS (menu *Cloud → Thông báo Zalo / SMS*)

- Quản trị server cấu hình (nên làm trên Cloud vì cần Internet):
  - **Zalo OA – ZNS**: access token OA, template ID đã được Zalo duyệt, ánh xạ tham số (VD `student_name=hs,correct=dung,total=tong,date=ngay`). Access token OA hết hạn định kỳ — cập nhật khi nhật ký báo lỗi token.
  - **SMS**: cổng HTTP nhận `POST {"phone","message"}` JSON + `Authorization: Bearer`. eSMS/SpeedSMS… cần một webhook trung gian chuyển định dạng. Mặc định gửi không dấu.
  - Mẫu tin: `Hôm nay {ngay}, cháu {hs} đã trả lời đúng {dung}/{tong} câu hỏi bằng Remote trên lớp {mon} ({lop}).` Học sinh vắng nhận tin vắng mặt.
  - **Chế độ thử**: chỉ ghi nhật ký, không gửi ra ngoài.
  - **Tự gửi khi đồng bộ**: Cloud tự gửi khi nhận buổi vừa được công bố từ server lớp học.
- **Phụ huynh tự đăng ký** số điện thoại và kênh trong cổng phụ huynh (đồng ý nhận tin, tắt được bất cứ lúc nào). Giáo viên không nhập số hộ.
- Giáo viên: trang **Kết quả** buổi học (đã kết thúc + công bố) → **📨 Gửi báo cáo Zalo/SMS**. Mỗi (buổi, phụ huynh, học sinh) chỉ một tin; lỗi được thử lại tối đa 3 lần từ trang Thông báo.
- Chưa xác nhận với Zalo/nhà mạng thật: cần OA, template và hợp đồng SMS của trường. Phí tin nhắn do nhà cung cấp tính.

## Kiểm thử

`tests/v11_integration.py` (chạy sau `http_smoke.py`, xem `tests/README.md`): 178 kiểm tra gồm phân quyền trường/chủ nhiệm, đồng bộ (sai mã, gửi lại không trùng, công bố muộn, thu hồi mã), thi đua (đổi quá điểm, mua trùng, hoàn điểm), Zalo/SMS qua stub localhost. Các bộ test v10 vẫn qua (smoke 171, ingest 7, extended 57).

## Tệp chính

Migration `db/migrate_006_school_cloud_rewards.sql`. Trang: `school.php`, `cohort.php`, `schools.php`, `rewards.php`, `sync.php`, `notify.php`, `api/sync.php`, `student/rewards.php`. Thư viện: `lib/school.php`, `lib/rewards.php`, `lib/sync.php`, `lib/notify.php`. Sửa: `lib/bootstrap.php` (vai trò, menu, miễn CSRF cho API dùng mã), `lib/student.php` (tab, avatar), `analytics.php`, `family.php`, `class.php`, `results.php`, `parent/home.php`, `assets/style.css`, `deploy/install_ubuntu.sh`.
