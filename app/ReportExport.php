<?php
declare(strict_types=1);
/** Dependency-free CSV / Excel (SpreadsheetML .xls) / PDF output for small tabular reports. Read-only presentation helper. */
final class ReportExport {
    public static function send(string $format, string $base, string $title, array $head, array $rows): void {
        $file = $base . '_' . date('Ymd');
        header('X-Content-Type-Options: nosniff');
        if ($format === 'xls') { self::xls($file, $title, $head, $rows); }
        elseif ($format === 'pdf') { self::pdf($file, $title, $head, $rows); }
        else { self::csv($file, $head, $rows); }
        exit;
    }
    private static function csvSafe($v): string { $v = (string)$v; return preg_match('/^[\x00-\x20]*[=+@\-]/u', $v) ? "'" . $v : $v; }
    private static function x($v): string { return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
    private static function csv(string $file, array $head, array $rows): void {
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $file . '.csv"');
        $fp = fopen('php://output', 'w'); fputcsv($fp, $head);
        foreach ($rows as $r) fputcsv($fp, array_map([self::class, 'csvSafe'], $r));
    }
    private static function xls(string $file, string $title, array $head, array $rows): void {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $file . '.xls"');
        $cell = function ($v): string {
            $isNum = is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d{1,15}(\.\d+)?$/', $v) && !preg_match('/^0\d/', $v));
            return '<Cell><Data ss:Type="' . ($isNum ? 'Number' : 'String') . '">' . self::x($v) . '</Data></Cell>';
        };
        $sheet = mb_substr((string)preg_replace('/[\\\\\/?*\[\]:]/', ' ', $title), 0, 31);
        echo '<?xml version="1.0" encoding="UTF-8"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
            . '<Styles><Style ss:ID="h"><Font ss:Bold="1"/><Interior ss:Color="#E8EAFB" ss:Pattern="Solid"/></Style></Styles>'
            . '<Worksheet ss:Name="' . self::x($sheet) . '"><Table>';
        echo '<Row>' . implode('', array_map(fn($h) => '<Cell ss:StyleID="h"><Data ss:Type="String">' . self::x($h) . '</Data></Cell>', $head)) . '</Row>';
        foreach ($rows as $r) echo '<Row>' . implode('', array_map($cell, $r)) . '</Row>';
        echo '</Table></Worksheet></Workbook>';
    }
    /** Minimal PDF 1.4 (A4 landscape, Helvetica). Text is converted to Windows-1252; wide cells are clipped. */
    private static function pdf(string $file, string $title, array $head, array $rows): void {
        $W = 842; $H = 595; $m = 36; $n = max(1, count($head)); $colW = ($W - 2 * $m) / $n; $maxCh = max(6, (int)floor($colW / 4.6));
        $enc = function ($s) use ($maxCh): string {
            $s = (string)$s; if (mb_strlen($s) > $maxCh) $s = mb_substr($s, 0, $maxCh - 1) . '...';
            $s = str_replace(['₹', '’', '‘', '“', '”', '–', '—'], ['Rs ', "'", "'", '"', '"', '-', '-'], $s);
            $s = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s) ?: '';
            return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $s);
        };
        $perPage = (int)floor(($H - 2 * $m - 50) / 16);
        $pages = array_chunk($rows, max(1, $perPage)) ?: [[]];
        $streams = [];
        foreach ($pages as $pi => $chunk) {
            $c = 'BT /F2 14 Tf ' . $m . ' ' . ($H - $m) . ' Td (' . $enc($title) . ") Tj ET\n";
            $c .= 'BT /F1 8 Tf ' . $m . ' ' . ($H - $m - 14) . ' Td (Generated ' . date('d M Y, h:i A') . '  |  Page ' . ($pi + 1) . ' of ' . count($pages) . ") Tj ET\n";
            $y = $H - $m - 40;
            $line = function (array $cells, string $font) use (&$c, &$y, $m, $colW, $enc): void {
                foreach (array_values($cells) as $i => $v) $c .= 'BT /' . $font . ' 8 Tf ' . round($m + $i * $colW, 1) . ' ' . $y . ' Td (' . $enc($v) . ") Tj ET\n";
                $c .= '0.8 G ' . $m . ' ' . ($y - 4) . ' m ' . round($m + count($cells) * $colW, 1) . ' ' . ($y - 4) . " l S\n";
                $y -= 16;
            };
            $line($head, 'F2');
            foreach ($chunk as $r) $line($r, 'F1');
            $streams[] = $c;
        }
        $out = "%PDF-1.4\n"; $off = [];
        $add = function (string $body) use (&$out, &$off): void { $off[] = strlen($out); $out .= count($off) . " 0 obj\n" . $body . "\nendobj\n"; };
        $add('<< /Type /Catalog /Pages 2 0 R >>');
        $kids = implode(' ', array_map(fn($i) => (5 + $i * 2) . ' 0 R', array_keys($streams)));
        $add('<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($streams) . ' >>');
        $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
        foreach ($streams as $i => $c) {
            $add('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $W . ' ' . $H . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . (6 + $i * 2) . ' 0 R >>');
            $add('<< /Length ' . strlen($c) . " >>\nstream\n" . $c . 'endstream');
        }
        $x = strlen($out); $cnt = count($off) + 1;
        $out .= "xref\n0 $cnt\n0000000000 65535 f \n";
        foreach ($off as $o) $out .= sprintf("%010d 00000 n \n", $o);
        $out .= "trailer\n<< /Size $cnt /Root 1 0 R >>\nstartxref\n$x\n%%EOF";
        header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="' . $file . '.pdf"'); header('Content-Length: ' . strlen($out));
        echo $out;
    }
}
