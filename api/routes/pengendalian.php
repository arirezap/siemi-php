<?php
declare(strict_types=1);

/* MODUL 4 — PENGENDALIAN STANDAR (padanan server/src/routes/pengendalian.js) */

const PTK_SELECT_BASE = "
  SELECT ca.*, f.id AS finding_id, f.code AS finding_code, f.kategori, f.uraian AS uraian_temuan,
         f.kriteria_acuan, f.bukti_objektif, f.status AS status_temuan, f.audit_id,
         a.ba_number, a.scheduled_date, a.executed_date, a.unit_id,
         un.name AS unit_name, un.code AS unit_code, p.id AS period_id, p.name AS period_name,
         i.indicator, i.code AS indikator_kode, i.target, s.code AS standar_kode, s.title AS standar_judul,
         ua.name AS pengisi_name, uv.name AS verifikator_name,
         la.name AS lead_auditor_name, la.jabatan AS lead_auditor_jabatan, la.nip AS lead_auditor_nip,
         ae.name AS auditee_name, ae.jabatan AS auditee_jabatan, ae.nip AS auditee_nip
    FROM findings f
    JOIN audits a ON a.id=f.audit_id
    JOIN units un ON un.id=a.unit_id
    JOIN periods p ON p.id=a.period_id
    LEFT JOIN corrective_actions ca ON ca.finding_id=f.id
    LEFT JOIN audit_assessments ass ON ass.id=f.assessment_id
    LEFT JOIN standard_indicators i ON i.id=ass.indicator_id
    LEFT JOIN standards s ON s.id=i.standard_id
    LEFT JOIN users ua ON ua.id=ca.diisi_oleh
    LEFT JOIN users uv ON uv.id=ca.diverifikasi_oleh
    LEFT JOIN users la ON la.id=a.lead_auditor_id
    LEFT JOIN users ae ON ae.id=a.auditee_id
";

const PTK_PERAN = ['Auditor (Temuan)', 'Teraudit (Rencana Tindakan)', 'Auditor (Tinjauan Efektivitas)'];

function ptk_temuan(int $fid): array
{
    $f = Db::one('SELECT f.*, a.unit_id FROM findings f JOIN audits a ON a.id=f.audit_id WHERE f.id=?', [$fid]);
    if (!$f) throw new HttpError('Temuan tidak ditemukan', 404);
    return $f;
}

