<?php
/**
 * Excel Template Generator with Hidden Settings Sheet
 * Creates an Excel file for student import with dropdowns populated
 * from a "Settings" sheet.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;

// dropdown values
$courses   = ['BSIS','BSOM','BTVTED','BSAIS','BSCA','ACT','DHRMT','HB','SMAW','Bookeeping','EIM'];
$years     = ['1','2','3','4'];
$sections  = ['A','B','C','D','E','F','G','H','I','J'];

$spreadsheet = new Spreadsheet();

// settings sheet (second sheet)
$settings = $spreadsheet->createSheet();
$settings->setTitle('Settings');

// write headings
$settings->fromArray(['Course','Year','Section'], null, 'A1');

// write values underneath
$settings->fromArray($courses, null, 'A2');
$settings->fromArray($years,   null, 'B2');
$settings->fromArray($sections,null, 'C2');

// define named ranges for easier validation
$spreadsheet->addNamedRange(new NamedRange('CourseList', $settings, 'A2:A' . (count($courses)+1)));
$spreadsheet->addNamedRange(new NamedRange('YearList',   $settings, 'B2:B' . (count($years)+1)));
$spreadsheet->addNamedRange(new NamedRange('SectionList',$settings, 'C2:C' . (count($sections)+1)));

// hide settings sheet so users don't accidentally edit it
$settings->setSheetState(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN
);

// students sheet (first sheet)
$sheet = $spreadsheet->setActiveSheetIndex(0);
$sheet->setTitle('Students');

// header row
$headers = ['student_id','full_name','email','phone','course','year_level','section'];
$sheet->fromArray($headers, null, 'A1');

// style header row
$headerStyle = $sheet->getStyle('A1:G1');
$headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF4472C4');
$headerStyle->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
$headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// column widths
$widths = [15,20,25,15,15,12,10];
foreach ($widths as $i => $w) {
    $sheet->getColumnDimensionByColumn($i+1)->setWidth($w);
}

// sample data rows
$sample = [
    ['22013938','Justine Martin','martin@gmail.com','9933447697','BSIS','4','A'],
    ['20013456','Juan Santos','juan@gmail.com','9912345678','BSOM','2','B'],
    ['21005789','Maria Cruz','maria@gmail.com','9923456789','BSAIS','3','C'],
];
$row = 2;
foreach ($sample as $r) {
    $sheet->fromArray($r, null, 'A' . $row);
    $row++;
}
// empty rows for data entry
for (; $row <= 21; $row++) {
    $sheet->fromArray(['','','','','','',''], null, 'A' . $row);
}

// apply data validation using named ranges
function namedValidation($formula) {
    $val = new DataValidation();
    $val->setType(DataValidation::TYPE_LIST);
    $val->setFormula1('='.$formula);
    $val->setAllowBlank(true);
    $val->setShowDropDown(true);
    return $val;
}

for ($r = 2; $r <= 21; $r++) {
    $sheet->getCell('E'.$r)->setDataValidation(namedValidation('CourseList'));
    $sheet->getCell('F'.$r)->setDataValidation(namedValidation('YearList'));
    $sheet->getCell('G'.$r)->setDataValidation(namedValidation('SectionList'));
}

// freeze header
$sheet->freezePane('A2');

// output file
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="students_template.xlsx"');
header('Cache-Control: max-age=0');

$writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet,'Xlsx');
$writer->save('php://output');
exit;
