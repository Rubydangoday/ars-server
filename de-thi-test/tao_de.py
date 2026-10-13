#!/usr/bin/env python3
"""Tạo 5 bộ đề kiểm tra (20 câu/bộ) theo 5 thư mục trong bai-giang/, mỗi bộ 3 định dạng nhập được vào ARS:

  <bo>/de_<bo>.csv   — UTF-8 có BOM, dấu ";", cột như file mẫu mau/ARS_du_lieu_thu.xlsx (sheet "Cau hoi")
  <bo>/de_<bo>.xlsx  — ghi bằng web/lib/sheet.php của ARS (sheet "Cau hoi")
  <bo>/de_<bo>.docx  — "Chủ đề: …", "Câu n: …", "A. …", "Đáp án: X", "Giải thích: …"

Chạy lại sau khi sửa câu hỏi bên dưới:   python3 de-thi-test/tao_de.py
Kiểm tra trình nhập của ARS đọc đúng:    python3 de-thi-test/tao_de.py --check
"""
import csv
import io
import json
import os
import random
import subprocess
import sys
import zipfile
from xml.sax.saxutils import escape

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
TIME = 30            # giây mỗi câu (cột "Thoi gian"); để trống = không giới hạn

# (chủ đề, nội dung, [phương án], đáp án, giải thích) — phương án đúng theo slide trong bai-giang/<thư mục>
BO = {
 'AI': ('Nhập môn Trí tuệ nhân tạo', 'Bai_giang_AI', [
  ('1. Introduction', 'Loại AI agent nào chỉ hành động dựa trên nhận thức hiện tại, bỏ qua lịch sử, theo luật điều kiện–hành động?',
   ['Simple reflex agent', 'Model-based reflex agent', 'Goal-based agent', 'Utility-based agent'], 'A',
   'Simple reflex agent chỉ dùng percept hiện tại và luật condition–action, ví dụ robot phân loại thư.'),
  ('1. Introduction', 'Robot hút bụi dùng bản đồ phòng và nhớ vùng đã dọn là ví dụ của loại agent nào?',
   ['Simple reflex agent', 'Model-based reflex agent', 'Learning agent', 'Utility-based agent'], 'B',
   'Model-based reflex agent dùng bộ nhớ và lịch sử nhận thức để xây mô hình môi trường (quan sát được một phần).'),
  ('1. Introduction', 'Utility-based agent được mô tả là:',
   ['Simple reflex agent có thêm bộ nhớ', 'Learning agent không có mục tiêu', 'Goal-based agent cộng tối ưu hàm tiện ích', 'Agent chỉ hoạt động trong môi trường quan sát đầy đủ'], 'C',
   'Utility-based agent = Goal-based agent + tối ưu utility (mức độ "tốt" của trạng thái).'),
  ('1. Introduction', 'Thuật ngữ "Artificial Intelligence" ra đời vào năm nào?',
   ['1943', '1956', '1997', '2012'], 'B', 'Năm 1956 (hội thảo Dartmouth).'),
  ('1. Introduction', 'Deep Blue đánh bại kỳ thủ Garry Kasparov năm 1997.',
   ['Đúng', 'Sai'], 'A', 'Đúng — mốc lịch sử AI trong slide giới thiệu.'),
  ('2. Search Algorithms', 'Thành phần nào của bài toán tìm kiếm kiểm tra trạng thái hiện tại có phải trạng thái đích?',
   ['Transition function', 'Goal test', 'Path cost', 'Initial state'], 'B', 'Goal test: x ∈ S → GOAL?(x) = T hoặc F.'),
  ('2. Search Algorithms', 'Bài toán 8-puzzle có bao nhiêu trạng thái (9!)?',
   ['40 320', '362 880', '1,04 × 10^13', '16!'], 'B', '8-puzzle có 9! = 362 880 trạng thái.'),
  ('2. Search Algorithms', 'Breadth-first search (BFS) luôn mở rộng nút nào trước?',
   ['Nút sâu nhất', 'Nút có chi phí nhỏ nhất', 'Nút nông nhất', 'Nút có heuristic nhỏ nhất'], 'C', 'BFS: expand a shallowest node first.'),
  ('2. Search Algorithms', 'Uniform cost search mở rộng nút nào trước?',
   ['Nút có chi phí đường đi tích luỹ nhỏ nhất', 'Nút nông nhất', 'Nút sâu nhất', 'Nút có h(n) nhỏ nhất'], 'A', 'UCS: expand a cheapest node first.'),
  ('2. Search Algorithms', 'A* chọn nút theo hàm đánh giá nào?',
   ['f(n) = h(n)', 'f(n) = g(n)', 'f(n) = g(n) − h(n)', 'f(n) = g(n) + h(n)'], 'D',
   'A* kết hợp chi phí đã đi g(n) và heuristic h(n); greedy search chỉ dùng h(n).'),
  ('2. Search Algorithms', 'Trong heuristic search, danh sách CLOSED chứa:',
   ['Các nút chưa được mở rộng', 'Các nút đã được mở rộng', 'Các nút đích', 'Các nút có heuristic âm'], 'B',
   'OPEN: nút chưa mở rộng; CLOSED: nút đã mở rộng.'),
  ('3. Probabilistic Reasoning', 'Công thức Bayes là:',
   ['P(A|B) = P(B|A)·P(A) / P(B)', 'P(A|B) = P(A)·P(B)', 'P(A|B) = P(A) + P(B)', 'P(A|B) = P(B|A) / P(A)'], 'A',
   'Bayes cho phép tính một xác suất có điều kiện từ chiều ngược lại.'),
  ('3. Probabilistic Reasoning', 'Hai biến ngẫu nhiên X và Y độc lập khi:',
   ['P(X, Y) = P(X) + P(Y)', 'P(X|Y) = P(Y|X)', 'P(X, Y) = P(X)·P(Y)', 'P(X) = P(Y)'], 'C', 'Độc lập: phân phối kết hợp bằng tích các phân phối biên.'),
  ('3. Probabilistic Reasoning', 'Bayesian network biểu diễn phân phối kết hợp bằng:',
   ['Một bảng lớn duy nhất', 'Tích các phân phối có điều kiện của mỗi biến theo các biến cha', 'Tổng các xác suất biên', 'Một mạng nơ-ron'], 'B',
   'Bayes net mã hoá phân phối kết hợp thành tích các phân phối có điều kiện cục bộ.'),
  ('3. Probabilistic Reasoning', 'Decision network chọn hành động như thế nào?',
   ['Ngẫu nhiên', 'Hành động có xác suất thành công cao nhất', 'Hành động tối đa kỳ vọng tiện ích (expected utility) với bằng chứng đã có', 'Hành động có chi phí nhỏ nhất'], 'C',
   'Decision network gồm action node, chance node, utility node; chọn hành động maximize expected utility.'),
  ('3. Probabilistic Reasoning', 'Naïve Bayes là thuật toán học có giám sát dùng cho bài toán phân loại (ví dụ lọc thư rác).',
   ['Đúng', 'Sai'], 'A', 'Đúng — slide nêu ví dụ spam email và nhận dạng chữ số.'),
  ('4. Learning Algorithm', 'Adaboost thuộc họ "boosting", có nhiệm vụ:',
   ['Giảm số chiều dữ liệu', 'Kết hợp nhiều weak learner thành strong learner', 'Phân cụm dữ liệu không nhãn', 'Tăng cường dữ liệu ảnh'], 'B',
   'Boosting: biến các bộ học yếu thành bộ học mạnh bằng trọng số.'),
  ('4. Learning Algorithm', 'Định lý hội tụ perceptron đảm bảo trọng số ngừng thay đổi sau hữu hạn bước khi nào?',
   ['Dữ liệu có nhiễu', 'Hai lớp tách được tuyến tính', 'Có nhiều hơn hai lớp', 'Tốc độ học bằng 0'], 'B',
   'Perceptron Convergence Theorem: nếu C1, C2 linearly separable.'),
  ('4. Learning Algorithm', 'Hàm kích hoạt nào là mô hình McCulloch-Pitts (1943)?',
   ['Logistic (sigmoid)', 'Hyperbolic tangent', 'Threshold (hàm ngưỡng)', 'Gaussian', 'ReLU'], 'C',
   'McCulloch-Pitts dùng hàm ngưỡng; sigmoid, tanh, Gaussian là các hàm squash khác.'),
  ('9. Generative Adversarial Network', 'GAN huấn luyện hai mạng cạnh tranh nhau, đó là:',
   ['Encoder và Decoder', 'Generator và Discriminator', 'Actor và Critic', 'Teacher và Student'], 'B',
   'Generator sinh mẫu giống dữ liệu thật; Discriminator phân loại thật/giả.'),
 ]),
 'IOT': ('Internet of Things', 'Bai_giang_IOT', [
  ('4. BLE', 'BLE dùng những kênh nào để quảng bá (advertising)?',
   ['0, 1, 2', '11, 12, 13', '37, 38, 39', '40, 41, 42'], 'C', 'BLE có 40 kênh: 37 kênh dữ liệu, 3 kênh quảng bá 37, 38, 39.'),
  ('4. BLE', 'Bluetooth Classic có bao nhiêu kênh 1 MHz và nhảy tần khoảng bao nhiêu lần mỗi giây?',
   ['40 kênh, 100 lần/s', '79 kênh, 1600 lần/s', '79 kênh, 100 lần/s', '40 kênh, 1600 lần/s'], 'B', 'Bluetooth: 79 kênh × 1 MHz, đổi kênh 1600 lần/giây.'),
  ('4. BLE', 'Dữ liệu trong một gói quảng bá BLE (legacy) tối đa bao nhiêu byte?',
   ['20', '31', '64', '255'], 'B', 'Advertisement data: 0–31 byte.'),
  ('4. BLE', 'Lớp nào của BLE chịu trách nhiệm phát hiện thiết bị và thiết lập kết nối (vai trò, quảng bá)?',
   ['GATT', 'ATT', 'GAP', 'L2CAP'], 'C', 'GAP: device discovery, connection, các vai trò broadcaster/observer/central/peripheral.'),
  ('4. BLE', 'GATT trao đổi dữ liệu thông qua:',
   ['Gói quảng bá', 'Service và characteristic (read / write / notify)', 'Địa chỉ IP', 'Topic MQTT'], 'B', 'GATT định nghĩa service/characteristic để đọc, ghi, notify.'),
  ('4. BLE', 'Thiết bị chỉ phát quảng bá, không nhận kết nối, có vai trò GAP nào?',
   ['Central', 'Peripheral', 'Observer', 'Broadcaster'], 'D', 'Broadcaster chỉ phát; Observer chỉ nghe.'),
  ('4. BLE', 'Module HC-05 có baudrate mặc định là bao nhiêu (theo slide)?',
   ['9600', '38400', '115200', '57600'], 'B', 'HC-05: 38400, mật khẩu 1234; HC-06: 9600.'),
  ('5. Wifi', 'Chuẩn IEEE 802.11b hoạt động ở băng tần và tốc độ nào?',
   ['5 GHz, 54 Mbps', '2,4 GHz, 11 Mbps', '2,4 GHz, 54 Mbps', '5 GHz, 1,3 Gbps'], 'B', '802.11b: 2,4 GHz HR-DSSS 11 Mbps.'),
  ('5. Wifi', 'Chuẩn 802.11ac (2014) hoạt động ở băng tần:',
   ['2,4 GHz', '5 GHz', '900 MHz', '60 GHz'], 'B', '802.11ac: 5 GHz, 256-QAM, đến 1,3 Gbps.'),
  ('9. MQTT', 'MQTT hoạt động theo mô hình nào?',
   ['Request/Response', 'Publish/Subscribe qua broker', 'Peer-to-peer không máy chủ', 'Polling định kỳ'], 'B', 'Client publish lên topic, broker chuyển tới các client đã subscribe.'),
  ('9. MQTT', 'QoS 1 trong MQTT đảm bảo:',
   ['Tối đa một lần', 'Ít nhất một lần', 'Đúng một lần', 'Không đảm bảo'], 'B', 'QoS 1: gửi lại tới khi nhận PUBACK nên có thể trùng.'),
  ('9. MQTT', 'QoS nào đảm bảo bản tin được nhận đúng một lần?',
   ['QoS 0', 'QoS 1', 'QoS 2', 'QoS 3'], 'C', 'QoS 2: bắt tay 4 bước, exactly once.'),
  ('9. MQTT', 'Bản tin "retained" có tác dụng gì?',
   ['Broker giữ bản tin cuối của topic và gửi ngay cho client mới subscribe', 'Client tự gửi lại khi mất mạng', 'Mã hoá bản tin', 'Xoá topic sau khi đọc'], 'A',
   'Broker lưu bản tin retained cuối cùng của mỗi topic.'),
  ('9. MQTT', 'Ký tự đại diện nào khớp NHIỀU cấp topic trong MQTT?',
   ['Dấu +', 'Dấu * (sao)', 'Dấu #', 'Dấu ?'], 'C', '"#" khớp nhiều cấp (đặt cuối); "+" khớp đúng một cấp.'),
  ('9.2 CoAP', 'CoAP chạy trên giao thức tầng vận chuyển nào?',
   ['TCP', 'UDP', 'SCTP', 'QUIC'], 'B', 'CoAP tối ưu cho IoT, chạy trên UDP.'),
  ('9.2 CoAP', 'Bản tin CoAP loại Confirmable (CON) cần bên nhận trả về ACK.',
   ['Đúng', 'Sai'], 'A', 'CON phải được ACK; NON (non-confirmable) thì không.'),
  ('7. Webserver', 'Phương thức HTTP nào thường dùng để lấy dữ liệu từ server?',
   ['POST', 'PUT', 'GET', 'DELETE'], 'C', 'GET đọc dữ liệu; POST gửi/tạo dữ liệu.'),
  ('7. Webserver', 'Mã trạng thái HTTP 404 nghĩa là:',
   ['Thành công', 'Không tìm thấy tài nguyên', 'Lỗi máy chủ', 'Chuyển hướng tạm thời'], 'B', '404 Not Found; 200 OK; 500 Internal Server Error.'),
  ('6. Database', 'Khoá chính (primary key) trong bảng SQL dùng để:',
   ['Mã hoá dữ liệu', 'Định danh duy nhất mỗi dòng', 'Sắp xếp bảng theo thời gian', 'Liên kết tới bảng khác bắt buộc'], 'B', 'Primary key là duy nhất và không NULL cho mỗi bản ghi.'),
  ('3. Communication', 'Băng ISM 2,4 GHz nằm trong khoảng tần số nào?',
   ['2,400 – 2,4835 GHz', '2,000 – 2,400 GHz', '2,483 – 2,600 GHz', '5,150 – 5,850 GHz', '868 – 915 MHz'], 'A', 'ISM 2,4 GHz: 2,4–2,4835 GHz, ngày càng đông thiết bị.'),
 ]),
 'ROS': ('Robot Operating System', 'Bai_giang_ROS', [
  ('2. ROS basics', 'Đơn vị tổ chức mã nguồn cơ bản ("basic cell") của ROS là:',
   ['Node', 'Package', 'Topic', 'Launch file'], 'B', 'Package là đơn vị cơ bản; một package có thể chứa nhiều node.'),
  ('2. ROS basics', 'Trong workspace ROS 2, thư mục nào chứa mã nguồn các package?',
   ['build', 'install', 'src', 'log'], 'C', 'src: source space; build/install/log do colcon tạo.'),
  ('2. ROS basics', 'Lệnh build workspace ROS 2 là:',
   ['catkin_make', 'colcon build', 'cmake --build', 'ros2 build'], 'B', 'ROS 2 dùng colcon build (catkin_make là ROS 1).'),
  ('2. Linux depth', 'Lệnh Linux nào in ra thư mục làm việc hiện tại?',
   ['ls', 'cd', 'pwd', 'mkdir'], 'C', 'pwd – Print Working Directory.'),
  ('2. ROS basics', 'Lệnh chạy một node trong ROS 2:',
   ['ros2 run <package> <executable>', 'ros2 start <node>', 'rosrun <node>', 'ros2 node <executable>'], 'A', 'Ví dụ: ros2 run camera_opencv camera_publisher.'),
  ('2. ROS basics', 'Thư viện client C++ của ROS 2 là:',
   ['rclpy', 'rclcpp', 'roscpp', 'tf2'], 'B', 'rclcpp cho C++, rclpy cho Python.'),
  ('2. ROS basics', 'File nào khai báo tên, phụ thuộc (dependencies) của một package ROS 2?',
   ['setup.cfg', 'package.xml', 'CMakeCache.txt', 'urdf.xml'], 'B', 'package.xml cho ROS biết package là gì và phụ thuộc vào đâu.'),
  ('3. Define your robot', 'URDF là định dạng mô tả robot dựa trên:',
   ['JSON', 'YAML', 'XML', 'CSV'], 'C', 'Unified Robot Description Format là định dạng XML.'),
  ('3. Define your robot', 'Hai phần tử chính của một mô hình URDF là:',
   ['node và topic', 'link và joint', 'world và plugin', 'service và action'], 'B', 'Link: thành phần vật lý; joint: chuyển động tương đối giữa các link.'),
  ('3. Define your robot', 'Thẻ nào trong một link dùng cho tính toán va chạm vật lý?',
   ['visual', 'inertial', 'collision', 'origin'], 'C', 'collision dùng cho physics; visual chỉ để hiển thị.'),
  ('3. Define your robot', 'Xacro được dùng để:',
   ['Mô phỏng cảm biến', 'Viết URDF gọn hơn bằng macro, chia nhiều file', 'Biên dịch package', 'Điều khiển động cơ'], 'B', 'URDF dài và lặp; xacro cho phép macro, biến, chia file.'),
  ('3. Define your robot', 'Gazebo mô tả model và world bằng định dạng nào?',
   ['SDF', 'URDF', 'STL', 'YAML'], 'A', 'Simulation Description Format (SDF).'),
  ('4. ROS Control', 'Thành phần nào của ros2_control là "phiên dịch" giữa thế giới ROS và phần cứng?',
   ['Resource Manager', 'Controller Manager', 'Hardware Interface', 'Broadcaster'], 'C', 'Hardware interface là plugin C++ đọc/ghi phần cứng (read/write).'),
  ('4. ROS Control', 'Thành phần được gọi là "trái tim" của ros2_control, quản lý vòng lặp thời gian thực:',
   ['Hardware Interface', 'Controller Manager', 'Topic', 'URDF'], 'B', 'Controller manager quản lý thời gian vòng lặp và nạp/chạy controller.'),
  ('4. ROS Control', 'Controller nào dùng cho robot di chuyển vi sai (differential drive)?',
   ['joint_trajectory_controller', 'diff_drive_controller', 'gripper_controller', 'joint_state_broadcaster'], 'B', 'diff_drive_controller nhận cmd_vel, tính vận tốc hai bánh.'),
  ('5. ROS Navigation', 'Thuật toán định vị robot trên bản đồ trong ROS (dùng particle filter, laser scan) là:',
   ['GMapping', 'AMCL', 'A*', 'EKF SLAM'], 'B', 'Adaptive Monte Carlo Localization.'),
  ('5. ROS Navigation', 'Trong ROS 2 lifecycle node, trạng thái nào là node đang làm việc thực sự?',
   ['Unconfigured', 'Inactive', 'Active', 'Finalized'], 'C', 'Unconfigured → Inactive (sẵn sàng) → Active (đang chạy) → Finalized.'),
  ('6. Behaviour Tree', 'Một node của Behavior Tree trả về những trạng thái nào?',
   ['True / False', 'Success / Failure / Running', 'Start / Stop', 'Idle / Busy / Error / Done'], 'B', 'Control node và leaf node đều trả Success, Failure hoặc Running.'),
  ('6. Behaviour Tree', 'Node nào có đúng MỘT con và thay đổi kết quả của con (ví dụ Inverter)?',
   ['Sequence', 'Fallback', 'Decorator', 'Action'], 'C', 'Decorator node: một con; Inverter đảo Success ↔ Failure.'),
  ('6. Behaviour Tree', 'So với máy trạng thái hữu hạn (FSM), Behavior Tree có ưu điểm chính là tính mô-đun, dễ mở rộng khi tác vụ phức tạp.',
   ['Đúng', 'Sai'], 'A', 'FSM thiếu tính mô-đun, kém hiệu quả khi số tác vụ tăng.'),
 ]),
 'RTOS': ('Hệ thống thời gian thực', 'Bai_giang_RTOS', [
  ('1. Introduction', 'Một hệ thống thời gian thực được coi là đúng khi:',
   ['Chạy càng nhanh càng tốt', 'Kết quả đúng logic và đúng thời điểm (đáp ứng deadline)', 'Dùng hệ điều hành Linux', 'Không có ngắt'], 'B',
   'Correctness phụ thuộc cả kết quả logic lẫn thời điểm tạo ra kết quả.'),
  ('1. Introduction', 'Real-time computing tương đương với tính toán:',
   ['Nhanh nhất có thể', 'Dự đoán được (predictable)', 'Song song', 'Tiết kiệm năng lượng'], 'B', 'Myth: real-time = fast. Truth: real-time = predictable.'),
  ('2. RT system model', 'Trong hệ thời gian thực cứng (hard real-time), lỡ một deadline thì:',
   ['Chấp nhận được nếu hiếm', 'Hệ thống bị coi là sai', 'Chỉ giảm chất lượng dịch vụ', 'Tác vụ được chạy lại sau'], 'B', 'Hard deadline: mọi deadline phải được đáp ứng.'),
  ('1. Introduction', 'Task là đơn vị công việc nhỏ nhất có thể lập lịch.',
   ['Đúng', 'Sai'], 'A', 'Task is the smallest schedulable unit of work.'),
  ('2. RT system model', 'Loại task nào có các job lặp lại đều đặn theo chu kỳ?',
   ['Aperiodic', 'Sporadic', 'Periodic', 'Background'], 'C', 'Periodic task: job được thực thi lặp lại theo chu kỳ cố định.'),
  ('2. RT system model', 'Task đến không đều nhưng có deadline cứng gọi là:',
   ['Periodic task', 'Sporadic task', 'Aperiodic task', 'Idle task'], 'B', 'Sporadic: đến bất thường, deadline cứng; aperiodic: deadline mềm hoặc không có.'),
  ('2. RT system model', 'Ký hiệu C_i trong mô hình task là:',
   ['Chu kỳ', 'Thời gian thực thi xấu nhất (WCET)', 'Deadline tương đối', 'Thời điểm phát hành'], 'B', 'Execution time C_i: thường lấy WCET.'),
  ('3. Clock-driven scheduling', 'Các task có chu kỳ 4, 5, 20, 20. Hyperperiod H bằng:',
   ['4', '10', '20', '400'], 'C', 'H = bội chung nhỏ nhất (LCM) các chu kỳ = 20.'),
  ('3. Clock-driven scheduling', 'Lập lịch theo đồng hồ (clock-driven) phù hợp nhất với hệ thống:',
   ['Tham số task thay đổi liên tục', 'Xác định trước, tham số mọi task periodic đã biết', 'Nhiều task aperiodic', 'Đa lõi không đồng bộ'], 'B', 'Bảng lịch tính offline cho hệ deterministic.'),
  ('3. Clock-driven scheduling', 'Ràng buộc kích thước khung (frame) f trong clock-driven scheduling:',
   ['f nhỏ hơn mọi C_i', 'f không nhỏ hơn thời gian thực thi lớn nhất max(C_i)', 'f bằng hyperperiod', 'f tuỳ ý'], 'B', 'Mỗi job phải chạy xong trong một khung: f ≥ max(C_i); f còn phải chia hết H.'),
  ('4. Priority-driven scheduling', 'Rate Monotonic Scheduling (RMS) gán độ ưu tiên theo:',
   ['Deadline tuyệt đối', 'Chu kỳ: chu kỳ ngắn hơn → ưu tiên cao hơn', 'Thời gian thực thi dài hơn → ưu tiên cao hơn', 'Thứ tự đến'], 'B', 'RMS: the shorter the period, the higher the priority.'),
  ('4. Priority-driven scheduling (cont)', 'Deadline Monotonic Scheduling (DMS) gán ưu tiên cao hơn cho task có:',
   ['Chu kỳ dài hơn', 'Deadline tương đối ngắn hơn', 'WCET lớn hơn', 'Slack lớn hơn'], 'B', 'DMS: relative deadline ngắn hơn → ưu tiên cao hơn.'),
  ('4. Priority-driven scheduling', 'Khi số task n rất lớn, giới hạn hiệu suất Liu & Layland của RMS xấp xỉ:',
   ['50%', '69,3%', '82,8%', '100%'], 'B', 'U ≤ n(2^(1/n) − 1) → ln 2 ≈ 0,693.'),
  ('4. Priority-driven scheduling', 'Ba task T1(4,1), T2(5,2), T3(20,5) (chu kỳ, WCET). Tổng hiệu suất U là:',
   ['0,75', '0,85', '0,90', '1,10'], 'C', 'U = 1/4 + 2/5 + 5/20 = 0,25 + 0,40 + 0,25 = 0,90 (vượt giới hạn L&L 0,78 nhưng RTA vẫn lập lịch được).'),
  ('4. Priority-driven scheduling (cont)', 'Earliest Deadline First (EDF) ưu tiên task có:',
   ['Chu kỳ ngắn nhất', 'Deadline tuyệt đối sớm nhất', 'Slack nhỏ nhất', 'WCET nhỏ nhất'], 'B', 'EDF là thuật toán ưu tiên động phổ biến nhất.'),
  ('4. Priority-driven scheduling (cont)', 'Trên một bộ xử lý, task độc lập, có chiếm quyền, D_i = p_i: EDF lập lịch được khi và chỉ khi:',
   ['U ≤ 0,693', 'U ≤ 0,828', 'U ≤ 1', 'U ≤ 2'], 'C', 'EDF tối ưu: schedulable iff U ≤ 1.'),
  ('4. Priority-driven scheduling (cont)', 'Thuật toán Least Slack Time First (LST) ưu tiên task có:',
   ['Slack (laxity) nhỏ nhất', 'Slack lớn nhất', 'Chu kỳ dài nhất', 'Deadline muộn nhất'], 'A', 'LST cùng điều kiện như EDF, ưu tiên laxity nhỏ nhất.'),
  ('4. Priority-driven scheduling', 'Khi chiếm quyền (preemption), hệ điều hành phải làm gì với task đang chạy?',
   ['Xoá task', 'Lưu ngữ cảnh (context) rồi chuyển sang task ưu tiên cao', 'Chờ task chạy xong', 'Khởi động lại CPU'], 'B', 'Context switch: lưu thanh ghi/ngăn xếp của task bị chiếm quyền.'),
  ('5. Aperiodic and sporadic tasks', 'Background scheduling chạy job aperiodic khi nào?',
   ['Ngay khi job đến', 'Chỉ khi không còn job periodic/sporadic nào sẵn sàng', 'Theo chu kỳ cố định', 'Trước mọi job periodic'], 'B', 'Đơn giản, nhưng thời gian đáp ứng aperiodic có thể rất lớn.'),
  ('5. Aperiodic and sporadic tasks', 'Server nào GIỮ LẠI ngân sách (budget) khi hàng đợi aperiodic đang rỗng?',
   ['Polling server', 'Background server', 'Deferrable server', 'Idle server', 'Interrupt server'], 'C', 'Polling server mất budget nếu hàng đợi rỗng; deferrable server hoãn và giữ budget.'),
 ]),
 'ROBOT': ('Thiết kế robot', 'Bai_giang_thiet_ke_robot', [
  ('2. Tổng quan về TK Robot', 'Theo bài giảng, thiết kế robot là một quá trình:',
   ['Tuyến tính, làm một lần', 'Lặp lại và có thoả hiệp', 'Chỉ gồm phần cơ khí', 'Không cần thông số mục tiêu'], 'B', 'Thiết kế là quá trình lặp lại, là sự thoả hiệp.'),
  ('2. Tổng quan về TK Robot', 'Bước 2 của quy trình thiết kế có mục tiêu gì?',
   ['Chế tạo mẫu thử', 'Chuyển nhu cầu khách hàng thành thông số kỹ thuật cụ thể', 'Bảo trì sau bán hàng', 'Chọn nhà cung cấp'], 'B', 'Bước 2: Xác định thông số kỹ thuật.'),
  ('2. Tổng quan về TK Robot', 'Bước "Hỗ trợ sản phẩm" (bước 5) tập trung vào:',
   ['Động não phương án', 'Duy trì vận hành và quản lý vòng đời robot sau khi ra mắt', 'Lập kế hoạch dự án', 'Tính bậc tự do'], 'B', 'Bước 5: hỗ trợ sản phẩm, vòng đời.'),
  ('2. Tổng quan về TK Robot', 'Công cụ chính của phương pháp Triển khai Chức năng Chất lượng (QFD) là:',
   ['Biểu đồ Gantt', 'Ngôi nhà chất lượng (House of Quality)', 'Sơ đồ động học', 'Bảng phân phối nguồn'], 'B', 'QFD chuyển mong muốn mơ hồ của người dùng thành đặc tính kỹ thuật qua House of Quality.'),
  ('2. Tổng quan về TK Robot', 'Phương pháp phân tích dựa trên nhiệm vụ (MBA) xác định thông số mục tiêu từ:',
   ['Giá thành đối thủ', 'Kịch bản vận hành cuối cùng của robot', 'Màu sắc sản phẩm', 'Số lượng linh kiện'], 'B', 'MBA dựa trên kịch bản vận hành; ví dụ robot Sojourner của NASA.'),
  ('2. Tổng quan về TK Robot', 'Robot nào được dùng làm ví dụ cho phương pháp MBA trong bài giảng?',
   ['Boston Dynamics Spot', 'Unitree Go 2', 'NASA Sojourner', 'ASIMO'], 'C', 'NASA’s first Mars rover – Sojourner.'),
  ('2. Tổng quan về TK Robot', 'Phương pháp so sánh (benchmarking) dùng để đo chênh lệch giữa robot của mình và đối thủ.',
   ['Đúng', 'Sai'], 'A', 'Từ đó đề xuất thông số mục tiêu cạnh tranh.'),
  ('3. Mechanical Design', 'Ký hiệu trong sơ đồ động học phải theo tiêu chuẩn nào?',
   ['ISO', 'IEEE', 'IP67', 'USB'], 'A', 'Các ký hiệu phải theo tiêu chuẩn ISO.'),
  ('3. Mechanical Design', 'Cơ cấu phẳng chuỗi hở gồm giá cố định và 3 khâu động nối bằng 3 khớp xoay có bao nhiêu bậc tự do?',
   ['1', '2', '3', '6'], 'C', 'Grübler phẳng: M = 3(n − 1) − 2·j1 = 3·3 − 2·3 = 3.'),
  ('3. Mechanical Design', 'Trên chân robot chó, khâu L1 (hip abduction–adduction) giúp:',
   ['Tăng tốc độ chạy', 'Chân không va chạm với thân robot', 'Giảm khối lượng pin', 'Đo góc nghiêng'], 'B', 'L1 giúp chân không va chạm với thân robot.'),
  ('3. Mechanical Design', 'Diện tích ellipsoid vận tốc của bàn chân càng lớn thì:',
   ['Cơ cấu càng kém linh hoạt', 'Cơ cấu càng linh hoạt', 'Động cơ càng nóng', 'Không ảnh hưởng'], 'B', 'Ellipsoid đại diện mức độ linh hoạt của cơ cấu: càng lớn càng tốt.'),
  ('3. Mechanical Design', 'Trình tự thiết kế chi tiết các khớp chân robot chó là:',
   ['Hông → đùi → gối', 'Gối → đùi → hông', 'Đùi → hông → gối', 'Tuỳ ý'], 'B', 'Từ khớp đơn giản tới phức tạp: gối → đùi → hông.'),
  ('3. Mechanical Design', 'Tiêu chí nào thuộc 3 tiêu chí chọn động cơ trong bài giảng?',
   ['Màu vỏ động cơ', 'Quán tính của tải (load inertia)', 'Thương hiệu', 'Kích thước dây'], 'B', '3 tiêu chí: mô men xoắn, quán tính tải, tốc độ.'),
  ('3. Mechanical Design', 'Khớp xoay chạy tốc độ thấp (< 200 rpm) nhưng chịu tải lớn nên chọn:',
   ['Ổ bi', 'Bạc lót', 'Khớp cầu nhựa', 'Không cần ổ đỡ'], 'B', 'Ổ bi: tốc độ cao (200–100k rpm); bạc lót: < 200 rpm, chịu tải cao hơn.'),
  ('4. Electronics Design', 'Điện áp hệ thống điển hình của AGV và robot chó theo bảng thông số mục tiêu:',
   ['3,3 – 5 V', '12 V', '24 – 48 V', '220 V xoay chiều'], 'C', 'AGV, robot chó: 24–48 V; UAV: pin 4S–14S.'),
  ('4. Electronics Design', 'Cảm biến nào thuộc nhóm cảm biến trạng thái (đo trạng thái của chính robot)?',
   ['Camera', 'Lidar', 'Encoder bánh xe', 'Cảm biến siêu âm'], 'C', 'Cảm biến trạng thái: pin, encoder, IMU; cảm biến môi trường: camera, lidar.'),
  ('4. Electronics Design', 'Mạch buck converter dùng để:',
   ['Tăng áp', 'Hạ áp cho từng nhánh nguồn', 'Đảo chiều động cơ', 'Sạc pin không dây'], 'B', 'Bước 2 tính toán nguồn: chọn mạch hạ áp (buck) cho từng nhánh.'),
  ('4. Electronics Design', 'Robot tiêu thụ dòng trung bình 5 A, cần chạy 4 giờ. Dung lượng pin tối thiểu (bỏ qua hao hụt):',
   ['1,25 Ah', '9 Ah', '20 Ah', '40 Ah'], 'C', 'Dung lượng = dòng trung bình × thời gian = 5 A × 4 h = 20 Ah.'),
  ('4. Electronics Design', 'Băng ISM 2,4 GHz là viết tắt của:',
   ['Industry, Science and Medical', 'Internet, Sensor and Mobile', 'International Standard Modulation', 'Integrated System Module'], 'A', 'ISM: Industry, Science and Medical, 2,4–2,4835 GHz.'),
  ('4. Electronics Design', 'Quan hệ giữa tần số f và bước sóng λ của sóng RF (c là tốc độ ánh sáng):',
   ['λ = c · f', 'λ = c / f', 'λ = f / c', 'λ = c + f', 'λ = 1 / (c · f)'], 'B', 'λ = c / f: tần số càng cao bước sóng càng ngắn.'),
 ]),
}

