<?php
require_once __DIR__ . '/../lib/tfpdf.php';
// tFPDF's AddFont(..., $uni=true) needs TTFontFile (from tFPDF's own
// font/unifont/ttfonts.php) to parse/subset a TTF — tfpdf.php ships with
// that require commented out (assumes an autoloader), so it's required
// explicitly here instead.
require_once __DIR__ . '/../lib/font/unifont/ttfonts.php';

/**
 * Shared PDF renderer for the Chairman's Report Center (admin/chairman_reports.php),
 * built 4 Sep 2026. Same proven structure as SalesReportPdf/DailyActivityReportPdf
 * (real bordered tables via FPDF, shaded header row, zebra striping, auto-repeating
 * header across page breaks, bold totals row) — but parameterized by $reportTitle
 * instead of hardcoding one report's name, since one class now backs all 14
 * report types in the hub so every one of them looks identical and consistently
 * "chairman-grade" rather than each report inventing its own layout.
 *
 * Switched from plain FPDF to tFPDF on 4 Sep 2026: the purchase-side reports
 * surfaced real Bengali supplier names and countries-of-origin
 * (এলিট এন্টারপ্রাইস, রাশিয়া, ...) that plain FPDF's Latin-1-only core fonts
 * rendered as illegible "???" — confirmed by rendering an actual PDF to PNG
 * and looking at it, not just checking for PHP errors. tFPDF embeds a real
 * TTF and writes UTF-8 directly (no manual CP1252 transliteration needed),
 * so this now uses "Hind Siliguri" (Google Fonts, OFL-licensed, includes
 * both Bengali and Latin glyphs — one font renders English, Bengali, and Tk
 * amounts correctly in the same cell) via core/lib/font/unifont/, tFPDF's
 * own expected font-file location. Regular + Bold weights are real static
 * TTFs (not a variable font) so header cells still render properly bold.
 */
class ChairmanReportPdf extends tFPDF
{
    public string $reportTitle = 'Report';
    public string $periodLabel = '';
    public string $branchLabel = 'All Branches';
    public string $generatedBy = '';

    public function __construct($orientation = 'P', $unit = 'mm', $size = 'A4')
    {
        parent::__construct($orientation, $unit, $size);
        $this->AddFont('HindSiliguri', '', 'HindSiliguri-Regular.ttf', true);
        $this->AddFont('HindSiliguri', 'B', 'HindSiliguri-Bold.ttf', true);
        // Deliberately NOT registering a style 'I' here (no italic TTF to
        // give it) — tried pointing it at the same Regular file as a cheap
        // fake-italic and it broke both styles: tFPDF's AddFont() derives
        // its on-disk metrics-cache filename from the TTF filename alone,
        // and the cached .mtx.php's include() overwrites AddFont()'s local
        // $fontkey variable in the current scope — so a second AddFont()
        // call reusing the same .ttf file re-registers under the FIRST
        // call's fontkey instead of its own, silently corrupting the first
        // one. note() below uses regular weight instead of italic.
    }

    /**
     * No-op now — kept (rather than removed) so every call site below reads
     * the same as before switching to tFPDF; tFPDF's uni=true fonts take
     * raw UTF-8 directly, unlike plain FPDF's Latin-1 core fonts which
     * needed manual CP1252 transliteration here.
     */
    private function enc(string $s): string
    {
        return $s;
    }

