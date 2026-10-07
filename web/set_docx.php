<?php
require __DIR__.'/lib/bootstrap.php';require_login();$s=own_set((int)($_GET['id']??0));
$paras=[[$s['title'].($s['variant_code']?' — Mã '.$s['variant_code']:''),'h']];
foreach(qall('SELECT * FROM questions WHERE set_id=? ORDER BY position',[$s['id']]) as $q){
 if($q['topic'])$paras[]=['Chủ đề: '.$q['topic'],''];
 $paras[]=['Câu '.$q['position'].': '.$q['content'],''];
 foreach(options_of($q) as $k=>$v)$paras[]=[$k.'. '.$v,''];
 if(!empty($_GET['key'])){if($q['correct'])$paras[]=['Đáp án: '.$q['correct'],''];if($q['explanation'])$paras[]=['Giải thích: '.$q['explanation'],''];}
}
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
header('Content-Disposition: attachment; filename="ARS_de_export.docx"');
header('Content-Length: ' . filesize($tmp));
output_raw(); readfile($tmp);
unlink($tmp);
