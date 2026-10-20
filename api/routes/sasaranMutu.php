<?php
declare(strict_types=1);

/* SASARAN MUTU — padanan server/src/routes/sasaranMutu.js (kontrak JSON sama). */

const SM_JUMLAH_PERIODE = ['bulanan' => 12, 'triwulanan' => 4, 'semester' => 2];
const SM_LABEL = [
    'bulanan' => ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'],
    'triwulanan' => ['Triwulan I', 'Triwulan II', 'Triwulan III', 'Triwulan IV'],
    'semester' => ['Semester I', 'Semester II'],
];
const SM_JENIS = ['utama', 'tambahan'];
const SM_LABEL_SKALA = [
    'abc' => 'Peringkat (A / B / C)',
    'predikat' => 'Predikat (Unggul / Baik Sekali / Baik)',
    'opini' => 'Opini Audit (WTP / WDP / TW / TMP)',
];

/** Pesan galat tipe nilai, atau null bila sah. */
function sm_cekTipeNilai(array $s): ?string
{
    $tipe = $s['tipe_nilai'] ?? null;
    $tipe = kosong($tipe) ? 'angka' : $tipe;
    if (!in_array($tipe, Peringkat::TIPE, true)) return 'tipe_nilai harus "angka" atau "peringkat"';
    if ($tipe === 'angka') return null;
    $skala = $s['skala_peringkat'] ?? null;
    if (!is_string($skala) || !isset(Peringkat::SKALA[$skala])) return 'Skala peringkat tidak dikenal';
    $lbl = SM_LABEL_SKALA[$skala];
    if (!Peringkat::normal($skala, $s['target'] ?? null)) return 'Target "' . ($s['target'] ?? '') . "\" bukan level pada skala $lbl";
    foreach (($s['rencana'] ?? []) as $r) {
        $tp = $r['target_periode'] ?? null;
        if (!kosong($tp) && !in_array(trim((string)$tp), ['0', '-'], true) && !Peringkat::normal($skala, $tp))
            return "Rencana \"$tp\" bukan level pada skala $lbl";
    }
    return null;
}
function sm_tipe(array $s): string { return ($s['tipe_nilai'] ?? null) === 'peringkat' ? 'peringkat' : 'angka'; }
function sm_skala(array $s): ?string { return sm_tipe($s) === 'peringkat' ? ($s['skala_peringkat'] ?? null) : null; }

function sm_tahunValid(mixed $t): bool
{
    $now = (int)date('Y');
    return is_numeric($t) && in_array((int)$t, [$now, $now - 1], true);
}

function sm_subUnitValid(mixed $subId, int $uid): bool
{
    if (kosong($subId)) return true;
    $su = Db::one('SELECT * FROM units WHERE id=?', [(int)$subId]);
    return $su && $su['type'] === 'sub_unit' && (int)$su['parent_id'] === $uid;
}

function sm_kunciTtd(int $unitId, int $tahun): int { return $unitId * 100000 + $tahun; }

function sm_rencanaDefault(string $pe, mixed $rencana = null): array
{
    if (is_array($rencana) && count($rencana)) return $rencana;
    $out = [];
    for ($i = 0; $i < SM_JUMLAH_PERIODE[$pe]; $i++) $out[] = ['periode_ke' => $i + 1, 'label' => SM_LABEL[$pe][$i], 'target_periode' => null];
    return $out;
}