    public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = ''): void
    {
        parent::Cell($w, $h, $this->enc((string)$txt), $border, $ln, $align, $fill, $link);
    }

    public function Header(): void
    {
        $this->SetFont('HindSiliguri', 'B', 14);
        $this->SetTextColor(31, 41, 55);
        $this->Cell(0, 7, 'Ujjal Flour Mills — ' . $this->reportTitle, 0, 1, 'L');
        $this->SetFont('HindSiliguri', '', 9);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, 'Period: ' . $this->periodLabel . '   |   Scope: ' . $this->branchLabel, 0, 1, 'L');
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
        $this->SetFont('HindSiliguri', '', 8);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 6, 'Page ' . $this->PageNo() . ' / {nb}', 0, 0, 'C');
    }

    public function sectionTitle(string $text): void
    {
        $this->SetFont('HindSiliguri', 'B', 11);
        $this->SetTextColor(31, 41, 55);
        $this->Cell(0, 7, $text, 0, 1, 'L');
        $this->Ln(1);
    }

    /** A short note line under a section title — e.g. explaining an empty section. */
    public function note(string $text): void
    {
        // No italic weight registered (see __construct) — gray + regular
        // weight still reads as a secondary note line.
        $this->SetFont('HindSiliguri', '', 8.5);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 5, $text, 0, 1, 'L');
        $this->SetTextColor(0, 0, 0);
        $this->Ln(2);
    }

    /**
     * @param string[] $headers
     * @param float[]  $widths
     * @param string[] $aligns  'L'|'R'|'C' per column
     * @param array<int,array<int,string>> $rows already-formatted string values
     * @param array<int,string>|null $totalRow formatted totals row (bold, shaded), or null
     * @param float $fontSize added 4 Sep 2026 — lets a wide/dense table (e.g. a
     *   fully itemized report with 15+ columns) shrink to fit a landscape page
     *   without every other report's table having to shrink too. Header stays
     *   1pt larger than body, same ratio as the original 8.5 default.
     */
    public function table(array $headers, array $widths, array $aligns, array $rows, ?array $totalRow = null, float $fontSize = 8.5): void
    {
        $lineHeight = max(4.5, $fontSize * 0.7);
        $headerSize = $fontSize + 1;

        $this->SetFont('HindSiliguri', 'B', $headerSize);
        $this->SetFillColor(51, 65, 85);
        $this->SetTextColor(255, 255, 255);
        $this->SetDrawColor(203, 213, 225);
        foreach ($headers as $i => $h) {
            $this->Cell($widths[$i], $lineHeight, $h, 1, 0, 'C', true);
        }
        $this->Ln();

        $this->SetFont('HindSiliguri', '', $fontSize);
        $this->SetTextColor(30, 30, 30);
        $fill = false;
        foreach ($rows as $row) {
            if ($this->GetY() > $this->GetPageHeight() - 20) {
                $this->AddPage($this->CurOrientation);
                $this->SetFont('HindSiliguri', 'B', $headerSize);
                $this->SetFillColor(51, 65, 85);
                $this->SetTextColor(255, 255, 255);
                foreach ($headers as $i => $h) {
                    $this->Cell($widths[$i], $lineHeight, $h, 1, 0, 'C', true);
                }
                $this->Ln();
                $this->SetFont('HindSiliguri', '', $fontSize);
                $this->SetTextColor(30, 30, 30);
            }
            $this->SetFillColor(248, 250, 252);
            foreach ($row as $i => $val) {
                $this->Cell($widths[$i], $lineHeight, $val, 1, 0, $aligns[$i] ?? 'L', $fill);
            }
            $this->Ln();
            $fill = !$fill;
        }

        if ($totalRow !== null) {
            $this->SetFont('HindSiliguri', 'B', $fontSize);
            $this->SetFillColor(241, 245, 249);
            foreach ($totalRow as $i => $val) {
                $this->Cell($widths[$i], $lineHeight, $val, 1, 0, $aligns[$i] ?? 'L', true);
            }
            $this->Ln();
        }
        $this->Ln(6);
    }

    /**
     * Width-safe replacement for GetStringWidth(), added 4 Sep 2026 after
     * live-testing purchase-side Bengali supplier/origin names: this font's
     * parsed glyph metrics (via tFPDF's bundled TTFontFile, a very old
     * TTF-table parser) report a wildly bogus advance width for at least
     * one real Bengali combining mark — confirmed live: U+09BC (Bengali
     * sign NUKTA, used in common words like "রাশিয়া") measured ~162mm
     * instead of its true near-zero advance, which single-handedly wrecked
     * word-wrap decisions for any string containing it. Summing per-glyph
     * widths and clamping anything past a sane per-glyph ceiling is not
     * just a workaround here — a combining mark's real advance width IS
     * approximately zero, so clamping it is the correct value anyway, and
     * it's a safe net against any other glyph this parser mismeasures.
     */
    private function safeWidth(string $s): float
    {
        static $cap = null;
        if ($cap === null) $cap = 6.0; // mm — generous for any real glyph at this class's 6.5-8.5pt sizes
        $total = 0.0;
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $w = $this->GetStringWidth($ch);
            if ($w <= $cap) $total += $w;
        }
        return $total;
    }

    /**
     * Word-wrap $text to fit $width mm at $fontSize, returns an array of line
     * strings. A single "word" too wide to ever fit on its own line (order
     * numbers, truck numbers — no spaces to break on) is hard-broken by
     * extended grapheme cluster (PCRE's \X — keeps a base character and its
     * combining marks, e.g. a Bengali vowel sign or nukta, together) rather
     * than by raw codepoint, so a forced mid-word break can't orphan a
     * combining mark onto its own line as a stray glyph.
     */
    private function wrapLines(string $text, float $width, float $fontSize): array
    {
        $text = trim($text);
        if ($text === '') return [''];
        $this->SetFont('HindSiliguri', '', $fontSize);
        $maxW = max(2, $width - 2); // ~1mm padding each side
        $words = preg_split('/\s+/', $text) ?: [$text];
        $lines = []; $cur = '';
        foreach ($words as $w) {
            if ($this->safeWidth($w) > $maxW) {
                if ($cur !== '') { $lines[] = $cur; $cur = ''; }
                $chunk = '';
                foreach (preg_split('/(\X)/u', $w, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$w] as $ch) {
                    $test = $chunk . $ch;
                    if ($chunk !== '' && $this->safeWidth($test) > $maxW) {
                        $lines[] = $chunk;
                        $chunk = $ch;
                    } else {
                        $chunk = $test;
                    }
                }
                $cur = $chunk;
                continue;
            }
            $test = $cur === '' ? $w : $cur . ' ' . $w;
            if ($cur === '' || $this->safeWidth($test) <= $maxW) {
                $cur = $test;
            } else {
                $lines[] = $cur;
                $cur = $w;
            }
        }
        if ($cur !== '') $lines[] = $cur;
        return $lines ?: [''];
    }

    /**
     * A genuinely merged (rowspan-style) table, added 4 Sep 2026 for the
     * Completed Sales report: order-level columns (customer, approval,
     * production, payment, totals, ...) are drawn as ONE tall spanning cell
     * per order, item-level columns (product, qty, price, ...) get one row
     * per item beside it — like a spreadsheet merge, with wrapped
     * (non-clipped) text, centered/aligned per column, and filled cell
     * backgrounds (zebra-striped per order block). FPDF has no native
     * rowspan or auto-wrap-with-uniform-row-height, so both are hand-built
     * here: wrapLines() simulates word-wrap via GetStringWidth(), and each
     * order block's height is the larger of (sum of its item rows' wrapped
     * heights) or (its own order-level text's wrapped height) — any extra
     * is absorbed into the block's last item row so the merged cell and its
     * item rows always add up exactly.
     *
     * @param array<int,array{order:array,items:array<int,array>}> $groups
     */
    public function mergedTable(array $orderHeaders, array $orderWidths, array $orderAligns,
                                 array $itemHeaders, array $itemWidths, array $itemAligns,
                                 array $groups, float $fontSize = 6.5): void
    {
        $lineHeight = max(3.6, $fontSize * 0.62);
        $headerSize = $fontSize + 1;
        $allHeaders = array_merge($orderHeaders, $itemHeaders);
        $allWidths  = array_merge($orderWidths, $itemWidths);
        $startX = $this->GetX();

        // Headers wrap too (same as data cells) — several labels here
        // ("Payment Method", "Production (Start->Done)") don't fit their
        // column width unwrapped, and FPDF's plain Cell() doesn't clip or
        // wrap, it just overflows into the next column, which read as
        // overlapping garbled text before this fix.
        $drawHeader = function () use ($allHeaders, $allWidths, $headerSize) {
            $this->SetFont('HindSiliguri', 'B', $headerSize);
            $this->SetFillColor(51, 65, 85);
            $this->SetTextColor(255, 255, 255);
            $this->SetDrawColor(203, 213, 225);
            $headerLineHeight = max(3.6, $headerSize * 0.62);
            $wrapped = []; $maxLines = 1;
            foreach ($allHeaders as $i => $h) {
                $wrapped[$i] = $this->wrapLines((string)$h, $allWidths[$i], $headerSize);
                $maxLines = max($maxLines, count($wrapped[$i]));
            }
            $hh = $maxLines * $headerLineHeight;
            $hx = $this->GetX(); $hy = $this->GetY();
            $cx = $hx;
            foreach ($allHeaders as $i => $h) {
                $this->Rect($cx, $hy, $allWidths[$i], $hh, 'DF');
                $lines = $wrapped[$i];
                $ty = $hy + ($hh - count($lines) * $headerLineHeight) / 2;
                foreach ($lines as $ln) {
                    $this->SetXY($cx, $ty);
                    $this->Cell($allWidths[$i], $headerLineHeight, $ln, 0, 0, 'C');
                    $ty += $headerLineHeight;
                }
                $cx += $allWidths[$i];
            }
            $this->SetXY($hx, $hy + $hh);
            $this->SetTextColor(30, 30, 30);
        };
        $drawHeader();
        $this->SetLineWidth(0.2);

        $zebra = false;
        $emptyItem = array_fill(0, count($itemHeaders), '-');
        foreach ($groups as $g) {
            $order = $g['order'];
            $itemRows = $g['items'] ?: [$emptyItem];

            $itemLineCounts = [];
            foreach ($itemRows as $rIdx => $row) {
                $maxLines = 1;
                foreach ($row as $ci => $val) {
                    $maxLines = max($maxLines, count($this->wrapLines((string)$val, $itemWidths[$ci], $fontSize)));
                }
                $itemLineCounts[$rIdx] = $maxLines;
            }
            $itemRowHeights = array_map(fn($lc) => $lc * $lineHeight, $itemLineCounts);
            $itemsBlockHeight = array_sum($itemRowHeights);

            $orderMaxLines = 1;
            foreach ($order as $ci => $val) {
                $orderMaxLines = max($orderMaxLines, count($this->wrapLines((string)$val, $orderWidths[$ci], $fontSize)));
            }
            $blockHeight = max($itemsBlockHeight, $orderMaxLines * $lineHeight);
            if ($blockHeight > $itemsBlockHeight) {
                $extra = $blockHeight - $itemsBlockHeight;
                $lastIdx = count($itemRowHeights) - 1;
                if ($lastIdx >= 0) $itemRowHeights[$lastIdx] += $extra;
                else $itemRowHeights = [$blockHeight];
            }

            $pageAvail = $this->GetPageHeight() - 20 - $this->GetY();
            if ($blockHeight <= $this->GetPageHeight() - 40 && $blockHeight > $pageAvail) {
                $this->AddPage($this->CurOrientation);
                $drawHeader();
                $this->SetLineWidth(0.2);
            }

            $blockTopY = $this->GetY();
            $fill = $zebra ? [248, 250, 252] : [255, 255, 255];
            $this->SetFillColor($fill[0], $fill[1], $fill[2]);
            $this->SetDrawColor(203, 213, 225);
            $this->SetFont('HindSiliguri', '', $fontSize);
            $this->SetTextColor(30, 30, 30);

            $x = $startX;
            foreach ($order as $ci => $val) {
                $this->Rect($x, $blockTopY, $orderWidths[$ci], $blockHeight, 'DF');
                $lines = $this->wrapLines((string)$val, $orderWidths[$ci], $fontSize);
                $ty = $blockTopY + ($blockHeight - count($lines) * $lineHeight) / 2;
                foreach ($lines as $ln) {
                    $this->SetXY($x, $ty);
                    $this->Cell($orderWidths[$ci], $lineHeight, $ln, 0, 0, $orderAligns[$ci] ?? 'C');
                    $ty += $lineHeight;
                }
                $x += $orderWidths[$ci];
            }

            $itemY = $blockTopY;
            foreach ($itemRows as $rIdx => $row) {
                $rh = $itemRowHeights[$rIdx];
                $ix = $x;
                foreach ($row as $ci => $val) {
                    $this->Rect($ix, $itemY, $itemWidths[$ci], $rh, 'DF');
                    $lines = $this->wrapLines((string)$val, $itemWidths[$ci], $fontSize);
                    $ty = $itemY + ($rh - count($lines) * $lineHeight) / 2;
                    foreach ($lines as $ln) {
                        $this->SetXY($ix, $ty);
                        $this->Cell($itemWidths[$ci], $lineHeight, $ln, 0, 0, $itemAligns[$ci] ?? 'C');
                        $ty += $lineHeight;
                    }
                    $ix += $itemWidths[$ci];
                }
                $itemY += $rh;
            }

            $this->SetXY($startX, $blockTopY + $blockHeight);
            $zebra = !$zebra;
        }
        $this->Ln(6);
    }

    public static function money(float $n): string
    {
        return 'Tk ' . number_format($n, 0);
    }

    public static function money2(float $n): string
    {
        return 'Tk ' . number_format($n, 2);
    }

    /**
     * A flat table (no rowspan/merge — every report that isn't a
     * hierarchical order->items shape uses this) whose cells still WRAP,
     * fill, and center like mergedTable(), added 4 Sep 2026 for Collections
     * once it grew to 15 columns and plain table()'s un-wrapped Cell() calls
     * would have overlapped/clipped just like the header bug fixed earlier
     * today. Each row's height is the max wrapped-line-count across its
     * cells — a much simpler case than mergedTable() since there's no
     * order/item split to reconcile heights against.
     */
    public function wrappedTable(array $headers, array $widths, array $aligns, array $rows, ?array $totalRow = null, float $fontSize = 7): void
    {
        $lineHeight = max(3.6, $fontSize * 0.62);
        $headerSize = $fontSize + 1;
        $startX = $this->GetX();

        $drawHeader = function () use ($headers, $widths, $headerSize) {
            $this->SetFont('HindSiliguri', 'B', $headerSize);
            $this->SetFillColor(51, 65, 85);
            $this->SetTextColor(255, 255, 255);
            $this->SetDrawColor(203, 213, 225);
            $headerLineHeight = max(3.6, $headerSize * 0.62);
            $wrapped = []; $maxLines = 1;
            foreach ($headers as $i => $h) {
                $wrapped[$i] = $this->wrapLines((string)$h, $widths[$i], $headerSize);
                $maxLines = max($maxLines, count($wrapped[$i]));
            }
            $hh = $maxLines * $headerLineHeight;
            $hx = $this->GetX(); $hy = $this->GetY(); $cx = $hx;
            foreach ($headers as $i => $h) {
                $this->Rect($cx, $hy, $widths[$i], $hh, 'DF');
                $lines = $wrapped[$i];
                $ty = $hy + ($hh - count($lines) * $headerLineHeight) / 2;
                foreach ($lines as $ln) {
                    $this->SetXY($cx, $ty);
                    $this->Cell($widths[$i], $headerLineHeight, $ln, 0, 0, 'C');
                    $ty += $headerLineHeight;
                }
                $cx += $widths[$i];
            }
            $this->SetXY($hx, $hy + $hh);
            $this->SetTextColor(30, 30, 30);
        };
        $drawHeader();
        $this->SetLineWidth(0.2);

        $zebra = false;
        foreach ($rows as $row) {
            $wrapped = []; $maxLines = 1;
            foreach ($row as $i => $val) {
                $wrapped[$i] = $this->wrapLines((string)$val, $widths[$i], $fontSize);
                $maxLines = max($maxLines, count($wrapped[$i]));
            }
            $rh = $maxLines * $lineHeight;

            $pageAvail = $this->GetPageHeight() - 20 - $this->GetY();
            if ($rh <= $this->GetPageHeight() - 40 && $rh > $pageAvail) {
                $this->AddPage($this->CurOrientation);
                $drawHeader();
                $this->SetLineWidth(0.2);
            }

            $y = $this->GetY();
            $fill = $zebra ? [248, 250, 252] : [255, 255, 255];
            $this->SetFillColor($fill[0], $fill[1], $fill[2]);
            $this->SetDrawColor(203, 213, 225);
            $this->SetFont('HindSiliguri', '', $fontSize);
            $this->SetTextColor(30, 30, 30);
            $x = $startX;
            foreach ($row as $i => $val) {
                $this->Rect($x, $y, $widths[$i], $rh, 'DF');
                $lines = $wrapped[$i];
                $ty = $y + ($rh - count($lines) * $lineHeight) / 2;
                foreach ($lines as $ln) {
                    $this->SetXY($x, $ty);
                    $this->Cell($widths[$i], $lineHeight, $ln, 0, 0, $aligns[$i] ?? 'C');
                    $ty += $lineHeight;
                }
                $x += $widths[$i];
            }
            $this->SetXY($startX, $y + $rh);
            $zebra = !$zebra;
        }

        if ($totalRow !== null) {
            $this->SetFont('HindSiliguri', 'B', $fontSize);
            $this->SetFillColor(241, 245, 249);
            $this->SetDrawColor(203, 213, 225);
            $x = $startX; $y = $this->GetY();
            foreach ($totalRow as $i => $val) {
                $this->Rect($x, $y, $widths[$i], $lineHeight, 'DF');
                $this->SetXY($x, $y);
                $this->Cell($widths[$i], $lineHeight, (string)$val, 0, 0, $aligns[$i] ?? 'C');
                $x += $widths[$i];
            }
            $this->SetXY($startX, $y + $lineHeight);
        }
        $this->Ln(6);
    }
}
