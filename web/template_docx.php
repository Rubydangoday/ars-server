<?php
// File Word mẫu để soạn đề rồi nhập vào trang "Soạn câu hỏi".
require __DIR__ . '/lib/bootstrap.php';
require_login();

// [văn bản, kiểu]  kiểu: h = tiêu đề, u = gạch chân (đáp án đúng), i = chữ nghiêng (ghi chú), '' = thường
$paras = [
    ['ĐỀ MẪU — NHẬP CÂU HỎI VÀO ARS', 'h'],
    ['Cách đánh dấu đáp án đúng (chọn một trong các cách): gạch chân hoặc tô đỏ phương án đúng; đặt dấu * trước chữ cái; thêm dòng "Đáp án: B"; hoặc bảng đáp án cuối đề.', 'i'],
    ['Chủ đề: Giao thức MQTT', ''],
    ['Câu 1: MQTT hoạt động theo mô hình nào?', ''],
    ['A. Client/Server đồng bộ', ''], ['B. Publish/Subscribe', 'u'], ['C. Peer-to-peer', ''], ['D. Request/Response', ''],
    ['Giải thích: Các thiết bị không gửi trực tiếp cho nhau mà qua broker theo topic.', ''],
    ['Câu 2: Cổng mặc định của MQTT khi không mã hoá là', ''],
    ['A. 80', ''], ['B. 443', ''], ['*C. 1883', ''], ['D. 8883', ''],
    ['Câu 3: Mức QoS nào đảm bảo bản tin được nhận đúng một lần?', ''],
    ['A. QoS 0     B. QoS 1     C. QoS 2', ''],
    ['Đáp án: C', ''],
    ['Chủ đề: Bluetooth Low Energy', ''],
    ['Câu 4: BLE hoạt động ở băng tần nào?', ''],
    ['A. 433 MHz', ''], ['B. 868 MHz', ''], ['C. 2,4 GHz', ''], ['D. 5 GHz', ''],
    ['BẢNG ĐÁP ÁN', 'h'],
    ['4.C', ''],
];
$esc = fn($s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$body = '';
foreach ($paras as [$txt, $k]) {
    $ppr = $k === 'h' ? '<w:pPr><w:pStyle w:val="Heading1"/></w:pPr>' : '';
    $rpr = ['u' => '<w:rPr><w:u w:val="single"/></w:rPr>', 'i' => '<w:rPr><w:i/><w:color w:val="667085"/></w:rPr>', 'h' => '', '' => ''][$k];
    $body .= "<w:p>$ppr<w:r>$rpr<w:t xml:space=\"preserve\">" . $esc($txt) . '</w:t></w:r></w:p>';
}
$W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
$tmp = tempnam(sys_get_temp_dir(), 'docx');
$z = new ZipArchive();
$z->open($tmp, ZipArchive::OVERWRITE);
$z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
    . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>');
$z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
$z->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
$z->addFromString('word/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles ' . $W . '>'
    . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/><w:sz w:val="26"/></w:rPr></w:rPrDefault></w:docDefaults>'
    . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:pPr><w:spacing w:after="60"/></w:pPr></w:style>'
    . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:before="240" w:after="120"/></w:pPr><w:rPr><w:b/><w:sz w:val="30"/></w:rPr></w:style></w:styles>');
$z->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . $W . '><w:body>' . $body
    . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1418" w:header="709" w:footer="709" w:gutter="0"/></w:sectPr></w:body></w:document>');
$z->close();
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="ARS_de_mau.docx"');
header('Content-Length: ' . filesize($tmp));
output_raw(); readfile($tmp);
unlink($tmp);
