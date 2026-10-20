<?php
declare(strict_types=1);

/* MODUL 3 — EVALUASI MUTU INTERNAL / AUDIT (padanan server/src/routes/emi.js) */

const EMI_VERDICT_KATEGORI = ['kts_mayor' => 'KTS Mayor', 'kts_minor' => 'KTS Minor', 'observasi' => 'Observasi'];

function emi_audit(int $id): array
{
    $a = Db::one('SELECT * FROM audits WHERE id=?', [$id]);
    if (!$a) throw new HttpError('Audit tidak ditemukan', 404);
    return $a;
}

function emi_belumTtd(array $audit): void
{
    if ($audit['status'] === 'ditandatangani') throw new HttpError('Berita acara sudah ditandatangani', 400);
}

function emi_simpanAnggota(int $auditId, array $anggota): void
{
    foreach ($anggota as $x) {
        $uid = is_array($x) ? ($x['user_id'] ?? null) : $x;
        $peran = is_array($x) && !kosong($x['peran'] ?? null) ? $x['peran'] : 'Anggota Auditor';
        if (kosong($uid)) continue;
        Db::run('INSERT IGNORE INTO audit_members (audit_id, user_id, peran) VALUES (?,?,?)', [$auditId, (int)$uid, $peran]);
    }
}

function emi_kodeTemuan(array $audit, string $prefix): string
{
    $unit = Db::one('SELECT code FROM units WHERE id=?', [$audit['unit_id']]);
    $tahun = Db::val('SELECT year FROM periods WHERE id=?', [$audit['period_id']]);
    $urut = (int)Db::val('SELECT COUNT(*) FROM findings WHERE audit_id=?', [$audit['id']]) + 1;
    return $prefix . '/' . ($unit['code'] ?? 'UNIT') . '/' . $tahun . '/' . str_pad((string)$urut, 2, '0', STR_PAD_LEFT);
}

function emi_validGambar(mixed $img): void
{
    if (!preg_match('#^data:image/(png|jpeg);base64,#', (string)$img)) throw new HttpError('Tanda tangan harus berupa gambar PNG/JPEG', 400);
}

