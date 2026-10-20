<?php
declare(strict_types=1);

/* MODUL 5 — RAPAT TINJAUAN MANAJEMEN (padanan server/src/routes/rtm.js) */

const RTM_AGENDA = [
    ['1', null, 'Status RTM sebelumnya'],
    ['2', null, 'Perubahan dalam masalah eksternal dan internal yang relevan dengan SMOP'],
    ['3', null, 'Informasi tentang kinerja dan efektivitas SMOP'],
    ['3a', '3', 'Kepuasan pemangku kepentingan (IKM)'],
    ['3b', '3', 'Pencapaian kinerja / Sasaran mutu'],
    ['3c', '3', 'Efektivitas dan kesesuaian produk dan layanan'],
    ['3d', '3', 'Ketidaksesuaian dan tindakan koreksi'],
    ['3e', '3', 'Pemantauan dan pengukuran hasil'],
    ['3f', '3', 'Hasil Audit'],
    ['3g', '3', 'Kinerja penyedia eksternal'],
    ['3h', '3', 'Nilai formatif dan sumatif'],
    ['4', null, 'Kecukupan Sumber Daya'],
    ['5', null, 'Efektivitas identifikasi risiko dan peluang'],
    ['6', null, 'Peluang untuk perbaikan berkelanjutan'],
    ['7', null, 'Umpan balik staf berkaitan dengan peningkatan kompetensinya'],
];
const RTM_KELUARAN = [
    ['peluang_perbaikan', 'Peluang untuk perbaikan yang diprogramkan'],
    ['perubahan_smop', 'Kebutuhan perubahan untuk SMOP'],
    ['kebutuhan_sumber_daya', 'Kebutuhan sumber daya'],
];
const RTM_EDITABLE = ['rencana', 'ditolak_spm'];

function rtm_ambil(mixed $id): array
{
    $r = Db::one('SELECT * FROM rtm_meetings WHERE id=?', [(int)$id]);
    if (!$r) throw new HttpError('RTM tidak ditemukan', 404);
    return $r;
}

function rtm_bolehEdit(array $r): void
{
    if (!in_array($r['status'], RTM_EDITABLE, true))
        throw new HttpError('Notulen sedang dalam proses pengesahan dan tidak dapat diubah. Tarik pengesahan notulen terlebih dahulu bila ingin mengubah isi rapat.', 400);
}

function rtm_simpanTtd(int $rtmId, string $peran, array $user, string $img): void
{
    Db::run("INSERT INTO signatures (entity_type, entity_id, user_id, peran, nama, jabatan, image_data) VALUES ('rtm',?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE image_data=VALUES(image_data), signed_at=NOW()", [$rtmId, $user['id'], $peran, $user['name'], $user['jabatan'], $img]);
}

function rtm_gambar(Request $q): string
{
    $img = $q->in('image_data');
    if (kosong($img)) throw new HttpError('image_data wajib diisi', 400);
    if (!preg_match('#^data:image/(png|jpeg);base64,#', (string)$img)) throw new HttpError('Tanda tangan harus berupa gambar', 400);
    return (string)$img;
}

function rtm_bolehVerifikasiSpm(Request $q): bool
{
    if ($q->user['role'] === 'admin') return true;
    return $q->user['role'] === 'spm' && preg_match('/kepala|ketua/i', (string)($q->user['jabatan'] ?? '')) === 1;
}

