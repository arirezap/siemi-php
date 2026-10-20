<?php
declare(strict_types=1);

/**
 * Penulis .xlsx minimal tanpa ekstensi tambahan (zip ditulis manual, metode STORED).
 * Padanan server/src/lib/xlsxWriter.js. Format sheet:
 *   ['nama'=>'Sheet1','kolom'=>[['lebar'=>12],...],'beku'=>['kolom'=>1,'baris'=>1],
 *    'baris'=>[['nilai'=>[..],'gaya'=>Xlsx::HEADER,'tinggi'=>24], ...]]
 */
final class Xlsx
{
    public const PALET = ['DBEAFE', 'DCFCE7', 'FEF3C7', 'EDE9FE', 'FFE4E6', 'E2E8F0'];

    public const JUDUL = [
        'font' => ['name' => 'Arial', 'size' => 14, 'bold' => true, 'color' => 'FFFFFF'],
        'fill' => '0F172A',
        'align' => ['h' => 'left', 'v' => 'center', 'indent' => 1],
    ];
    public const SUBJUDUL = [
        'font' => ['name' => 'Arial', 'size' => 10, 'italic' => true, 'color' => 'FFFFFF'],
        'fill' => '0F172A',
        'align' => ['h' => 'left', 'v' => 'center', 'indent' => 1],
    ];
    public const HEADER = [
        'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => 'FFFFFF'],
        'fill' => '1D4ED8',
        'align' => ['h' => 'left', 'v' => 'center', 'wrap' => true, 'indent' => 1],
        'border' => true,
    ];

    public static function data(?string $fillHex = null): array
    {
        return [
            'font' => ['name' => 'Arial', 'size' => 9],
            'fill' => $fillHex,
            'align' => ['h' => 'left', 'v' => 'top', 'wrap' => true, 'indent' => 1],
            'border' => true,
        ];
    }

