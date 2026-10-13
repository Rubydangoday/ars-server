# Đề thi test — 5 bộ đề theo 5 bài giảng

Mỗi bộ **20 câu trắc nghiệm** soạn theo slide trong [`../bai-giang/`](../bai-giang/), mỗi bộ có **3 định dạng** nhập thẳng vào ARS:

| File | Định dạng | Nhập ở đâu trên web |
|---|---|---|
| `de_*.csv` | CSV UTF-8 (có BOM), dấu `;` — cột giống file mẫu `mau/ARS_du_lieu_thu.xlsx` | Bộ câu hỏi → **Nhập từ Excel/CSV**; hoặc **Soạn đề** → kéo thả |
| `de_*.xlsx` | Excel, sheet `Cau hoi` (ghi bằng `web/lib/sheet.php` của ARS) | như trên |
| `de_*.docx` | Word: `Chủ đề: …`, `Câu n: …`, `A. …`, `Đáp án: X`, `Giải thích: …` | **Soạn đề** → kéo thả file Word |

Cột CSV/Excel: `STT · Noi dung · So lua chon · A … J · Dap an · Thoi gian (30 s) · Chu de · Giai thich`.

| Bộ đề | Môn | Số câu | Số lựa chọn | Đáp án (câu 1 → 20) | Chủ đề |
|---|---|---|---|---|---|
| [Bai_giang_AI](Bai_giang_AI/) | Nhập môn Trí tuệ nhân tạo | 20 | 2 câu 2 lựa chọn, 17 câu 4 lựa chọn, 1 câu 5 lựa chọn | `BCABABCDABDCCADADBCC` | 1. Introduction; 2. Search Algorithms; 3. Probabilistic Reasoning; 4. Learning Algorithm; 9. Generative Adversarial Network |
| [Bai_giang_IOT](Bai_giang_IOT/) | Internet of Things | 20 | 1 câu 2 lựa chọn, 18 câu 4 lựa chọn, 1 câu 5 lựa chọn | `DCBBDBABBCBDDBBABCAA` | 4. BLE; 5. Wifi; 9. MQTT; 9.2 CoAP; 7. Webserver; 6. Database; 3. Communication |
| [Bai_giang_ROS](Bai_giang_ROS/) | Robot Operating System | 20 | 1 câu 2 lựa chọn, 19 câu 4 lựa chọn | `BCBCBBAAACBCDCDCDADA` | 2. ROS basics; 2. Linux depth; 3. Define your robot; 4. ROS Control; 5. ROS Navigation; 6. Behaviour Tree |
| [Bai_giang_RTOS](Bai_giang_RTOS/) | Hệ thống thời gian thực | 20 | 1 câu 2 lựa chọn, 18 câu 4 lựa chọn, 1 câu 5 lựa chọn | `BACACAACABBDBBBAACBD` | 1. Introduction; 2. RT system model; 3. Clock-driven scheduling; 4. Priority-driven scheduling; 4. Priority-driven scheduling (cont); 5. Aperiodic and sporadic tasks |
| [Bai_giang_thiet_ke_robot](Bai_giang_thiet_ke_robot/) | Thiết kế robot | 20 | 1 câu 2 lựa chọn, 18 câu 4 lựa chọn, 1 câu 5 lựa chọn | `BBABDBACDDCDDABDBBDB` | 2. Tổng quan về TK Robot; 3. Mechanical Design; 4. Electronics Design |

Mỗi bộ có vài câu **2 lựa chọn (Đúng/Sai)** và **5 lựa chọn (A–E)** để thử: Remote chặn ngay chữ vượt số lựa chọn
(bấm E ở câu 4 lựa chọn → `LOCAL REJECT`), server NACK `bad_option` nếu Remote chưa biết số lựa chọn.
Thứ tự phương án được xáo **cố định** để đáp án đúng rải đều A–D (chạy lại script cho ra đúng đề cũ).

## Cách test nhanh

1. Web giáo viên → **Bộ câu hỏi** → tạo bộ mới → **Nhập từ Excel/CSV** → chọn `de_IOT.csv` → xem trước → xác nhận
   (hoặc **Soạn đề** → kéo thả `de_IOT.docx` / `.xlsx`).
2. Lớp → **Bắt đầu** buổi với bộ vừa nhập → mở từng câu → học sinh bấm Remote.
3. **Kết quả** → **Xuất Excel** / **Xuất điểm CSV**; so đáp án với cột "Đáp án" ở bảng trên.

## Sửa / tạo lại đề

Câu hỏi nằm trong [`tao_de.py`](tao_de.py) (biến `BO`). Sau khi sửa:
```bash
python3 de-thi-test/tao_de.py --check     # tạo lại 15 file và đọc lại bằng trình nhập của ARS (web/lib/qparse.php)
```
`--check` phải in `ĐẠT` cho cả 15 file (đúng số câu, số lựa chọn, đáp án).
Lưu ý khi soạn file Word: dấu `*` ở đầu phương án là ký hiệu **đáp án đúng** của trình nhập — đừng dùng `*` làm nội dung phương án.