function rute_pengendalian(Router $r): void
{
    $L = [[Auth::class, 'wajibLogin']];
    $AU = [[Auth::class, 'wajibLogin'], Auth::peran('auditee', 'spm')];
    $AV = [[Auth::class, 'wajibLogin'], Auth::peran('auditor', 'spm')];
    $B = '/pengendalian';

    $r->get($B, function (Request $q) {
        $where = []; $p = [];
        if (!kosong($q->query('period_id'))) { $where[] = 'a.period_id=?'; $p[] = $q->query('period_id'); }
        if (!kosong($q->query('unit_id'))) { $where[] = 'a.unit_id=?'; $p[] = $q->query('unit_id'); }
        if (!kosong($q->query('kategori'))) { $where[] = 'f.kategori=?'; $p[] = $q->query('kategori'); }
        if (!kosong($q->query('status'))) { $where[] = "COALESCE(ca.status,'menunggu_auditee')=?"; $p[] = $q->query('status'); }
        if ($q->user['role'] === 'auditee') { $where[] = 'a.unit_id=?'; $p[] = $q->user['unit_id']; }
        return Db::all(PTK_SELECT_BASE . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . " ORDER BY CASE f.kategori WHEN 'KTS Mayor' THEN 1 WHEN 'KTS Minor' THEN 2 ELSE 3 END, f.id DESC", $p);
    }, $L);

    $r->get("$B/rekap/periode/:periodId", function (Request $q) {
        $pid = (int)$q->params['periodId'];
        $perKategori = Db::all("SELECT f.kategori, COUNT(*) jumlah, COALESCE(SUM(CASE WHEN f.status='ditutup' THEN 1 ELSE 0 END),0) ditutup
                                  FROM findings f JOIN audits a ON a.id=f.audit_id WHERE a.period_id=? GROUP BY f.kategori", [$pid]);
        $perUnit = Db::all("SELECT un.name AS unit_name, un.code, COUNT(*) jumlah, COALESCE(SUM(CASE WHEN f.status='ditutup' THEN 1 ELSE 0 END),0) ditutup,
                                   COALESCE(SUM(CASE WHEN ca.efektivitas='efektif' THEN 1 ELSE 0 END),0) efektif
                              FROM findings f JOIN audits a ON a.id=f.audit_id JOIN units un ON un.id=a.unit_id LEFT JOIN corrective_actions ca ON ca.finding_id=f.id
                             WHERE a.period_id=? GROUP BY un.id, un.name, un.code ORDER BY jumlah DESC", [$pid]);
        $terlambat = Db::all("SELECT f.code, f.kategori, un.name AS unit_name, ca.rencana_tanggal, ca.status
                                FROM findings f JOIN audits a ON a.id=f.audit_id JOIN units un ON un.id=a.unit_id JOIN corrective_actions ca ON ca.finding_id=f.id
                               WHERE a.period_id=? AND ca.rencana_tanggal IS NOT NULL AND ca.rencana_tanggal < ? AND f.status <> 'ditutup'
                               ORDER BY ca.rencana_tanggal", [$pid, date('Y-m-d')]);
        return ['per_kategori' => $perKategori, 'per_unit' => $perUnit, 'terlambat' => $terlambat];
    }, $L);

    /* Pengesahan daftar PTK per periode */
    $r->get("$B/daftar-ptk/:periodId/tanda-tangan", fn(Request $q) => Db::all("SELECT * FROM signatures WHERE entity_type='daftar_ptk' AND entity_id=?", [(int)$q->params['periodId']]), $L);

    $r->post("$B/daftar-ptk/:periodId/tanda-tangan", function (Request $q) {
        $img = $q->in('image_data');
        if (kosong($img)) throw new HttpError('image_data wajib diisi', 400);
        if (!preg_match('#^data:image/(png|jpeg);base64,#', (string)$img)) throw new HttpError('Tanda tangan harus berupa gambar', 400);
        $pid = (int)$q->params['periodId'];
        Db::run("INSERT INTO signatures (entity_type, entity_id, user_id, peran, nama, jabatan, image_data) VALUES ('daftar_ptk',?,?,'Lead Auditor',?,?,?)
                 ON DUPLICATE KEY UPDATE image_data=VALUES(image_data), signed_at=NOW()", [$pid, $q->user['id'], $q->user['name'], $q->user['jabatan'], $img]);
        Db::log((int)$q->user['id'], 'tanda_tangan_daftar_ptk', 'periods', $pid);
        return ['ok' => true];
    }, $AV);

    $r->delete("$B/daftar-ptk/:periodId/tanda-tangan", function (Request $q) {
        Db::run("DELETE FROM signatures WHERE entity_type='daftar_ptk' AND entity_id=?", [(int)$q->params['periodId']]);
        return ['ok' => true];
    }, $AV);

    $r->get("$B/:findingId", function (Request $q) {
        $row = Db::one(PTK_SELECT_BASE . ' WHERE f.id=?', [(int)$q->params['findingId']]);
        if (!$row) throw new HttpError('Temuan tidak ditemukan', 404);
        if (!Auth::bisaAksesUnit($q->user, $row['unit_id'])) throw new HttpError('Bukan unit Anda', 403);
        $row['tanda_tangan'] = Db::all("SELECT * FROM signatures WHERE entity_type='corrective_action' AND entity_id=? ORDER BY id", [$row['finding_id']]);
        return $row;
    }, $L);

    $r->post("$B/:findingId/tanda-tangan", function (Request $q) {
        $fid = (int)$q->params['findingId'];
        $f = ptk_temuan($fid);
        $peran = $q->in('peran'); $img = $q->in('image_data');
        if (!in_array($peran, PTK_PERAN, true)) throw new HttpError('Peran tanda tangan tidak dikenal', 400);
        if (kosong($img) || !preg_match('#^data:image/(png|jpeg);base64,#', (string)$img)) throw new HttpError('Tanda tangan harus berupa gambar PNG/JPEG', 400);
        $role = $q->user['role'];
        $bolehAuditor = in_array($role, ['auditor', 'spm', 'admin'], true);
        $bolehTeraudit = in_array($role, ['auditee', 'spm', 'admin'], true) && ($role !== 'auditee' || (int)$q->user['unit_id'] === (int)$f['unit_id']);
        if (str_starts_with($peran, 'Auditor') && !$bolehAuditor) throw new HttpError('Hanya auditor yang dapat menandatangani sebagai Auditor', 403);
        if (str_starts_with($peran, 'Teraudit') && !$bolehTeraudit) throw new HttpError('Hanya auditee unit terkait yang dapat menandatangani sebagai Teraudit', 403);
        Db::run("INSERT INTO signatures (entity_type, entity_id, user_id, peran, nama, jabatan, image_data) VALUES ('corrective_action',?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE image_data=VALUES(image_data), signed_at=NOW()", [$fid, $q->user['id'], $peran, $q->user['name'], $q->user['jabatan'], $img]);
        Db::log((int)$q->user['id'], 'tanda_tangan_ptk', 'findings', $fid, (string)$peran);
        return ['ok' => true];
    }, $L);

    $r->post("$B/:findingId/tindakan-koreksi", function (Request $q) {
        $fid = (int)$q->params['findingId'];
        $f = ptk_temuan($fid);
        if (!Auth::bisaAksesUnit($q->user, $f['unit_id'])) throw new HttpError('Bukan unit Anda', 403);
        if (kosong($q->in('analisis_penyebab')) || kosong($q->in('rencana_tindakan')) || kosong($q->in('rencana_tanggal')))
            throw new HttpError('Analisis penyebab, rencana tindakan koreksi, dan rencana tanggal penyelesaian wajib diisi', 400);
        Db::run('INSERT IGNORE INTO corrective_actions (finding_id) VALUES (?)', [$fid]);
        Db::run("UPDATE corrective_actions SET analisis_penyebab=?, koreksi_sementara=?, rencana_tindakan=?, rencana_tanggal=?, penanggung_jawab=?, diisi_oleh=?, diisi_at=NOW(),
                        status = CASE WHEN status IN ('menunggu_auditee','rencana_disusun') THEN 'rencana_disusun' ELSE status END, updated_at=NOW()
                  WHERE finding_id=?",
            [$q->in('analisis_penyebab'), $q->in('koreksi_sementara'), $q->in('rencana_tindakan'), $q->in('rencana_tanggal'),
             $q->in('penanggung_jawab') ?? $q->user['name'], $q->user['id'], $fid]);
        Db::run("UPDATE findings SET status='tindakan_koreksi' WHERE id=?", [$fid]);
        Db::log((int)$q->user['id'], 'isi_tindakan_koreksi', 'findings', $fid);
        return ['ok' => true];
    }, $AU);

    $r->post("$B/:findingId/realisasi", function (Request $q) {
        $fid = (int)$q->params['findingId'];
        $f = ptk_temuan($fid);
        if (!Auth::bisaAksesUnit($q->user, $f['unit_id'])) throw new HttpError('Bukan unit Anda', 403);
        if (kosong($q->in('realisasi_tindakan'))) throw new HttpError('Uraian realisasi tindakan wajib diisi', 400);
        Db::run("UPDATE corrective_actions SET realisasi_tindakan=?, bukti_realisasi=?, tanggal_realisasi=?, realisasi_at=NOW(), status='realisasi_dilaporkan', updated_at=NOW() WHERE finding_id=?",
            [$q->in('realisasi_tindakan'), $q->in('bukti_realisasi'), $q->in('tanggal_realisasi'), $fid]);
        Db::run("UPDATE findings SET status='verifikasi' WHERE id=?", [$fid]);
        Db::log((int)$q->user['id'], 'lapor_realisasi', 'findings', $fid);
        return ['ok' => true];
    }, $AU);

    $r->post("$B/:findingId/verifikasi", function (Request $q) {
        $fid = (int)$q->params['findingId'];
        $ef = $q->in('efektivitas');
        if (!in_array($ef, ['efektif', 'belum_efektif', 'tidak_efektif'], true)) throw new HttpError('Efektivitas harus salah satu dari: efektif, belum_efektif, tidak_efektif', 400);
        $tutup = $ef === 'efektif' && $q->in('tutup') !== false;
        Db::run("UPDATE corrective_actions SET efektivitas=?, catatan_verifikasi=?, tanggal_penyelesaian=?, diverifikasi_oleh=?, diverifikasi_at=NOW(), status=?, updated_at=NOW() WHERE finding_id=?",
            [$ef, $q->in('catatan_verifikasi'), $q->in('tanggal_penyelesaian'), $q->user['id'], $tutup ? 'ditutup' : 'diverifikasi', $fid]);
        Db::run('UPDATE findings SET status=? WHERE id=?', [$tutup ? 'ditutup' : 'tindakan_koreksi', $fid]);
        Db::log((int)$q->user['id'], 'verifikasi_tindakan_koreksi', 'findings', $fid, $ef);
        return ['ok' => true, 'ditutup' => $tutup];
    }, $AV);
}
