<?php
/**
 * Minimal, dependency-free XLSX writer (uses only PHP's built-in ZipArchive —
 * no Composer/PhpSpreadsheet available on this host). Produces a real,
 * genuinely-formatted .xlsx: bold/shaded header row, number formats,
 * borders, frozen header row, column widths, bold totals row.
 *
 * Usage:
 *   $xlsx = new XlsxWriter();
 *   $s = $xlsx->addSheet('Product Breakdown', [30,14,10,12,12,12,12]);
 *   $xlsx->headerRow($s, ['Product','Variant','Grade','Qty','Gross','Discount','Net']);
 *   $xlsx->row($s, ['Kobutor Moyda','50kg','', 26664, 67874950, 19300, 67857150],
 *              [ST_TEXT, ST_TEXT, ST_TEXT, ST_INT, ST_MONEY, ST_MONEY, ST_MONEY]);
 *   $xlsx->totalRow($s, ['Total','','', 26664, 67874950, 19300, 67857150],
 *              [ST_TEXT, ST_TEXT, ST_TEXT, ST_INT, ST_MONEY, ST_MONEY, ST_MONEY]);
 *   $xlsx->output('report.xlsx');
 */

const ST_TEXT  = 2; // bordered text
const ST_INT   = 3; // bordered integer, #,##0
const ST_MONEY = 4; // bordered 2dp number, #,##0.00

class XlsxWriter
{
    private array $sheets = []; // idx => ['name'=>, 'widths'=>, 'rows'=>[]]

    public function addSheet(string $name, array $colWidths = []): int
    {
        $idx = count($this->sheets);
        $this->sheets[$idx] = ['name' => $this->sanitizeSheetName($name), 'widths' => $colWidths, 'rows' => []];
        return $idx;
    }

    private function sanitizeSheetName(string $name): string
    {
        $name = preg_replace('/[\[\]\*\/\\\\\?:]/', ' ', $name);
        return mb_substr($name, 0, 31);
    }

    public function headerRow(int $sheet, array $cells): void
    {
        $this->sheets[$sheet]['rows'][] = ['type' => 'header', 'cells' => $cells];
    }

    public function titleRow(int $sheet, string $text, int $span = 1): void
    {
        $this->sheets[$sheet]['rows'][] = ['type' => 'title', 'cells' => [$text], 'span' => $span];
    }

    public function blankRow(int $sheet): void
    {
        $this->sheets[$sheet]['rows'][] = ['type' => 'blank', 'cells' => []];
    }

    public function row(int $sheet, array $values, array $styles): void
    {
        $this->sheets[$sheet]['rows'][] = ['type' => 'data', 'cells' => $values, 'styles' => $styles];
    }

    public function totalRow(int $sheet, array $values, array $styles): void
    {
        $this->sheets[$sheet]['rows'][] = ['type' => 'total', 'cells' => $values, 'styles' => $styles];
    }

    private function colLetter(int $n): string
    {
        $letters = '';
        $n++;
        while ($n > 0) {
            $rem = ($n - 1) % 26;
            $letters = chr(65 + $rem) . $letters;
            $n = intdiv($n - 1, 26);
        }
        return $letters;
    }

