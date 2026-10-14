# Máy chủ Cloud 24/24 miễn phí cho học sinh ở nhà (Oracle Cloud Free + Tailscale Funnel)

Giống Azota: học sinh và phụ huynh vào web **bất cứ lúc nào**, kể cả khi laptop lớp học tắt hoặc hết 4G.

```
TRÊN LỚP (laptop, không cần Internet)               24/24 (máy chủ Cloud miễn phí, Singapore)
Remote ─▶ gateway ─▶ laptop ARS ──"Sync to Cloud"──▶  ARS Cloud  ◀── học sinh / phụ huynh ở nhà
          điểm danh, kiểm tra bằng Remote              https://ars-cloud.<tên-tailnet>.ts.net/hs
```

| Làm ở đâu | Việc |
|---|---|
| **Laptop lớp học** | Lớp, danh sách học sinh (laptop là bản gốc), dạy bằng Remote, bài kiểm tra trên lớp → **tự đồng bộ** lên Cloud mỗi 3 phút khi có mạng |
| **Cloud** | Bài giảng (tải file), **bài tập về nhà**, chấm bài, mã mời phụ huynh, ôn tập, xem kết quả đã công bố |

Đồng bộ một chiều laptop → Cloud, gửi lại không bị trùng; mỗi lần chỉ vài chục KB nên tốn rất ít 4G.
Học sinh tự đăng ký / đăng nhập trên Cloud bằng MSSV như bình thường.

**Chi phí: 0 đ.** Oracle "Always Free" (máy ARM tới 4 nhân / 24 GB RAM, 200 GB đĩa — miễn phí vĩnh viễn) + Tailscale
(link HTTPS `*.ts.net` miễn phí, không cần mua tên miền, không phải mở cổng tường lửa).

---

## 1. Tạo máy chủ Oracle Cloud (15–20 phút, một lần)

1. Đăng ký tại https://www.oracle.com/cloud/free/ → **Start for free**.
   - **Home Region: chọn `Singapore`** (gần Việt Nam nhất, ~30–50 ms). Chọn rồi **không đổi được**.
   - Cần thẻ Visa/Mastercard (kể cả thẻ ghi nợ) để xác minh; Oracle tạm giữ ~1 USD rồi hoàn, không trừ tiền trong hạn mức miễn phí.
2. Vào **Compute → Instances → Create instance**:
   - Name: `ars-cloud`
   - Image: **Ubuntu 24.04** (Canonical Ubuntu)
   - Shape: **Ampere → VM.Standard.A1.Flex**, 2 OCPU, 12 GB (nhãn *Always Free-eligible*).
     Báo *Out of capacity* → thử lại sau vài giờ, hoặc chọn **VM.Standard.E2.1.Micro** (AMD, 1 GB RAM — vẫn chạy được ARS).
   - **Add SSH keys → Generate a key pair → Save private key** (file `ssh-key-….key`, cất kỹ).
   - Bấm **Create**, đợi trạng thái *Running*, chép **Public IP address**.
3. **Tránh bị thu hồi máy rảnh:** Oracle có thể thu hồi máy Always Free dùng rất ít trong 7 ngày. Cách chắc nhất:
   *Billing → Upgrade to Pay As You Go* (vẫn **miễn phí** trong hạn mức Always Free), rồi tạo
   *Budgets* cảnh báo 1 USD để yên tâm không phát sinh phí.

## 2. Cài ARS chế độ Cloud (10 phút)

Trên **laptop** (thư mục repo) — gói mã nguồn, bỏ thư mục slide `bai-giang/` cho nhẹ (bài giảng sẽ tải lên qua web):
```bash
cd ~/Downloads/ars-server
git archive --format=tar.gz -o ~/ars-cloud.tar.gz HEAD -- . ':(exclude)bai-giang'
chmod 600 ~/Downloads/ssh-key-*.key
scp -i ~/Downloads/ssh-key-*.key ~/ars-cloud.tar.gz ubuntu@<PUBLIC_IP>:
ssh -i ~/Downloads/ssh-key-*.key ubuntu@<PUBLIC_IP>
```
Trên **máy chủ Cloud** (cửa sổ SSH vừa mở):
```bash
mkdir ars-server && tar xzf ars-cloud.tar.gz -C ars-server && cd ars-server
sudo bash deploy/install_ubuntu.sh --cloud
```
Chế độ `--cloud`: cài Apache + PHP + MariaDB, **bỏ** Bluetooth, gateway USB, tên `ars.local`, mở cổng tường lửa.
Chế độ được nhớ: lần cập nhật sau chỉ cần `sudo bash deploy/install_ubuntu.sh`.

