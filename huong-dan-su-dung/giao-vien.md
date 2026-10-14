# Hướng dẫn cho giáo viên

[← Mục lục](README.md)

## Mục lục
1. [Chuẩn bị](#1-chuẩn-bị)
2. [Tạo lớp và danh sách học sinh](#2-tạo-lớp-và-danh-sách-học-sinh)
3. [Soạn bộ câu hỏi](#3-soạn-bộ-câu-hỏi)
4. [Dạy trên lớp bằng Remote](#4-dạy-trên-lớp-bằng-remote)
5. [Xem kết quả](#5-xem-kết-quả)
6. [Buổi học và bài giảng](#6-buổi-học-và-bài-giảng)
7. [Bài tập về nhà](#7-bài-tập-về-nhà)
8. [Thi đua và quà](#8-thi-đua-và-quà)
9. [Phụ huynh và nhận xét](#9-phụ-huynh-và-nhận-xét)
10. [Thiết bị Remote](#10-thiết-bị-remote)
11. [Cài đặt và trợ lý AI](#11-cài-đặt-và-trợ-lý-ai)
12. [Chức năng quản trị](#12-chức-năng-quản-trị)
13. [Câu hỏi thường gặp](#13-câu-hỏi-thường-gặp)

---

## 1. Chuẩn bị

**Máy chủ:** laptop cài ARS (xem [HUONG_DAN_CAI_DAT.md](../HUONG_DAN_CAI_DAT.md)). Cắm **gateway nRF52840** vào cổng USB của laptop trong giờ học, vì nó thu đáp án từ Remote.

**Đăng nhập:** ở bất cứ đâu mở **https://ngoc-legion-5-15arp8.tail4be8bf.ts.net/gv**; trong lớp cùng Wi-Fi với máy chủ có thể dùng `http://ars.local/gv`.
Gửi học sinh link **https://ngoc-legion-5-15arp8.tail4be8bf.ts.net/hs** để làm bài ở nhà (laptop máy chủ phải đang bật và có mạng).
- Lần đầu cài máy chủ: trang đăng nhập cho tạo **tài khoản quản trị**.
- Giáo viên khác: bấm **Đăng ký**. **Mã mời giáo viên** (do quản trị viên cấp) có thể nhập ngay hoặc để trống và nhập sau.
  - Có mã đúng: tài khoản dùng đầy đủ ngay.
  - Để trống: tài khoản vẫn được tạo và vào thẳng trang Tổng quan (lần đầu trang này hiện **mã khôi phục mật khẩu**, hãy chép lại). Tài khoản ở trạng thái **chưa xác nhận**: dạy, soạn câu hỏi, quản lý lớp bình thường, nhưng **trợ lý AI chạy chế độ Cơ bản** và **chưa tải tệp lên được** (bài giảng, ảnh, âm thanh). Khi có mã, nhập vào **thanh nhắc màu vàng** ở đầu trang rồi bấm **Xác nhận**.
  - Nhập sai mã lúc đăng ký thì báo lỗi, không tạo tài khoản.
- Có thể đăng nhập bằng Google nếu quản trị viên đã bật.
- Quên mật khẩu: bấm **Quên mật khẩu**.
  - Nếu mở ngay trên laptop máy chủ, đặt lại được luôn.
  - Nếu mở từ máy khác, cần **mã khôi phục** (hiện khi tạo tài khoản, tạo lại được trong Cài đặt).
  - Hoặc trên terminal máy chủ chạy `ars reset-gv <tên đăng nhập>`.

**Thanh menu trên cùng:**

| Mục | Dùng để |
|---|---|
| Tổng quan | Số liệu chính, buổi đang chạy, việc cần làm |
| Lớp học | Danh sách lớp, học sinh, bắt đầu buổi học |
| Câu hỏi | Soạn câu hỏi, ngân hàng bộ câu hỏi |
| Bài tập | Bài tập về nhà, chấm bài |
| Kết quả | Kết quả từng buổi dạy bằng Remote, lịch sử luyện tập của học sinh |
| Thêm ▾ | Buổi học, Kho đề, Báo cáo, Thi đua, Phụ huynh, Thiết bị, Cloud |

Nút **▶ Bắt đầu** màu cam luôn ở góc phải để mở nhanh buổi học. Khi đang có buổi chạy, viên trạng thái bên cạnh cho biết lớp nào và bao nhiêu em đã điểm danh; bấm vào để về bảng điều khiển.

---

## 2. Tạo lớp và danh sách học sinh

1. **Lớp học → Tạo lớp**. Nhập:
   - **Tên lớp**, **môn học**, **lớp hành chính** (để giáo viên chủ nhiệm xem được báo cáo).
   - **Mã lớp (room, 1–255)**: con số mà Remote của học sinh phải nhớ. Mỗi phòng học dùng một room. Room này phải trùng với `[gateway] room` trong `config.ini` của máy chủ (hỏi người cài máy chủ nếu đổi room).
2. Vào lớp, thêm học sinh:
   - Từng em: MSSV, họ tên, **số ghế (1–99)**, **SĐT phụ huynh**. Số ghế chính là **ID học sinh gõ trên Remote**. SĐT phụ huynh dùng làm tên đăng nhập của phụ huynh (xem mục 9).
   - Hoặc bấm **Nhập danh sách (Excel, CSV, Word, TXT)** để nhập cả lớp từ file. Xem trước rồi mới bấm xác nhận:
     - **Excel (.xlsx)** hoặc **CSV** (dấu `,` hoặc `;`, UTF-8);
     - **Word (.docx)**: một bảng, hoặc mỗi dòng một học sinh;
     - **.txt**: mỗi dòng một học sinh, ví dụ `1. 23134001 Nguyễn Văn An` hoặc `23134001 - Nguyễn Văn An`.

     Cột được nhận theo tiêu đề, có dấu hay không dấu đều được: **STT** (số ghế), **MSSV / Mã HS**, **Họ tên**, **SĐT phụ huynh**. Nếu file tách thành hai cột **Họ đệm** và **Tên** thì hệ thống tự ghép lại. Nếu file không có cột STT, số ghế được đánh 1, 2, 3… theo thứ tự trong file; phần xem trước ghi *(tự đánh số)*. File `.doc` cũ cần mở bằng Word rồi lưu lại thành `.docx`.
3. **Mã tham gia lớp** (ví dụ `FDDC78`) hiện trong trang lớp, kèm link `http://…/hs/FDDC78` và nút **QR** để chiếu cho cả lớp quét. Học sinh chưa có trong danh sách dùng mã này để tự đăng ký vào lớp và được cấp số ghế trống tiếp theo.
   - **Khoá đăng ký**: ngừng nhận học sinh mới.
   - **Đổi mã**: tạo mã mới khi mã cũ bị lộ.
4. Học sinh quên mật khẩu: trong danh sách học sinh bấm **đặt lại MK**.

---

## 3. Soạn bộ câu hỏi

### 3.1 Soạn nhanh: Câu hỏi → Soạn câu hỏi
Trang chia hai cột.

**Bên trái — Nhập câu hỏi:**
- **Gõ / dán văn bản**: thanh công cụ giống Word (đậm, nghiêng, gạch chân, màu chữ, ký hiệu, **công thức LaTeX** có xem trước). Phím tắt: `Ctrl+B / I / U`, `Ctrl+M` chèn công thức, `Ctrl+Enter` tách câu.
- **Tải file Word / Excel**: kéo thả file vào khung. Có **file Word mẫu** và **file Excel mẫu** để tải về làm theo.
  - Cách đánh dấu đáp án đúng: gạch chân hoặc tô đỏ phương án đúng, đặt dấu `*` trước chữ cái, thêm dòng `Đáp án: B`, hoặc bảng đáp án cuối đề.
- Bấm **Tách câu hỏi**: hệ thống tự tách từng câu và cho xem trước. Câu hỏi **chỉ được lưu khi bấm thêm** vào bộ câu hỏi.

**Bên phải — Trợ lý AI:**
- Chọn **số câu**, **mức độ**, **số lựa chọn**, **nguồn tài liệu** (file bài giảng hoặc để AI tự soạn), rồi bấm soạn.
- Mỗi câu AI gợi ý có nút thêm riêng; nút **✓ Thêm tất cả vào bài kiểm tra** thêm hết một lần.
- Tab **💬 Hỏi đáp AI**: trò chuyện với AI như ChatGPT (hỏi cách giảng, xin ví dụ, nhờ sửa câu hỏi).
- AI chỉ hoạt động khi đã cấu hình ở **Cài đặt → Trợ lý AI** (mục 11).

### 3.2 Quản lý bộ câu hỏi: Câu hỏi → Ngân hàng
- Mỗi **bộ câu hỏi** có 2–10 lựa chọn mỗi câu (A–J), có thể sắp thứ tự, sửa, chèn hình, công thức.
- Bộ dùng với Remote tối đa **64 câu**.
- Khi soạn xong, bấm **Xác nhận hoàn tất** (có thể đặt số câu mục tiêu). Bộ đã hoàn tất hiện nhãn "đã hoàn tất"; muốn sửa tiếp bấm **Mở lại**. Bộ đang soạn hiện nhãn "đang soạn" ở trang lớp khi chọn đề.
- Xuất bộ câu hỏi ra Word/Excel hoặc **in** để phát giấy.

### 3.3 Kho đề (Thêm → Kho đề)
Kho đề dùng chung giữa các giáo viên. Từ một bộ câu hỏi có thể tạo **mã đề trộn** (đảo thứ tự câu và phương án, đáp án tự đổi theo); bộ gốc không bị thay đổi.

---

## 4. Dạy trên lớp bằng Remote

### 4.1 Bắt đầu buổi
1. Vào **lớp** → khung **Bắt đầu buổi học**: chọn **buổi**, **bộ câu hỏi**, **loại** (buổi học / kiểm tra), tuỳ chọn *Cho học sinh đổi đáp án khi câu còn mở*.
2. Bấm **Bắt đầu** → mở **bảng điều khiển** buổi học.
3. Trên bảng điều khiển, dòng trạng thái phải là **Gateway: đang nhận**. Nếu hiện *chưa kết nối*: kiểm tra gateway đã cắm USB, hoặc nhờ người quản lý máy chủ chạy `ars status`. Gateway cắm vào máy của bạn (không phải máy chủ): bấm **🔌 Gateway USB máy này** (Chrome/Edge).

### 4.2 Điểm danh
Học sinh bật Remote, nhập **số ghế** rồi bấm **OK**. Khi đã điểm danh, ô ghế của em trên bảng điều khiển chuyển màu và Remote sáng đèn xanh.

Hệ thống **tự điểm danh**: em nào đăng nhập bằng Remote, hoặc có câu trả lời được nhận trong buổi, được tính là **có mặt**. Em không có cả hai được tính là **vắng**. Ví dụ, em làm xong bài kiểm tra bằng Remote thì đã được tick có mặt.

- **Sửa điểm danh**: bấm nút **📋 Điểm danh** ở trang kết quả buổi, hoặc ở mục *Các buổi đã dạy* trong trang lớp. Với mỗi em, chọn *Tự động*, *Có mặt*, *Vắng* hoặc *Vắng có phép*, có thể ghi chú (ví dụ: Remote hỏng, làm bài giấy), rồi bấm **Lưu điểm danh**.
- **Điểm danh cả lớp**: trong trang lớp, bấm **🗓 Điểm danh**. Trang này có bảng mọi buổi × học sinh (✓ có mặt · ✗ vắng · P vắng có phép), kèm tỉ lệ có mặt của từng em, và có nút **Xuất Excel** / **CSV**.
- **Báo cho phụ huynh**: khi bạn bấm **Kết thúc buổi**, mỗi phụ huynh đã liên kết sẽ nhận một tin trong mục **🔔 Thông báo** ở trang phụ huynh. Tin cho biết con có mặt hay vắng, và có điểm nếu kết quả đã công bố.
  - Với bài kiểm tra chưa công bố, tin ghi *"Điểm sẽ được báo khi thầy cô công bố"*. Khi bạn bấm công bố, chính tin đó được thêm điểm.
  - Khi bạn sửa điểm danh, tin cũng được cập nhật.
  - Trong cả hai trường hợp, tin hiện lại là **chưa đọc** để phụ huynh thấy thay đổi.

### 4.3 Hỏi từng câu

| Nút | Tác dụng |
|---|---|
| **Mở câu** | Học sinh bắt đầu bấm được. Nếu câu có **giới hạn thời gian** (đặt khi soạn câu), đồng hồ đếm ngược và hết giờ câu tự đóng |
| **Đóng** | Ngừng nhận đáp án |
| **Hiện kết quả** | Hiện biểu đồ phân bố đáp án trên màn chiếu (bấm lần nữa để ẩn) |
| **Câu tiếp** | Sang câu sau |
| **Kết thúc** | Kết thúc buổi; mọi câu đang mở sẽ đóng |

- **Màn chiếu**: mở trang màn chiếu trên máy nối máy chiếu. Trang hiện câu đang chiếu, đồng hồ, số em đã trả lời; biểu đồ chỉ hiện khi bạn bấm **Hiện kết quả** (tránh học sinh bị ảnh hưởng).
- Học sinh có thể dùng phím ◀ ▶ trên Remote để chuyển giữa các câu đang mở.
- **Học sinh đổi Remote giữa giờ**: bấm vào ô ghế của em để **giải phóng ID**, rồi cho em điểm danh lại bằng Remote mới.

### 4.4 Không có Remote?
Chạy thử bằng Remote giả lập: trên máy chủ gõ `ars sim 30` (30 Remote, room 1), rồi bấm **Mở câu**: các ô sẽ chuyển xanh dần.

---

## 5. Xem kết quả

**Kết quả** liệt kê mọi buổi đã dạy, có thẻ số liệu (tỉ lệ đúng trung bình, điểm danh), biểu đồ tỉ lệ đúng theo thời gian và bộ lọc theo lớp.

Bấm vào một buổi để xem:
- **Theo câu**: tỉ lệ đúng (p), độ phân biệt (D), phương án nhiễu được chọn nhiều, thời gian trả lời.
- **Theo học sinh**: từng em đúng/sai câu nào.
- **Xuất Excel (.xlsx)**: 3 sheet — theo câu, theo học sinh (điểm thang 10, đáp án từng câu), toàn bộ lượt gửi.
- **Xuất điểm CSV**: chỉ bảng điểm theo học sinh, mở được bằng Excel / Google Sheets / phần mềm quản lý điểm.

Các nơi khác:
- **Kết quả → Luyện tập của học sinh**: lịch sử học sinh tự ôn trên web (bài được giao, ôn câu sai, ôn chủ đề, làm lại buổi).
- **Thêm → Báo cáo**: báo cáo kỹ năng và điểm theo lớp, theo chủ đề; **Xuất Excel** hoặc **Xuất điểm CSV** (mọi buổi và bài tập đã công bố).

File CSV dùng dấu `;` và mã UTF-8 (Excel tiếng Việt mở đúng dấu). Ô bắt đầu bằng `=`, `+`, `-`, `@` được thêm dấu `'` để Excel không coi là công thức.

Sau mỗi buổi, học sinh tự xem lại được câu đúng/sai và rút kinh nghiệm trong trang học sinh.

---

## 6. Buổi học và bài giảng

**Thêm → Buổi học** (hoặc nút *10 buổi học & bài giảng* trong trang lớp): chương trình của lớp, mặc định 10 buổi. Mỗi buổi có:
- **File bài giảng** (PDF, Word, PowerPoint…), xem trước ngay trên web; học sinh xem ở tab **Bài giảng**.
- **Kiến thức trọng tâm**.
- **Câu hỏi củng cố** để học sinh ôn sau buổi.
- Nút **dạy bằng clicker** để mở buổi học bằng Remote gắn với buổi này.

---

## 7. Bài tập về nhà

**Bài tập → ＋ Giao bài mới**:
1. Nhập tên bài, chủ đề, hướng dẫn, hạn nộp → **Tạo bản nháp**.
2. **＋ Thêm câu**: trắc nghiệm (lấy từ ngân hàng), **tự luận**, **vẽ tay** hoặc **ghi âm**. Mỗi câu có điểm tối đa, đáp án mẫu / tiêu chí chấm.
3. **Công bố** để học sinh thấy bài.
4. Khi có bài nộp: **Chấm / công bố kết quả**. Câu trắc nghiệm tự chấm; câu tự luận/vẽ/ghi âm bạn chấm điểm và ghi nhận xét. Học sinh và phụ huynh thấy kết quả sau khi công bố.

5. **Xuất bảng điểm** (trang bài tập hoặc trang chấm bài): **Excel** gồm sheet *Diem* (mọi học sinh trong lớp, cả em chưa nộp: trạng thái, thời điểm nộp, điểm từng câu, tổng, thang 10) và sheet *Nhan xet*; hoặc **CSV** chỉ bảng điểm. Thang 10 chỉ có khi bài đã chấm hết.

Trạng thái bài: *Bản nháp* → *Đang giao* → *Hết hạn*; số bài *chờ chấm* hiện ngay trong danh sách.

Gợi ý: nút **Giao bài ôn tập** trong trang lớp giao nhanh một bộ câu hỏi để học sinh luyện trên web.

---

## 8. Thi đua và quà

**Thêm → Thi đua**:
- **Bảng xếp hạng** tuần/tháng, tính từ câu đúng trên Remote và bài tập về nhà. Có nút **In / chiếu bảng xếp hạng**.
- **Cửa hàng quà** của lớp: tạo món quà và số điểm cần đổi.
- **Duyệt quà**: học sinh đổi quà thực tế, bạn bấm trao để xác nhận.

---

## 9. Phụ huynh và nhận xét

**Thêm → Phụ huynh**:
- **Mời phụ huynh**: mỗi học sinh có **mã mời ngắn** (dạng `K7PM-3QXA`) và **mã QR**, hiệu lực **7 ngày**, dùng một lần. Có thể **in phiếu mời cả lớp** để phát cho học sinh mang về.
- Quản lý liên kết phụ huynh ↔ học sinh: **thu hồi** liên kết hoặc **huỷ các mã mời** chưa dùng khi cần.
- **Gửi nhận xét** cho từng em; phụ huynh đọc ở trang của mình.
- Nếu quản trị viên đã cấu hình Zalo OA / SMS (**Thêm → Cloud → Thông báo**), kết quả buổi học có thể gửi tự động cho phụ huynh.

### 9.1 Tài khoản phụ huynh bằng số điện thoại

Cách này giống cổng phụ huynh mà nhiều trường đang dùng: giáo viên cấp tài khoản, phụ huynh đăng nhập bằng số điện thoại.

1. **Nhập SĐT phụ huynh** vào danh sách lớp, theo một trong hai cách:
   - thêm cột **SĐT phụ huynh** vào file danh sách rồi nhập lại (file mẫu đã có sẵn cột này; nhập lại file không có cột SĐT thì SĐT cũ vẫn được giữ);
   - hoặc bấm **Sửa** từng em trong trang lớp.
2. Vào **Thêm → Phụ huynh** (hoặc nút **📱 Tài khoản phụ huynh** trong trang lớp), mục **Tài khoản phụ huynh bằng số điện thoại**. Chọn **Excel** hoặc **CSV** rồi bấm **⬇ Cấp tài khoản & tải file mật khẩu**.
3. File tải về có các cột: STT, họ tên học sinh, MSSV, lớp, **SĐT phụ huynh (tên đăng nhập)**, **mật khẩu lần đầu** (8 ký tự, tự tạo), trang đăng nhập và ghi chú. Hãy in phiếu hoặc gửi riêng cho từng phụ huynh, không đăng lên nhóm chung.
4. Phụ huynh đăng nhập bằng SĐT và mật khẩu đó. Hệ thống **bắt buộc đổi mật khẩu** ngay lần đầu; chưa đổi thì chưa xem được gì.

Lưu ý:
- Mật khẩu chỉ có trong file tải về, vì máy chủ chỉ lưu dạng mã hoá. Lần sau bấm lại thì chỉ học sinh mới được cấp mật khẩu. Muốn tạo lại mật khẩu cho những phụ huynh **chưa đăng nhập** (ví dụ mất phiếu), tick **Cấp lại mật khẩu…** trước khi bấm.
- Anh chị em có **cùng SĐT phụ huynh** dùng chung một tài khoản, kể cả khi học khác lớp.
- **Phụ huynh quên mật khẩu**: trong bảng học sinh, cột *Tài khoản SĐT*, bấm **cấp lại MK**. Mật khẩu mới hiện một lần, và phụ huynh phải đổi lại khi đăng nhập.
- **Đổi SĐT** của một em thì tài khoản theo SĐT cũ bị bỏ liên kết ở lần cấp tiếp theo.
- Tài khoản dùng chung với lớp của giáo viên khác thì chỉ được liên kết thêm con; muốn cấp lại mật khẩu, nhờ giáo viên lớp đó.
- Nên tải file **Excel**: mở file CSV bằng Excel có thể làm mất số 0 đầu SĐT.

Phụ huynh tự đăng ký bằng mã mời mà quên mật khẩu: cấp **mã mời mới** cho em đó.

---

## 10. Thiết bị Remote

**Thêm → Thiết bị**:
- **Gateway**: trạng thái gateway đang nhận (cổng, gói/giây, lần cập nhật cuối).
- **Clicker**: mọi Remote máy chủ đã nghe thấy, gồm địa chỉ (MAC), **số trên vỏ** (bạn tự đặt), room, ghế gần nhất, **pin**, **RSSI** (độ mạnh sóng), lần cuối thấy, tỉ lệ gói trùng.
- **Ghép Remote với học sinh**: gắn cố định một Remote cho một số ghế. Remote khác bấm vào ghế đó sẽ bị từ chối, chống mượn máy bấm hộ. Nên đổi ghép ngoài giờ học.

Đọc nhanh: pin dưới khoảng 2,6 V là cần thay pin. RSSI càng âm (ví dụ −90) là càng xa gateway. Gateway đã lọc các bản lặp trong một lần bấm, nên "gói trùng" còn lại chủ yếu là các lần Remote phải gửi lại vì chưa thấy xác nhận: Remote nào có tỉ lệ này cao thường ở xa hoặc bị che sóng.

---

## 11. Cài đặt và trợ lý AI

**Cài đặt** (menu tài khoản góc phải):
- **Đường dẫn cố định**: link cho giáo viên/học sinh trong mạng trường và qua Internet, có nút **Chép** và **QR**.
- **Kết nối clicker**: cách máy chủ thu Remote (gateway cắm máy chủ, hoặc cắm máy giáo viên qua trình duyệt).
- **Trợ lý AI**: chọn nhà cung cấp:
  - **Cơ bản** (không cần mạng, gợi ý đơn giản).
  - **Google Gemini** (có gói miễn phí): lấy API key ở Google AI Studio bằng **Gmail cá nhân**, dán vào ô key, bấm **Kiểm tra** rồi **Lưu**.
  - **Claude (Anthropic)** hoặc **OpenAI**: cần API key trả phí.
  - Sau khi lưu, ô key hiện "✓ Đã lưu key …abcd". Không dán mật khẩu đăng nhập vào ô key.
- **Tài khoản**: đổi mật khẩu, ngôn ngữ, liên kết Google.

---

## 12. Chức năng quản trị

Chỉ tài khoản **quản trị** thấy:
- **Giáo viên**: tạo mã mời giáo viên, chế độ đăng ký, khoá/mở tài khoản, cấp quyền, đặt lại mật khẩu. Giáo viên đăng ký chưa có mã hiện nhãn *chưa xác nhận*; bấm **Duyệt** để xác nhận mà không cần mã.
- **Trường**: tạo trường/trung tâm, gán giáo viên, cấp quyền **Admin trường** (ban giám hiệu xem báo cáo các lớp).
- **Cloud**: đồng bộ "Hybrid". Máy chủ ở lớp chạy offline; khi có mạng, nhập địa chỉ Cloud + mã đồng bộ rồi bấm **Lưu & kiểm tra kết nối**. Chỉ cần làm một lần. Từ đó, máy chủ ở lớp **tự đồng bộ**: cứ mỗi 3 phút khi máy bật và có mạng, nó tự đẩy buổi mới, điểm và điểm danh lên Cloud. Muốn gửi ngay thì bấm **Sync to Cloud**. Trang Cloud có nút tắt chế độ tự động nếu không muốn dùng.
- **Đăng nhập Google** và **Zalo/SMS** cấu hình trong Cài đặt.

---

## 13. Câu hỏi thường gặp

**Bảng điều khiển báo "Gateway: chưa kết nối".**
Kiểm tra gateway đã cắm USB. Trên máy chủ chạy `ars status`, xem `ars-bridge` phải là `active`; `ars ports` để xem cổng (sai cổng thì sửa `[gateway] serial` trong `config.ini`), rồi `sudo systemctl restart ars-bridge`.

**Học sinh bấm mà ô không đổi màu.**
- Remote nhớ sai room. Em nhập lại số ghế; room phải trùng mã lớp.
- Câu chưa **Mở** hoặc đã **Đóng**.
- Số ghế không có trong danh sách lớp.
- Remote đã ghép cố định cho ghế khác.
- Xem bảng **Gói bị server từ chối** trên bảng điều khiển: mỗi gói bị loại đều ghi lý do.

**Hai học sinh cùng số ghế.**
Ghế đã có Remote khác điểm danh thì máy sau bị từ chối. Bấm ô ghế để giải phóng nếu cần.

**Mất Internet có dạy được không?**
Được. Máy chủ chạy trên laptop, Remote gửi qua gateway cắm USB. Chỉ AI (Gemini/Claude/OpenAI) và đồng bộ Cloud cần Internet.

**AI báo lỗi 403 / 404 / 503.**
- 403: dự án Google bị chặn, tạo key bằng Gmail cá nhân.
- 404: tên mô hình sai, chọn lại trong danh sách gợi ý.
- 503: máy chủ AI quá tải; hệ thống tự thử lại và chuyển mô hình dự phòng, đợi một lát rồi thử lại.