function sm_ambilLengkap(array $pk): array
{
    $list = Db::all('SELECT * FROM sasaran_mutu WHERE program_kerja_id=? ORDER BY sort_order, id', [$pk['id']]);
    $sasaran = [];
    foreach ($list as $sm) {
        $rencana = Db::all('SELECT periode_ke, label, target_periode FROM sasaran_mutu_rencana WHERE sasaran_mutu_id=? ORDER BY periode_ke', [$sm['id']]);
        $capaian = Db::all('SELECT periode_ke, realisasi, capaian_nilai, capaian_teks, bukti_uraian, bukti_url, kendala, faktor_pendukung, status, submitted_at
                              FROM sasaran_mutu_capaian WHERE sasaran_mutu_id=? ORDER BY periode_ke', [$sm['id']]);
        $byP = [];
        foreach ($capaian as $c) $byP[$c['periode_ke']] = $c;
        $gabung = [];
        foreach ($rencana as $r) {
            $g = array_merge($r, $byP[$r['periode_ke']] ?? []);
            if ($sm['tipe_nilai'] === 'peringkat' && !empty($g['capaian_teks']))
                $g['capaian_persen'] = Peringkat::persen($sm['skala_peringkat'], $sm['target'], $g['capaian_teks']);
            $gabung[] = $g;
        }
        $nilai = Db::one('SELECT aa.skor, aa.capaian_persen, aa.verdict, aa.catatan_auditor, aa.verifikasi_bukti, a.status AS audit_status
                            FROM audit_assessments aa JOIN audits a ON a.id = aa.audit_id
                           WHERE aa.sasaran_mutu_id=? ORDER BY aa.updated_at DESC LIMIT 1', [$sm['id']]);
        $terisi = 0;
        foreach ($capaian as $c) if ($c['status'] === 'diserahkan') $terisi++;
        $sasaran[] = array_merge($sm, ['rencana' => $gabung, 'terisi' => $terisi, 'total_periode' => count($rencana), 'penilaian' => $nilai]);
    }
    return array_merge($pk, ['sasaran' => $sasaran]);
}

function rute_sasaranMutu(Router $r): void
{
    $L  = [[Auth::class, 'wajibLogin']];
    $AU = [[Auth::class, 'wajibLogin'], Auth::peran('auditee', 'spm')];
    $B  = '/sasaran-mutu';

    /* --- Rute statis lebih dulu agar tidak tertangkap sebagai :id --- */
    $r->get("$B/tanda-tangan", function (Request $q) {
        $unitId = (int)$q->query('unit_id', 0);
        $tahun = (int)$q->query('tahun', 0);
        if (!$unitId || !$tahun) throw new HttpError('unit_id dan tahun wajib diisi', 400);
        return Db::one("SELECT * FROM signatures WHERE entity_type='sasaran_mutu' AND entity_id=?", [sm_kunciTtd($unitId, $tahun)]);
    }, $L);

    $r->post("$B/tanda-tangan", function (Request $q) {
        $unitId = (int)$q->in('unit_id');
        $th = (int)$q->in('tahun');
        $img = $q->in('image_data');
        if (!$unitId || !$th) throw new HttpError('unit_id dan tahun wajib diisi', 400);
        if (!Auth::bisaAksesUnit($q->user, $unitId)) throw new HttpError('Bukan unit Anda', 403);
        if (kosong($img)) throw new HttpError('image_data wajib diisi', 400);
        if (!preg_match('#^data:image/(png|jpeg);base64,#', (string)$img)) throw new HttpError('Tanda tangan harus berupa gambar', 400);
        Db::run("INSERT INTO signatures (entity_type, entity_id, user_id, peran, nama, jabatan, image_data)
                 VALUES ('sasaran_mutu',?,?,'Kepala Unit/Bagian',?,?,?)
                 ON DUPLICATE KEY UPDATE image_data=VALUES(image_data), nama=VALUES(nama), jabatan=VALUES(jabatan), signed_at=NOW()",
            [sm_kunciTtd($unitId, $th), $q->user['id'], $q->user['name'], $q->user['jabatan'], $img]);
        Db::log((int)$q->user['id'], 'tanda_tangan_sasaran_mutu', 'program_kerja', null, "Unit $unitId tahun $th");
        return ['ok' => true];
    }, $AU);

    $r->delete("$B/tanda-tangan", function (Request $q) {
        $unitId = (int)$q->query('unit_id', 0);
        $tahun = (int)$q->query('tahun', 0);
        if (!$unitId || !$tahun) throw new HttpError('unit_id dan tahun wajib diisi', 400);
        if (!Auth::bisaAksesUnit($q->user, $unitId)) throw new HttpError('Bukan unit Anda', 403);
        Db::run("DELETE FROM signatures WHERE entity_type='sasaran_mutu' AND entity_id=?", [sm_kunciTtd($unitId, $tahun)]);
        return ['ok' => true];
    }, $AU);

    $r->get("$B/laporan/cetak", function (Request $q) {
        $unitId = (int)$q->query('unit_id', 0);
        $periodId = (int)$q->query('period_id', 0);
        $tahun = $q->query('tahun') ? (int)$q->query('tahun') : null;
        if (!$unitId || !$periodId) throw new HttpError('unit_id dan period_id wajib diisi', 400);
        if (!Auth::bisaAksesUnit($q->user, $unitId)) throw new HttpError('Bukan unit Anda', 403);
        $unit = Db::one('SELECT * FROM units WHERE id=?', [$unitId]);
        $period = Db::one('SELECT * FROM periods WHERE id=?', [$periodId]);
        $daftar = Db::all("SELECT pk.*, su.name AS sub_unit_name FROM program_kerja pk LEFT JOIN units su ON su.id = pk.sub_unit_id
                            WHERE pk.unit_id=? AND pk.period_id=? AND pk.status='ditetapkan' AND (? IS NULL OR pk.tahun=?)
                            ORDER BY pk.tahun DESC, pk.jenis, pk.id", [$unitId, $periodId, $tahun, $tahun]);
        return ['unit' => $unit, 'period' => $period, 'tahun' => $tahun, 'programKerja' => array_map('sm_ambilLengkap', $daftar)];
    }, $L);

    $r->get("$B/laporan/rekap", function (Request $q) {
        $pid = (int)$q->query('period_id', 0);
        if (!$pid) throw new HttpError('period_id wajib diisi', 400);
        return Db::all("SELECT un.id AS unit_id, un.code, un.name, un.type, COUNT(DISTINCT pk.id) AS total_program,
                               COUNT(DISTINCT CASE WHEN pk.status='ditetapkan' THEN pk.id END) AS ditetapkan
                          FROM units un LEFT JOIN program_kerja pk ON pk.unit_id = un.id AND pk.period_id = ?
                         WHERE un.is_active = 1 GROUP BY un.id, un.code, un.name, un.type
                        HAVING total_program > 0 ORDER BY un.type, un.name", [$pid]);
    }, $L);

    $r->get("$B/laporan/tahun-tersedia", function (Request $q) {
        $unitId = (int)$q->query('unit_id', 0);
        if (!$unitId) throw new HttpError('unit_id wajib diisi', 400);
        if (!Auth::bisaAksesUnit($q->user, $unitId)) throw new HttpError('Bukan unit Anda', 403);
        return array_column(Db::all('SELECT DISTINCT tahun FROM program_kerja WHERE unit_id=? ORDER BY tahun DESC', [$unitId]), 'tahun');
    }, $L);

    $r->post("$B/duplikasi", function (Request $q) {
        $uid = (int)$q->in('unit_id');
        $src = (int)$q->in('source_tahun');
        $tgt = (int)$q->in('target_tahun');
        $periodId = $q->in('period_id');
        if (!$uid || !$src || !$tgt || kosong($periodId)) throw new HttpError('unit_id, source_tahun, target_tahun, dan period_id wajib diisi', 400);
        if (!Auth::bisaAksesUnit($q->user, $uid)) throw new HttpError('Bukan unit Anda', 403);
        if ($src === $tgt) throw new HttpError('Tahun sumber dan tujuan tidak boleh sama', 400);
        if (!sm_tahunValid($tgt)) throw new HttpError('Tahun tujuan hanya boleh tahun berjalan atau tahun sebelumnya', 400);

        $sumber = Db::all('SELECT * FROM program_kerja WHERE unit_id=? AND tahun=?', [$uid, $src]);
        $ids = $q->in('program_kerja_ids');
        if (is_array($ids) && count($ids)) {
            $set = array_map('intval', $ids);
            $sumber = array_values(array_filter($sumber, fn($pk) => in_array((int)$pk['id'], $set, true)));
        }
        if (!$sumber) throw new HttpError('Tidak ada program kerja terpilih dari tahun sumber', 400);

        $terpakai = [];
        foreach (Db::all('SELECT jenis, nama, sub_unit_id FROM program_kerja WHERE unit_id=? AND tahun=?', [$uid, $tgt]) as $x)
            $terpakai[$x['jenis'] . '::' . $x['nama'] . '::' . ($x['sub_unit_id'] ?? '')] = true;

        $hasil = ['diduplikasi' => [], 'dilewati' => []];
        Db::transaksi(function () use ($sumber, &$terpakai, &$hasil, $uid, $tgt, $periodId, $q) {
            foreach ($sumber as $pk) {
                $kunci = $pk['jenis'] . '::' . $pk['nama'] . '::' . ($pk['sub_unit_id'] ?? '');
                if (isset($terpakai[$kunci])) { $hasil['dilewati'][] = $pk['nama']; continue; }
                $baru = Db::insert('INSERT INTO program_kerja (unit_id, sub_unit_id, period_id, tahun, jenis, nama, created_by) VALUES (?,?,?,?,?,?,?)',
                    [$uid, $pk['sub_unit_id'], (int)$periodId, $tgt, $pk['jenis'], $pk['nama'], $q->user['id']]);
                foreach (Db::all('SELECT * FROM sasaran_mutu WHERE program_kerja_id=? ORDER BY sort_order, id', [$pk['id']]) as $s) {
                    $smBaru = Db::insert('INSERT INTO sasaran_mutu (program_kerja_id, sasaran, target, satuan, periode_evaluasi, sort_order, tipe_nilai, skala_peringkat) VALUES (?,?,?,?,?,?,?,?)',
                        [$baru, $s['sasaran'], $s['target'], $s['satuan'], $s['periode_evaluasi'], $s['sort_order'], $s['tipe_nilai'] ?: 'angka', $s['skala_peringkat']]);
                    foreach (sm_rencanaDefault($s['periode_evaluasi']) as $rn)
                        Db::run('INSERT INTO sasaran_mutu_rencana (sasaran_mutu_id, periode_ke, label, target_periode) VALUES (?,?,?,NULL)', [$smBaru, $rn['periode_ke'], $rn['label']]);
                }
                $hasil['diduplikasi'][] = $pk['nama'];
                $terpakai[$kunci] = true;
            }
        });
        Db::log((int)$q->user['id'], 'duplikasi_program_kerja', 'program_kerja', null,
            count($hasil['diduplikasi']) . " program kerja diduplikasi dari tahun $src ke tahun $tgt (unit $uid)");
        return $hasil;
    }, $AU);

    $r->post("$B/sasaran/:sasaranId/capaian", function (Request $q) {
        $sm = Db::one('SELECT sm.*, pk.unit_id, pk.status AS status_program FROM sasaran_mutu sm
                         JOIN program_kerja pk ON pk.id = sm.program_kerja_id WHERE sm.id=?', [(int)$q->params['sasaranId']]);
        if (!$sm) throw new HttpError('Sasaran mutu tidak ditemukan', 404);
        if (!Auth::bisaAksesUnit($q->user, $sm['unit_id'])) throw new HttpError('Bukan unit Anda', 403);
        if ($sm['status_program'] !== 'ditetapkan') throw new HttpError('Program kerja belum ditetapkan', 400);

        $periode = $q->in('periode_ke');
        if (kosong($periode)) throw new HttpError('periode_ke wajib diisi', 400);
        $status = $q->in('status') ?? 'draft';
        $nilai = $q->in('capaian_nilai');
        $teks = $q->in('capaian_teks');

        if ($sm['tipe_nilai'] === 'peringkat') {
            $nilai = null;
            if ($teks === null || $teks === '') {
                $teks = null;
            } else {
                $baku = Peringkat::normal($sm['skala_peringkat'], $teks);
                if (!$baku) throw new HttpError("Capaian \"$teks\" bukan level pada skala " . (SM_LABEL_SKALA[$sm['skala_peringkat']] ?? '-'), 400);
                $teks = $baku;
            }
        } else {
            $teks = null;
            if ($nilai === '') $nilai = null;
        }

        if (!Db::one('SELECT 1 AS x FROM sasaran_mutu_rencana WHERE sasaran_mutu_id=? AND periode_ke=?', [$sm['id'], (int)$periode]))
            throw new HttpError('Sub-periode tidak dikenal untuk sasaran ini', 400);

        $ex = Db::one('SELECT * FROM sasaran_mutu_capaian WHERE sasaran_mutu_id=? AND periode_ke=?', [$sm['id'], (int)$periode]);
        $kirim = $status === 'diserahkan';
        $v = [$q->in('realisasi'), $nilai, $teks, $q->in('bukti_uraian'), $q->in('bukti_url'), $q->in('kendala'), $q->in('faktor_pendukung')];

        if ($ex) {
            Db::run('UPDATE sasaran_mutu_capaian SET realisasi=?, capaian_nilai=?, capaian_teks=?, bukti_uraian=?, bukti_url=?, kendala=?, faktor_pendukung=?,
                            status=?, submitted_by=?, submitted_at = CASE WHEN ?=1 THEN NOW() ELSE submitted_at END, updated_at = NOW() WHERE id=?',
                array_merge($v, [$status, $q->user['id'], $kirim ? 1 : 0, $ex['id']]));
            Db::log((int)$q->user['id'], 'isi_capaian_sasaran_mutu', 'sasaran_mutu_capaian', (int)$ex['id']);
            return ['id' => (int)$ex['id'], 'ok' => true];
        }
        $id = Db::insert('INSERT INTO sasaran_mutu_capaian (sasaran_mutu_id, periode_ke, realisasi, capaian_nilai, capaian_teks, bukti_uraian, bukti_url,
                              kendala, faktor_pendukung, status, submitted_by, submitted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            array_merge([$sm['id'], (int)$periode], $v, [$status, $q->user['id'], $kirim ? waktuSekarang() : null]));
        Db::log((int)$q->user['id'], 'isi_capaian_sasaran_mutu', 'sasaran_mutu_capaian', $id);
        return new Respons(['id' => $id, 'ok' => true], 201);
    }, $AU);

    /* ------------------------- Daftar & detail ------------------------- */
    $r->get($B, function (Request $q) {
        $unitId = (int)$q->query('unit_id', 0);
        $periodId = (int)$q->query('period_id', 0);
        $tahun = $q->query('tahun') ? (int)$q->query('tahun') : null;
        if (!$unitId || !$periodId) throw new HttpError('unit_id dan period_id wajib diisi', 400);
        if (!Auth::bisaAksesUnit($q->user, $unitId)) throw new HttpError('Bukan unit Anda', 403);
        $daftar = Db::all('SELECT pk.*, u.name AS unit_name, u.code AS unit_code, su.name AS sub_unit_name
                             FROM program_kerja pk JOIN units u ON u.id = pk.unit_id LEFT JOIN units su ON su.id = pk.sub_unit_id
                            WHERE pk.unit_id=? AND pk.period_id=? AND (? IS NULL OR pk.tahun=?)
                            ORDER BY pk.tahun DESC, pk.jenis, pk.id', [$unitId, $periodId, $tahun, $tahun]);
        return array_map('sm_ambilLengkap', $daftar);
    }, $L);

    $r->get("$B/:id", function (Request $q) {
        $pk = Db::one('SELECT pk.*, u.name AS unit_name, su.name AS sub_unit_name FROM program_kerja pk
                         JOIN units u ON u.id = pk.unit_id LEFT JOIN units su ON su.id = pk.sub_unit_id WHERE pk.id=?', [(int)$q->params['id']]);
        if (!$pk) throw new HttpError('Program kerja tidak ditemukan', 404);
        if (!Auth::bisaAksesUnit($q->user, $pk['unit_id'])) throw new HttpError('Bukan unit Anda', 403);
        return sm_ambilLengkap($pk);
    }, $L);

    /* --------------------------- Buat program kerja -------------------- */
    $r->post($B, function (Request $q) {
        $uid = (int)($q->in('unit_id') ?: $q->user['unit_id']);
        $periodId = $q->in('period_id');
        $nama = $q->in('nama');
        $tahun = $q->in('tahun') ?? (int)date('Y');
        $jenis = $q->in('jenis') ?? 'utama';
        $subUnit = $q->in('sub_unit_id');
        $sasaran = $q->in('sasaran');
        if (!$uid || kosong($periodId) || kosong($nama)) throw new HttpError('unit_id, period_id, dan nama program kerja wajib diisi', 400);
        if (!Auth::bisaAksesUnit($q->user, $uid)) throw new HttpError('Bukan unit Anda', 403);
        if (!in_array($jenis, SM_JENIS, true)) throw new HttpError('jenis harus "utama" atau "tambahan"', 400);
        if (!sm_tahunValid($tahun)) throw new HttpError('Tahun hanya boleh tahun berjalan atau tahun sebelumnya', 400);
        if (!sm_subUnitValid($subUnit, $uid)) throw new HttpError('Sub-unit kerja tidak valid untuk unit ini', 400);
        if (!is_array($sasaran) || !count($sasaran)) throw new HttpError('Minimal satu sasaran wajib diisi', 400);
        foreach ($sasaran as $s) {
            if (kosong($s['sasaran'] ?? null) || kosong($s['target'] ?? null)) throw new HttpError('Setiap sasaran wajib memiliki uraian sasaran dan target', 400);
            $pe = kosong($s['periode_evaluasi'] ?? null) ? 'bulanan' : $s['periode_evaluasi'];
            if (!isset(SM_JUMLAH_PERIODE[$pe])) throw new HttpError('periode_evaluasi tidak valid', 400);
            if ($g = sm_cekTipeNilai($s)) throw new HttpError($g, 400);
        }
        $id = Db::transaksi(function () use ($uid, $subUnit, $periodId, $tahun, $jenis, $nama, $sasaran, $q) {
            $pkId = Db::insert('INSERT INTO program_kerja (unit_id, sub_unit_id, period_id, tahun, jenis, nama, created_by) VALUES (?,?,?,?,?,?,?)',
                [$uid, $subUnit ?: null, (int)$periodId, (int)$tahun, $jenis, $nama, $q->user['id']]);
            foreach (array_values($sasaran) as $idx => $s) {
                $pe = kosong($s['periode_evaluasi'] ?? null) ? 'bulanan' : $s['periode_evaluasi'];
                $smId = Db::insert('INSERT INTO sasaran_mutu (program_kerja_id, sasaran, target, satuan, periode_evaluasi, sort_order, tipe_nilai, skala_peringkat) VALUES (?,?,?,?,?,?,?,?)',
                    [$pkId, $s['sasaran'], (string)$s['target'], $s['satuan'] ?? null, $pe, $idx, sm_tipe($s), sm_skala($s)]);
                foreach (sm_rencanaDefault($pe, $s['rencana'] ?? null) as $rn)
                    Db::run('INSERT INTO sasaran_mutu_rencana (sasaran_mutu_id, periode_ke, label, target_periode) VALUES (?,?,?,?)',
                        [$smId, $rn['periode_ke'], $rn['label'], isset($rn['target_periode']) ? (string)$rn['target_periode'] : null]);
            }
            return $pkId;
        });
        Db::log((int)$q->user['id'], 'buat_program_kerja', 'program_kerja', $id, (string)$nama);
        return new Respons(['id' => $id, 'ok' => true], 201);
    }, $AU);

    /* --------------------------- Ubah program kerja -------------------- */
    $r->put("$B/:id", function (Request $q) {
        $pk = Db::one('SELECT * FROM program_kerja WHERE id=?', [(int)$q->params['id']]);
        if (!$pk) throw new HttpError('Program kerja tidak ditemukan', 404);
        if (!Auth::bisaAksesUnit($q->user, $pk['unit_id'])) throw new HttpError('Bukan unit Anda', 403);
        if ($pk['status'] === 'ditetapkan') throw new HttpError('Sudah ditetapkan. Batalkan penetapan dahulu untuk mengubah.', 400);

        $b = $q->body();
        $jenis = $b['jenis'] ?? null;
        $nama = $b['nama'] ?? null;
        $sasaran = $b['sasaran'] ?? null;
        if (!kosong($jenis) && !in_array($jenis, SM_JENIS, true)) throw new HttpError('jenis harus "utama" atau "tambahan"', 400);
        if (array_key_exists('tahun', $b) && !sm_tahunValid($b['tahun'])) throw new HttpError('Tahun hanya boleh tahun berjalan atau tahun sebelumnya', 400);
        if (array_key_exists('sub_unit_id', $b) && !sm_subUnitValid($b['sub_unit_id'], (int)$pk['unit_id'])) throw new HttpError('Sub-unit kerja tidak valid untuk unit ini', 400);
        if (is_array($sasaran)) foreach ($sasaran as $s) if ($g = sm_cekTipeNilai($s)) throw new HttpError($g, 400);

        Db::transaksi(function () use ($pk, $b, $jenis, $nama, $sasaran) {
            Db::run('UPDATE program_kerja SET jenis=?, nama=?, sub_unit_id=?, tahun=? WHERE id=?', [
                $jenis ?? $pk['jenis'], $nama ?? $pk['nama'],
                array_key_exists('sub_unit_id', $b) ? ($b['sub_unit_id'] ?: null) : $pk['sub_unit_id'],
                array_key_exists('tahun', $b) ? (int)$b['tahun'] : $pk['tahun'], $pk['id'],
            ]);
            if (!is_array($sasaran)) return;

            $ada = array_map('intval', array_column(Db::all('SELECT id FROM sasaran_mutu WHERE program_kerja_id=?', [$pk['id']]), 'id'));
            $tahan = [];
            foreach ($sasaran as $s) if (!kosong($s['id'] ?? null)) $tahan[] = (int)$s['id'];
            foreach ($ada as $smId) {
                if (in_array($smId, $tahan, true)) continue;
                if (Db::one("SELECT 1 AS x FROM sasaran_mutu_capaian WHERE sasaran_mutu_id=? AND status='diserahkan'", [$smId]))
                    throw new HttpError('Salah satu sasaran yang dihapus sudah memiliki capaian yang diserahkan', 400);
                Db::run('DELETE FROM sasaran_mutu WHERE id=?', [$smId]);
            }
            foreach (array_values($sasaran) as $idx => $s) {
                $pe = kosong($s['periode_evaluasi'] ?? null) ? 'bulanan' : $s['periode_evaluasi'];
                if (!isset(SM_JUMLAH_PERIODE[$pe])) throw new HttpError('periode_evaluasi tidak valid', 400);
                if (!kosong($s['id'] ?? null)) {
                    $sid = (int)$s['id'];
                    if (!in_array($sid, $ada, true)) throw new HttpError('Sasaran tidak termasuk program kerja ini', 400);
                    Db::run('UPDATE sasaran_mutu SET sasaran=?, target=?, satuan=?, periode_evaluasi=?, sort_order=?, tipe_nilai=?, skala_peringkat=? WHERE id=?',
                        [$s['sasaran'] ?? '', (string)($s['target'] ?? ''), $s['satuan'] ?? null, $pe, $idx, sm_tipe($s), sm_skala($s), $sid]);
                    if (isset($s['rencana']) && is_array($s['rencana'])) {
                        Db::run('DELETE FROM sasaran_mutu_rencana WHERE sasaran_mutu_id=?', [$sid]);
                        foreach ($s['rencana'] as $rn)
                            Db::run('INSERT INTO sasaran_mutu_rencana (sasaran_mutu_id, periode_ke, label, target_periode) VALUES (?,?,?,?)',
                                [$sid, $rn['periode_ke'], $rn['label'], isset($rn['target_periode']) ? (string)$rn['target_periode'] : null]);
                    }
                } else {
                    $smId = Db::insert('INSERT INTO sasaran_mutu (program_kerja_id, sasaran, target, satuan, periode_evaluasi, sort_order, tipe_nilai, skala_peringkat) VALUES (?,?,?,?,?,?,?,?)',
                        [$pk['id'], $s['sasaran'] ?? '', (string)($s['target'] ?? ''), $s['satuan'] ?? null, $pe, $idx, sm_tipe($s), sm_skala($s)]);
                    foreach (sm_rencanaDefault($pe, $s['rencana'] ?? null) as $rn)
                        Db::run('INSERT INTO sasaran_mutu_rencana (sasaran_mutu_id, periode_ke, label, target_periode) VALUES (?,?,?,?)',
                            [$smId, $rn['periode_ke'], $rn['label'], isset($rn['target_periode']) ? (string)$rn['target_periode'] : null]);
                }
            }
        });
        Db::log((int)$q->user['id'], 'ubah_program_kerja', 'program_kerja', (int)$pk['id']);
        return ['ok' => true];
    }, $AU);

    $r->post("$B/:id/tetapkan", function (Request $q) {
        $pk = Db::one('SELECT * FROM program_kerja WHERE id=?', [(int)$q->params['id']]);
        if (!$pk) throw new HttpError('Program kerja tidak ditemukan', 404);
        if (!Auth::bisaAksesUnit($q->user, $pk['unit_id'])) throw new HttpError('Bukan unit Anda', 403);
        if (!Db::one('SELECT 1 AS x FROM sasaran_mutu WHERE program_kerja_id=?', [$pk['id']])) throw new HttpError('Minimal satu sasaran wajib diisi sebelum ditetapkan', 400);
        Db::run("UPDATE program_kerja SET status='ditetapkan', ditetapkan_at=NOW(), ditetapkan_by=? WHERE id=?", [$q->user['id'], $pk['id']]);
        Db::log((int)$q->user['id'], 'tetapkan_program_kerja', 'program_kerja', (int)$pk['id']);
        return ['ok' => true];
    }, $AU);

    $r->post("$B/:id/batal-tetapkan", function (Request $q) {
        $pk = Db::one('SELECT * FROM program_kerja WHERE id=?', [(int)$q->params['id']]);
        if (!$pk) throw new HttpError('Program kerja tidak ditemukan', 404);
        if (!Auth::bisaAksesUnit($q->user, $pk['unit_id'])) throw new HttpError('Bukan unit Anda', 403);
        Db::run("UPDATE program_kerja SET status='draft', ditetapkan_at=NULL, ditetapkan_by=NULL WHERE id=?", [$pk['id']]);
        return ['ok' => true];
    }, $AU);

    $r->delete("$B/:id", function (Request $q) {
        $pk = Db::one('SELECT * FROM program_kerja WHERE id=?', [(int)$q->params['id']]);
        if (!$pk) throw new HttpError('Program kerja tidak ditemukan', 404);
        if (!Auth::bisaAksesUnit($q->user, $pk['unit_id'])) throw new HttpError('Bukan unit Anda', 403);
        if ($pk['status'] === 'ditetapkan') throw new HttpError('Sudah ditetapkan. Batalkan penetapan dahulu untuk menghapus.', 400);
        if (Db::one("SELECT 1 AS x FROM sasaran_mutu_capaian c JOIN sasaran_mutu sm ON sm.id = c.sasaran_mutu_id
                      WHERE sm.program_kerja_id=? AND c.status='diserahkan'", [$pk['id']]))
            throw new HttpError('Sudah ada capaian yang diserahkan pada program kerja ini', 400);
        Db::run('DELETE FROM program_kerja WHERE id=?', [$pk['id']]);
        Db::log((int)$q->user['id'], 'hapus_program_kerja', 'program_kerja', (int)$pk['id'], $pk['nama']);
        return ['ok' => true];
    }, $AU);
}