LETTERS = 'ABCDEFGHIJ'


def shuffled(key, qs):
    """Xáo thứ tự phương án CỐ ĐỊNH (cùng kết quả mỗi lần chạy) để đáp án đúng rải đều A–D, không dồn vào một chữ.
    Câu Đúng/Sai giữ nguyên thứ tự."""
    out = []
    for i, (topic, content, opts, ans, expl) in enumerate(qs, 1):
        if opts[:2] == ['Đúng', 'Sai']:
            out.append((topic, content, opts, ans, expl))
            continue
        right = opts[LETTERS.index(ans)]
        order = opts[:]
        random.Random(f'{key}-{i}').shuffle(order)
        out.append((topic, content, order, LETTERS[order.index(right)], expl))
    return out


HEADER = ['STT', 'Noi dung', 'So lua chon'] + list(LETTERS) + ['Dap an', 'Thoi gian', 'Chu de', 'Giai thich']


def rows_for(qs):
    out = [HEADER]
    for i, (topic, content, opts, ans, expl) in enumerate(qs, 1):
        assert 2 <= len(opts) <= 10 and ans in LETTERS[:len(opts)], (content, ans)
        out.append([i, content, len(opts)] + opts + [''] * (10 - len(opts)) + [ans, TIME, topic, expl])
    return out