## 3. Link HTTPS 24/24 bằng Tailscale Funnel (5 phút)

Vẫn trên máy chủ Cloud:
```bash
sudo hostnamectl set-hostname ars-cloud                  # tên này thành tên link
curl -fsSL https://tailscale.com/install.sh | sh
sudo tailscale up                                         # mở link in ra, đăng nhập CÙNG tài khoản Tailscale với laptop
sudo ars public on                                        # lần đầu có thể in link để bấm cho phép Funnel
```
Kết quả, ví dụ:
```
Giáo viên: https://ars-cloud.tail4be8bf.ts.net/gv
Học sinh : https://ars-cloud.tail4be8bf.ts.net/hs
```
Kiểm tra từ điện thoại **chỉ bật 4G**: mở link `/hs` → thấy trang đăng nhập học sinh là xong.

## 4. Nối laptop lớp học với Cloud (5 phút)

1. Mở **Cloud** `…/gv` → tạo tài khoản giáo viên (tài khoản đầu tiên là quản trị).
2. Cloud → menu **☁️ Cloud** → *Nhận dữ liệu từ server lớp học* → **Tạo mã đồng bộ** → chép mã (bắt đầu `ars_`, chỉ hiện một lần).
3. Trên **laptop** → menu **☁️ Cloud** → *Địa chỉ Cloud*: `https://ars-cloud.<tên-tailnet>.ts.net/ars` · dán mã → **Lưu & kiểm tra kết nối**.
4. Bấm **⬆ Sync to Cloud** → lớp và học sinh lên Cloud.
5. Từ đây trở đi laptop **tự đồng bộ**. Dịch vụ `ars-autosync.timer` chạy 2 phút sau khi máy bật, sau đó cứ mỗi 3 phút một lần. Mỗi lần chạy, nó gửi lên Cloud các buổi vừa kết thúc, điểm vừa công bố và điểm danh vừa sửa. Giáo viên quên bấm nút cũng không sao.
   - Khi mất mạng, laptop tự thử lại với khoảng cách tăng dần, tối đa 30 phút một lần. Dữ liệu vẫn nằm an toàn trên laptop.
   - Trang **☁️ Cloud** hiện **🔄 Tự động: BẬT**. Giáo viên có thể tắt chế độ này cho riêng tài khoản của mình.
   - Muốn gửi ngay: chạy `ars sync`. Xem nhật ký: `ars sync log`.

## 5. Dùng hằng ngày

- **Đăng bài giảng / giao bài tập về nhà: làm trên Cloud** (`…/gv` của Cloud). Học sinh thấy ngay, 24/24.
  Nên xuất PowerPoint sang **PDF** trước khi tải lên cho nhẹ.
- **Dạy bằng Remote: làm trên laptop** như cũ (không cần mạng). Xong buổi, kết quả **tự lên Cloud** trong vòng 3 phút khi laptop có Wi-Fi/4G (muốn gửi ngay thì bấm **Sync to Cloud**).
- Đổi danh sách lớp: sửa trên **laptop** rồi Sync (laptop là bản gốc của lớp/học sinh).
- Gửi học sinh / phụ huynh **link Cloud** (`…/hs`, mã mời phụ huynh tạo trên Cloud), không gửi link laptop.

## 6. Bảo trì

| Việc | Lệnh (trên máy chủ Cloud) |
|---|---|
| Xem dịch vụ | `ars status` (apache2, mariadb: active) |
| Cập nhật code mới | chép `ars-cloud.tar.gz` mới như mục 2 → giải nén đè → `sudo bash deploy/install_ubuntu.sh` |
| Sao lưu CSDL | `ars backup` (nên làm hằng tuần; tải file về laptop bằng `scp`) |
| Xem link | `ars links` |

Giới hạn: đồng bộ chỉ chiều laptop → Cloud; buổi đang chạy chưa được gửi (kết thúc buổi rồi Sync); xoá lớp trên laptop
không xoá trên Cloud. Chi tiết: `UPGRADE_V11.md` mục 2.
