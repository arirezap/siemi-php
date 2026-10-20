<?php
declare(strict_types=1);

/* MODUL 1 — PENETAPAN STANDAR (padanan server/src/routes/standar.js). Hak input: spm. */

function std_simpanIndikator(int $sid, int $stid, string $kodeStandar, int $urutPernyataan, array $daftar): void
{
    $bersih = preg_replace('/\+/', '', $kodeStandar, 1);

    $ada = array_map('intval', array_column(Db::all('SELECT id FROM standard_indicators WHERE statement_id=?', [$stid]), 'id'));
    $keep = [];
    foreach ($daftar as $i) if (!kosong($i['id'] ?? null)) $keep[] = (int)$i['id'];
    foreach ($ada as $eid) if (!in_array($eid, $keep, true)) Db::run('DELETE FROM standard_indicators WHERE id=?', [$eid]);

    foreach (array_values($daftar) as $n => $it) {
        $indicator = $it['indicator'] ?? null;
        $target = $it['target'] ?? null;
        $targetSkor = $it['target_skor'] ?? null;
        $satuan = $it['satuan'] ?? null;
        $strategy = $it['strategy'] ?? null;
        $bukti = $it['bukti_sahih'] ?? null;
        $sumberId = !kosong($it['source_ref_item_id'] ?? null) ? (int)$it['source_ref_item_id'] : null;

        if ($sumberId) {
            $src = Db::one('SELECT title, uraian, target, target_skor, satuan, strategy FROM ref_items WHERE id=?', [$sumberId]);
            if (!$src) throw new RuntimeException("Butir acuan sumber (id $sumberId) tidak ditemukan");
            $indicator = (trim((string)$src['uraian']) !== '') ? trim((string)$src['uraian']) : $src['title'];
            $target = $src['target']; $targetSkor = $src['target_skor']; $satuan = $src['satuan']; $strategy = $src['strategy'];
        }
        if ($indicator === null || trim((string)$indicator) === '')
            throw new RuntimeException('Setiap indikator wajib memiliki teks atau sumber acuan yang valid');

        $kode = !kosong($it['code'] ?? null) ? $it['code'] : $bersih . '.' . ($urutPernyataan + 1) . '.' . ($n + 1);
        if (!kosong($it['id'] ?? null)) {
            $iid = (int)$it['id'];
            Db::run('UPDATE standard_indicators SET code=?, indicator=?, target=?, target_skor=?, satuan=?, strategy=?, bukti_sahih=?, deadline=?,
                            source_ref_item_id=?, sort_order=? WHERE id=?',
                [$kode, $indicator, $target, $targetSkor, $satuan, $strategy, $bukti, $it['deadline'] ?? null, $sumberId, $n, $iid]);
        } else {
            $iid = Db::insert('INSERT INTO standard_indicators (standard_id, statement_id, code, indicator, target, target_skor, satuan, strategy,
                                    bukti_sahih, deadline, source_ref_item_id, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [$sid, $stid, $kode, $indicator, $target, $targetSkor, $satuan, $strategy, $bukti, $it['deadline'] ?? null, $sumberId, $n]);
        }

        Db::run('DELETE FROM indicator_assignments WHERE indicator_id=?', [$iid]);
        $pengampu = $it['pengampu_unit_id'] ?? null;
        if (!kosong($pengampu)) Db::run('INSERT IGNORE INTO indicator_assignments (indicator_id, unit_id, peran) VALUES (?,?,?)', [$iid, (int)$pengampu, 'pengampu']);
        foreach (($it['pelaksana_unit_ids'] ?? []) as $u) {
            if ((int)$u !== (int)$pengampu)
                Db::run('INSERT IGNORE INTO indicator_assignments (indicator_id, unit_id, peran) VALUES (?,?,?)', [$iid, (int)$u, 'pelaksana']);
        }
    }
}