function rute_emi(Router $r): void
{
    $L = [[Auth::class, 'wajibLogin']];
    $AS = [[Auth::class, 'wajibLogin'], Auth::peran('spm', 'auditor')];
    $AA = [[Auth::class, 'wajibLogin'], Auth::peran('auditor', 'spm')];
    $SPM = [[Auth::class, 'wajibLogin'], Auth::peran('spm')];
    $B = '/emi';

    $r->get($B, function (Request $q) {
        $where = []; $p = [];
        foreach (['period_id' => 'a.period_id', 'unit_id' => 'a.unit_id', 'status' => 'a.status'] as $k => $kol) {
            if (!kosong($q->query($k))) { $where[] = "$kol=?"; $p[] = $q->query($k); }
        }
        if ($q->user['role'] === 'auditee') { $where[] = 'a.unit_id=?'; $p[] = $q->user['unit_id']; }
        if ($q->user['role'] === 'auditor') {
            $where[] = '(a.lead_auditor_id=? OR EXISTS (SELECT 1 FROM audit_members m WHERE m.audit_id=a.id AND m.user_id=?))';
            array_push($p, $q->user['id'], $q->user['id']);
        }
        return Db::all(
            "SELECT a.*, un.name AS unit_name, un.code AS unit_code, p.name AS period_name, la.name AS lead_auditor_name, ae.name AS auditee_name,
                    (SELECT COUNT(*) FROM findings f WHERE f.audit_id=a.id) AS jumlah_temuan,
                    (SELECT COUNT(*) FROM audit_assessments s WHERE s.audit_id=a.id) AS jumlah_dinilai,
                    (SELECT COUNT(*) FROM signatures g WHERE g.entity_type='audit' AND g.entity_id=a.id) AS jumlah_ttd,
                    (SELECT GROUP_CONCAT(u.name SEPARATOR ', ') FROM audit_members m JOIN users u ON u.id=m.user_id WHERE m.audit_id=a.id) AS anggota_names,
                    (SELECT GROUP_CONCAT(DISTINCT CONCAT(s.code, ' — ', s.title) ORDER BY s.code SEPARATOR ', ')
                       FROM standard_indicators i
                       JOIN indicator_assignments ia ON ia.indicator_id = i.id AND ia.unit_id = a.unit_id
                       JOIN standards s ON s.id = i.standard_id
                      WHERE s.period_id = a.period_id AND s.status = 'ditetapkan') AS persyaratan_standar
               FROM audits a JOIN units un ON un.id=a.unit_id JOIN periods p ON p.id=a.period_id
               LEFT JOIN users la ON la.id=a.lead_auditor_id LEFT JOIN users ae ON ae.id=a.auditee_id "
            . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY a.scheduled_date DESC, a.id DESC', $p);
    }, $L);

    $r->post($B, function (Request $q) {
        $periodId = idNull($q->in('period_id')); $unitId = idNull($q->in('unit_id'));
        if (!$periodId || !$unitId) throw new HttpError('period_id dan unit_id wajib diisi', 400);
        $tahun = Db::val('SELECT year FROM periods WHERE id=?', [$periodId]);
        $unit = Db::one('SELECT code FROM units WHERE id=?', [$unitId]);
        $urut = (int)Db::val('SELECT COUNT(*) FROM audits WHERE period_id=?', [$periodId]) + 1;
        $ba = 'BA-EMI/' . str_pad((string)$urut, 3, '0', STR_PAD_LEFT) . '/' . ($unit['code'] ?? 'UNIT') . '/' . $tahun;
        $id = Db::insert('INSERT INTO audits (period_id, unit_id, ba_number, lead_auditor_id, auditee_id, scheduled_date, scheduled_time, tempat, scope, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$periodId, $unitId, $ba, idNull($q->in('lead_auditor_id')), idNull($q->in('auditee_id')), $q->in('scheduled_date'), $q->in('scheduled_time'), $q->in('tempat'), $q->in('scope'), $q->user['id']]);
        $ang = $q->in('anggota');
        if (is_array($ang)) emi_simpanAnggota($id, $ang);
        Db::log((int)$q->user['id'], 'jadwalkan_audit', 'audits', $id, $ba);
        return new Respons(['id' => $id, 'ba_number' => $ba], 201);
    }, $AS);

    /* --- tanda tangan jadwal audit per periode (rute statis, tiga segmen) --- */
    $r->get("$B/jadwal/:periodId/tanda-tangan", fn(Request $q) => Db::all("SELECT * FROM signatures WHERE entity_type='jadwal_audit' AND entity_id=?", [(int)$q->params['periodId']]), $L);

    $r->post("$B/jadwal/:periodId/tanda-tangan", function (Request $q) {
        $img = $q->in('image_data');
        if (kosong($img)) throw new HttpError('image_data wajib diisi', 400);
        if (!preg_match('#^data:image/(png|jpeg);base64,#', (string)$img)) throw new HttpError('Tanda tangan harus berupa gambar', 400);
        $pid = (int)$q->params['periodId'];
        Db::run("INSERT INTO signatures (entity_type, entity_id, user_id, peran, nama, jabatan, image_data) VALUES ('jadwal_audit',?,?,'SPM',?,?,?)
                 ON DUPLICATE KEY UPDATE image_data=VALUES(image_data), signed_at=NOW()", [$pid, $q->user['id'], $q->user['name'], $q->user['jabatan'], $img]);
        Db::log((int)$q->user['id'], 'tanda_tangan_jadwal_audit', 'periods', $pid);
        return ['ok' => true];
    }, $SPM);

    $r->delete("$B/jadwal/:periodId/tanda-tangan", function (Request $q) {
        Db::run("DELETE FROM signatures WHERE entity_type='jadwal_audit' AND entity_id=?", [(int)$q->params['periodId']]);
        return ['ok' => true];
    }, $SPM);

    $r->delete("$B/temuan/:fid", function (Request $q) {
        Db::run('DELETE FROM findings WHERE id=?', [(int)$q->params['fid']]);
        return ['ok' => true];
    }, $AA);

    $r->put("$B/:id", function (Request $q) {
        $a = emi_audit((int)$q->params['id']);
        if ($a['status'] === 'ditandatangani') throw new HttpError('Berita acara sudah ditandatangani', 400);
        $b = $q->body();
        $unitBaru = $b['unit_id'] ?? null;
        if (!kosong($unitBaru) && (int)$unitBaru !== (int)$a['unit_id'] && (int)Db::val('SELECT COUNT(*) FROM audit_assessments WHERE audit_id=?', [$a['id']]) > 0)
            throw new HttpError('Unit tidak dapat diubah karena sudah ada indikator yang dinilai. Hapus jadwal ini dan buat ulang bila unitnya salah.', 400);
        $pakai = fn($k) => array_key_exists($k, $b) && $b[$k] !== null ? $b[$k] : $a[$k];
        Db::run('UPDATE audits SET unit_id=?, lead_auditor_id=?, auditee_id=?, scheduled_date=?, scheduled_time=?, executed_date=?, tempat=?, scope=?, kesimpulan=?, status=?, ba_number=? WHERE id=?', [
            idNull($pakai('unit_id')), idNull($pakai('lead_auditor_id')), idNull($pakai('auditee_id')), $pakai('scheduled_date'), $pakai('scheduled_time'),
            $pakai('executed_date'), $pakai('tempat'), $pakai('scope'), $pakai('kesimpulan'), $pakai('status'), $pakai('ba_number'), $a['id'],
        ]);
        if (isset($b['anggota']) && is_array($b['anggota'])) {
            Db::run('DELETE FROM audit_members WHERE audit_id=?', [$a['id']]);
            emi_simpanAnggota((int)$a['id'], $b['anggota']);
        }
        Db::log((int)$q->user['id'], 'ubah_jadwal_audit', 'audits', (int)$a['id']);
        return ['ok' => true];
    }, $AS);

    $r->get("$B/:id/anggota", fn(Request $q) => Db::all('SELECT m.user_id, m.peran, u.name FROM audit_members m JOIN users u ON u.id=m.user_id WHERE m.audit_id=?', [(int)$q->params['id']]), $L);

    $r->delete("$B/:id", function (Request $q) {
        $a = emi_audit((int)$q->params['id']);
        if ($a['status'] === 'ditandatangani') throw new HttpError('Berita acara sudah ditandatangani dan tidak dapat dihapus.', 400);
        Db::transaksi(function () use ($a) {
            Db::run("DELETE FROM signatures WHERE entity_type='audit' AND entity_id=?", [$a['id']]);
            Db::run("UPDATE implementations SET status='diserahkan' WHERE status='dinilai' AND id IN (SELECT implementation_id FROM (SELECT implementation_id FROM audit_assessments WHERE audit_id=? AND implementation_id IS NOT NULL) x)", [$a['id']]);
            Db::run('DELETE FROM audits WHERE id=?', [$a['id']]);
        });
        Db::log((int)$q->user['id'], 'hapus_jadwal_audit', 'audits', (int)$a['id'], $a['ba_number']);
        return ['ok' => true];
    }, $AS);

    $r->get("$B/:id/lembar-penilaian", function (Request $q) {
        $audit = Db::one('SELECT a.*, un.name AS unit_name, un.code AS unit_code, p.name AS period_name FROM audits a JOIN units un ON un.id=a.unit_id JOIN periods p ON p.id=a.period_id WHERE a.id=?', [(int)$q->params['id']]);
        if (!$audit) throw new HttpError('Audit tidak ditemukan', 404);
        $items = Db::all(
            "SELECT s.id AS standard_id, s.code AS standar_kode, s.category, s.ranah, s.kelompok, s.sifat, s.title AS standar_judul, s.statement,
                    i.id AS indicator_id, i.code AS indikator_kode, i.indicator, i.target, i.target_skor, i.satuan, ia.peran AS peran_unit,
                    im.id AS implementation_id, im.realisasi, im.capaian_nilai, im.skor AS auditee_skor, im.bukti_uraian, im.bukti_url, im.kendala, im.status AS status_isian,
                    as_.id AS assessment_id, as_.skor, as_.capaian_persen, as_.verdict, as_.catatan_auditor, as_.verifikasi_bukti,
                    f.id AS finding_id, f.code AS finding_code, f.kategori AS finding_kategori,
                    r.code AS acuan_kode, r.name AS acuan_nama, r.warna AS acuan_warna, r.skor_min, r.skor_maks, r.skor_step, ri.code AS butir_kode
               FROM standard_indicators i
               JOIN indicator_assignments ia ON ia.indicator_id=i.id AND ia.unit_id=?
               JOIN standards s ON s.id=i.standard_id
               LEFT JOIN implementations im ON im.indicator_id=i.id AND im.unit_id=? AND im.period_id=?
               LEFT JOIN audit_assessments as_ ON as_.audit_id=? AND as_.indicator_id=i.id
               LEFT JOIN findings f ON f.assessment_id = as_.id
               LEFT JOIN ref_items ri ON ri.id = i.source_ref_item_id
               LEFT JOIN refs r ON r.id = ri.ref_id
              WHERE s.period_id=? AND s.status='ditetapkan' ORDER BY s.ranah, s.kelompok, s.code, i.sort_order, i.id",
            [$audit['unit_id'], $audit['unit_id'], $audit['period_id'], $audit['id'], $audit['period_id']]);

        $sm = Db::all(
            "SELECT sm.id AS sasaran_mutu_id, sm.sasaran, sm.target, sm.satuan, sm.periode_evaluasi, sm.tipe_nilai, sm.skala_peringkat,
                    pk.id AS program_kerja_id, pk.nama AS program_kerja, pk.jenis AS jenis_program, pk.tahun, su.name AS sub_unit_name,
                    as_.id AS assessment_id, as_.skor, as_.capaian_persen, as_.verdict, as_.catatan_auditor, as_.verifikasi_bukti,
                    f.id AS finding_id, f.code AS finding_code
               FROM sasaran_mutu sm JOIN program_kerja pk ON pk.id = sm.program_kerja_id LEFT JOIN units su ON su.id = pk.sub_unit_id
               LEFT JOIN audit_assessments as_ ON as_.audit_id=? AND as_.sasaran_mutu_id=sm.id
               LEFT JOIN findings f ON f.assessment_id = as_.id
              WHERE pk.unit_id=? AND pk.period_id=? AND pk.status='ditetapkan'
              ORDER BY pk.tahun DESC, pk.jenis, pk.id, sm.sort_order, sm.id", [$audit['id'], $audit['unit_id'], $audit['period_id']]);

        foreach ($sm as &$s) {
            $s['capaian'] = Db::all('SELECT r.periode_ke, r.label, r.target_periode, c.realisasi, c.capaian_nilai, c.capaian_teks, c.status, c.submitted_at, c.kendala, c.faktor_pendukung, c.bukti_uraian, c.bukti_url
                                       FROM sasaran_mutu_rencana r LEFT JOIN sasaran_mutu_capaian c ON c.sasaran_mutu_id=r.sasaran_mutu_id AND c.periode_ke=r.periode_ke
                                      WHERE r.sasaran_mutu_id=? ORDER BY r.periode_ke', [$s['sasaran_mutu_id']]);
            $s['tahun_sebelumnya'] = Db::one('SELECT sm2.target, sm2.satuan, aa2.capaian_persen, aa2.skor, pk2.tahun FROM sasaran_mutu sm2
                                                JOIN program_kerja pk2 ON pk2.id = sm2.program_kerja_id LEFT JOIN audit_assessments aa2 ON aa2.sasaran_mutu_id = sm2.id
                                               WHERE pk2.unit_id=? AND sm2.sasaran=? AND pk2.tahun<? ORDER BY pk2.tahun DESC LIMIT 1',
                [$audit['unit_id'], $s['sasaran'], $s['tahun']]);
        }
        unset($s);
        return ['audit' => $audit, 'items' => $items, 'sasaranMutu' => $sm];
    }, $L);

    $r->post("$B/:id/nilai", function (Request $q) {
        $audit = emi_audit((int)$q->params['id']);
        emi_belumTtd($audit);
        $auditId = (int)$audit['id'];
        $indId = idNull($q->in('indicator_id'));
        if (!$indId) throw new HttpError('indicator_id wajib diisi', 400);
        $skor = $q->in('skor');
        if ($skor !== null && $skor !== '') {
            $g = SkorSkala::validasi((float)$skor, SkorSkala::untukIndikator($indId));
            if ($g) throw new HttpError($g, 400);
        }
        $verdict = $q->in('verdict') ?? 'sesuai';
        $implId = idNull($q->in('implementation_id'));
        $vals = [angkaNull($skor), angkaNull($q->in('capaian_persen')), $verdict, $q->in('catatan_auditor'), $q->in('verifikasi_bukti')];

        $id = Db::transaksi(function () use ($q, $audit, $auditId, $indId, $verdict, $implId, $vals) {
            $ada = Db::one('SELECT * FROM audit_assessments WHERE audit_id=? AND indicator_id=?', [$auditId, $indId]);
            if ($ada) {
                Db::run('UPDATE audit_assessments SET implementation_id=?, skor=?, capaian_persen=?, verdict=?, catatan_auditor=?, verifikasi_bukti=?, updated_at=NOW() WHERE id=?',
                    array_merge([$implId ?? $ada['implementation_id']], $vals, [$ada['id']]));
                $aid = (int)$ada['id'];
            } else {
                $aid = Db::insert('INSERT INTO audit_assessments (audit_id, indicator_id, implementation_id, skor, capaian_persen, verdict, catatan_auditor, verifikasi_bukti) VALUES (?,?,?,?,?,?,?,?)',
                    array_merge([$auditId, $indId, $implId], $vals));
            }
            if ($implId) Db::run("UPDATE implementations SET status='dinilai' WHERE id=?", [$implId]);

            $temuan = Db::one('SELECT * FROM findings WHERE assessment_id=?', [$aid]);
            if ($verdict === 'sesuai') {
                if ($temuan) {
                    $ca = Db::one('SELECT * FROM corrective_actions WHERE finding_id=?', [$temuan['id']]);
                    if (!$ca || (kosong($ca['analisis_penyebab']) && kosong($ca['rencana_tindakan'])))
                        Db::run('DELETE FROM findings WHERE id=?', [$temuan['id']]);
                }
                return $aid;
            }
            $ind = Db::one('SELECT i.*, s.code AS standar_kode, s.title AS standar_judul, s.reference_iso, s.reference_lam FROM standard_indicators i JOIN standards s ON s.id=i.standard_id WHERE i.id=?', [$indId]);
            $kategori = EMI_VERDICT_KATEGORI[$verdict] ?? 'Observasi';
            $acuan = !kosong($q->in('kriteria_acuan')) ? $q->in('kriteria_acuan')
                : $ind['standar_kode'] . ' — ' . $ind['standar_judul'] . (!kosong($ind['reference_iso']) ? " (ISO 21001 klausul {$ind['reference_iso']})" : '');
            $uraian = !kosong($q->in('uraian_temuan')) ? $q->in('uraian_temuan')
                : 'Indikator "' . $ind['indicator'] . '" dengan target ' . (!kosong($ind['target']) ? $ind['target'] : '-') . ' belum tercapai/tidak sesuai.';
            if ($temuan) {
                Db::run('UPDATE findings SET kategori=?, uraian=?, kriteria_acuan=?, bukti_objektif=? WHERE id=?',
                    [$kategori, $uraian, $acuan, $q->in('bukti_objektif') ?? $temuan['bukti_objektif'], $temuan['id']]);
                return $aid;
            }
            $kode = emi_kodeTemuan($audit, $verdict === 'observasi' ? 'OBS' : 'KTS');
            $fid = Db::insert('INSERT INTO findings (audit_id, assessment_id, code, kategori, uraian, kriteria_acuan, bukti_objektif, created_by) VALUES (?,?,?,?,?,?,?,?)',
                [$auditId, $aid, $kode, $kategori, $uraian, $acuan, $q->in('bukti_objektif'), $q->user['id']]);
            Db::run('INSERT IGNORE INTO corrective_actions (finding_id) VALUES (?)', [$fid]);
            return $aid;
        });
        return ['ok' => true, 'assessment_id' => $id];
    }, $AA);

    $r->delete("$B/:id/nilai/:indicatorId", function (Request $q) {
        $audit = emi_audit((int)$q->params['id']);
        emi_belumTtd($audit);
        $as = Db::one('SELECT * FROM audit_assessments WHERE audit_id=? AND indicator_id=?', [$audit['id'], (int)$q->params['indicatorId']]);
        if (!$as) throw new HttpError('Penilaian tidak ditemukan', 404);
        Db::transaksi(function () use ($as) {
            $t = Db::one('SELECT * FROM findings WHERE assessment_id=?', [$as['id']]);
            if ($t) {
                $ca = Db::one('SELECT * FROM corrective_actions WHERE finding_id=?', [$t['id']]);
                if (!$ca || (kosong($ca['analisis_penyebab']) && kosong($ca['rencana_tindakan']))) Db::run('DELETE FROM findings WHERE id=?', [$t['id']]);
                else Db::run('UPDATE findings SET assessment_id=NULL WHERE id=?', [$t['id']]);
            }
            if (!empty($as['implementation_id'])) Db::run("UPDATE implementations SET status='diserahkan' WHERE id=? AND status='dinilai'", [$as['implementation_id']]);
            Db::run('DELETE FROM audit_assessments WHERE id=?', [$as['id']]);
        });
        Db::log((int)$q->user['id'], 'hapus_penilaian', 'audit_assessments', (int)$as['id']);
        return ['ok' => true];
    }, $AA);

    $r->post("$B/:id/nilai-sasaran-mutu", function (Request $q) {
        $audit = emi_audit((int)$q->params['id']);
        emi_belumTtd($audit);
        $smId = idNull($q->in('sasaran_mutu_id'));
        if (!$smId) throw new HttpError('sasaran_mutu_id wajib diisi', 400);
        $sm = Db::one('SELECT sm.* FROM sasaran_mutu sm JOIN program_kerja pk ON pk.id = sm.program_kerja_id WHERE sm.id=? AND pk.unit_id=?', [$smId, $audit['unit_id']]);
        if (!$sm) throw new HttpError('Sasaran mutu tidak ditemukan pada unit audit ini', 404);
        $verdict = $q->in('verdict') ?? 'sesuai';
        $vals = [angkaNull($q->in('skor')), angkaNull($q->in('capaian_persen')), $verdict, $q->in('catatan_auditor'), $q->in('verifikasi_bukti')];
        $ada = Db::one('SELECT * FROM audit_assessments WHERE audit_id=? AND sasaran_mutu_id=?', [$audit['id'], $smId]);
        if ($ada) {
            Db::run('UPDATE audit_assessments SET skor=?, capaian_persen=?, verdict=?, catatan_auditor=?, verifikasi_bukti=?, updated_at=NOW() WHERE id=?', array_merge($vals, [$ada['id']]));
            $aid = (int)$ada['id'];
        } else {
            $aid = Db::insert('INSERT INTO audit_assessments (audit_id, sasaran_mutu_id, skor, capaian_persen, verdict, catatan_auditor, verifikasi_bukti) VALUES (?,?,?,?,?,?,?)',
                array_merge([$audit['id'], $smId], $vals));
        }
        Db::log((int)$q->user['id'], 'nilai_sasaran_mutu', 'audit_assessments', $aid, (string)$sm['sasaran']);
        return ['ok' => true, 'assessment_id' => $aid];
    }, $AA);

    $r->delete("$B/:id/nilai-sasaran-mutu/:sasaranMutuId", function (Request $q) {
        $audit = emi_audit((int)$q->params['id']);
        emi_belumTtd($audit);
        $as = Db::one('SELECT * FROM audit_assessments WHERE audit_id=? AND sasaran_mutu_id=?', [$audit['id'], (int)$q->params['sasaranMutuId']]);
        if (!$as) throw new HttpError('Penilaian tidak ditemukan', 404);
        Db::run('DELETE FROM audit_assessments WHERE id=?', [$as['id']]);
        Db::log((int)$q->user['id'], 'hapus_penilaian_sasaran_mutu', 'audit_assessments', (int)$as['id']);
        return ['ok' => true];
    }, $AA);

    /* ------------------------------ TEMUAN ----------------------------- */
    $r->get("$B/:id/temuan", fn(Request $q) => Db::all(
        'SELECT f.*, i.indicator, i.code AS indikator_kode, s.code AS standar_kode, s.title AS standar_judul, ca.status AS status_tindakan, ca.efektivitas
           FROM findings f LEFT JOIN audit_assessments a ON a.id=f.assessment_id LEFT JOIN standard_indicators i ON i.id=a.indicator_id
           LEFT JOIN standards s ON s.id=i.standard_id LEFT JOIN corrective_actions ca ON ca.finding_id=f.id
          WHERE f.audit_id=? ORDER BY f.kategori, f.id', [(int)$q->params['id']]), $L);

    $r->post("$B/:id/temuan", function (Request $q) {
        $audit = emi_audit((int)$q->params['id']);
        $kategori = $q->in('kategori'); $uraian = $q->in('uraian');
        if (kosong($kategori) || kosong($uraian)) throw new HttpError('kategori dan uraian wajib diisi', 400);
        $kode = emi_kodeTemuan($audit, $kategori === 'Observasi' ? 'OBS' : 'KTS');
        $fid = Db::insert('INSERT INTO findings (audit_id, code, kategori, uraian, kriteria_acuan, bukti_objektif, created_by) VALUES (?,?,?,?,?,?,?)',
            [$audit['id'], $kode, $kategori, $uraian, $q->in('kriteria_acuan'), $q->in('bukti_objektif'), $q->user['id']]);
        Db::run('INSERT IGNORE INTO corrective_actions (finding_id) VALUES (?)', [$fid]);
        return new Respons(['id' => $fid, 'code' => $kode], 201);
    }, $AA);

    /* --------------------------- BERITA ACARA -------------------------- */
    $r->get("$B/:id/berita-acara", function (Request $q) {
        $audit = Db::one('SELECT a.*, un.name AS unit_name, un.code AS unit_code, un.type AS unit_type, p.name AS period_name, p.year,
                                 la.name AS lead_auditor_name, la.jabatan AS lead_auditor_jabatan, la.nip AS lead_auditor_nip,
                                 ae.name AS auditee_name, ae.jabatan AS auditee_jabatan, ae.nip AS auditee_nip
                            FROM audits a JOIN units un ON un.id=a.unit_id JOIN periods p ON p.id=a.period_id
                            LEFT JOIN users la ON la.id=a.lead_auditor_id LEFT JOIN users ae ON ae.id=a.auditee_id WHERE a.id=?', [(int)$q->params['id']]);
        if (!$audit) throw new HttpError('Audit tidak ditemukan', 404);
        $aid = (int)$audit['id'];
        $anggota = Db::all('SELECT m.peran, u.name, u.jabatan, u.nip FROM audit_members m JOIN users u ON u.id=m.user_id WHERE m.audit_id=?', [$aid]);
        $temuan = Db::all("SELECT f.*, i.indicator, i.code AS indikator_kode, i.target, s.code AS standar_kode, s.title AS standar_judul
                             FROM findings f LEFT JOIN audit_assessments a ON a.id=f.assessment_id LEFT JOIN standard_indicators i ON i.id=a.indicator_id
                             LEFT JOIN standards s ON s.id=i.standard_id WHERE f.audit_id=?
                            ORDER BY CASE f.kategori WHEN 'KTS Mayor' THEN 1 WHEN 'KTS Minor' THEN 2 ELSE 3 END, f.id", [$aid]);
        $ringkasan = Db::all('SELECT verdict, COUNT(*) c, AVG(capaian_persen) avg_capaian, AVG(skor) avg_skor FROM audit_assessments WHERE audit_id=? GROUP BY verdict', [$aid]);
        $total = Db::one('SELECT COUNT(*) total, AVG(capaian_persen) rata_capaian, AVG(skor) rata_skor FROM audit_assessments WHERE audit_id=?', [$aid]);
        $kel = Db::all("SELECT COALESCE(r.code, '') AS acuan_kode, r.name AS acuan_nama, r.skor_maks, COUNT(*) c, AVG(as_.skor) avg_skor
                          FROM audit_assessments as_ JOIN standard_indicators i ON i.id = as_.indicator_id
                          LEFT JOIN ref_items ri ON ri.id = i.source_ref_item_id LEFT JOIN refs r ON r.id = ri.ref_id
                         WHERE as_.audit_id=? AND as_.skor IS NOT NULL GROUP BY COALESCE(r.code,''), r.name, r.skor_maks ORDER BY r.name IS NULL, r.name", [$aid]);
        $rs = [];
        foreach ($kel as $k) {
            $biner = $k['skor_maks'] !== null && (float)$k['skor_maks'] <= 1;
            $rs[] = [
                'acuan_kode' => $k['acuan_kode'] !== '' ? $k['acuan_kode'] : null, 'acuan_nama' => $k['acuan_nama'] ?: null,
                'tipe' => $biner ? 'persen' : 'skor', 'skor_maks' => $k['skor_maks'],
                'nilai' => $biner ? round($k['avg_skor'] / $k['skor_maks'] * 1000) / 10 : round($k['avg_skor'] * 100) / 100,
                'jumlah' => $k['c'],
            ];
        }
        $ttd = Db::all("SELECT * FROM signatures WHERE entity_type='audit' AND entity_id=? ORDER BY id", [$aid]);
        return ['audit' => $audit, 'anggota' => $anggota, 'temuan' => $temuan, 'ringkasan' => $ringkasan, 'total' => $total, 'ringkasan_skor' => $rs, 'tanda_tangan' => $ttd];
    }, $L);

    $r->post("$B/:id/tanda-tangan", function (Request $q) {
        $audit = emi_audit((int)$q->params['id']);
        $aid = (int)$audit['id'];
        $peran = $q->in('peran'); $img = $q->in('image_data');
        if (kosong($peran) || kosong($img)) throw new HttpError('peran dan image_data wajib diisi', 400);
        emi_validGambar($img);
        $role = $q->user['role'];
        $bolehAuditor = in_array($role, ['auditor', 'spm', 'admin'], true);
        $bolehAuditee = $role === 'auditee' && (int)$q->user['unit_id'] === (int)$audit['unit_id'];
        $pl = strtolower((string)$peran);
        if (str_contains($pl, 'auditee') && !$bolehAuditee && $role !== 'admin') throw new HttpError('Hanya auditee unit terkait yang dapat menandatangani', 403);
        if (str_contains($pl, 'auditor') && !$bolehAuditor) throw new HttpError('Hanya auditor yang dapat menandatangani', 403);

        Db::run("INSERT INTO signatures (entity_type, entity_id, user_id, peran, nama, jabatan, image_data) VALUES ('audit',?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE image_data=VALUES(image_data), signed_at=NOW()", [$aid, $q->user['id'], $peran, $q->user['name'], $q->user['jabatan'], $img]);

        $peranTtd = array_map('strtolower', array_column(Db::all("SELECT peran FROM signatures WHERE entity_type='audit' AND entity_id=?", [$aid]), 'peran'));
        $adaAuditor = (bool)array_filter($peranTtd, fn($p) => str_contains($p, 'auditor'));
        $adaAuditee = (bool)array_filter($peranTtd, fn($p) => str_contains($p, 'auditee'));
        if ($adaAuditor && $adaAuditee) {
            Db::run("UPDATE audits SET status='ditandatangani', executed_date = COALESCE(NULLIF(executed_date,''), ?) WHERE id=?", [date('Y-m-d'), $aid]);
            Db::run("UPDATE findings SET status='tindakan_koreksi' WHERE audit_id=? AND status='terbuka'", [$aid]);
        }
        Db::log((int)$q->user['id'], 'tanda_tangan_ba', 'audits', $aid, (string)$peran);
        return ['ok' => true, 'lengkap' => $adaAuditor && $adaAuditee];
    }, $L);

    $r->delete("$B/:id/tanda-tangan", function (Request $q) {
        $audit = emi_audit((int)$q->params['id']);
        $sig = Db::one("SELECT * FROM signatures WHERE entity_type='audit' AND entity_id=? AND user_id=?", [$audit['id'], $q->user['id']]);
        if (!$sig) throw new HttpError('Anda belum menandatangani berita acara ini', 404);
        Db::run('DELETE FROM signatures WHERE id=?', [$sig['id']]);
        if ($audit['status'] === 'ditandatangani') Db::run("UPDATE audits SET status='selesai' WHERE id=?", [$audit['id']]);
        Db::log((int)$q->user['id'], 'tarik_ttd_ba', 'audits', (int)$audit['id'], (string)$sig['peran']);
        return ['ok' => true];
    }, $L);
}