function rute_rtm(Router $r): void
{
    $L = [[Auth::class, 'wajibLogin']];
    $PS = [[Auth::class, 'wajibLogin'], Auth::peran('pimpinan', 'spm')];
    $B = '/rtm';

    $r->get("$B/meta/agenda-template", fn() => [
        'agenda' => array_map(fn($a) => ['key' => $a[0], 'parent' => $a[1], 'judul' => $a[2]], RTM_AGENDA),
        'keluaran' => array_map(fn($k) => ['key' => $k[0], 'label' => $k[1]], RTM_KELUARAN),
    ], $L);

    $r->get($B, function (Request $q) {
        $pid = $q->query('period_id');
        return Db::all('SELECT r.*, p.name AS period_name, u.name AS pemimpin_name, u.jabatan AS pemimpin_jabatan, n.name AS notulis_name,
                               (SELECT COUNT(*) FROM rtm_outputs o WHERE o.rtm_id=r.id) AS jumlah_keluaran,
                               (SELECT COUNT(*) FROM rtm_participants c WHERE c.rtm_id=r.id AND c.hadir=1) AS jumlah_hadir
                          FROM rtm_meetings r JOIN periods p ON p.id=r.period_id LEFT JOIN users u ON u.id=r.pemimpin_id LEFT JOIN users n ON n.id=r.notulis_id '
            . (!kosong($pid) ? 'WHERE r.period_id=? ' : '') . 'ORDER BY r.tanggal DESC, r.id DESC', !kosong($pid) ? [(int)$pid] : []);
    }, $L);

    $r->post($B, function (Request $q) {
        $periodId = idNull($q->in('period_id')); $judul = $q->in('judul');
        if (!$periodId || kosong($judul)) throw new HttpError('period_id dan judul wajib diisi', 400);
        $tahun = Db::val('SELECT year FROM periods WHERE id=?', [$periodId]);
        $urut = (int)Db::val('SELECT COUNT(*) FROM rtm_meetings WHERE period_id=?', [$periodId]) + 1;
        $nomor = 'RTM/' . str_pad((string)$urut, 2, '0', STR_PAD_LEFT) . '/PKTJ/' . ($tahun ?: '');
        $id = Db::transaksi(function () use ($q, $periodId, $judul, $nomor) {
            $id = Db::insert('INSERT INTO rtm_meetings (period_id, nomor, judul, tanggal, waktu, tempat, pemimpin_id, notulis_id, created_by) VALUES (?,?,?,?,?,?,?,?,?)',
                [$periodId, $nomor, $judul, $q->in('tanggal'), $q->in('waktu'), $q->in('tempat'), idNull($q->in('pemimpin_id')), idNull($q->in('notulis_id')), $q->user['id']]);
            foreach (RTM_AGENDA as $n => $a)
                Db::run('INSERT INTO rtm_agenda (rtm_id, agenda_key, parent_key, judul, sort_order) VALUES (?,?,?,?,?)', [$id, $a[0], $a[1], $a[2], $n]);
            foreach (Db::all("SELECT id, name, jabatan FROM users WHERE is_active=1 AND role IN ('pimpinan','spm') ORDER BY role, name") as $u)
                Db::run('INSERT IGNORE INTO rtm_participants (rtm_id, user_id, nama, jabatan) VALUES (?,?,?,?)', [$id, $u['id'], $u['name'], $u['jabatan']]);
            return $id;
        });
        Db::log((int)$q->user['id'], 'buat_rtm', 'rtm_meetings', $id, (string)$judul);
        return new Respons(['id' => $id, 'nomor' => $nomor], 201);
    }, $PS);

    $r->get("$B/:id", function (Request $q) {
        $rtm = Db::one('SELECT r.*, p.name AS period_name, p.year, u.name AS pemimpin_name, u.jabatan AS pemimpin_jabatan, n.name AS notulis_name, n.jabatan AS notulis_jabatan
                          FROM rtm_meetings r JOIN periods p ON p.id=r.period_id LEFT JOIN users u ON u.id=r.pemimpin_id LEFT JOIN users n ON n.id=r.notulis_id WHERE r.id=?', [(int)$q->params['id']]);
        if (!$rtm) throw new HttpError('RTM tidak ditemukan', 404);
        $id = $rtm['id'];
        $rtm['agenda'] = Db::all('SELECT * FROM rtm_agenda WHERE rtm_id=? ORDER BY sort_order', [$id]);
        $rtm['peserta'] = Db::all('SELECT * FROM rtm_participants WHERE rtm_id=? ORDER BY id', [$id]);
        $rtm['keluaran'] = Db::all('SELECT o.*, un.name AS unit_name FROM rtm_outputs o LEFT JOIN units un ON un.id=o.unit_id WHERE o.rtm_id=? ORDER BY o.kategori, o.sort_order, o.id', [$id]);
        $rtm['tanda_tangan'] = Db::all("SELECT * FROM signatures WHERE entity_type='rtm' AND entity_id=?", [$id]);
        return $rtm;
    }, $L);

    $r->put("$B/:id", function (Request $q) {
        $r = rtm_ambil($q->params['id']);
        rtm_bolehEdit($r);
        $p = fn($k) => $q->in($k) ?? $r[$k];
        Db::run('UPDATE rtm_meetings SET nomor=?, judul=?, tanggal=?, waktu=?, tempat=?, pemimpin_id=?, notulis_id=?, status=?, catatan=? WHERE id=?',
            [$p('nomor'), $p('judul'), $p('tanggal'), $p('waktu'), $p('tempat'), idNull($p('pemimpin_id')), idNull($p('notulis_id')), $p('status'), $p('catatan'), $r['id']]);
        Db::log((int)$q->user['id'], 'ubah_jadwal_rtm', 'rtm_meetings', (int)$r['id']);
        return ['ok' => true];
    }, $PS);

    $r->delete("$B/:id", function (Request $q) {
        $r = rtm_ambil($q->params['id']);
        if ($q->user['role'] !== 'admin') rtm_bolehEdit($r);
        Db::transaksi(function () use ($r) {
            Db::run("DELETE FROM signatures WHERE entity_type='rtm' AND entity_id=?", [$r['id']]);
            Db::run('DELETE FROM rtm_meetings WHERE id=?', [$r['id']]);
        });
        Db::log((int)$q->user['id'], 'hapus_jadwal_rtm', 'rtm_meetings', (int)$r['id'], (string)$r['judul']);
        return ['ok' => true];
    }, $PS);

    /* ------------------------------ AGENDA ----------------------------- */
    $r->put("$B/:id/agenda/:agendaId", function (Request $q) {
        $r = rtm_ambil($q->params['id']);
        rtm_bolehEdit($r);
        Db::run('UPDATE rtm_agenda SET pembahasan=?, data_pendukung=?, kesimpulan=?, pic=? WHERE id=? AND rtm_id=?',
            [$q->in('pembahasan'), $q->in('data_pendukung'), $q->in('kesimpulan'), $q->in('pic'), (int)$q->params['agendaId'], $r['id']]);
        return ['ok' => true];
    }, $PS);

    /* ------------------------------ PESERTA ---------------------------- */
    $r->post("$B/:id/peserta", function (Request $q) {
        $r = rtm_ambil($q->params['id']);
        rtm_bolehEdit($r);
        if (kosong($q->in('nama'))) throw new HttpError('nama wajib diisi', 400);
        $id = Db::insert('REPLACE INTO rtm_participants (rtm_id, user_id, nama, jabatan, hadir) VALUES (?,?,?,?,?)',
            [$r['id'], idNull($q->in('user_id')), $q->in('nama'), $q->in('jabatan'), kosong($q->in('hadir')) ? 0 : 1]);
        return new Respons(['id' => $id], 201);
    }, $PS);

    $r->put("$B/:id/peserta/:pid", function (Request $q) {
        $r = rtm_ambil($q->params['id']);
        rtm_bolehEdit($r);
        Db::run('UPDATE rtm_participants SET hadir=? WHERE id=? AND rtm_id=?', [kosong($q->in('hadir')) ? 0 : 1, (int)$q->params['pid'], $r['id']]);
        return ['ok' => true];
    }, $PS);

    $r->delete("$B/:id/peserta/:pid", function (Request $q) {
        $r = rtm_ambil($q->params['id']);
        rtm_bolehEdit($r);
        Db::run('DELETE FROM rtm_participants WHERE id=? AND rtm_id=?', [(int)$q->params['pid'], $r['id']]);
        return ['ok' => true];
    }, $PS);

    /* ----------------------------- KELUARAN ---------------------------- */
    $r->post("$B/:id/keluaran", function (Request $q) {
        $r = rtm_ambil($q->params['id']);
        rtm_bolehEdit($r);
        $kat = $q->in('kategori');
        if (!in_array($kat, array_column(RTM_KELUARAN, 0), true)) throw new HttpError('Kategori keluaran tidak dikenal', 400);
        if (kosong($q->in('uraian'))) throw new HttpError('uraian wajib diisi', 400);
        $id = Db::insert('INSERT INTO rtm_outputs (rtm_id, kategori, uraian, unit_id, penanggung_jawab, target_tanggal) VALUES (?,?,?,?,?,?)',
            [$r['id'], $kat, $q->in('uraian'), idNull($q->in('unit_id')), $q->in('penanggung_jawab'), $q->in('target_tanggal')]);
        return new Respons(['id' => $id], 201);
    }, $PS);

    $r->put("$B/:id/keluaran/:oid", function (Request $q) {
        $o = Db::one('SELECT * FROM rtm_outputs WHERE id=?', [(int)$q->params['oid']]);
        if (!$o) throw new HttpError('Keluaran tidak ditemukan', 404);
        $role = $q->user['role'];
        if (!in_array($role, ['pimpinan', 'spm', 'auditee', 'admin'], true)) throw new HttpError('Akses ditolak. Dibutuhkan peran: pimpinan / spm / auditee', 403);
        if ($role === 'auditee' && (int)$o['unit_id'] !== (int)$q->user['unit_id']) throw new HttpError('Bukan unit Anda', 403);
        $p = fn($k) => $q->in($k) ?? $o[$k];
        if ($role === 'auditee') {
            Db::run('UPDATE rtm_outputs SET status_tindak_lanjut=?, realisasi=? WHERE id=?', [$p('status_tindak_lanjut'), $p('realisasi'), $o['id']]);
        } else {
            $r = rtm_ambil($q->params['id']);
            rtm_bolehEdit($r);
            Db::run('UPDATE rtm_outputs SET uraian=?, unit_id=?, penanggung_jawab=?, target_tanggal=?, status_tindak_lanjut=?, realisasi=? WHERE id=?',
                [$p('uraian'), idNull($p('unit_id')), $p('penanggung_jawab'), $p('target_tanggal'), $p('status_tindak_lanjut'), $p('realisasi'), $o['id']]);
        }
        return ['ok' => true];
    }, $L);

    $r->delete("$B/:id/keluaran/:oid", function (Request $q) {
        $r = rtm_ambil($q->params['id']);
        rtm_bolehEdit($r);
        Db::run('DELETE FROM rtm_outputs WHERE id=? AND rtm_id=?', [(int)$q->params['oid'], $r['id']]);
        return ['ok' => true];
    }, $PS);

    /* --------------------------- BAHAN RAPAT --------------------------- */
    $r->get("$B/:id/bahan", function (Request $q) {
        $rtm = rtm_ambil($q->params['id']);
        $pid = (int)$rtm['period_id'];
        $hasilAudit = Db::all('SELECT un.name AS unit_name, a.ba_number, a.status, a.executed_date,
                                      (SELECT ROUND(AVG(capaian_persen),2) FROM audit_assessments s WHERE s.audit_id=a.id) AS rata_capaian,
                                      (SELECT COUNT(*) FROM findings f WHERE f.audit_id=a.id) AS temuan
                                 FROM audits a JOIN units un ON un.id=a.unit_id WHERE a.period_id=? ORDER BY un.name', [$pid]);
        $ketidaksesuaian = Db::all("SELECT f.kategori, COUNT(*) jumlah, COALESCE(SUM(CASE WHEN f.status='ditutup' THEN 1 ELSE 0 END),0) ditutup,
                                           COALESCE(SUM(CASE WHEN ca.efektivitas='efektif' THEN 1 ELSE 0 END),0) efektif
                                      FROM findings f JOIN audits a ON a.id=f.audit_id LEFT JOIN corrective_actions ca ON ca.finding_id=f.id
                                     WHERE a.period_id=? GROUP BY f.kategori", [$pid]);
        $sasaran = Db::all('SELECT s.category, COUNT(DISTINCT i.id) AS indikator, ROUND(AVG(ass.capaian_persen),2) AS rata_capaian
                              FROM standards s JOIN standard_indicators i ON i.standard_id=s.id
                              LEFT JOIN audit_assessments ass ON ass.indicator_id=i.id LEFT JOIN audits a ON a.id=ass.audit_id AND a.period_id=s.period_id
                             WHERE s.period_id=? GROUP BY s.category ORDER BY s.category', [$pid]);
        $sebelumnya = Db::all('SELECT o.kategori, o.uraian, o.status_tindak_lanjut, o.realisasi, o.target_tanggal, r.judul AS rtm_judul, r.tanggal AS rtm_tanggal
                                 FROM rtm_outputs o JOIN rtm_meetings r ON r.id=o.rtm_id
                                WHERE r.id <> ? AND r.tanggal <= COALESCE(?, ?) ORDER BY r.tanggal DESC LIMIT 50', [$rtm['id'], $rtm['tanggal'], date('Y-m-d')]);
        $progres = Db::all("SELECT un.name AS unit_name, COUNT(DISTINCT i.id) AS total,
                                   COUNT(DISTINCT CASE WHEN im.status IN ('diserahkan','dinilai') THEN im.indicator_id END) AS terisi
                              FROM units un JOIN indicator_assignments ia ON ia.unit_id=un.id JOIN standard_indicators i ON i.id=ia.indicator_id
                              JOIN standards s ON s.id=i.standard_id AND s.period_id=? AND s.status='ditetapkan'
                              LEFT JOIN implementations im ON im.indicator_id=i.id AND im.unit_id=un.id AND im.period_id=?
                             WHERE un.is_active=1 GROUP BY un.id, un.name ORDER BY un.name", [$pid, $pid]);
        return ['hasil_audit' => $hasilAudit, 'ketidaksesuaian' => $ketidaksesuaian, 'sasaran_mutu' => $sasaran, 'rtm_sebelumnya' => $sebelumnya, 'progres_unit' => $progres];
    }, $L);

    /* ------------------------ PENGESAHAN NOTULEN ----------------------- */
    $r->post("$B/:id/tanda-tangan/notulen", function (Request $q) {
        $r = rtm_ambil($q->params['id']);
        if (!in_array($r['status'], RTM_EDITABLE, true)) throw new HttpError('Notulen sudah diajukan untuk pengesahan lebih lanjut.', 400);
        if (!((int)$q->user['id'] === (int)$r['notulis_id'] || in_array($q->user['role'], ['spm', 'admin'], true)))
            throw new HttpError('Hanya notulis rapat ini yang dapat mengesahkan notulen.', 403);
        $img = rtm_gambar($q);
        rtm_simpanTtd((int)$r['id'], 'Notulen', $q->user, $img);
        Db::run("UPDATE rtm_meetings SET status='menunggu_verifikasi_spm', catatan=NULL WHERE id=?", [$r['id']]);
        Db::log((int)$q->user['id'], 'sahkan_notulen', 'rtm_meetings', (int)$r['id']);
        return ['ok' => true, 'status' => 'menunggu_verifikasi_spm'];
    }, $L);

    $r->delete("$B/:id/tanda-tangan/notulen", function (Request $q) {
        $r = rtm_ambil($q->params['id']);
        if ($r['status'] !== 'menunggu_verifikasi_spm') throw new HttpError('Pengesahan hanya dapat ditarik selama menunggu verifikasi Kepala SPM.', 400);
        if (!((int)$q->user['id'] === (int)$r['notulis_id'] || in_array($q->user['role'], ['spm', 'admin'], true)))
            throw new HttpError('Hanya notulis rapat ini yang dapat menarik pengesahan.', 403);
        Db::run("DELETE FROM signatures WHERE entity_type='rtm' AND entity_id=? AND peran='Notulen'", [$r['id']]);
        Db::run("UPDATE rtm_meetings SET status='rencana' WHERE id=?", [$r['id']]);
        Db::log((int)$q->user['id'], 'tarik_pengesahan_notulen', 'rtm_meetings', (int)$r['id']);
        return ['ok' => true, 'status' => 'rencana'];
    }, $L);

    $r->post("$B/:id/verifikasi-spm", function (Request $q) {
        $r = rtm_ambil($q->params['id']);
        if ($r['status'] !== 'menunggu_verifikasi_spm') throw new HttpError('Notulen belum diajukan untuk verifikasi Kepala SPM.', 400);
        if (!rtm_bolehVerifikasiSpm($q)) throw new HttpError('Hanya Kepala Satuan Penjaminan Mutu yang dapat memverifikasi notulen.', 403);
        $kep = $q->in('keputusan');
        if (!in_array($kep, ['setuju', 'tolak'], true)) throw new HttpError('Keputusan harus "setuju" atau "tolak"', 400);
        if ($kep === 'tolak') {
            $cat = !kosong($q->in('catatan')) ? $q->in('catatan') : 'Notulen ditolak oleh Kepala SPM, mohon diperbaiki dan disahkan ulang.';
            Db::transaksi(function () use ($r, $cat) {
                Db::run("DELETE FROM signatures WHERE entity_type='rtm' AND entity_id=? AND peran='Notulen'", [$r['id']]);
                Db::run("UPDATE rtm_meetings SET status='ditolak_spm', catatan=? WHERE id=?", [$cat, $r['id']]);
            });
            Db::log((int)$q->user['id'], 'tolak_notulen', 'rtm_meetings', (int)$r['id'], (string)($q->in('catatan') ?? ''));
            return ['ok' => true, 'status' => 'ditolak_spm'];
        }
        if (kosong($q->in('image_data'))) throw new HttpError('image_data wajib diisi untuk menyetujui', 400);
        $img = rtm_gambar($q);
        rtm_simpanTtd((int)$r['id'], 'Kepala SPM', $q->user, $img);
        Db::run("UPDATE rtm_meetings SET status='menunggu_direktur', catatan=NULL WHERE id=?", [$r['id']]);
        Db::log((int)$q->user['id'], 'setujui_notulen', 'rtm_meetings', (int)$r['id']);
        return ['ok' => true, 'status' => 'menunggu_direktur'];
    }, $L);

    $r->delete("$B/:id/verifikasi-spm", function (Request $q) {
        $r = rtm_ambil($q->params['id']);
        if ($r['status'] !== 'menunggu_direktur') throw new HttpError('Persetujuan hanya dapat ditarik selama menunggu tanda tangan Direktur.', 400);
        if (!rtm_bolehVerifikasiSpm($q)) throw new HttpError('Hanya Kepala Satuan Penjaminan Mutu yang dapat menarik persetujuan.', 403);
        Db::run("DELETE FROM signatures WHERE entity_type='rtm' AND entity_id=? AND peran='Kepala SPM'", [$r['id']]);
        Db::run("UPDATE rtm_meetings SET status='menunggu_verifikasi_spm' WHERE id=?", [$r['id']]);
        Db::log((int)$q->user['id'], 'tarik_persetujuan_spm', 'rtm_meetings', (int)$r['id']);
        return ['ok' => true, 'status' => 'menunggu_verifikasi_spm'];
    }, $L);

    $r->post("$B/:id/tanda-tangan/direktur", function (Request $q) {
        $r = rtm_ambil($q->params['id']);
        if ($r['status'] !== 'menunggu_direktur') throw new HttpError('Notulen belum disetujui Kepala SPM.', 400);
        if (!((int)$q->user['id'] === (int)$r['pemimpin_id'] || in_array($q->user['role'], ['pimpinan', 'admin'], true)))
            throw new HttpError('Hanya pemimpin rapat yang dapat menandatangani notulen final.', 403);
        $img = rtm_gambar($q);
        rtm_simpanTtd((int)$r['id'], 'Direktur', $q->user, $img);
        Db::run("UPDATE rtm_meetings SET status='ditandatangani' WHERE id=?", [$r['id']]);
        Db::log((int)$q->user['id'], 'tanda_tangan_notulen_final', 'rtm_meetings', (int)$r['id']);
        return ['ok' => true, 'status' => 'ditandatangani'];
    }, $L);

    $r->delete("$B/:id/tanda-tangan/direktur", function (Request $q) {
        $r = rtm_ambil($q->params['id']);
        if ($r['status'] !== 'ditandatangani') throw new HttpError('Notulen belum ditandatangani Direktur.', 400);
        if (!((int)$q->user['id'] === (int)$r['pemimpin_id'] || in_array($q->user['role'], ['pimpinan', 'admin'], true)))
            throw new HttpError('Hanya pemimpin rapat yang dapat menarik tanda tangan final.', 403);
        Db::run("DELETE FROM signatures WHERE entity_type='rtm' AND entity_id=? AND peran='Direktur'", [$r['id']]);
        Db::run("UPDATE rtm_meetings SET status='menunggu_direktur' WHERE id=?", [$r['id']]);
        Db::log((int)$q->user['id'], 'tarik_tanda_tangan_direktur', 'rtm_meetings', (int)$r['id']);
        return ['ok' => true, 'status' => 'menunggu_direktur'];
    }, $L);
}