    private function xmlEscape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function buildSheetXml(array $sheet): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        if (!empty($sheet['widths'])) {
            $xml .= '<cols>';
            foreach ($sheet['widths'] as $i => $w) {
                $col = $i + 1;
                $xml .= '<col min="' . $col . '" max="' . $col . '" width="' . $w . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetViews><sheetView workbookViewId="0">';
        $xml .= '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>';
        $xml .= '</sheetView></sheetViews>';

        $xml .= '<sheetData>';
        $r = 1;
        foreach ($sheet['rows'] as $rowDef) {
            $xml .= '<row r="' . $r . '">';
            if ($rowDef['type'] === 'title') {
                $xml .= '<c r="A' . $r . '" t="inlineStr" s="8"><is><t xml:space="preserve">' . $this->xmlEscape($rowDef['cells'][0]) . '</t></is></c>';
            } elseif ($rowDef['type'] === 'blank') {
                // empty row
            } elseif ($rowDef['type'] === 'header') {
                foreach ($rowDef['cells'] as $i => $val) {
                    $ref = $this->colLetter($i) . $r;
                    $xml .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t xml:space="preserve">' . $this->xmlEscape((string)$val) . '</t></is></c>';
                }
            } else { // data / total
                $baseStyle = $rowDef['type'] === 'total' ? ['offset' => 3] : ['offset' => 0];
                foreach ($rowDef['cells'] as $i => $val) {
                    $ref = $this->colLetter($i) . $r;
                    $st = $rowDef['styles'][$i] ?? ST_TEXT;
                    $styleIdx = $st + $baseStyle['offset'];
                    if ($st === ST_TEXT) {
                        $xml .= '<c r="' . $ref . '" t="inlineStr" s="' . $styleIdx . '"><is><t xml:space="preserve">' . $this->xmlEscape((string)$val) . '</t></is></c>';
                    } else {
                        $num = is_numeric($val) ? $val : 0;
                        $xml .= '<c r="' . $ref . '" s="' . $styleIdx . '"><v>' . $num . '</v></c>';
                    }
                }
            }
            $xml .= '</row>';
            $r++;
        }
        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    private function stylesXml(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<numFmts count="2">
<numFmt numFmtId="164" formatCode="#,##0"/>
<numFmt numFmtId="165" formatCode="#,##0.00"/>
</numFmts>
<fonts count="4">
<font><sz val="10"/><name val="Calibri"/></font>
<font><b/><sz val="10"/><name val="Calibri"/></font>
<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
<font><b/><sz val="14"/><color rgb="FF1F2937"/><name val="Calibri"/></font>
</fonts>
<fills count="4">
<fill><patternFill patternType="none"/></fill>
<fill><patternFill patternType="gray125"/></fill>
<fill><patternFill patternType="solid"><fgColor rgb="FF334155"/><bgColor indexed="64"/></patternFill></fill>
<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/><bgColor indexed="64"/></patternFill></fill>
</fills>
<borders count="2">
<border><left/><right/><top/><bottom/><diagonal/></border>
<border><left style="thin"><color rgb="FFCBD5E1"/></left><right style="thin"><color rgb="FFCBD5E1"/></right><top style="thin"><color rgb="FFCBD5E1"/></top><bottom style="thin"><color rgb="FFCBD5E1"/></bottom></border>
</borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="9">
<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>
<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
<xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>
<xf numFmtId="164" fontId="1" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
<xf numFmtId="165" fontId="1" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="center"/></xf>
</cellXfs>
<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>
XML;
    }

    private function workbookXml(): string
    {
        $sheetsXml = '';
        foreach ($this->sheets as $i => $s) {
            $sheetsXml .= '<sheet name="' . $this->xmlEscape($s['name']) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheetsXml . '</sheets></workbook>';
    }

    private function workbookRelsXml(): string
    {
        $rels = '';
        foreach ($this->sheets as $i => $s) {
            $n = $i + 1;
            $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        }
        $n2 = count($this->sheets) + 1;
        $rels .= '<Relationship Id="rId' . $n2 . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
    }

    private function contentTypesXml(): string
    {
        $overrides = '';
        foreach ($this->sheets as $i => $s) {
            $n = $i + 1;
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $overrides
            . '</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    /** Build the zip and return raw bytes (does not touch output buffer). */
    public function toBytes(): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new ZipArchive();
        $zip->open($tmpFile, ZipArchive::OVERWRITE);
        $zip->addEmptyDir('_rels');
        $zip->addEmptyDir('xl');
        $zip->addEmptyDir('xl/_rels');
        $zip->addEmptyDir('xl/worksheets');
        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        foreach ($this->sheets as $i => $s) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->buildSheetXml($s));
        }
        $zip->close();
        $bytes = file_get_contents($tmpFile);
        unlink($tmpFile);
        return $bytes;
    }

    public function output(string $filename): void
    {
        $bytes = $this->toBytes();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: max-age=0');
        echo $bytes;
    }
}