def write_csv(path, rows):
    buf = io.StringIO()
    w = csv.writer(buf, delimiter=';', lineterminator='\n')
    for r in rows:
        w.writerow(r)
    with open(path, 'w', encoding='utf-8-sig', newline='') as f:     # BOM: Excel tiếng Việt đọc đúng dấu
        f.write(buf.getvalue())


def write_xlsx(path, rows):
    """Ghi bằng chính lib/sheet.php của ARS (đảm bảo trình nhập đọc được)."""
    php = ("function output_raw(){} require $argv[1]; $d=json_decode(file_get_contents('php://stdin'),true);"
           "$f=xlsx_write(['Cau hoi'=>$d]); rename($f,$argv[2]);")
    subprocess.run(['php', '-r', php, os.path.join(ROOT, 'web/lib/sheet.php'), path],
                   input=json.dumps(rows, ensure_ascii=False), text=True, check=True)


def write_docx(path, title, subject_dir, qs):
    paras = [(title, True), (f'Theo bài giảng: bai-giang/{subject_dir} · {len(qs)} câu · {TIME} giây/câu', False), ('', False)]
    last = None
    for i, (topic, content, opts, ans, expl) in enumerate(qs, 1):
        if topic != last:
            paras.append((f'Chủ đề: {topic}', True))
            last = topic
        paras.append((f'Câu {i}: {content}', False))
        paras += [(f'{LETTERS[k]}. {o}', False) for k, o in enumerate(opts)]
        paras += [(f'Đáp án: {ans}', False), (f'Giải thích: {expl}', False), ('', False)]
    body = ''.join('<w:p><w:r>' + ('<w:rPr><w:b/></w:rPr>' if bold else '')
                   + f'<w:t xml:space="preserve">{escape(t)}</w:t></w:r></w:p>' for t, bold in paras)
    doc = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
           + body + '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
           '<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="0" w:footer="0" w:gutter="0"/></w:sectPr>'
           '</w:body></w:document>')
    ct = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
          '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
          '<Default Extension="xml" ContentType="application/xml"/>'
          '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
          '</Types>')
    rels = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            '</Relationships>')
    with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as z:
        z.writestr('[Content_Types].xml', ct)
        z.writestr('_rels/.rels', rels)
        z.writestr('word/document.xml', doc)


