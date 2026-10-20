<?php
declare(strict_types=1);

/* MODUL ACUAN MUTU (padanan server/src/routes/acuan.js) */

function acu_pastelkan(?string $hex, float $putih = 0.82): string
{
    $h = ltrim((string)($hex ?: '64748b'), '#');
    $r = hexdec(substr($h, 0, 2)) ?: 100;
    $g = hexdec(substr($h, 2, 2)) ?: 116;
    $b = hexdec(substr($h, 4, 2)) ?: 139;
    $c = fn($x) => str_pad(dechex((int)round($x + (255 - $x) * $putih)), 2, '0', STR_PAD_LEFT);
    return $c($r) . $c($g) . $c($b);
}

/** Sinkronkan indikator standar yang mengadopsi butir acuan ini setelah butir diedit. */
function acu_sinkron(int $refItemId): void
{
    $src = Db::one('SELECT title, uraian, target, target_skor, satuan, strategy FROM ref_items WHERE id=?', [$refItemId]);
    if (!$src) return;
    $ind = trim((string)$src['uraian']) !== '' ? trim((string)$src['uraian']) : $src['title'];
    Db::run('UPDATE standard_indicators SET indicator=?, target=?, target_skor=?, satuan=?, strategy=? WHERE source_ref_item_id=?',
        [$ind, $src['target'], $src['target_skor'], $src['satuan'], $src['strategy'], $refItemId]);
}