function std_simpanPernyataan(int $sid, string $kodeStandar, array $daftar): void
{
    $ada = array_map('intval', array_column(Db::all('SELECT id FROM standard_statements WHERE standard_id=?', [$sid]), 'id'));
    $keep = [];
    foreach ($daftar as $p) if (!kosong($p['id'] ?? null)) $keep[] = (int)$p['id'];
    foreach ($ada as $eid) if (!in_array($eid, $keep, true)) Db::run('DELETE FROM standard_statements WHERE id=?', [$eid]);

    foreach (array_values($daftar) as $pn => $p) {
        if (trim((string)($p['statement'] ?? '')) === '') throw new RuntimeException('Pernyataan standar ke-' . ($pn + 1) . ' tidak boleh kosong');
        if (!kosong($p['id'] ?? null)) {
            $stid = (int)$p['id'];
            Db::run('UPDATE standard_statements SET label=?, statement=?, rationale=?, sort_order=? WHERE id=?',
                [$p['label'] ?? null, $p['statement'], $p['rationale'] ?? null, $pn, $stid]);
        } else {
            $stid = Db::insert('INSERT INTO standard_statements (standard_id, label, statement, rationale, sort_order) VALUES (?,?,?,?,?)',
                [$sid, $p['label'] ?? null, $p['statement'], $p['rationale'] ?? null, $pn]);
        }
        std_simpanIndikator($sid, $stid, $kodeStandar, $pn, $p['indikator'] ?? []);
    }
}

function std_normalisasi(array $b): array
{
    if (isset($b['pernyataan']) && is_array($b['pernyataan'])) return $b['pernyataan'];
    if (isset($b['indikator']) && is_array($b['indikator']) && count($b['indikator']))
        return [['statement' => !kosong($b['statement'] ?? null) ? $b['statement'] : ($b['title'] ?? ''), 'indikator' => $b['indikator']]];
    return [];
}

