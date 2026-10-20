<?php
declare(strict_types=1);

/* SISTEM PENJAMINAN MUTU EKSTERNAL (SPME) — padanan server/src/routes/spme.js.
   Perhitungan dipertahankan persis (termasuk pembulatan gaya JavaScript). */

/** Math.round gaya JavaScript (setengah ke atas). */
function jr(float|int $x): float { return floor($x + 0.5); }
function r2(float|int $x): float { return jr($x * 100) / 100; }
function r1p(float|int $x): float { return jr($x * 1000) / 10; }

const SPME_TETAP = [
    'akreditasi_pt' => ['Akreditasi Perguruan Tinggi', 'BANPT'],
    'iso21001' => ['ISO 21001', 'ISO21001'],
    'zi' => ['Zona Integritas (WBK-WBBM)', 'ZI'],
    'greenmetric' => ['UI GreenMetric', 'GREENMETRIC'],
];

const SPME_AMBANG_DASAR = 2;

function spme_s(string $kunci, array $kode, string $tipe, float $a5, float $a3, ?string $satuan = null, ?string $arah = null): array
{
    return ['kunci' => $kunci, 'label' => '', 'kode' => $kode, 'tipe' => $tipe, 'satuan' => $satuan, 'arah' => $arah, 'a5' => $a5, 'a3' => $a3];
}

/** Definisi syarat perlu LAM Teknik: [kunci, label, kode[], tipe, satuan, arah, ambangUnggul5, ambangUnggul3] */
function spme_syaratDef(): array
{
    $skor = fn($k, $l, $kd) => [$k, $l, $kd, 'skor', null, null, 3.5, 3.0];
    return [
        'LAMTEKNIKSTR' => [
            $skor('a', 'Sistem Tata Pamong', ['STR-04a', 'STR-04b']),
            $skor('b', 'Komitmen Pimpinan dan Kemampuan Manajerial', ['STR-05a', 'STR-05b']),
            $skor('c', 'Pemutakhiran Kurikulum', ['STR-12']),
            $skor('d', 'Profil Lulusan dan CPL', ['STR-13a', 'STR-13b']),
            $skor('e', 'Kesesuaian dan Tinjauan CPL', ['STR-14a', 'STR-14b']),
            $skor('f', 'Proses Pembelajaran', ['STR-16a', 'STR-16b']),
            ['g', 'Ketersediaan Mata Kuliah Basic Sciences dan Matematika', ['STR-19'], 'capaian', 'sks', null, 4.0, 4.0],
            ['h', 'Kualifikasi Akademik DTPS', ['STR-27'], 'capaian', '%', null, 15.0, 12.5],
            ['i', 'Jabatan Akademik DTPS', ['STR-28'], 'capaian', '%', null, 40.0, 30.0],
            ['j', 'Kinerja DTPS (Karya Ilmiah)', ['STR-38'], 'capaian', '%', null, 10.0, 7.5],
            ['k', 'Waktu Tunggu Lulusan', ['STR-53'], 'capaian', 'bulan', 'maks', 6.0, 6.0],
            ['l', 'Kesesuaian Bidang Kerja', ['STR-54'], 'capaian', '%', null, 50.0, 50.0],
            $skor('m', 'Keberadaan Unit Penjaminan Mutu dan Komitmen Pimpinan', ['STR-57a']),
            $skor('n', 'Ketersediaan Perangkat SPMI dan Pengakuan Mutu Eksternal', ['STR-57b']),
            $skor('o', 'Indikator Kinerja Tambahan (IKT)', ['STR-58']),
            $skor('p', 'Keterlaksanaan Penjaminan Mutu dan Audit Mutu Internal', ['STR-59']),
            $skor('q', 'Evaluasi Capaian Kinerja', ['STR-60']),
            $skor('r', 'Kepuasan Pemangku Kepentingan', ['STR-61']),
        ],
        'LAMTEKNIKD3' => [
            $skor('c', 'Pemutakhiran Kurikulum', ['D3-12']),
            $skor('d', 'Profil Lulusan dan CPL', ['D3-13a', 'D3-13b']),
            $skor('e', 'Kesesuaian dan Tinjauan CPL', ['D3-14a', 'D3-14b']),
            $skor('f', 'Proses Pembelajaran', ['D3-16a', 'D3-16b']),
            ['i', 'Jabatan Akademik DTPS', ['D3-25'], 'capaian', '%', null, 40.0, 30.0],
            ['k', 'Waktu Tunggu Lulusan', ['D3-45'], 'capaian', 'bulan', 'maks', 3.0, 3.0],
            ['l', 'Kesesuaian Bidang Kerja', ['D3-46'], 'capaian', '%', null, 70.0, 70.0],
            $skor('m', 'Keberadaan Unit Penjaminan Mutu dan Komitmen Pimpinan', ['D3-49a']),
            $skor('n', 'Ketersediaan Perangkat SPMI dan Pengakuan Mutu Eksternal', ['D3-49b']),
            $skor('o', 'Indikator Kinerja Tambahan (IKT)', ['D3-50']),
            $skor('p', 'Keterlaksanaan Penjaminan Mutu dan Audit Mutu Internal', ['D3-51']),
            $skor('q', 'Evaluasi Capaian Kinerja', ['D3-52']),
            $skor('r', 'Kepuasan Pemangku Kepentingan', ['D3-53']),
        ],
    ];
}

