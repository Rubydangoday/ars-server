# ARS v10 — mở rộng giáo viên, học sinh và phụ huynh

Bản này phát triển trực tiếp từ `ars-server.zip` v9 được cung cấp. Giữ giao thức Remote hiện có, bổ sung LMS và phân quyền ở server. Không cần đổi firmware để dùng các trang web mới. Học sinh làm bài ở nhà; trên lớp tiếp tục dùng Remote.

## Cài mới hoặc nâng cấp

1. Sao lưu CSDL và thư mục uploads. Giữ `config.ini` cùng mật khẩu đang dùng, không chép đè bằng file mẫu.
2. Thay mã nguồn bằng bản này. Server đang chạy v9 đã có migrations 002–004 thì chạy:

   ```bash
   mysql -u root -p ars < db/migrate_005_lms.sql
   ```

   Cài mới: `deploy/install_ubuntu.sh` đã thêm migration 005. Nếu cài thủ công, chạy schema.sql rồi 002, 003, 004, 005 theo thứ tự, luôn chọn database `ars`. Migration 005 có thể chạy lại.
3. Khởi động lại Apache và ingest để nạp mã mới:

   ```bash
   sudo systemctl restart apache2 ars-ingest
   ```

4. Cần PHP 8.1+ (đã kiểm trên PHP 8.3), MariaDB 10.11, PDO MySQL, mbstring, fileinfo, XML, ZIP, cURL, Poppler. KaTeX 0.16.22 và fonts đã đóng gói cùng source, không cần CDN.
5. Upload vẫn lưu ngoài document root theo `[storage] uploads`. Kiểm tra quyền ghi của Apache. Cho `upload_max_filesize` ít nhất 20M và `post_max_size` lớn hơn tổng tệp của một bài (ví dụ 100M); giới hạn app vẫn là 20 MB/tệp. Installer cũ không tự thay các giá trị PHP này.
6. Ghi âm trực tiếp cần HTTPS hoặc localhost, quyền microphone và trình duyệt hỗ trợ MediaRecorder. Truy cập bằng HTTP IP LAN thường không ghi âm trực tiếp được; học sinh vẫn tải tệp ghi âm có sẵn. Dùng HTTPS cho truy cập ở nhà.

## Giáo viên

- **Câu hỏi → Soạn câu:** thêm nhập PDF có lớp chữ, xem trước và xác nhận từng câu. PDF scan cần OCR trước; không tự khôi phục ảnh, bố cục nhiều cột hay công thức từ PDF. Word/Excel giữ bộ đọc cũ.
- **Bộ câu hỏi:** có liên kết gắn hình/audio/video; xuất Excel, Word có/không đáp án, in/lưu PDF. Media được lưu riêng và kiểm tra quyền khi đọc. Xuất Word/Excel giữ nội dung chữ và đáp án; không nhúng media hay chuyển LaTeX thành công thức Word. In PDF có hình và công thức hiển thị qua KaTeX.
- Công thức nhập dạng `\(x^2\)` hoặc `$$E=mc^2$$`. KaTeX chạy cục bộ với `trust:false`. Không dùng HTML tùy ý trong nội dung.
- **Kho đề:** private, school, public. Quản trị vào **Trường** để gán giáo viên cùng trường. Public hiện cho giáo viên đăng nhập trên server; không phải phát đáp án công khai cho học sinh. Sao chép tạo bộ độc lập, gồm media. Mặc định bản sao là private.
- **Tạo mã đề:** sao chép, đảo thứ tự câu/phương án và tính lại đáp án đúng; không sửa đề gốc. Mỗi phiên Remote chỉ dùng một bộ đề chung. Chưa hỗ trợ mỗi ghế một mã trong cùng phiên; thiết bị không hiển thị nội dung đề riêng. Đề đã dùng cho homework bị khóa sửa/xóa câu tham chiếu để không đổi đáp án khi đang làm.
- **Bài tập:** tạo nháp → thêm câu → công bố. Có trắc nghiệm từ kho cá nhân, tự luận, vẽ/ảnh, ghi âm. Tự luận nhập đáp án mẫu/rubric. Không thêm câu sau khi đã có bài làm. Học sinh lưu nháp rồi nộp; server chặn sửa sau nộp/hết hạn.
- **Chấm / công bố:** trắc nghiệm chấm tự động; giáo viên chấm hình/âm thanh. AI chỉ gợi ý chấm tự luận **văn bản** có rubric bằng provider đã cấu hình (Ollama/LM Studio hoặc cloud). AI không tự phát hành điểm. Giáo viên xác nhận từng điểm, rồi công bố; còn câu chưa chấm thì không công bố được. Sửa điểm sẽ thu hồi công bố cho tới khi công bố lại. Bộ AI offline cơ bản không chấm tự luận.
- **Phụ huynh:** chọn học sinh có mã, đặt khối 1–12 cho gợi ý AI, tạo mã mời dùng một lần trong 7 ngày. Gửi mã đúng phụ huynh bằng kênh riêng. Thêm nhận xét và thu hồi liên kết khi cần. Chưa gửi email/tin nhắn tự động.
- **Thiết bị → Ghép Remote:** ghép MAC với ghế/học sinh, không chỉ nhãn số trên vỏ. Ingest kiểm tra cả login và answer. Ghế chưa ghép vẫn đăng nhập như v9. Một MAC chỉ ghép một ghế trong một lớp; đổi ghép ngoài giờ, điểm danh lại. Giáo viên thường chỉ thấy/sửa thiết bị có last_room thuộc lớp mình; quản trị thấy toàn server.
- **Máy chiếu:** toàn màn hình, bật/tắt âm 5 giây cuối; mặc định chỉ hiện biểu đồ khi giáo viên bật kết quả. Có tùy chọn hiện phân bố trong lúc nhận mà không lộ đáp án đúng. Lưu ý tùy chọn này có thể ảnh hưởng câu trả lời của học sinh. Nút khóa/mở và hạn giờ có sẵn được giữ nguyên.
- **Báo cáo:** điểm Remote, homework đã công bố, ma trận chủ đề, Excel và in/lưu PDF. Ma trận bỏ qua buổi vắng khi tính chủ đề, tính trung bình mức đạt của các câu được ghi nhận. Không gộp điểm thành điểm học kỳ. Chưa xác nhận mẫu sổ điểm Bộ GD&ĐT: cần mẫu của trường và quy tắc môn/cấp học trước khi dùng làm sổ chính thức.

