<?php
require_once __DIR__ . '/../lib/fpdf.php';

/**
 * Formatted sales-reconciliation PDF: real tables (borders, shaded header,
 * bold totals row) via FPDF, not a browser print of the HTML page.
 * FPDF's core fonts are Latin-1 only, so ৳ is rendered as "Tk".
 */
class SalesReportPdf extends FPDF
{
    public string $periodLabel = '';
    public string $branchLabel = 'All Factories';
    public string $generatedBy = '';

    /** FPDF core fonts are CP1252/Latin-1 only; transliterate UTF-8 input so
     *  characters like em-dash or non-Latin names degrade instead of garbling. */
    private function enc(string $s): string
    {
        $converted = @iconv('UTF-8', 'CP1252//TRANSLIT', $s);
        return $converted !== false ? $converted : mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
    }

    public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = ''): void
    {
        parent::Cell($w, $h, $this->enc((string)$txt), $border, $ln, $align, $fill, $link);
    }

    public function Header(): void
    {
        $this->SetFont('Helvetica', 'B', 14);
        $this->SetTextColor(31, 41, 55);
        $this->Cell(0, 7, 'Ujjal Flour Mills — Sales Report', 0, 1, 'L');
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, 'Period: ' . $this->periodLabel . '   |   Factory: ' . $this->branchLabel, 0, 1, 'L');
        $this->Cell(0, 4, 'Generated: ' . date('d M Y, h:i A') . ($this->generatedBy ? '   |   By: ' . $this->generatedBy : ''), 0, 1, 'L');
        $this->SetDrawColor(203, 213, 225);
        $this->SetLineWidth(0.3);
        $this->Line(10, $this->GetY() + 1, $this->GetPageWidth() - 10, $this->GetY() + 1);
        $this->Ln(4);
        $this->SetTextColor(0, 0, 0);
    }

    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 6, 'Page ' . $this->PageNo() . ' / {nb}', 0, 0, 'C');
    }

    public function sectionTitle(string $text): void
    {
        $this->SetFont('Helvetica', 'B', 11);
        $this->SetTextColor(31, 41, 55);
        $this->Cell(0, 7, $text, 0, 1, 'L');
        $this->Ln(1);
    }

    /**
     * @param string[] $headers
     * @param float[]  $widths
     * @param string[] $aligns  'L'|'R'|'C' per column
     * @param array<int,array<int,string>> $rows already-formatted string values
     * @param array<int,string>|null $totalRow formatted totals row (bold, shaded), or null
     */
    public function table(array $headers, array $widths, array $aligns, array $rows, ?array $totalRow = null): void
    {
        $lineHeight = 6;

        // Header row
        $this->SetFont('Helvetica', 'B', 8.5);
        $this->SetFillColor(51, 65, 85);
        $this->SetTextColor(255, 255, 255);
        $this->SetDrawColor(203, 213, 225);
        foreach ($headers as $i => $h) {
            $this->Cell($widths[$i], $lineHeight, $h, 1, 0, 'C', true);
        }
        $this->Ln();

        // Data rows
        $this->SetFont('Helvetica', '', 8.5);
        $this->SetTextColor(30, 30, 30);
        $fill = false;
        foreach ($rows as $row) {
            if ($this->GetY() > $this->GetPageHeight() - 20) {
                $this->AddPage($this->CurOrientation);
                $this->SetFont('Helvetica', 'B', 8.5);
                $this->SetFillColor(51, 65, 85);
                $this->SetTextColor(255, 255, 255);
                foreach ($headers as $i => $h) {
                    $this->Cell($widths[$i], $lineHeight, $h, 1, 0, 'C', true);
                }
                $this->Ln();
                $this->SetFont('Helvetica', '', 8.5);
                $this->SetTextColor(30, 30, 30);
            }
            $this->SetFillColor(248, 250, 252);
            foreach ($row as $i => $val) {
                $this->Cell($widths[$i], $lineHeight, $val, 1, 0, $aligns[$i] ?? 'L', $fill);
            }
            $this->Ln();
            $fill = !$fill;
        }

        // Total row
        if ($totalRow !== null) {
            $this->SetFont('Helvetica', 'B', 8.5);
            $this->SetFillColor(241, 245, 249);
            foreach ($totalRow as $i => $val) {
                $this->Cell($widths[$i], $lineHeight, $val, 1, 0, $aligns[$i] ?? 'L', true);
            }
            $this->Ln();
        }
        $this->Ln(6);
    }

    public static function money(float $n): string
    {
        return 'Tk ' . number_format($n, 0);
    }
}