    /* ------------------------------ ZIP ---------------------------------- */
    private static function zip(array $entries): string
    {
        $t = getdate();
        $dosTime = (($t['hours'] & 0x1f) << 11) | (($t['minutes'] & 0x3f) << 5) | (intdiv($t['seconds'], 2) & 0x1f);
        $dosDate = ((($t['year'] - 1980) & 0x7f) << 9) | (($t['mon'] & 0xf) << 5) | ($t['mday'] & 0x1f);
        $lokal = '';
        $sentral = '';
        $offset = 0;
        foreach ($entries as [$nama, $data]) {
            $crc = crc32($data) & 0xffffffff;
            $ukuran = strlen($data);
            $n = strlen($nama);
            $lokal .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate, $crc, $ukuran, $ukuran, $n, 0) . $nama . $data;
            $sentral .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate, $crc, $ukuran, $ukuran, $n, 0, 0, 0, 0, 0, $offset) . $nama;
            $offset += 30 + $n + $ukuran;
        }
        $akhir = pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries), strlen($sentral), $offset, 0);
        return $lokal . $sentral . $akhir;
    }

    private static function esc(mixed $v): string
    {
        $s = htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? $s;
    }

    private static function kolomKe(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $sisa = ($n - 1) % 26;
            $s = chr(65 + $sisa) . $s;
            $n = intdiv($n - 1, 26);
        }
        return $s;
    }

    /* ------------------------ Registri gaya ------------------------------ */
    private array $fonts = [];
    private array $fills = [];
    private array $borders = [];
    private array $xfs = [];

    private function __construct()
    {
        $this->fonts = [['name' => 'Calibri', 'size' => 11, 'bold' => false, 'italic' => false, 'color' => null]];
        $this->fills = [['type' => 'none'], ['type' => 'gray125']];
        $this->borders = [['thin' => false]];
        $this->xfs = [['fontId' => 0, 'fillId' => 0, 'borderId' => 0, 'align' => null]];
    }

    private static function idDalam(array &$daftar, array $item): int
    {
        $key = json_encode($item);
        foreach ($daftar as $i => $x) if (json_encode($x) === $key) return $i;
        $daftar[] = $item;
        return count($daftar) - 1;
    }

    private function idXf(?array $g): int
    {
        if (!$g) return 0;
        $f = $g['font'] ?? [];
        $font = [
            'name' => $f['name'] ?? 'Calibri', 'size' => $f['size'] ?? 11,
            'bold' => !empty($f['bold']), 'italic' => !empty($f['italic']), 'color' => $f['color'] ?? null,
        ];
        $fill = !empty($g['fill']) ? ['type' => 'solid', 'rgb' => strtoupper((string)$g['fill'])] : ['type' => 'none'];
        $xf = [
            'fontId' => self::idDalam($this->fonts, $font),
            'fillId' => self::idDalam($this->fills, $fill),
            'borderId' => self::idDalam($this->borders, ['thin' => !empty($g['border'])]),
            'align' => $g['align'] ?? null,
        ];
        return self::idDalam($this->xfs, $xf);
    }

    private function stylesXml(): string
    {
        $fonts = '';
        foreach ($this->fonts as $f) {
            $b = '<sz val="' . $f['size'] . '"/>';
            if ($f['color']) $b .= '<color rgb="FF' . $f['color'] . '"/>';
            $b .= '<name val="' . self::esc($f['name']) . '"/>';
            if ($f['bold']) $b .= '<b/>';
            if ($f['italic']) $b .= '<i/>';
            $fonts .= "<font>$b</font>";
        }
        $fills = '';
        foreach ($this->fills as $f) {
            if ($f['type'] === 'solid') $fills .= '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $f['rgb'] . '"/><bgColor indexed="64"/></patternFill></fill>';
            elseif ($f['type'] === 'gray125') $fills .= '<fill><patternFill patternType="gray125"/></fill>';
            else $fills .= '<fill><patternFill patternType="none"/></fill>';
        }
        $borders = '';
        foreach ($this->borders as $b) {
            if (!$b['thin']) { $borders .= '<border><left/><right/><top/><bottom/><diagonal/></border>'; continue; }
            $c = '<color indexed="64"/>';
            $borders .= "<border><left style=\"thin\">$c</left><right style=\"thin\">$c</right><top style=\"thin\">$c</top><bottom style=\"thin\">$c</bottom><diagonal/></border>";
        }
        $xfs = '';
        foreach ($this->xfs as $xf) {
            $al = '';
            if ($xf['align']) {
                $a = $xf['align'];
                $at = 'horizontal="' . ($a['h'] ?? 'left') . '" vertical="' . ($a['v'] ?? 'top') . '"';
                if (!empty($a['wrap'])) $at .= ' wrapText="1"';
                if (!empty($a['indent'])) $at .= ' indent="' . $a['indent'] . '"';
                $al = "<alignment $at/>";
            }
            $attrs = 'numFmtId="0" fontId="' . $xf['fontId'] . '" fillId="' . $xf['fillId'] . '" borderId="' . $xf['borderId'] . '" xfId="0"'
                . ($xf['fontId'] ? ' applyFont="1"' : '') . ($xf['fillId'] ? ' applyFill="1"' : '')
                . ($xf['borderId'] ? ' applyBorder="1"' : '') . ($al ? ' applyAlignment="1"' : '');
            $xfs .= $al ? "<xf $attrs>$al</xf>" : "<xf $attrs/>";
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="' . count($this->fonts) . '">' . $fonts . '</fonts>'
            . '<fills count="' . count($this->fills) . '">' . $fills . '</fills>'
            . '<borders count="' . count($this->borders) . '">' . $borders . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($this->xfs) . '">' . $xfs . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private function sheetXml(array $sheet): string
    {
        $baris = $sheet['baris'];
        if (!empty($sheet['kolom'])) $jumlahKolom = count($sheet['kolom']);
        else { $jumlahKolom = 1; foreach ($baris as $b) $jumlahKolom = max($jumlahKolom, count($b['nilai'] ?? [])); }

        $cols = '';
        if (!empty($sheet['kolom'])) {
            foreach ($sheet['kolom'] as $i => $k)
                if (!empty($k['lebar'])) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $k['lebar'] . '" customWidth="1"/>';
            $cols = "<cols>$cols</cols>";
        }

        $rows = '';
        foreach ($baris as $ri => $row) {
            $rNum = $ri + 1;
            $s = $this->idXf($row['gaya'] ?? null);
            $nil = $row['nilai'] ?? [];
            $nKol = !empty($row['gaya']) ? $jumlahKolom : count($nil);
            $sAttr = $s ? " s=\"$s\"" : '';
            $cells = '';
            for ($ci = 0; $ci < $nKol; $ci++) {
                $val = $nil[$ci] ?? null;
                $ref = self::kolomKe($ci + 1) . $rNum;
                if ($val === null || $val === '') {
                    if ($s) $cells .= "<c r=\"$ref\"$sAttr/>";
                } elseif ((is_int($val) || is_float($val)) && is_finite((float)$val)) {
                    $cells .= "<c r=\"$ref\"$sAttr><v>$val</v></c>";
                } else {
                    $cells .= "<c r=\"$ref\" t=\"inlineStr\"$sAttr xml:space=\"preserve\"><is><t xml:space=\"preserve\">" . self::esc($val) . '</t></is></c>';
                }
            }
            $ht = !empty($row['tinggi']) ? ' ht="' . $row['tinggi'] . '" customHeight="1"' : '';
            $rows .= "<row r=\"$rNum\"$ht>$cells</row>";
        }

        $views = '';
        $beku = $sheet['beku'] ?? null;
        if ($beku && (!empty($beku['kolom']) || !empty($beku['baris']))) {
            $x = (int)($beku['kolom'] ?? 0);
            $y = (int)($beku['baris'] ?? 0);
            $topLeft = self::kolomKe($x + 1) . ($y + 1);
            $pane = ($x && $y) ? 'bottomRight' : ($x ? 'topRight' : 'bottomLeft');
            $views = '<sheetViews><sheetView workbookViewId="0"><pane' . ($x ? " xSplit=\"$x\"" : '') . ($y ? " ySplit=\"$y\"" : '')
                . " topLeftCell=\"$topLeft\" activePane=\"$pane\" state=\"frozen\"/></sheetView></sheetViews>";
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $views . $cols . '<sheetData>' . $rows . '</sheetData></worksheet>';
    }

    /** @param array<int,array> $sheets */
    public static function buat(array $sheets): string
    {
        $me = new self();
        foreach ($sheets as $sh) foreach ($sh['baris'] as $b) $me->idXf($b['gaya'] ?? null);

        $nama = function ($s, int $i): string {
            $n = mb_substr(preg_replace('/[:\\\\\/?*\[\]]/', ' ', (string)($s ?: 'Sheet' . ($i + 1))), 0, 31);
            return $n !== '' ? $n : 'Sheet' . ($i + 1);
        };

        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        foreach ($sheets as $i => $_) $ct .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $ct .= '</Types>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';

        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        foreach ($sheets as $i => $s) $wb .= '<sheet name="' . self::esc($nama($s['nama'] ?? null, $i)) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 2) . '"/>';
        $wb .= '</sheets></workbook>';

        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        foreach ($sheets as $i => $_) $wbRels .= '<Relationship Id="rId' . ($i + 2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        $wbRels .= '</Relationships>';

        $entries = [
            ['[Content_Types].xml', $ct], ['_rels/.rels', $rootRels], ['xl/workbook.xml', $wb],
            ['xl/_rels/workbook.xml.rels', $wbRels],
        ];
        $sheetXmls = [];
        foreach ($sheets as $i => $s) $sheetXmls[] = ['xl/worksheets/sheet' . ($i + 1) . '.xml', $me->sheetXml($s)];
        $entries[] = ['xl/styles.xml', $me->stylesXml()];
        return self::zip(array_merge($entries, $sheetXmls));
    }

    /** Kirim sebagai unduhan. */
    public static function kirim(string $isi, string $namaBerkas): never
    {
        http_response_code(200);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^\w.\- ]/', '_', $namaBerkas) . '"');
        header('Content-Length: ' . strlen($isi));
        echo $isi;
        exit;
    }
}
