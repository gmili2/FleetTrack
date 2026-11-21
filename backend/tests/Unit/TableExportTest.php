<?php

use App\Exports\TableExport;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

it('écrit les textes saisis comme du texte, jamais comme une formule (injection Excel)', function () {
    $sheet = (new Spreadsheet)->getActiveSheet();
    $export = new TableExport('Test', ['A'], []);
    $export->bindValue($sheet->getCell('A1'), '=HYPERLINK("http://exemple.test","clic")');
    $export->bindValue($sheet->getCell('A2'), 42.5);

    expect($sheet->getCell('A1')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell('A2')->getDataType())->toBe(DataType::TYPE_NUMERIC);
});