function spme_adaBobot(?int $refId): bool
{
    if (!$refId) return false;
    return (int)Db::val('SELECT COUNT(*) FROM ref_items WHERE ref_id=? AND wajib_petakan=1 AND bobot IS NOT NULL', [$refId]) > 0;
}

function spme_skala(?array $a): array
{
    return [
        'min' => $a['skor_min'] ?? 0, 'maks' => $a['skor_maks'] ?? 100, 'step' => $a['skor_step'] ?? null,
    ];
}

function spme_semuaInstrumen(): array
{
    $hasil = [];
    $tetap = function (string $kunci) use (&$hasil) {
        [$label, $ref] = SPME_TETAP[$kunci];
        $a = Db::one('SELECT * FROM refs WHERE code=?', [$ref]);
        $sk = spme_skala($a);
        $hasil[$kunci] = [
            'label' => $label, 'kelompok' => null, 'unitKode' => null,
            'acuanId' => $a['id'] ?? null, 'acuanRefCode' => $ref, 'unitId' => null, 'jenjang' => null,
            'berbobot' => spme_adaBobot($a ? (int)$a['id'] : null),
            'skorMin' => $sk['min'], 'skorMaks' => $sk['maks'], 'skorStep' => $sk['step'],
            'keterangan' => $a['name'] ?? "Acuan \"$ref\" belum tersedia di Acuan Mutu",
        ];
    };
    $tetap('akreditasi_pt');

    foreach (Db::all("SELECT id, code, name, jenjang FROM units WHERE type='prodi' AND is_active=1 ORDER BY name") as $p) {
        $kunci = 'akreditasi_prodi_u' . $p['id'];
        $singk = preg_replace('/^PS-?/i', '', $p['code']) ?: $p['code'];
        if (kosong($p['jenjang'])) {
            $hasil[$kunci] = [
                'label' => "Akreditasi Prodi $singk", 'kelompok' => 'Akreditasi Program Studi', 'unitKode' => $p['code'],
                'acuanId' => null, 'acuanRefCode' => null, 'unitId' => (int)$p['id'], 'jenjang' => null, 'berbobot' => false,
                'skorMin' => 0, 'skorMaks' => 100, 'skorStep' => null,
                'keterangan' => 'Jenjang prodi ini belum diisi — lengkapi di Data Master agar acuan LAM yang sesuai dapat dicocokkan.',
            ];
            continue;
        }
        $a = Db::one('SELECT * FROM refs WHERE jenjang=? AND is_active=1 ORDER BY sort_order LIMIT 1', [$p['jenjang']]);
        $sk = spme_skala($a);
        $hasil[$kunci] = [
            'label' => "Akreditasi Prodi $singk", 'kelompok' => 'Akreditasi Program Studi', 'unitKode' => $p['code'],
            'acuanId' => $a['id'] ?? null, 'acuanRefCode' => $a['code'] ?? null, 'unitId' => (int)$p['id'], 'jenjang' => $p['jenjang'],
            'berbobot' => spme_adaBobot($a ? (int)$a['id'] : null),
            'skorMin' => $sk['min'], 'skorMaks' => $sk['maks'], 'skorStep' => $sk['step'],
            'keterangan' => $a ? $a['name'] : "Acuan LAM untuk jenjang \"{$p['jenjang']}\" belum tersedia — tambahkan di menu Acuan Mutu dan tandai jenjangnya \"{$p['jenjang']}\".",
        ];
    }
    $tetap('iso21001');
    $tetap('zi');
    $tetap('greenmetric');
    return $hasil;
}

function spme_ambil(string $kunci): array
{
    $info = spme_semuaInstrumen()[$kunci] ?? null;
    if (!$info) throw new HttpError("Instrumen SPME \"$kunci\" tidak dikenal", 404);
    return $info;
}

function spme_pohon(array $baris): array
{
    $anak = []; $akar = [];
    foreach ($baris as $b) { if (!empty($b['parent_id'])) $anak[$b['parent_id']][] = $b; else $akar[] = $b; }
    $hasil = [];
    $jalan = function (array $n) use (&$jalan, &$hasil, $anak) {
        $hasil[] = $n;
        foreach ($anak[$n['id']] ?? [] as $a) $jalan($a);
    };
    foreach ($akar as $a) $jalan($a);
    if (count($hasil) < count($baris)) {
        $sudah = array_flip(array_column($hasil, 'id'));
        foreach ($baris as $b) if (!isset($sudah[$b['id']])) $hasil[] = $b;
    }
    return $hasil;
}