/** Urutan pohon pre-order dari baris datar. */
function acu_urutkanPohon(array $baris): array
{
    $anak = []; $akar = [];
    foreach ($baris as $b) {
        if (!empty($b['parent_id'])) $anak[$b['parent_id']][] = $b; else $akar[] = $b;
    }
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

function acu_angka(array $b, string $k, mixed $lama): mixed
{
    if (!array_key_exists($k, $b)) return $lama;
    $v = $b[$k];
    return ($v === '' || $v === null) ? null : (float)$v;
}

function rute_acuan(Router $r): void
{
    $L   = [[Auth::class, 'wajibLogin']];
    $spm = [[Auth::class, 'wajibLogin'], Auth::peran('spm')];
    $B   = '/acuan';

    $r->get($B, fn() => Db::all(
        'SELECT r.*, (SELECT COUNT(*) FROM ref_items i WHERE i.ref_id = r.id) AS jumlah_butir,
                (SELECT COUNT(*) FROM ref_items i WHERE i.ref_id = r.id AND i.wajib_petakan = 1) AS butir_wajib,
                (SELECT COUNT(*) FROM ref_items i WHERE i.ref_id = r.id AND i.wajib_petakan = 1
                    AND (EXISTS (SELECT 1 FROM ref_map m WHERE m.ref_item_id = i.id)
                      OR EXISTS (SELECT 1 FROM standard_indicators si WHERE si.source_ref_item_id = i.id))) AS butir_terpetakan
           FROM refs r ORDER BY r.sort_order, r.id'), $L);

    $r->post($B, function (Request $q) {
        if (kosong($q->in('code')) || kosong($q->in('name'))) throw new HttpError('Kode dan nama acuan wajib diisi', 400);
        $urut = (int)Db::val('SELECT COUNT(*) FROM refs');
        $n = fn($k) => ($q->in($k) === '' || $q->in($k) === null) ? null : (float)$q->in($k);
        try {
            $id = Db::insert('INSERT INTO refs (code, name, version, publisher, effective_date, warna, keterangan, jenjang, skor_min, skor_maks, skor_step, sort_order)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [$q->in('code'), $q->in('name'), $q->in('version'), $q->in('publisher'), $q->in('effective_date'), $q->in('warna'), $q->in('keterangan'),
                 $q->in('jenjang') ?: null, $n('skor_min'), $n('skor_maks'), $n('skor_step'), $urut]);
        } catch (PDOException $e) {
            throw new HttpError('Kode acuan sudah digunakan', 400);
        }
        Db::log((int)$q->user['id'], 'tambah_acuan', 'refs', $id, (string)$q->in('code'));
        return new Respons(['id' => $id], 201);
    }, $spm);

    $r->get("$B/export", function () {
        $list = Db::all('SELECT * FROM refs ORDER BY sort_order, id');
        $ringkas = [
            'nama' => 'Daftar Acuan',
            'kolom' => array_map(fn($w) => ['lebar' => $w], [16, 42, 14, 20, 14, 16, 10, 10, 10, 12]),
            'beku' => ['kolom' => 1, 'baris' => 1],
            'baris' => array_merge([
                ['gaya' => Xlsx::HEADER, 'tinggi' => 28, 'nilai' => ['Kode', 'Nama Acuan', 'Versi', 'Penerbit', 'Tanggal Berlaku', 'Jenjang', 'Skor Min', 'Skor Maks', 'Skor Step', 'Jumlah Butir']],
            ], array_map(fn($a) => [
                'gaya' => Xlsx::data(acu_pastelkan($a['warna'])),
                'nilai' => [$a['code'], $a['name'], $a['version'], $a['publisher'], $a['effective_date'], $a['jenjang'], $a['skor_min'], $a['skor_maks'], $a['skor_step'],
                            (int)Db::val('SELECT COUNT(*) FROM ref_items WHERE ref_id=?', [$a['id']])],
            ], $list)),
        ];
        $header = ['kode', 'judul', 'uraian', 'kode_induk', 'tipe', 'bobot', 'payung', 'target', 'target_skor', 'satuan', 'strategy'];
        $lebar = array_map(fn($w) => ['lebar' => $w], [12, 34, 42, 12, 12, 8, 8, 34, 16, 10, 42]);
        $sheets = [$ringkas];
        foreach ($list as $a) {
            $butir = acu_urutkanPohon(Db::all('SELECT * FROM ref_items WHERE ref_id=? ORDER BY level, sort_order, id', [$a['id']]));
            $idKode = [];
            foreach ($butir as $b) $idKode[$b['id']] = $b['code'];
            $gayaH = ['font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => 'FFFFFF'], 'fill' => ltrim((string)($a['warna'] ?: '1D4ED8'), '#'),
                      'align' => ['h' => 'left', 'v' => 'center', 'wrap' => true, 'indent' => 1], 'border' => true];
            $gd = Xlsx::data(acu_pastelkan($a['warna']));
            $sheets[] = [
                'nama' => $a['code'], 'kolom' => $lebar, 'beku' => ['kolom' => 2, 'baris' => 1],
                'baris' => array_merge([['gaya' => $gayaH, 'tinggi' => 28, 'nilai' => $header]], array_map(fn($b) => [
                    'gaya' => $gd,
                    'nilai' => [$b['code'], $b['title'], $b['uraian'], $b['parent_id'] ? ($idKode[$b['parent_id']] ?? null) : null, $b['tipe'], $b['bobot'],
                                ((int)$b['wajib_petakan'] === 0) ? 'Ya' : null, $b['target'], $b['target_skor'], $b['satuan'], $b['strategy']],
                ], $butir)),
            ];
        }
        Xlsx::kirim(Xlsx::buat($sheets), 'Matriks_Acuan_Mutu.xlsx');
    }, $L);

    $r->get("$B/kandidat-indikator", function (Request $q) {
        $where = ["i.target IS NOT NULL AND i.target <> ''", 'r.is_active = 1'];
        $p = [];
        if (!kosong($q->query('ref_id'))) { $where[] = 'i.ref_id = ?'; $p[] = $q->query('ref_id'); }
        if (!kosong($q->query('cari'))) {
            $where[] = '(i.code LIKE ? OR i.title LIKE ? OR i.uraian LIKE ?)';
            $like = '%' . $q->query('cari') . '%';
            array_push($p, $like, $like, $like);
        }
        return Db::all('SELECT i.id, i.code, i.title, i.uraian, i.target, i.target_skor, i.satuan, i.strategy,
                               r.id AS ref_id, r.code AS acuan_kode, r.name AS acuan_nama, r.warna,
                               (SELECT COUNT(*) FROM standard_indicators si WHERE si.source_ref_item_id = i.id) AS jumlah_diadopsi
                          FROM ref_items i JOIN refs r ON r.id = i.ref_id WHERE ' . implode(' AND ', $where) . ' ORDER BY r.sort_order, i.code LIMIT 200', $p);
    }, $L);

    $r->get("$B/laporan/cakupan", function (Request $q) {
        $pid = (int)$q->query('period_id', 0);
        if (!$pid) throw new HttpError('period_id wajib diisi', 400);
        $tercakup = '(EXISTS (SELECT 1 FROM ref_map m WHERE m.ref_item_id = i.id) OR EXISTS (SELECT 1 FROM standard_indicators si WHERE si.source_ref_item_id = i.id))';

        $ring = Db::all("SELECT r.id, r.code, r.name, r.version, r.warna, r.sort_order, COUNT(i.id) AS butir_wajib,
                                COUNT(CASE WHEN $tercakup THEN 1 END) AS terpetakan
                           FROM refs r LEFT JOIN ref_items i ON i.ref_id = r.id AND i.wajib_petakan = 1
                          WHERE r.is_active = 1 GROUP BY r.id, r.code, r.name, r.version, r.warna, r.sort_order ORDER BY r.sort_order");
        $yatim = Db::all("SELECT r.code AS acuan_kode, r.name AS acuan_nama, i.id, i.code, i.title, i.tipe,
                                 CASE WHEN i.target IS NOT NULL AND i.target <> '' THEN 1 ELSE 0 END AS punya_indikator
                            FROM ref_items i JOIN refs r ON r.id = i.ref_id
                           WHERE i.wajib_petakan = 1 AND r.is_active = 1 AND NOT $tercakup ORDER BY r.sort_order, i.level, i.sort_order");
        $tanpa = Db::all('SELECT s.id, s.code, s.title, s.ranah, s.kelompok, s.sifat, s.category FROM standards s
                           WHERE s.period_id = ?
                             AND NOT EXISTS (SELECT 1 FROM ref_map m WHERE m.standard_id = s.id OR m.indicator_id IN (SELECT id FROM standard_indicators WHERE standard_id = s.id))
                             AND NOT EXISTS (SELECT 1 FROM standard_indicators si WHERE si.standard_id = s.id AND si.source_ref_item_id IS NOT NULL)
                           ORDER BY s.code', [$pid]);
        $manual = Db::all('SELECT si.id, si.code, si.indicator, si.standard_id, s.code AS standar_kode, s.title AS standar_judul, s.ranah, s.kelompok, s.sifat
                             FROM standard_indicators si JOIN standards s ON s.id = si.standard_id
                            WHERE s.period_id = ? AND si.source_ref_item_id IS NULL AND NOT EXISTS (SELECT 1 FROM ref_map m WHERE m.indicator_id = si.id)
                            ORDER BY s.code, si.sort_order', [$pid]);
        $perRanah = Db::all('SELECT s.ranah, s.kelompok, COUNT(DISTINCT s.id) AS jumlah_standar,
                                    COUNT(DISTINCT COALESCE(m.ref_item_id, i.source_ref_item_id)) AS butir_terserap
                               FROM standards s LEFT JOIN standard_indicators i ON i.standard_id = s.id
                               LEFT JOIN ref_map m ON m.standard_id = s.id OR m.indicator_id = i.id
                              WHERE s.period_id = ? GROUP BY s.ranah, s.kelompok ORDER BY s.ranah, s.kelompok', [$pid]);
        foreach ($ring as &$x) $x['persen'] = $x['butir_wajib'] ? (int)round($x['terpetakan'] / $x['butir_wajib'] * 100) : null;
        unset($x);
        return ['ringkasan' => $ring, 'butir_yatim' => $yatim, 'standar_tanpa_acuan' => $tanpa, 'indikator_manual' => $manual, 'per_ranah' => $perRanah];
    }, $L);

    $r->get("$B/lensa/:refId", function (Request $q) {
        $pid = (int)$q->query('period_id', 0);
        if (!$pid) throw new HttpError('period_id wajib diisi', 400);
        $rid = (int)$q->params['refId'];
        $acuan = Db::one('SELECT * FROM refs WHERE id=?', [$rid]);
        if (!$acuan) throw new HttpError('Acuan tidak ditemukan', 404);
        $butir = acu_urutkanPohon(Db::all('SELECT i.id, i.code, i.title, i.tipe, i.level, i.parent_id, i.wajib_petakan, i.sort_order FROM ref_items i WHERE i.ref_id = ? ORDER BY i.level, i.sort_order, i.id', [$rid]));
        $petaan = Db::all('SELECT m.ref_item_id, s.id AS standard_id, s.code AS standar_kode, s.title AS standar_judul, s.ranah, s.kelompok, s.sifat,
                                  ind.id AS indicator_id, ind.code AS indikator_kode, ind.indicator, ind.target,
                                  (SELECT ROUND(AVG(a.capaian_persen),1) FROM audit_assessments a JOIN audits au ON au.id = a.audit_id AND au.period_id = ? WHERE a.indicator_id = ind.id) AS capaian
                             FROM ref_map m JOIN ref_items b ON b.id = m.ref_item_id AND b.ref_id = ?
                             LEFT JOIN standards s ON s.id = m.standard_id LEFT JOIN standard_indicators ind ON ind.id = m.indicator_id
                            WHERE s.period_id = ? OR s.id IS NULL ORDER BY s.code, ind.sort_order', [$pid, $rid, $pid]);
        return ['acuan' => $acuan, 'butir' => $butir, 'petaan' => $petaan];
    }, $L);

    /* ----------------------------- Pemetaan ---------------------------- */
    $r->get("$B/butir/:bid/petaan", fn(Request $q) => Db::all(
        'SELECT m.*, s.code AS standar_kode, s.title AS standar_judul, s.ranah, s.kelompok, s.sifat, i.code AS indikator_kode, i.indicator
           FROM ref_map m LEFT JOIN standards s ON s.id = m.standard_id LEFT JOIN standard_indicators i ON i.id = m.indicator_id
          WHERE m.ref_item_id = ? ORDER BY s.code, i.sort_order', [(int)$q->params['bid']]), $L);

    $r->get("$B/petaan/standar/:sid", fn(Request $q) => Db::all(
        'SELECT m.*, r.code AS acuan_kode, r.name AS acuan_nama, r.warna, b.code AS butir_kode, b.title AS butir_judul, b.tipe
           FROM ref_map m JOIN ref_items b ON b.id = m.ref_item_id JOIN refs r ON r.id = b.ref_id
          WHERE m.standard_id = ? OR m.indicator_id IN (SELECT id FROM standard_indicators WHERE standard_id = ?)
          ORDER BY r.sort_order, b.code', [(int)$q->params['sid'], (int)$q->params['sid']]), $L);

    $r->post("$B/petaan", function (Request $q) {
        $item = $q->in('ref_item_id'); $std = $q->in('standard_id'); $ind = $q->in('indicator_id');
        if (kosong($item)) throw new HttpError('ref_item_id wajib diisi', 400);
        if (kosong($std) && kosong($ind)) throw new HttpError('Butir harus dipetakan ke standar atau indikator', 400);
        $sid = kosong($std) ? null : (int)$std;
        if (!kosong($ind)) {
            $i = Db::one('SELECT standard_id FROM standard_indicators WHERE id=?', [(int)$ind]);
            if (!$i) throw new HttpError('Indikator tidak ditemukan', 404);
            $sid = (int)$i['standard_id'];
        }
        // Keunikan dijaga di sini (indeks unik berekspresi tidak dipakai di MySQL/MariaDB)
        if (Db::one('SELECT id FROM ref_map WHERE ref_item_id=? AND COALESCE(standard_id,0)=? AND COALESCE(indicator_id,0)=?', [(int)$item, $sid ?? 0, kosong($ind) ? 0 : (int)$ind]))
            throw new HttpError('Pemetaan ini sudah ada', 400);
        $id = Db::insert('INSERT INTO ref_map (ref_item_id, standard_id, indicator_id, kekuatan, catatan, created_by) VALUES (?,?,?,?,?,?)',
            [(int)$item, $sid, kosong($ind) ? null : (int)$ind, $q->in('kekuatan') ?? 'utama', $q->in('catatan'), $q->user['id']]);
        return new Respons(['id' => $id], 201);
    }, $spm);

    $r->delete("$B/petaan/:mid", function (Request $q) {
        Db::run('DELETE FROM ref_map WHERE id=?', [(int)$q->params['mid']]);
        return ['ok' => true];
    }, $spm);

    /* ------------------------------ Butir ------------------------------ */
    $r->get("$B/:id/butir", function (Request $q) {
        $id = (int)$q->params['id'];
        $acuan = Db::one('SELECT * FROM refs WHERE id=?', [$id]);
        if (!$acuan) throw new HttpError('Acuan tidak ditemukan', 404);
        $butir = Db::all('SELECT i.*, (SELECT COUNT(*) FROM ref_map m WHERE m.ref_item_id = i.id) AS jumlah_petaan,
                                 (SELECT COUNT(*) FROM standard_indicators si WHERE si.source_ref_item_id = i.id) AS jumlah_diadopsi
                            FROM ref_items i WHERE i.ref_id = ? ORDER BY i.level, i.sort_order, i.id', [$id]);
        return ['acuan' => $acuan, 'butir' => acu_urutkanPohon($butir)];
    }, $L);

    $r->post("$B/:id/butir", function (Request $q) {
        $refId = (int)$q->params['id'];
        $parent = $q->in('parent_id');
        if (kosong($q->in('code')) || kosong($q->in('title'))) throw new HttpError('Kode dan judul butir wajib diisi', 400);
        $level = !kosong($parent) ? ((int)(Db::val('SELECT level FROM ref_items WHERE id=?', [(int)$parent]) ?? 0)) + 1 : 1;
        $urut = (int)Db::val('SELECT COUNT(*) FROM ref_items WHERE ref_id=? AND COALESCE(parent_id,0)=COALESCE(?,0)', [$refId, kosong($parent) ? null : (int)$parent]);
        $wp = $q->in('wajib_petakan');
        try {
            $id = Db::insert('INSERT INTO ref_items (ref_id, parent_id, code, title, uraian, tipe, level, bobot, wajib_petakan, target, target_skor, satuan, strategy, sort_order)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$refId, kosong($parent) ? null : (int)$parent, $q->in('code'), $q->in('title'), $q->in('uraian'), $q->in('tipe') ?? 'butir', $level, $q->in('bobot'),
                 ($wp === 0 || $wp === false) ? 0 : 1, $q->in('target'), $q->in('target_skor'), $q->in('satuan'), $q->in('strategy'), $urut]);
        } catch (PDOException $e) {
            throw new HttpError('Kode butir "' . $q->in('code') . '" sudah ada pada acuan ini', 400);
        }
        return new Respons(['id' => $id], 201);
    }, $spm);

    $r->put("$B/butir/:bid", function (Request $q) {
        $bid = (int)$q->params['bid'];
        $b = Db::one('SELECT * FROM ref_items WHERE id=?', [$bid]);
        if (!$b) throw new HttpError('Butir tidak ditemukan', 404);
        $body = $q->body();
        $ada = fn($k, $lama) => array_key_exists($k, $body) ? $body[$k] : $lama;
        Db::run('UPDATE ref_items SET code=?, title=?, uraian=?, tipe=?, bobot=?, wajib_petakan=?, target=?, target_skor=?, satuan=?, strategy=? WHERE id=?', [
            $body['code'] ?? $b['code'], $body['title'] ?? $b['title'], $body['uraian'] ?? $b['uraian'], $body['tipe'] ?? $b['tipe'], $body['bobot'] ?? $b['bobot'],
            array_key_exists('wajib_petakan', $body) ? ($body['wajib_petakan'] ? 1 : 0) : $b['wajib_petakan'],
            $ada('target', $b['target']), $ada('target_skor', $b['target_skor']), $ada('satuan', $b['satuan']), $ada('strategy', $b['strategy']), $bid,
        ]);
        acu_sinkron($bid);
        return ['ok' => true];
    }, $spm);

    $r->delete("$B/butir/:bid", function (Request $q) {
        $bid = (int)$q->params['bid'];
        $j = (int)Db::val('SELECT COUNT(*) FROM standard_indicators WHERE source_ref_item_id=?', [$bid]);
        if ($j) throw new HttpError("Butir ini sudah diadopsi menjadi $j indikator standar. Lepas adopsinya terlebih dahulu di Modul 1 sebelum menghapus butir.", 400);
        Db::run('DELETE FROM ref_items WHERE id=?', [$bid]);
        return ['ok' => true];
    }, $spm);

    $r->post("$B/:id/impor", function (Request $q) {
        $refId = (int)$q->params['id'];
        if (!Db::one('SELECT id FROM refs WHERE id=?', [$refId])) throw new HttpError('Acuan tidak ditemukan', 404);
        $baris = $q->in('baris');
        if (!is_array($baris) || !count($baris)) throw new HttpError('Tidak ada baris yang dikirim', 400);

        $hasil = ['ditambah' => 0, 'diperbarui' => 0, 'gagal' => []];
        $terurut = array_values($baris);
        usort($terurut, fn($a, $b) => strlen((string)($a['kode'] ?? '')) <=> strlen((string)($b['kode'] ?? '')));

        Db::transaksi(function () use ($terurut, $refId, &$hasil) {
            foreach ($terurut as $n => $r) {
                $kode = trim((string)($r['kode'] ?? '')); $judul = trim((string)($r['judul'] ?? ''));
                if ($kode === '' || $judul === '') { $hasil['gagal'][] = ['baris' => $n + 1, 'alasan' => 'Kode atau judul kosong']; continue; }
                $kodeInduk = trim((string)($r['kode_induk'] ?? ''));
                $parentId = null; $level = 1;
                if ($kodeInduk !== '') {
                    $induk = Db::one('SELECT id, level FROM ref_items WHERE ref_id=? AND code=?', [$refId, $kodeInduk]);
                    if (!$induk) { $hasil['gagal'][] = ['baris' => $n + 1, 'kode' => $kode, 'alasan' => "Kode induk \"$kodeInduk\" belum ada pada acuan ini"]; continue; }
                    $parentId = (int)$induk['id']; $level = (int)$induk['level'] + 1;
                }
                $pay = $r['payung'] ?? null;
                $payung = $pay === true || $pay === 1 || str_starts_with(strtolower((string)$pay), 'y');
                $target = teksAtauNull($r['target'] ?? null); $tskor = teksAtauNull($r['target_skor'] ?? null);
                $satuan = teksAtauNull($r['satuan'] ?? null); $strategy = teksAtauNull($r['strategy'] ?? null);
                $ada = Db::one('SELECT id FROM ref_items WHERE ref_id=? AND code=?', [$refId, $kode]);
                $tipe = $r['tipe'] ?? 'butir';
                if ($ada) {
                    Db::run('UPDATE ref_items SET parent_id=?, title=?, uraian=?, tipe=?, level=?, bobot=?, wajib_petakan=?, target=?, target_skor=?, satuan=?, strategy=? WHERE id=?',
                        [$parentId, $judul, $r['uraian'] ?? null, $tipe, $level, $r['bobot'] ?? null, $payung ? 0 : 1, $target, $tskor, $satuan, $strategy, $ada['id']]);
                    acu_sinkron((int)$ada['id']);
                    $hasil['diperbarui']++;
                } else {
                    Db::run('INSERT INTO ref_items (ref_id, parent_id, code, title, uraian, tipe, level, bobot, wajib_petakan, target, target_skor, satuan, strategy, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        [$refId, $parentId, $kode, $judul, $r['uraian'] ?? null, $tipe, $level, $r['bobot'] ?? null, $payung ? 0 : 1, $target, $tskor, $satuan, $strategy, $n]);
                    $hasil['ditambah']++;
                }
            }
        });
        Db::log((int)$q->user['id'], 'impor_butir_acuan', 'refs', $refId, "{$hasil['ditambah']} baru, {$hasil['diperbarui']} diperbarui");
        return $hasil;
    }, $spm);

    /* ------------------- Ubah / hapus acuan (rute :id terakhir) -------- */
    $r->put("$B/:id", function (Request $q) {
        $id = (int)$q->params['id'];
        $a = Db::one('SELECT * FROM refs WHERE id=?', [$id]);
        if (!$a) throw new HttpError('Acuan tidak ditemukan', 404);
        $b = $q->body();
        Db::run('UPDATE refs SET code=?, name=?, version=?, publisher=?, effective_date=?, warna=?, keterangan=?, is_active=?, jenjang=?, skor_min=?, skor_maks=?, skor_step=? WHERE id=?', [
            $b['code'] ?? $a['code'], $b['name'] ?? $a['name'], $b['version'] ?? $a['version'], $b['publisher'] ?? $a['publisher'],
            $b['effective_date'] ?? $a['effective_date'], $b['warna'] ?? $a['warna'], $b['keterangan'] ?? $a['keterangan'], $b['is_active'] ?? $a['is_active'],
            array_key_exists('jenjang', $b) ? ($b['jenjang'] ?: null) : $a['jenjang'],
            acu_angka($b, 'skor_min', $a['skor_min']), acu_angka($b, 'skor_maks', $a['skor_maks']), acu_angka($b, 'skor_step', $a['skor_step']), $id,
        ]);
        return ['ok' => true];
    }, $spm);

    $r->delete("$B/:id", function (Request $q) {
        $id = (int)$q->params['id'];
        Db::run('DELETE FROM refs WHERE id=?', [$id]);
        Db::log((int)$q->user['id'], 'hapus_acuan', 'refs', $id);
        return ['ok' => true];
    }, $spm);
}