def check(paths):
    """Đọc lại mọi file bằng trình nhập của ARS (web/lib/qparse.php) và so với ngân hàng câu hỏi."""
    # nạp các hằng (LETTERS, DIFFICULTY…) của web/lib/bootstrap.php — chỉ câu lệnh const cấp ngoài cùng, không chạy
    # phần phiên đăng nhập / CSDL của bootstrap
    php = (r"""$out=''; $in=false; $d=0;
foreach (token_get_all(file_get_contents(dirname($argv[1]).'/bootstrap.php')) as $t) {
  $x = is_array($t) ? $t[1] : $t;
  if (is_array($t) && $t[0] === T_CONST && $d === 0) $in = true;
  if ($x === '{') $d++; elseif ($x === '}') $d--;
  if ($in && !(is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true))) { $out .= $x; if ($x === ';') { $in = false; $out .= "\n"; } }
}
eval($out);
function output_raw(){} require $argv[1]; $r=parse_upload($argv[2],basename($argv[2]));"""
           "echo json_encode(['n'=>count($r['questions']),'w'=>$r['warnings'],"
           "'q'=>array_map(fn($q)=>[$q['content'],count($q['options']),$q['correct']],$r['questions'])],JSON_UNESCAPED_UNICODE);")
    ok = True
    for key, path in paths:
        out = subprocess.run(['php', '-r', php, os.path.join(ROOT, 'web/lib/qparse.php'), path], capture_output=True, text=True)
        try:
            r = json.loads(out.stdout)
        except ValueError:
            print('LỖI', path, out.stdout[:300], out.stderr[:300]); ok = False; continue
        want = [[c, len(o), a] for _, c, o, a, _ in shuffled(key, BO[key][2])]
        good = r['n'] == len(want) and [[q[0], q[1], q[2]] for q in r['q']] == want
        ok &= good
        print(('ĐẠT ' if good else 'LỖI ') + os.path.relpath(path, ROOT) + f": {r['n']} câu" + (f" — cảnh báo: {r['w']}" if r['w'] else ''))
    return ok


def main():
    paths = []
    for key, (title, subject_dir, qs) in BO.items():
        qs = shuffled(key, qs)
        d = os.path.join(HERE, subject_dir)
        os.makedirs(d, exist_ok=True)
        rows = rows_for(qs)
        base = os.path.join(d, f'de_{key}')
        write_csv(base + '.csv', rows)
        write_xlsx(base + '.xlsx', rows)
        write_docx(base + '.docx', f'ĐỀ KIỂM TRA: {title.upper()}', subject_dir, qs)
        paths += [(key, base + ext) for ext in ('.csv', '.xlsx', '.docx')]
        print(f'{subject_dir}: {len(qs)} câu, đáp án ' + ''.join(q[3] for q in qs))
    if '--check' in sys.argv and not check(paths):
        sys.exit(1)


if __name__ == '__main__':
    main()
