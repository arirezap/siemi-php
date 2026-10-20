<?php
declare(strict_types=1);

const ACUAN_PENGAMPU_BOLEH_ISI_PELAKSANA = ['LAMTEKNIKSTR', 'LAMTEKNIKD3'];

/** Apakah unit pengampu boleh mengisi/menghapus isian indikator atas nama unit pelaksana (LAM Teknik). */
function bisaIsiAtasNamaPelaksana(mixed $userUnitId, int $indicatorId, int $targetUnitId): bool
{
    if ((int)$userUnitId === $targetUnitId) return false;
    $row = Db::one(
        "SELECT
           EXISTS(SELECT 1 FROM indicator_assignments WHERE indicator_id=? AND unit_id=? AND peran='pengampu') AS pengampu,
           EXISTS(SELECT 1 FROM indicator_assignments WHERE indicator_id=? AND unit_id=? AND peran='pelaksana') AS pelaksana,
           (SELECT r.code FROM standard_indicators i LEFT JOIN ref_items b ON b.id = i.source_ref_item_id
              LEFT JOIN refs r ON r.id = b.ref_id WHERE i.id = ?) AS acuan_kode",
        [$indicatorId, (int)$userUnitId, $indicatorId, $targetUnitId, $indicatorId]);
    return $row && $row['pengampu'] && $row['pelaksana'] && in_array($row['acuan_kode'], ACUAN_PENGAMPU_BOLEH_ISI_PELAKSANA, true);
}