## Học sinh và phụ huynh

- Học sinh: menu **Bài tập về nhà**, chọn đáp án, viết, vẽ hoặc ghi âm. Sau vẽ phải bấm **Đính kèm hình vẽ**, sau ghi âm bấm **Dừng và đính kèm**; sau đó **Lưu nháp/Nộp bài**. Tệp đã chọn nhưng chưa lưu chưa được gửi lên server. Ghi âm giới hạn 3 phút. Có tải ảnh/âm thanh từ thiết bị nếu API trình duyệt không hỗ trợ.
- Kết quả chỉ xuất hiện khi giáo viên công bố. Sổ câu sai gom Remote, trắc nghiệm homework đã công bố, và câu sai lần đầu của lượt luyện tập đã kết thúc. Có ôn lại và hỏi AI giải thích. Câu tự luận sai xem nhận xét ở bài tập; không được biến thành trắc nghiệm tự động.
- Thưởng 20 XP cho mỗi bài homework đã nộp, một lần/bài; huy hiệu bài đầu tiên và 10 bài. Giữ XP, level và chuỗi luyện tập có sẵn của v9.
- Phụ huynh: vào `/parent/` từ trang cổng. Đăng ký bằng mã mời, có tài khoản riêng, chỉ xem học sinh/lớp đã liên kết. Tài khoản đã có có thể thêm con/lớp bằng mã mời mới. Xem điểm, chuyên cần theo ngày, mức đạt chủ đề, nhận xét và tài liệu đã công bố. Có biểu đồ tiến độ và tổng hợp điểm/chuyên cần theo ngày hoặc tuần (12 kỳ gần nhất); chưa có thông báo đẩy.

## Đã kiểm thử và giới hạn xác nhận

Xem `tests/README.md` và `tests/RESULTS.md`. Các test tạo tài khoản giả trên MariaDB cô lập, kiểm tra HTTP PHP thực và ingest trực tiếp. Không dùng dữ liệu thật, không gửi dữ liệu ra dịch vụ AI bên ngoài; chấm AI kiểm qua endpoint giả lập tại localhost.

Chưa xác nhận chất lượng chấm của model thực, truy cập HTTPS của server triển khai, chất lượng microphone từng điện thoại hoặc gateway/Remote BLE thật. Các tính năng này cần chạy nghiệm thu với cấu hình trường. Bản v9 vẫn có tài khoản học sinh mật khẩu mặc định bằng mã học sinh trước lần đổi đầu tiên: nên cấp tài khoản/mật khẩu riêng trước mở Internet. Bản này không thay hệ thống đăng nhập cũ hoặc tự cài reverse proxy.

## Tệp chính

Migration: `db/migrate_005_lms.sql`. Trang giáo viên: `homework.php`, `homework_grade.php`, `family.php`, `library.php`, `variants.php`, `media.php`, `mapping.php`, `schools.php`, `analytics.php`, `set_export.php`, `set_docx.php`, `set_print.php`. Cổng mới: `web/parent/`, `web/student/homework.php`. Helper: `web/lib/lms.php`, `web/lib/variants.php`. Assets: `capture.js`, `math.js`, KaTeX (giấy phép MIT đính kèm).
