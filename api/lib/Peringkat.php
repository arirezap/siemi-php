<?php
declare(strict_types=1);

/** Salinan server/src/peringkat.js — skala peringkat Sasaran Mutu (level dari terbaik ke terendah). */
final class Peringkat
{
    public const SKALA = [
        'abc'      => ['A', 'B', 'C'],
        'predikat' => ['Unggul', 'Baik Sekali', 'Baik'],
        'opini'    => ['WTP', 'WDP', 'TW', 'TMP'],
    ];
    public const TIPE = ['angka', 'peringkat'];

    private static function n(mixed $v): string { return mb_strtolower(trim((string)$v)); }

    public static function urutan(?string $skala, mixed $nilai): int
    {
        if (!$skala || !isset(self::SKALA[$skala])) return -1;
        foreach (self::SKALA[$skala] as $i => $l) if (self::n($l) === self::n($nilai)) return $i;
        return -1;
    }

    /** Kembalikan penulisan baku level (mis. "unggul" -> "Unggul"), atau null bila tidak dikenal. */
    public static function normal(?string $skala, mixed $nilai): ?string
    {
        $i = self::urutan($skala, $nilai);
        return $i < 0 ? null : self::SKALA[$skala][$i];
    }

    public static function persen(?string $skala, mixed $target, mixed $capaian): ?int
    {
        if (!$skala || !isset(self::SKALA[$skala])) return null;
        $n = count(self::SKALA[$skala]);
        $rt = self::urutan($skala, $target);
        $rc = self::urutan($skala, $capaian);
        if ($rt < 0 || $rc < 0) return null;
        return $rc <= $rt ? 100 : (int)round((($n - $rc) / ($n - $rt)) * 100);
    }
}