function rute_pelaksanaan(Router $r): void
{
    $L  = [[Auth::class, 'wajibLogin']];
    $AU = [[Auth::class, 'wajibLogin'], Auth::peran('auditee', 'spm')];

    $r->get('/pelaksanaan/lembar-kerja', function (Request $q) {
        $unitId = (int)($q->query('unit_id') ?: $q->user['unit_id']);
        $periodId = (int)$q->query('period_id', 0);
        if (!$unitId) throw new HttpError('unit_id wajib diisi', 400);
        if (!$periodId) throw new HttpError('period_id wajib diisi', 400);
        if (!Auth::bisaAksesUnit($q->user, $unitId)) throw new HttpError('Bukan unit Anda', 403);

        $unit = Db::one('SELECT * FROM units WHERE id=?', [$unitId]);
        $rows = Db::all(
            "SELECT s.id AS standard_id, s.code AS standar_kode, s.category, s.ranah, s.kelompok, s.sifat,
                    s.title AS standar_judul, s.statement,
                    i.id AS indicator_id, i.code AS indikator_kode, i.indicator, i.target, i.target_skor,
                    i.satuan, i.strategy, i.deadline, ia.peran AS peran_unit,
                    r.code AS acuan_kode, r.name AS acuan_nama, r.warna AS acuan_warna,
                    r.skor_min, r.skor_maks, r.skor_step, b.code AS butir_kode
               FROM standard_indicators i
               JOIN indicator_assignments ia ON ia.indicator_id = i.id AND ia.unit_id = ?
               JOIN standards s ON s.id = i.standard_id
               LEFT JOIN ref_items b ON b.id = i.source_ref_item_id
               LEFT JOIN refs r ON r.id = b.ref_id
              WHERE s.period_id = ? AND s.status = 'ditetapkan'
              ORDER BY s.ranah, s.kelompok, s.code, i.sort_order, i.id", [$unitId, $periodId]);

        $impl = fn(int $ind, int $uid, int $pid) => Db::one(
            'SELECT im.id AS implementation_id, im.realisasi, im.capaian_nilai, im.skor, im.bukti_uraian, im.bukti_url,
                    im.kendala, im.status, im.submitted_at, u.name AS submitted_by_name
               FROM implementations im LEFT JOIN users u ON u.id = im.submitted_by
              WHERE im.indicator_id = ? AND im.unit_id = ? AND im.period_id = ?', [$ind, $uid, $pid]) ?? [];

        $items = [];
        foreach ($rows as $row) {
            $pengampuLam = $row['peran_unit'] === 'pengampu' && in_array($row['acuan_kode'], ACUAN_PENGAMPU_BOLEH_ISI_PELAKSANA, true);
            $pel = $pengampuLam ? Db::all("SELECT a.unit_id, un.code, un.name FROM indicator_assignments a
                                            JOIN units un ON un.id = a.unit_id
                                           WHERE a.indicator_id = ? AND a.peran = 'pelaksana' ORDER BY un.name", [$row['indicator_id']]) : [];
            if ($pel) {
                foreach ($pel as $pu) {
                    $items[] = array_merge($row, [
                        'untuk_unit_id' => $pu['unit_id'], 'untuk_unit_kode' => $pu['code'], 'untuk_unit_nama' => $pu['name'],
                    ], $impl((int)$row['indicator_id'], (int)$pu['unit_id'], $periodId));
                }
            } else {
                $items[] = array_merge($row, [
                    'untuk_unit_id' => $unit['id'] ?? $unitId, 'untuk_unit_kode' => $unit['code'] ?? null, 'untuk_unit_nama' => $unit['name'] ?? null,
                ], $impl((int)$row['indicator_id'], $unitId, $periodId));
            }
        }
        return ['unit' => $unit, 'period_id' => $periodId, 'items' => $items];
    }, $L);

    $r->post('/pelaksanaan', function (Request $q) {
        $periodId = $q->in('period_id'); $stdId = $q->in('standard_id'); $indId = $q->in('indicator_id');
        $uid = (int)($q->in('unit_id') ?: $q->user['unit_id']);
        if (kosong($periodId) || kosong($stdId) || kosong($indId) || !$uid)
            throw new HttpError('period_id, standard_id, indicator_id, dan unit_id wajib diisi', 400);
        if (!Auth::bisaAksesUnit($q->user, $uid) && !bisaIsiAtasNamaPelaksana($q->user['unit_id'], (int)$indId, $uid))
            throw new HttpError('Bukan unit Anda', 403);

        $skor = $q->in('skor');
        if ($skor !== null && $skor !== '') {
            $g = SkorSkala::validasi((float)$skor, SkorSkala::untukIndikator((int)$indId));
            if ($g) throw new HttpError($g, 400);
        }
        $ex = Db::one('SELECT * FROM implementations WHERE period_id=? AND indicator_id=? AND unit_id=?', [(int)$periodId, (int)$indId, $uid]);
        if ($ex && $ex['status'] === 'dinilai') throw new HttpError('Isian sudah dinilai auditor dan tidak dapat diubah', 400);

        $status = $q->in('status') ?? 'draft';
        $kirim = $status === 'diserahkan';
        $nilai = $q->in('capaian_nilai');
        $val = [$q->in('realisasi'), ($nilai === '' ? null : $nilai), ($skor === '' ? null : $skor),
                $q->in('bukti_uraian'), $q->in('bukti_url'), $q->in('kendala')];

        if ($ex) {
            Db::run('UPDATE implementations SET realisasi=?, capaian_nilai=?, skor=?, bukti_uraian=?, bukti_url=?, kendala=?,
                            status=?, submitted_by=?, submitted_at = CASE WHEN ?=1 THEN NOW() ELSE submitted_at END, updated_at = NOW()
                      WHERE id=?', array_merge($val, [$status, $q->user['id'], $kirim ? 1 : 0, $ex['id']]));
            Db::log((int)$q->user['id'], 'isi_pelaksanaan', 'implementations', (int)$ex['id']);
            return ['id' => (int)$ex['id'], 'ok' => true];
        }
        $id = Db::insert('INSERT INTO implementations (period_id, standard_id, indicator_id, unit_id, realisasi, capaian_nilai, skor,
                              bukti_uraian, bukti_url, kendala, status, submitted_by, submitted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            array_merge([(int)$periodId, (int)$stdId, (int)$indId, $uid], $val, [$status, $q->user['id'], $kirim ? waktuSekarang() : null]));
        Db::log((int)$q->user['id'], 'isi_pelaksanaan', 'implementations', $id);
        return new Respons(['id' => $id, 'ok' => true], 201);
    }, $AU);

    $r->delete('/pelaksanaan/:id', function (Request $q) {
        $ex = Db::one('SELECT * FROM implementations WHERE id=?', [(int)$q->params['id']]);
        if (!$ex) throw new HttpError('Isian tidak ditemukan', 404);
        if (!Auth::bisaAksesUnit($q->user, $ex['unit_id']) && !bisaIsiAtasNamaPelaksana($q->user['unit_id'], (int)$ex['indicator_id'], (int)$ex['unit_id']))
            throw new HttpError('Bukan unit Anda', 403);
        if ($ex['status'] === 'dinilai')
            throw new HttpError('Isian sudah dinilai auditor. Hapus dulu penilaiannya di Modul 3 sebelum menghapus isian ini.', 400);
        Db::run('DELETE FROM implementations WHERE id=?', [(int)$ex['id']]);
        Db::log((int)$q->user['id'], 'hapus_pelaksanaan', 'implementations', (int)$ex['id']);
        return ['ok' => true];
    }, $AU);

    $r->post('/pelaksanaan/serahkan', function (Request $q) {
        $periodId = (int)$q->in('period_id');
        $uid = (int)($q->in('unit_id') ?: $q->user['unit_id']);
        if (!Auth::bisaAksesUnit($q->user, $uid)) throw new HttpError('Bukan unit Anda', 403);

        $jumlah = Db::run("UPDATE implementations SET status='diserahkan', submitted_at=NOW(), submitted_by=?
                            WHERE period_id=? AND unit_id=? AND status='draft'", [$q->user['id'], $periodId, $uid]);
        $pasangan = Db::all("SELECT DISTINCT pengampu.indicator_id, pel.unit_id
                               FROM indicator_assignments pengampu
                               JOIN indicator_assignments pel ON pel.indicator_id = pengampu.indicator_id AND pel.peran = 'pelaksana'
                               JOIN standard_indicators i ON i.id = pengampu.indicator_id
                               LEFT JOIN ref_items b ON b.id = i.source_ref_item_id
                               LEFT JOIN refs r ON r.id = b.ref_id
                              WHERE pengampu.unit_id = ? AND pengampu.peran = 'pengampu' AND r.code IN ('LAMTEKNIKSTR','LAMTEKNIKD3')", [$uid]);
        foreach ($pasangan as $p) {
            $jumlah += Db::run("UPDATE implementations SET status='diserahkan', submitted_at=NOW(), submitted_by=?
                                 WHERE period_id=? AND indicator_id=? AND unit_id=? AND status='draft'",
                [$q->user['id'], $periodId, $p['indicator_id'], $p['unit_id']]);
        }
        Db::log((int)$q->user['id'], 'serahkan_pelaksanaan', 'units', $uid, "$jumlah indikator");
        return ['ok' => true, 'jumlah' => $jumlah];
    }, $AU);

    $r->get('/pelaksanaan/rekap', function (Request $q) {
        $pid = (int)$q->query('period_id', 0);
        if (!$pid) throw new HttpError('period_id wajib diisi', 400);
        $rows = Db::all("SELECT un.id AS unit_id, un.code, un.name, un.type,
                                COUNT(DISTINCT i.id) AS total_indikator,
                                COUNT(DISTINCT CASE WHEN ia.peran='pengampu' THEN i.id END) AS diampu,
                                COUNT(DISTINCT CASE WHEN im.status IN ('diserahkan','dinilai') THEN im.indicator_id END) AS terisi
                           FROM units un
                           JOIN indicator_assignments ia ON ia.unit_id = un.id
                           JOIN standard_indicators i ON i.id = ia.indicator_id
                           JOIN standards s ON s.id = i.standard_id AND s.period_id = ? AND s.status='ditetapkan'
                           LEFT JOIN implementations im ON im.indicator_id = i.id AND im.unit_id = un.id AND im.period_id = ?
                          WHERE un.is_active = 1
                          GROUP BY un.id, un.code, un.name, un.type ORDER BY un.type, un.name", [$pid, $pid]);
        foreach ($rows as &$r) $r['persen'] = $r['total_indikator'] ? (int)round($r['terisi'] / $r['total_indikator'] * 100) : 0;
        return $rows;
    }, $L);

    $r->post('/pelaksanaan/unggah-bukti', function (Request $q) {
        $nama = $q->in('filename'); $data = $q->in('data');
        if (kosong($nama) || kosong($data)) throw new HttpError('filename dan data wajib diisi', 400);
        if (!preg_match('#^data:([^;]+);base64,(.+)$#s', (string)$data, $m)) throw new HttpError('Format data URL tidak valid', 400);
        $buf = base64_decode($m[2], true);
        if ($buf === false) throw new HttpError('Data berkas tidak valid', 400);
        if (strlen($buf) > 10 * 1024 * 1024) throw new HttpError('Ukuran berkas maksimal 10 MB', 400);
        $safe = preg_replace('/[^\w.\-]/', '_', (string)$nama);
        $url = Berkas::simpan((int)(microtime(true) * 1000) . '-' . $q->user['id'] . '-' . $safe, $buf);
        return ['url' => $url, 'filename' => $safe];
    }, $L);
}
