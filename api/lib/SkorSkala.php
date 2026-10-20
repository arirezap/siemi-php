<?php
declare(strict_types=1);

/** Skala skor resmi acuan asal suatu indikator (salinan server/src/lib/skorSkala.js). */
final class SkorSkala
{
    public static function untukIndikator(int $indicatorId): ?array
    {
        $r = Db::one('SELECT rf.skor_min, rf.skor_maks, rf.skor_step, rf.code AS acuan_kode
                        FROM standard_indicators i
                        LEFT JOIN ref_items ri ON ri.id = i.source_ref_item_id
                        LEFT JOIN refs rf ON rf.id = ri.ref_id WHERE i.id = ?', [$indicatorId]);
        if (!$r || $r['skor_min'] === null || $r['skor_maks'] === null) return null;
        return $r;
    }

    /** Pesan galat, atau null bila skor sah. */
    public static function validasi(?float $skor, ?array $skala): ?string
    {
        if ($skor === null || !$skala) return null;
        $min = (float)$skala['skor_min']; $maks = (float)$skala['skor_maks']; $step = (float)($skala['skor_step'] ?? 0);
        if ($skor < $min || $skor > $maks) return "Skor harus di antara $min dan $maks (acuan {$skala['acuan_kode']})";
        if ($step) {
            $langkah = ($skor - $min) / $step;
            if (abs(round($langkah) - $langkah) > 1e-6) return "Skor harus kelipatan $step dari $min (acuan {$skala['acuan_kode']})";
        }
        return null;
    }
}