function rute_standar(Router $r): void
{
    $L   = [[Auth::class, 'wajibLogin']];
    $spm = [[Auth::class, 'wajibLogin'], Auth::peran('spm')];
    $B   = '/standar';

    $r->get($B, function (Request $q) {
        $where = []; $p = [];
        foreach (['period_id' => 's.period_id', 'category' => 's.category', 'ranah' => 's.ranah', 'kelompok' => 's.kelompok', 'sifat' => 's.sifat', 'status' => 's.status'] as $k => $kol) {
            $v = $q->query($k);
            if (!kosong($v)) { $where[] = "$kol = ?"; $p[] = $v; }
        }
        if (!kosong($q->query('unit_id'))) {
            $where[] = 'EXISTS (SELECT 1 FROM standard_indicators i JOIN indicator_assignments a ON a.indicator_id = i.id WHERE i.standard_id = s.id AND a.unit_id = ?)';
            $p[] = $q->query('unit_id');
        }
        return Db::all(
            'SELECT s.*, p.name AS period_name,
                    (SELECT st.statement FROM standard_statements st WHERE st.standard_id = s.id ORDER BY st.sort_order, st.id LIMIT 1) AS pernyataan_utama,
                    (SELECT COUNT(*) FROM standard_statements st WHERE st.standard_id = s.id) AS jumlah_pernyataan,
                    (SELECT COUNT(*) FROM standard_indicators i WHERE i.standard_id = s.id) AS jumlah_indikator,
                    (SELECT COUNT(DISTINCT a.unit_id) FROM standard_indicators i JOIN indicator_assignments a ON a.indicator_id = i.id WHERE i.standard_id = s.id) AS jumlah_unit,
                    (SELECT COUNT(*) FROM ref_items b WHERE
                        b.id IN (SELECT ref_item_id FROM ref_map WHERE standard_id = s.id)
                     OR b.id IN (SELECT m.ref_item_id FROM ref_map m JOIN standard_indicators ii ON ii.id = m.indicator_id WHERE ii.standard_id = s.id)
                     OR b.id IN (SELECT source_ref_item_id FROM standard_indicators WHERE standard_id = s.id AND source_ref_item_id IS NOT NULL)
                    ) AS jumlah_acuan
               FROM standards s JOIN periods p ON p.id = s.period_id '
            . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY s.ranah, s.kelompok, s.code', $p);
    }, $L);

    $r->post("$B/duplikasi", function (Request $q) {
        $src = (int)$q->in('source_period_id'); $tgt = (int)$q->in('target_period_id');
        if (!$src || !$tgt) throw new HttpError('source_period_id dan target_period_id wajib diisi', 400);
        if ($src === $tgt) throw new HttpError('Periode sumber dan tujuan tidak boleh sama', 400);
        if (!Db::one('SELECT id FROM periods WHERE id=?', [$tgt])) throw new HttpError('Periode tujuan tidak ditemukan', 404);

        $sumber = Db::all('SELECT * FROM standards WHERE period_id=?', [$src]);
        $ids = $q->in('standard_ids');
        if (is_array($ids) && count($ids)) {
            $set = array_map('intval', $ids);
            $sumber = array_values(array_filter($sumber, fn($s) => in_array((int)$s['id'], $set, true)));
        }
        if (!$sumber) throw new HttpError('Tidak ada standar terpilih dari periode sumber', 400);

        $terpakai = array_flip(array_column(Db::all('SELECT code FROM standards WHERE period_id=?', [$tgt]), 'code'));
        $hasil = ['diduplikasi' => [], 'dilewati' => []];

        Db::transaksi(function () use ($sumber, &$terpakai, &$hasil, $tgt, $q) {
            foreach ($sumber as $std) {
                if (isset($terpakai[$std['code']])) { $hasil['dilewati'][] = $std['code']; continue; }
                $baru = Db::insert("INSERT INTO standards (period_id, code, category, ranah, kelompok, sifat, title, rationale, scope, strategy, status, created_by)
                                    VALUES (?,?,?,?,?,?,?,?,?,?,'draft',?)",
                    [$tgt, $std['code'], $std['category'], $std['ranah'], $std['kelompok'], $std['sifat'], $std['title'], $std['rationale'], $std['scope'], $std['strategy'], $q->user['id']]);
                foreach (Db::all('SELECT * FROM standard_statements WHERE standard_id=? ORDER BY sort_order, id', [$std['id']]) as $p) {
                    $np = Db::insert('INSERT INTO standard_statements (standard_id, label, statement, rationale, sort_order) VALUES (?,?,?,?,?)',
                        [$baru, $p['label'], $p['statement'], $p['rationale'], $p['sort_order']]);
                    foreach (Db::all('SELECT * FROM standard_indicators WHERE statement_id=? ORDER BY sort_order, id', [$p['id']]) as $ind) {
                        $ni = Db::insert('INSERT INTO standard_indicators (standard_id, statement_id, code, indicator, target, target_skor, satuan, strategy, bukti_sahih, deadline, source_ref_item_id, sort_order)
                                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                            [$baru, $np, $ind['code'], $ind['indicator'], $ind['target'], $ind['target_skor'], $ind['satuan'], $ind['strategy'], $ind['bukti_sahih'], $ind['deadline'], $ind['source_ref_item_id'], $ind['sort_order']]);
                        foreach (Db::all('SELECT unit_id, peran FROM indicator_assignments WHERE indicator_id=?', [$ind['id']]) as $a)
                            Db::run('INSERT IGNORE INTO indicator_assignments (indicator_id, unit_id, peran) VALUES (?,?,?)', [$ni, $a['unit_id'], $a['peran']]);
                    }
                }
                $hasil['diduplikasi'][] = $std['code'];
                $terpakai[$std['code']] = true;
            }
        });
        Db::log((int)$q->user['id'], 'duplikasi_standar', 'standards', null, count($hasil['diduplikasi']) . " standar diduplikasi dari periode $src ke periode $tgt");
        return $hasil;
    }, $spm);

    $r->get("$B/export", function (Request $q) {
        $pid = (int)$q->query('period_id', 0);
        if (!$pid) throw new HttpError('period_id wajib diisi', 400);
        $period = Db::one('SELECT * FROM periods WHERE id=?', [$pid]);
        if (!$period) throw new HttpError('Periode tidak ditemukan', 404);

        $warna = ['A' => Xlsx::PALET[0], 'B' => Xlsx::PALET[1], 'C' => Xlsx::PALET[2], 'D' => Xlsx::PALET[3], 'E' => Xlsx::PALET[4]];
        $header = ['Ranah', 'Kelompok', 'Kategori', 'Kode Standar', 'Judul Standar', 'Sifat', 'Rasional Umum Standar', 'Ruang Lingkup',
            'Strategi Pencapaian Standar', 'Unit Pengampu', 'Unit Pelaksana', 'No. Pernyataan', 'Label Pernyataan', 'Rumusan Pernyataan Standar',
            'Rasional Pernyataan', 'No. Indikator', 'Kode Indikator', 'Kode Acuan', 'Nama Acuan', 'Kode Butir Acuan', 'Indikator',
            'Target Pencapaian', 'Target Skor', 'Satuan', 'Strategi Pencapaian Indikator', 'Bukti Sahih'];

        $standar = Db::all('SELECT * FROM standards WHERE period_id=? ORDER BY ranah, kelompok, code', [$pid]);
        $jmlP = 0; $jmlI = 0; $data = [];
        foreach ($standar as $std) {
            $gaya = Xlsx::data($warna[$std['ranah']] ?? Xlsx::PALET[5]);
            foreach (Db::all('SELECT * FROM standard_statements WHERE standard_id=? ORDER BY sort_order, id', [$std['id']]) as $pIdx => $p) {
                $jmlP++;
                $inds = Db::all('SELECT si.*, r.code AS acuan_kode, r.name AS acuan_nama, b.code AS butir_kode FROM standard_indicators si
                                   LEFT JOIN ref_items b ON b.id = si.source_ref_item_id LEFT JOIN refs r ON r.id = b.ref_id
                                  WHERE si.statement_id=? ORDER BY si.sort_order, si.id', [$p['id']]);
                foreach ($inds as $iIdx => $ind) {
                    $jmlI++;
                    $unit = Db::all('SELECT a.peran, un.name FROM indicator_assignments a JOIN units un ON un.id = a.unit_id WHERE a.indicator_id=? ORDER BY a.peran DESC, un.name', [$ind['id']]);
                    $pengampu = null; $pel = [];
                    foreach ($unit as $u) { if ($u['peran'] === 'pengampu' && $pengampu === null) $pengampu = $u['name']; if ($u['peran'] === 'pelaksana') $pel[] = $u['name']; }
                    $data[] = ['gaya' => $gaya, 'nilai' => [
                        $std['ranah'], $std['kelompok'], $std['category'], $std['code'], $std['title'], $std['sifat'], $std['rationale'], $std['scope'], $std['strategy'],
                        $pengampu, $pel ? implode('; ', $pel) : null, $pIdx + 1, $p['label'], $p['statement'], $p['rationale'], $iIdx + 1,
                        $ind['code'], $ind['acuan_kode'], $ind['acuan_nama'], $ind['butir_kode'], $ind['indicator'], $ind['target'], $ind['target_skor'], $ind['satuan'], $ind['strategy'], $ind['bukti_sahih'],
                    ]];
                }
            }
        }
        $lebar = [6, 8, 26, 10, 24, 8, 34, 34, 34, 16, 28, 8, 20, 40, 34, 8, 12, 12, 24, 10, 34, 34, 16, 10, 34, 34];
        $sheet = [
            'nama' => 'Matriks Standar',
            'kolom' => array_map(fn($w) => ['lebar' => $w], $lebar),
            'beku' => ['kolom' => 4, 'baris' => 3],
            'baris' => array_merge([
                ['gaya' => Xlsx::JUDUL, 'tinggi' => 26, 'nilai' => ["Matriks Standar Mutu Internal PKTJ — Modul Penetapan Standar ({$period['name']})"]],
                ['gaya' => Xlsx::SUBJUDUL, 'tinggi' => 18, 'nilai' => [count($standar) . " standar · $jmlP pernyataan · $jmlI indikator — seluruh indikator diadopsi verbatim dari acuan mutu yang berlaku"]],
                ['gaya' => Xlsx::HEADER, 'tinggi' => 32, 'nilai' => $header],
            ], $data),
        ];
        Xlsx::kirim(Xlsx::buat([$sheet]), 'Matriks_Standar_dan_Kode_Modul_Penetapan_Standar.xlsx');
    }, $L);

    $r->get("$B/meta/beban-unit", function (Request $q) {
        $pid = (int)$q->query('period_id', 0);
        if (!$pid) throw new HttpError('period_id wajib diisi', 400);
        return Db::all("SELECT un.id, un.code, un.name, un.type,
                               COUNT(DISTINCT CASE WHEN a.peran='pengampu' THEN i.id END) AS diampu,
                               COUNT(DISTINCT CASE WHEN a.peran='pelaksana' THEN i.id END) AS dilaksanakan,
                               COUNT(DISTINCT i.standard_id) AS jumlah_standar
                          FROM units un LEFT JOIN indicator_assignments a ON a.unit_id = un.id
                          LEFT JOIN standard_indicators i ON i.id = a.indicator_id
                          LEFT JOIN standards s ON s.id = i.standard_id AND s.period_id = ?
                         WHERE un.is_active = 1 GROUP BY un.id, un.code, un.name, un.type ORDER BY diampu DESC, un.name", [$pid]);
    }, $L);

    $r->get("$B/meta/kategori", fn() => Db::all('SELECT DISTINCT category, ranah, kelompok FROM standards ORDER BY ranah, kelompok'), $L);

    $r->get("$B/:id", function (Request $q) {
        $std = Db::one('SELECT s.*, p.name AS period_name, u.name AS penetap_name FROM standards s JOIN periods p ON p.id = s.period_id
                          LEFT JOIN users u ON u.id = s.ditetapkan_by WHERE s.id = ?', [(int)$q->params['id']]);
        if (!$std) throw new HttpError('Standar tidak ditemukan', 404);
        $sid = (int)$std['id'];

        $pern = Db::all('SELECT * FROM standard_statements WHERE standard_id = ? ORDER BY sort_order, id', [$sid]);
        $indikator = Db::all('SELECT i.*, r.code AS acuan_kode, r.name AS acuan_nama, r.warna, b.code AS butir_kode, b.title AS butir_judul
                                FROM standard_indicators i LEFT JOIN ref_items b ON b.id = i.source_ref_item_id LEFT JOIN refs r ON r.id = b.ref_id
                               WHERE i.standard_id = ? ORDER BY i.sort_order, i.id', [$sid]);
        $penugasan = Db::all('SELECT a.indicator_id, a.unit_id, a.peran, un.code, un.name, un.type FROM indicator_assignments a
                                JOIN units un ON un.id = a.unit_id JOIN standard_indicators i ON i.id = a.indicator_id
                               WHERE i.standard_id = ? ORDER BY a.peran DESC, un.name', [$sid]);
        foreach ($indikator as &$i) $i['units'] = array_values(array_filter($penugasan, fn($p) => (int)$p['indicator_id'] === (int)$i['id']));
        unset($i);
        foreach ($pern as &$p) $p['indikator'] = array_values(array_filter($indikator, fn($i) => (int)$i['statement_id'] === (int)$p['id']));
        unset($p);
        $std['pernyataan'] = $pern;
        $std['indikator_tanpa_pernyataan'] = array_values(array_filter($indikator, fn($i) => empty($i['statement_id'])));

        $peta = [];
        foreach ($penugasan as $pg) {
            $ada = $peta[$pg['unit_id']] ?? null;
            if (!$ada || ($pg['peran'] === 'pengampu' && $ada['peran'] !== 'pengampu'))
                $peta[$pg['unit_id']] = ['id' => $pg['unit_id'], 'code' => $pg['code'], 'name' => $pg['name'], 'type' => $pg['type'], 'peran' => $pg['peran']];
        }
        $units = array_values($peta);
        usort($units, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        $std['units'] = $units;

        $std['acuan'] = Db::all(
            "SELECT 'map' AS jenis, m.id AS map_id, m.indicator_id, m.kekuatan, m.catatan, r.code AS acuan_kode, r.name AS acuan_nama, r.warna,
                    b.id AS butir_id, b.code AS butir_kode, b.title AS butir_judul
               FROM ref_map m JOIN ref_items b ON b.id = m.ref_item_id JOIN refs r ON r.id = b.ref_id
              WHERE m.standard_id = ? OR m.indicator_id IN (SELECT id FROM standard_indicators WHERE standard_id = ?)
             UNION ALL
             SELECT 'adopsi' AS jenis, NULL AS map_id, i.id AS indicator_id, NULL, NULL, r.code AS acuan_kode, r.name AS acuan_nama, r.warna,
                    b.id AS butir_id, b.code AS butir_kode, b.title AS butir_judul
               FROM standard_indicators i JOIN ref_items b ON b.id = i.source_ref_item_id JOIN refs r ON r.id = b.ref_id
              WHERE i.standard_id = ?
              ORDER BY acuan_kode, butir_kode", [$sid, $sid, $sid]);
        return $std;
    }, $L);

    $r->post($B, function (Request $q) {
        $period = $q->in('period_id'); $code = $q->in('code'); $title = $q->in('title');
        if (kosong($period) || kosong($code) || kosong($title)) throw new HttpError('period_id, code, dan title wajib diisi', 400);
        $ranah = $q->in('ranah');
        $pern = std_normalisasi($q->body());
        try {
            $id = Db::transaksi(function () use ($q, $period, $code, $title, $ranah, $pern) {
                $sid = Db::insert('INSERT INTO standards (period_id, code, category, ranah, kelompok, sifat, title, rationale, scope, strategy, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    [(int)$period, $code, $q->in('category') ?: ($ranah ?: '-'), $ranah, $q->in('kelompok') ?: null, $q->in('sifat') ?? 'melampaui', $title,
                     $q->in('rationale'), $q->in('scope'), $q->in('strategy'), $q->user['id']]);
                std_simpanPernyataan($sid, (string)$code, $pern);
                return $sid;
            });
        } catch (Throwable $e) {
            throw new HttpError('Gagal menyimpan: ' . $e->getMessage(), 400);
        }
        Db::log((int)$q->user['id'], 'tetapkan_standar', 'standards', $id, (string)$code);
        return new Respons(['id' => $id], 201);
    }, $spm);

    $r->put("$B/:id", function (Request $q) {
        $id = (int)$q->params['id'];
        $std = Db::one('SELECT * FROM standards WHERE id = ?', [$id]);
        if (!$std) throw new HttpError('Standar tidak ditemukan', 404);
        $b = $q->body();
        $title = $b['title'] ?? null;
        $adaPern = (isset($b['pernyataan']) && is_array($b['pernyataan'])) || (isset($b['indikator']) && is_array($b['indikator']));
        $pern = std_normalisasi($b);

        if ($std['sifat'] === 'wajib' && !kosong($title) && $title !== $std['title'])
            throw new HttpError('Standar wajib SN Dikti tidak boleh diganti namanya. Ubah sifatnya menjadi "melampaui" bila memang bukan standar wajib.', 400);

        $kode = $b['code'] ?? $std['code'];
        try {
            Db::transaksi(function () use ($id, $std, $b, $title, $adaPern, $pern, $kode) {
                Db::run('UPDATE standards SET code=?, category=?, ranah=?, kelompok=?, sifat=?, title=?, rationale=?, scope=?, strategy=? WHERE id=?', [
                    $kode, $b['category'] ?? $std['category'], $b['ranah'] ?? $std['ranah'],
                    array_key_exists('kelompok', $b) ? ($b['kelompok'] ?: null) : $std['kelompok'],
                    $b['sifat'] ?? $std['sifat'], $title ?? $std['title'], $b['rationale'] ?? $std['rationale'],
                    $b['scope'] ?? $std['scope'], $b['strategy'] ?? $std['strategy'], $id,
                ]);
                if ($adaPern) std_simpanPernyataan($id, (string)$kode, $pern);
            });
        } catch (Throwable $e) {
            throw new HttpError('Gagal menyimpan: ' . $e->getMessage(), 400);
        }
        Db::log((int)$q->user['id'], 'ubah_standar', 'standards', $id);
        return ['ok' => true];
    }, $spm);

    $r->put("$B/:id/pernyataan", function (Request $q) {
        $id = (int)$q->params['id'];
        $std = Db::one('SELECT id, code FROM standards WHERE id=?', [$id]);
        if (!$std) throw new HttpError('Standar tidak ditemukan', 404);
        $p = $q->in('pernyataan');
        if (!is_array($p)) throw new HttpError('pernyataan (array) wajib diisi', 400);
        try {
            Db::transaksi(fn() => std_simpanPernyataan($id, (string)$std['code'], $p));
        } catch (Throwable $e) {
            throw new HttpError('Gagal menyimpan: ' . $e->getMessage(), 400);
        }
        Db::log((int)$q->user['id'], 'ubah_pernyataan_standar', 'standards', $id);
        return ['ok' => true];
    }, $spm);

    $r->post("$B/:id/tetapkan", function (Request $q) {
        $id = (int)$q->params['id'];
        if (!(int)Db::val('SELECT COUNT(*) FROM standard_indicators WHERE standard_id=?', [$id])) throw new HttpError('Standar harus memiliki minimal satu indikator', 400);
        $tp = (int)Db::val("SELECT COUNT(*) FROM standard_indicators i WHERE i.standard_id=?
                              AND NOT EXISTS (SELECT 1 FROM indicator_assignments a WHERE a.indicator_id=i.id AND a.peran='pengampu')", [$id]);
        if ($tp) throw new HttpError("$tp indikator belum memiliki unit pengampu. Tetapkan penanggung jawab pada setiap indikator terlebih dahulu.", 400);
        Db::run("UPDATE standards SET status='ditetapkan', ditetapkan_at=NOW(), ditetapkan_by=? WHERE id=?", [$q->user['id'], $id]);
        Db::log((int)$q->user['id'], 'sahkan_standar', 'standards', $id);
        return ['ok' => true];
    }, $spm);

    $r->post("$B/:id/batal-tetapkan", function (Request $q) {
        Db::run("UPDATE standards SET status='draft', ditetapkan_at=NULL, ditetapkan_by=NULL WHERE id=?", [(int)$q->params['id']]);
        return ['ok' => true];
    }, $spm);

    $r->delete("$B/:id", function (Request $q) {
        $id = (int)$q->params['id'];
        $std = Db::one('SELECT sifat, code FROM standards WHERE id=?', [$id]);
        if ($std && $std['sifat'] === 'wajib') throw new HttpError("{$std['code']} adalah standar wajib SN Dikti dan tidak boleh dihapus.", 400);
        Db::run('DELETE FROM standards WHERE id=?', [$id]);
        Db::log((int)$q->user['id'], 'hapus_standar', 'standards', $id);
        return ['ok' => true];
    }, $spm);
}