function spme_nilaiOtomatis(int $refItemId, int $periodId, ?int $unitId): ?array
{
    $p = [$periodId, $refItemId, $refItemId];
    $f = '';
    if ($unitId) { $f = ' AND au.unit_id = ?'; $p[] = $unitId; }
    return Db::one('SELECT ROUND(AVG(a.skor), 2) AS skor, COUNT(*) AS jumlah
                      FROM standard_indicators si JOIN audit_assessments a ON a.indicator_id = si.id AND a.skor IS NOT NULL
                      JOIN audits au ON au.id = a.audit_id AND au.period_id = ?
                     WHERE (si.source_ref_item_id = ? OR EXISTS (SELECT 1 FROM ref_map m WHERE m.ref_item_id = ? AND m.indicator_id = si.id))' . $f, $p);
}

function spme_capaianOtomatis(int $refItemId, int $periodId, ?int $unitId): ?array
{
    $p = [$periodId, $refItemId, $refItemId];
    $f = '';
    if ($unitId) { $f = ' AND au.unit_id = ?'; $p[] = $unitId; }
    return Db::one('SELECT ROUND(AVG(a.capaian_persen), 2) AS capaian, COUNT(*) AS jumlah
                      FROM standard_indicators si JOIN audit_assessments a ON a.indicator_id = si.id AND a.capaian_persen IS NOT NULL
                      JOIN audits au ON au.id = a.audit_id AND au.period_id = ?
                     WHERE (si.source_ref_item_id = ? OR EXISTS (SELECT 1 FROM ref_map m WHERE m.ref_item_id = ? AND m.indicator_id = si.id))' . $f, $p);
}

/** Kelompok kategori untuk grafik radar (baris harus sudah pre-order). */
function spme_kelompokRadar(array $baris, float $skorMin, float $skorMaks, bool $skorTertimbang = false, bool $keluaranPersen = false): array
{
    $biner = $skorMaks <= 1;
    $jml = [];
    foreach ($baris as $b) {
        if ((int)$b['wajib_petakan'] === 1) continue;
        $jml[$b['level']] = ($jml[$b['level']] ?? 0) + 1;
    }
    ksort($jml);
    $levels = array_keys($jml);
    if (!$levels) return [];
    $level = $levels[0];
    foreach ($levels as $lv) if ($jml[$lv] >= 4) { $level = $lv; break; }

    $byId = [];
    foreach ($baris as $b) $byId[$b['id']] = $b;
    $leluhur = function (array $b) use ($byId, $level) {
        $cur = $b;
        while ($cur && $cur['level'] > $level) $cur = $byId[$cur['parent_id']] ?? null;
        return $cur;
    };

    $kel = [];
    foreach ($baris as $b) {
        if ((int)$b['wajib_petakan'] !== 1) continue;
        $l = $leluhur($b);
        if (!$l) continue;
        $id = $l['id'];
        if (!isset($kel[$id])) $kel[$id] = ['kode' => $l['code'], 'label' => $l['title'], 'total' => 0, 'dinilai' => 0, 'sj' => 0.0, 'sn' => 0, 'bj' => 0.0, 'stj' => 0.0, 'butir' => []];
        $g = &$kel[$id];
        $g['total']++;
        $ada = $b['skor_efektif'] !== null;
        if ($ada) { $g['dinilai']++; $g['sj'] += $b['skor_efektif']; $g['sn']++; }
        if ($b['bobot'] !== null) {
            $g['bj'] += $b['bobot'];
            if ($ada) $g['stj'] += $b['bobot'] * $b['skor_efektif'];
        }
        $g['butir'][] = ['kode' => $b['code'], 'label' => $b['title'], 'bobot' => $b['bobot'], 'skor' => $b['skor_efektif'], 'dinilai' => $ada, 'nilai' => $b['skor_efektif']];
        unset($g);
    }

    $out = [];
    foreach ($kel as $g) {
        $rata = $g['sn'] ? jr(($g['sj'] / $g['sn']) * 100) / 100 : null;
        $persenTerpenuhi = $g['total'] > 0 ? jr(($g['sj'] / ($skorMaks * $g['total'])) * 1000) / 10 : null;
        if ($biner) $nilai = $persenTerpenuhi;
        elseif ($skorTertimbang) {
            $bobotBulat = jr($g['bj'] * 100) / 100;
            $stBulat = jr($g['stj'] * 100) / 100;
            $maxKat = jr($bobotBulat * $skorMaks * 100) / 100;
            $pk = $maxKat > 0 ? jr(($stBulat / $maxKat) * 1000) / 10 : null;
            $nilai = $pk === null ? null : ($keluaranPersen ? $pk : jr(($skorMin + ($pk / 100) * ($skorMaks - $skorMin)) * 100) / 100);
        } else $nilai = $rata;
        $out[] = ['kode' => $g['kode'], 'label' => $g['label'], 'total_butir' => $g['total'], 'butir_dinilai' => $g['dinilai'], 'rata_skor' => $rata, 'nilai' => $nilai, 'butir' => $g['butir']];
    }
    return $out;
}

function spme_rekapZI(array $baris): array
{
    $byId = []; $byCode = [];
    foreach ($baris as $b) { $byId[$b['id']] = $b; $byCode[$b['code']] = $b; }

    $daunDi = function ($nodeId) use ($baris, $byId) {
        $out = [];
        foreach ($baris as $b) {
            if ((int)$b['wajib_petakan'] !== 1) continue;
            $cur = $b;
            while ($cur && $cur['id'] != $nodeId) $cur = $byId[$cur['parent_id']] ?? null;
            if ($cur) $out[] = $b;
        }
        return $out;
    };
    $rekapCabang = function ($nodeId) use ($daunDi) {
        $daun = $daunDi($nodeId);
        $bobot = 0.0; $nilai = 0.0; $dinilai = 0; $butir = [];
        foreach ($daun as $b) {
            $bb = $b['bobot'] ?? 0;
            $bobot += $bb;
            $ada = $b['skor_efektif'] !== null;
            if ($ada) { $dinilai++; $nilai += $bb * $b['skor_efektif']; }
            $butir[] = ['kode' => $b['code'], 'label' => $b['title'], 'bobot' => $b['bobot'], 'skor' => $b['skor_efektif'], 'dinilai' => $ada,
                        'nilai' => $ada ? jr($bb * $b['skor_efektif'] * 100) / 100 : null];
        }
        return ['bobot' => jr($bobot * 100) / 100, 'nilai' => jr($nilai * 100) / 100, 'total' => count($daun), 'dinilai' => $dinilai, 'butir' => $butir];
    };

    $a1 = $byCode['ZI-A.1'] ?? null;
    $a2 = $byCode['ZI-A.2'] ?? null;
    $anakA1 = $a1 ? array_values(array_filter($baris, fn($b) => $b['parent_id'] == $a1['id'])) : [];
    $anakA2 = $a2 ? array_values(array_filter($baris, fn($b) => $b['parent_id'] == $a2['id'])) : [];

    $aspek = [];
    foreach ($anakA1 as $as1) {
        $as2 = null;
        foreach ($anakA2 as $x) if (strtoupper(trim($x['title'])) === strtoupper(trim($as1['title']))) { $as2 = $x; break; }
        $rp = $rekapCabang($as1['id']);
        $rr = $as2 ? $rekapCabang($as2['id']) : ['bobot' => 0, 'nilai' => 0, 'total' => 0, 'dinilai' => 0, 'butir' => []];
        $bobot = jr(($rp['bobot'] + $rr['bobot']) * 100) / 100;
        $nilai = jr(($rp['nilai'] + $rr['nilai']) * 100) / 100;
        $aspek[] = [
            'kode_pemenuhan' => $as1['code'], 'kode_reform' => $as2['code'] ?? null, 'label' => $as1['title'], 'bobot' => $bobot,
            'pemenuhan' => $rp['nilai'], 'pemenuhan_maks' => $rp['bobot'], 'reform' => $rr['nilai'], 'reform_maks' => $rr['bobot'],
            'nilai' => $nilai, 'persen' => $bobot > 0 ? r1p($nilai / $bobot) : null,
            'lengkap' => $rp['dinilai'] === $rp['total'] && $rr['dinilai'] === $rr['total'] && $rp['total'] + $rr['total'] > 0,
            'total_butir' => $rp['total'] + $rr['total'], 'butir_dinilai' => $rp['dinilai'] + $rr['dinilai'],
            'butir' => array_merge(array_map(fn($x) => $x + ['cabang' => 'Pemenuhan'], $rp['butir']), array_map(fn($x) => $x + ['cabang' => 'Reform'], $rr['butir'])),
        ];
    }
    $bobotP = jr(array_sum(array_column($aspek, 'bobot')) * 100) / 100;
    $nilaiP = jr(array_sum(array_column($aspek, 'nilai')) * 100) / 100;
    $lengkapP = true;
    foreach ($aspek as $x) if (!$x['lengkap']) $lengkapP = false;
    $pengungkit = ['bobot' => $bobotP, 'nilai' => $nilaiP, 'persen' => $bobotP > 0 ? r1p($nilaiP / $bobotP) : null, 'lengkap' => $lengkapP, 'aspek' => $aspek];

    $area = [];
    foreach ([['ZI-B.1', 'I', 'BIROKRASI YANG BERSIH DAN AKUNTABEL'], ['ZI-B.2', 'II', 'PELAYANAN PUBLIK YANG PRIMA']] as [$kode, $romawi, $labelResmi]) {
        $node = $byCode[$kode] ?? null;
        if (!$node) continue;
        $anak = array_values(array_filter($baris, fn($b) => $b['parent_id'] == $node['id'] && (int)$b['wajib_petakan'] === 1));
        $bobot = 0.0; $nilai = 0.0; $dinilai = 0; $item = [];
        foreach ($anak as $b) {
            $bb = $b['bobot'] ?? 0;
            $bobot += $bb;
            $ada = $b['skor_efektif'] !== null;
            if ($ada) { $dinilai++; $nilai += $bb * $b['skor_efektif']; }
            $item[] = ['kode' => $b['code'], 'label' => $b['title'], 'bobot' => $b['bobot'], 'skor' => $b['skor_efektif'], 'dinilai' => $ada,
                       'nilai' => $ada ? jr($bb * $b['skor_efektif'] * 100) / 100 : null,
                       'persen' => $b['bobot'] ? ($ada ? r1p(($bb * $b['skor_efektif']) / $bb) : null) : null];
        }
        $bobot = jr($bobot * 100) / 100;
        $nilai = jr($nilai * 100) / 100;
        $area[] = ['kode' => $node['code'], 'romawi' => $romawi, 'label' => $node['title'] ?: $labelResmi, 'bobot' => $bobot, 'nilai' => $nilai,
                   'persen' => $bobot > 0 ? r1p($nilai / $bobot) : null, 'lengkap' => $dinilai === count($anak) && count($anak) > 0,
                   'total_butir' => count($anak), 'butir_dinilai' => $dinilai, 'item' => $item];
    }
    $bobotH = jr(array_sum(array_column($area, 'bobot')) * 100) / 100;
    $nilaiH = jr(array_sum(array_column($area, 'nilai')) * 100) / 100;
    $lengkapH = true;
    foreach ($area as $x) if (!$x['lengkap']) $lengkapH = false;
    $hasil = ['bobot' => $bobotH, 'nilai' => $nilaiH, 'persen' => $bobotH > 0 ? r1p($nilaiH / $bobotH) : null, 'lengkap' => $lengkapH, 'area' => $area];

    $bobotT = jr(($pengungkit['bobot'] + $hasil['bobot']) * 100) / 100;
    $nilaiT = jr(($pengungkit['nilai'] + $hasil['nilai']) * 100) / 100;
    $persenT = $bobotT > 0 ? r1p($nilaiT / $bobotT) : null;
    $status = $persenT === null ? null : ($persenT >= 85 ? 'Memenuhi WBBM' : ($persenT >= 75 ? 'Memenuhi WBK' : 'Tidak Memenuhi'));
    $total = ['bobot' => $bobotT, 'nilai' => $nilaiT, 'persen' => $persenT, 'lengkap' => $pengungkit['lengkap'] && $hasil['lengkap'], 'status' => $status];
    return ['pengungkit' => $pengungkit, 'hasil' => $hasil, 'total' => $total];
}

function spme_radarZI(array $rekap): array
{
    $out = [];
    foreach ($rekap['pengungkit']['aspek'] as $a)
        $out[] = ['kode' => $a['kode_reform'] ? $a['kode_pemenuhan'] . ' / ' . $a['kode_reform'] : $a['kode_pemenuhan'], 'label' => $a['label'],
                  'total_butir' => $a['total_butir'], 'butir_dinilai' => $a['butir_dinilai'], 'nilai' => $a['persen'], 'butir' => $a['butir']];
    foreach ($rekap['hasil']['area'] as $ar)
        $out[] = ['kode' => $ar['kode'], 'label' => $ar['label'], 'total_butir' => $ar['total_butir'], 'butir_dinilai' => $ar['butir_dinilai'], 'nilai' => $ar['persen'],
                  'butir' => array_map(fn($it) => ['kode' => $it['kode'], 'label' => $it['label'], 'bobot' => $it['bobot'], 'skor' => $it['skor'], 'dinilai' => $it['dinilai'], 'nilai' => $it['skor']], $ar['item'])];
    return $out;
}

function spme_statusLAM(?float $na, bool $syarat, string $tier): array
{
    if ($na === null) return ['status' => null, 'durasi' => null];
    if ($na < 200 || !$syarat) return ['status' => 'Tidak Terakreditasi', 'durasi' => null];
    if ($na >= 361) {
        if ($tier === 'lima') return ['status' => 'Terakreditasi Unggul', 'durasi' => '5 Tahun'];
        if ($tier === 'tiga') return ['status' => 'Terakreditasi Unggul', 'durasi' => '3 Tahun'];
        return ['status' => 'Terakreditasi', 'durasi' => '5 Tahun'];
    }
    if ($na >= 331) {
        if ($tier === 'lima' || $tier === 'tiga') return ['status' => 'Terakreditasi Unggul', 'durasi' => '3 Tahun'];
        return ['status' => 'Terakreditasi', 'durasi' => '5 Tahun'];
    }
    return ['status' => 'Terakreditasi', 'durasi' => '5 Tahun'];
}

function spme_hitungSyarat(string $acuanCode, array $baris): ?array
{
    $def = spme_syaratDef()[$acuanCode] ?? null;
    if (!$def) return null;
    $byCode = [];
    foreach ($baris as $b) $byCode[$b['code']] = $b;

    $item = [];
    foreach ($def as [$kunci, $label, $kode, $tipe, $satuan, $arah, $a5, $a3]) {
        $nilai = null; $lengkap = true;
        if ($tipe === 'skor') {
            $tiap = array_map(fn($k) => $byCode[$k]['skor_efektif'] ?? null, $kode);
            if (in_array(null, $tiap, true)) $lengkap = false;
            else $nilai = jr((array_sum($tiap) / count($tiap)) * 100) / 100;
        } else {
            $nc = $byCode[$kode[0]]['capaian_efektif'] ?? null;
            if ($nc === null) $lengkap = false; else $nilai = $nc;
        }
        $cocok = fn($ambang) => $nilai === null ? null : ($arah === 'maks' ? $nilai <= $ambang : $nilai >= $ambang);
        $item[] = [
            'kunci' => $kunci, 'label' => $label, 'kode' => $kode, 'tipe' => $tipe, 'satuan' => $satuan ?? 'skor 1-4', 'arah' => $arah ?? 'min',
            'nilai' => $nilai, 'lengkap' => $lengkap, 'ambang_dasar' => $tipe === 'skor' ? SPME_AMBANG_DASAR : null,
            'ambang_unggul_5' => $a5, 'ambang_unggul_3' => $a3,
            'memenuhi_dasar' => $tipe === 'skor' ? $cocok(SPME_AMBANG_DASAR) : true,
            'memenuhi_unggul_5' => $cocok($a5), 'memenuhi_unggul_3' => $cocok($a3),
        ];
    }
    $itemSkor = array_values(array_filter($item, fn($i) => $i['tipe'] === 'skor'));
    $semua = fn(array $arr, callable $f) => count(array_filter($arr, $f)) === count($arr);
    $lengkapDasar = $semua($itemSkor, fn($i) => $i['lengkap']);
    $syarat = $lengkapDasar && $semua($itemSkor, fn($i) => $i['memenuhi_dasar'] === true);
    $lengkapUnggul = $semua($item, fn($i) => $i['lengkap']);
    $tier = $lengkapUnggul && $semua($item, fn($i) => $i['memenuhi_unggul_5'] === true) ? 'lima'
        : ($lengkapUnggul && $semua($item, fn($i) => $i['memenuhi_unggul_3'] === true) ? 'tiga' : 'tidak');
    return ['item' => $item, 'lengkap_dasar' => $lengkapDasar, 'lengkap_unggul' => $lengkapUnggul, 'syarat_terakreditasi' => $syarat, 'unggul_tier' => $tier];
}

function spme_ringkasan(string $kunci, array $info, int $periodId): array
{
    if (empty($info['acuanId'])) return array_merge(['kunci' => $kunci], $info, ['tersedia' => false]);
    $daun = Db::all('SELECT id, bobot FROM ref_items WHERE ref_id=? AND wajib_petakan=1', [$info['acuanId']]);
    $bobotMaks = 0.0; $kontribusi = 0.0; $dinilai = 0; $jumlahSkor = 0.0;
    $ov = [];
    foreach (Db::all('SELECT ref_item_id, skor FROM spme_scores WHERE period_id=? AND instrumen=? AND skor IS NOT NULL', [$periodId, $kunci]) as $r) $ov[$r['ref_item_id']] = $r['skor'];
    foreach ($daun as $b) {
        $override = $ov[$b['id']] ?? null;
        $otomatis = $override === null ? spme_nilaiOtomatis((int)$b['id'], $periodId, $info['unitId']) : null;
        $efektif = $override ?? ($otomatis['skor'] ?? null);
        if ($efektif !== null) {
            $dinilai++; $jumlahSkor += $efektif;
            if ($b['bobot'] !== null) { $bobotMaks += $b['bobot']; $kontribusi += $b['bobot'] * $efektif; }
        } elseif ($b['bobot'] !== null) $bobotMaks += $b['bobot'];
    }
    return array_merge(['kunci' => $kunci], $info, [
        'tersedia' => true, 'total_butir' => count($daun), 'butir_dinilai' => $dinilai,
        'rata_capaian' => $daun ? r1p($jumlahSkor / (count($daun) * $info['skorMaks'])) : null,
        'bobot_maksimal' => $info['berbobot'] ? jr($bobotMaks * 100) / 100 : null,
        'skor_tertimbang' => $info['berbobot'] ? jr($kontribusi * 100) / 100 : null,
        'persen_tertimbang' => $info['berbobot'] && $bobotMaks > 0 ? r1p($kontribusi / ($bobotMaks * $info['skorMaks'])) : null,
    ]);
}

function rute_spme(Router $r): void
{
    $L = [[Auth::class, 'wajibLogin']];
    $SPM = [[Auth::class, 'wajibLogin'], Auth::peran('spm')];
    $B = '/spme';

    $r->get($B, function (Request $q) {
        $pid = (int)$q->query('period_id', 0);
        if (!$pid) throw new HttpError('period_id wajib diisi', 400);
        $out = [];
        foreach (spme_semuaInstrumen() as $kunci => $info) $out[] = spme_ringkasan($kunci, $info, $pid);
        return $out;
    }, $L);

    $r->get("$B/:kunci", function (Request $q) {
        $kunci = $q->params['kunci'];
        $info = spme_ambil($kunci);
        $pid = (int)$q->query('period_id', 0);
        if (!$pid) throw new HttpError('period_id wajib diisi', 400);
        if (empty($info['acuanId'])) throw new HttpError($info['keterangan'] ?: 'Acuan untuk instrumen ini belum tersedia', 404);

        $acuan = Db::one('SELECT * FROM refs WHERE id=?', [$info['acuanId']]);
        $unit = $info['unitId'] ? Db::one('SELECT id, name, code FROM units WHERE id=?', [$info['unitId']]) : null;
        $baris = Db::all('SELECT * FROM ref_items WHERE ref_id=? ORDER BY level, sort_order, id', [$acuan['id']]);

        $ov = [];
        foreach (Db::all('SELECT ref_item_id, skor, capaian, bukti_url, catatan, updated_at, u.name AS updated_by_name FROM spme_scores s LEFT JOIN users u ON u.id = s.updated_by WHERE s.period_id=? AND s.instrumen=?', [$pid, $kunci]) as $o)
            $ov[$o['ref_item_id']] = $o;

        $prodi = str_starts_with($kunci, 'akreditasi_prodi_');
        $bobotMaks = 0.0; $kontribusi = 0.0; $dinilai = 0; $jumlahSkor = 0.0; $totalDaun = 0;
        $butir = [];
        foreach ($baris as $b) {
            if ((int)$b['wajib_petakan'] !== 1) { $butir[] = $b; continue; }
            $totalDaun++;
            $o = $ov[$b['id']] ?? null;
            $otomatis = spme_nilaiOtomatis((int)$b['id'], $pid, $unit['id'] ?? null);
            $manual = $o['skor'] ?? null;
            $efektif = $manual ?? ($otomatis['skor'] ?? null);
            if ($efektif !== null) {
                $dinilai++; $jumlahSkor += $efektif;
                if ($b['bobot'] !== null) { $bobotMaks += $b['bobot']; $kontribusi += $b['bobot'] * $efektif; }
            } elseif ($b['bobot'] !== null) $bobotMaks += $b['bobot'];

            $cOto = null; $cMan = null; $cEf = null;
            if ($prodi) {
                $cOto = spme_capaianOtomatis((int)$b['id'], $pid, $unit['id'] ?? null)['capaian'] ?? null;
                $cMan = $o['capaian'] ?? null;
                $cEf = $cMan ?? $cOto;
            }
            $butir[] = array_merge($b, [
                'skor_otomatis' => $otomatis['skor'] ?? null, 'jumlah_penilaian' => $otomatis['jumlah'] ?? 0, 'skor_manual' => $manual, 'skor_efektif' => $efektif,
                'capaian_otomatis' => $cOto, 'capaian_manual' => $cMan, 'capaian_efektif' => $cEf,
                'bukti_url_manual' => $o['bukti_url'] ?? null, 'catatan_manual' => $o['catatan'] ?? null,
                'diedit_oleh' => $o['updated_by_name'] ?? null, 'diedit_pada' => $o['updated_at'] ?? null,
            ]);
        }
        $terurut = spme_pohon($butir);

        $zi = $acuan['code'] === 'ZI';
        $gm = $acuan['code'] === 'GREENMETRIC';
        $rekapZi = $zi ? spme_rekapZI($terurut) : null;
        $kelRadar = $zi ? spme_radarZI($rekapZi) : spme_kelompokRadar($terurut, (float)$info['skorMin'], (float)$info['skorMaks'], $prodi || $gm, $gm);
        $radarTipe = ($zi || $info['skorMaks'] <= 1 || $gm) ? 'persen' : 'skor';
        $domainKat = $radarTipe === 'persen' ? [0, 100] : [$info['skorMin'], $info['skorMaks']];

        $hasilCapaian = null;
        if (!$info['berbobot']) {
            $semua100 = true;
            foreach ($kelRadar as $k) if ($k['nilai'] !== 100.0 && $k['nilai'] !== 100) $semua100 = false;
            $kode = $acuan['code'];
            $hasilCapaian = [
                'terpenuhi' => $totalDaun > 0 && $semua100,
                'label_terpenuhi' => $kode === 'BANPT' ? 'Terakreditasi' : ($kode === 'ISO21001' ? 'Tersertifikasi' : 'Terpenuhi Seluruhnya'),
                'label_belum' => $kode === 'BANPT' ? 'Belum Terakreditasi' : ($kode === 'ISO21001' ? 'Belum Tersertifikasi' : 'Belum Terpenuhi Seluruhnya'),
            ];
        }

        $hasilAkr = null;
        if ($prodi && $info['berbobot']) {
            $na = jr($kontribusi * 100) / 100;
            $syarat = spme_hitungSyarat($acuan['code'], $terurut);
            $dihitung = ($syarat && $syarat['lengkap_dasar']) ? spme_statusLAM($na, $syarat['syarat_terakreditasi'], $syarat['unggul_tier']) : ['status' => null, 'durasi' => null];
            $hasilAkr = ['na' => $na, 'na_maksimal' => jr($bobotMaks * $info['skorMaks'] * 100) / 100, 'status' => $dihitung['status'], 'durasi' => $dihitung['durasi'], 'syarat_perlu' => $syarat];
        }

        return [
            'instrumen' => array_merge(['kunci' => $kunci], $info), 'acuan' => $acuan, 'unit' => $unit, 'butir' => $terurut, 'kelompok' => $kelRadar,
            'rekap_zi' => $rekapZi, 'hasil_capaian' => $hasilCapaian, 'hasil_akreditasi' => $hasilAkr,
            'radar' => ['tipe' => $radarTipe, 'domain_kategori' => $domainKat, 'domain_butir' => [$info['skorMin'], $info['skorMaks']]],
            'ringkasan' => [
                'total_butir' => $totalDaun, 'butir_dinilai' => $dinilai,
                'rata_capaian' => $totalDaun ? r1p($jumlahSkor / ($totalDaun * $info['skorMaks'])) : null,
                'bobot_maksimal' => $info['berbobot'] ? jr($bobotMaks * 100) / 100 : null,
                'skor_tertimbang' => $info['berbobot'] ? jr($kontribusi * 100) / 100 : null,
                'persen_tertimbang' => $info['berbobot'] && $bobotMaks > 0 ? r1p($kontribusi / ($bobotMaks * $info['skorMaks'])) : null,
            ],
        ];
    }, $L);

    $r->get("$B/:kunci/butir/:refItemId/detail", function (Request $q) {
        $info = spme_ambil($q->params['kunci']);
        $pid = (int)$q->query('period_id', 0);
        $rid = (int)$q->params['refItemId'];
        if (!$pid) throw new HttpError('period_id wajib diisi', 400);
        $p = [$pid, $pid, $pid, $rid, $rid];
        $f = '';
        if ($info['unitId']) { $f = ' AND un.id = ?'; $p[] = $info['unitId']; }
        return Db::all('SELECT si.id AS indicator_id, si.code, si.indicator, si.standard_id, s.title AS standard_title,
                               un.id AS unit_id, un.name AS unit_name, ia.peran,
                               im.realisasi, im.capaian_nilai, im.bukti_uraian, im.bukti_url, im.status AS status_isian, im.submitted_at,
                               aa.skor AS skor_auditor, aa.capaian_persen AS capaian_auditor, aa.verdict
                          FROM standard_indicators si
                          JOIN standards s ON s.id = si.standard_id AND s.period_id = ?
                          JOIN indicator_assignments ia ON ia.indicator_id = si.id
                          JOIN units un ON un.id = ia.unit_id
                          LEFT JOIN implementations im ON im.indicator_id = si.id AND im.unit_id = un.id AND im.period_id = ?
                          LEFT JOIN audits au ON au.unit_id = un.id AND au.period_id = ?
                          LEFT JOIN audit_assessments aa ON aa.audit_id = au.id AND aa.indicator_id = si.id
                         WHERE (si.source_ref_item_id = ? OR EXISTS (SELECT 1 FROM ref_map m WHERE m.ref_item_id = ? AND m.indicator_id = si.id))' . $f . '
                         ORDER BY s.title, si.code, ia.peran DESC, un.name', $p);
    }, $L);

    $r->put("$B/:kunci/butir/:refItemId", function (Request $q) {
        $kunci = $q->params['kunci'];
        $info = spme_ambil($kunci);
        $pid = $q->in('period_id'); $skor = $q->in('skor'); $capaian = $q->in('capaian');
        if (kosong($pid)) throw new HttpError('period_id wajib diisi', 400);
        if ($skor === null || $skor === '') throw new HttpError('Skor wajib diisi', 400);
        if (!is_numeric($skor) || (float)$skor < $info['skorMin'] || (float)$skor > $info['skorMaks'])
            throw new HttpError("Skor harus di antara {$info['skorMin']} dan {$info['skorMaks']}", 400);
        $nilai = (float)$skor;
        if (!empty($info['skorStep'])) {
            $langkah = ($nilai - $info['skorMin']) / $info['skorStep'];
            if (abs(jr($langkah) - $langkah) > 1e-6) throw new HttpError("Skor harus kelipatan {$info['skorStep']} dari {$info['skorMin']}", 400);
        }
        $nc = null;
        if ($capaian !== null && $capaian !== '') {
            if (!is_numeric($capaian)) throw new HttpError('Capaian target harus berupa angka', 400);
            $nc = (float)$capaian;
        }
        $rid = (int)$q->params['refItemId'];
        if (!Db::one('SELECT id FROM ref_items WHERE id=?', [$rid])) throw new HttpError('Butir acuan tidak ditemukan', 404);
        Db::run('INSERT INTO spme_scores (period_id, instrumen, ref_item_id, skor, capaian, bukti_url, catatan, updated_by) VALUES (?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE skor=VALUES(skor), capaian=VALUES(capaian), bukti_url=VALUES(bukti_url), catatan=VALUES(catatan), updated_by=VALUES(updated_by), updated_at=NOW()',
            [(int)$pid, $kunci, $rid, $nilai, $nc, teksAtauNull($q->in('bukti_url')), $q->in('catatan'), $q->user['id']]);
        Db::log((int)$q->user['id'], 'koreksi_skor_spme', 'ref_items', $rid, "{$info['label']}: skor $nilai");
        return ['ok' => true];
    }, $SPM);

    $r->delete("$B/:kunci/butir/:refItemId", function (Request $q) {
        $pid = (int)$q->query('period_id', 0);
        if (!$pid) throw new HttpError('period_id wajib diisi', 400);
        Db::run('DELETE FROM spme_scores WHERE period_id=? AND instrumen=? AND ref_item_id=?', [$pid, $q->params['kunci'], (int)$q->params['refItemId']]);
        return ['ok' => true];
    }, $SPM);
}
