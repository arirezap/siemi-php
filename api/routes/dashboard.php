<?php
declare(strict_types=1);

function rute_dashboard(Router $r): void
{
    $L = [[Auth::class, 'wajibLogin']];

    $r->get('/dashboard/ringkasan', function (Request $q) {
        $pid = (int)$q->query('period_id', 0)
            ?: (int)Db::val("SELECT id FROM periods WHERE status='aktif' ORDER BY id DESC")
            ?: (int)Db::val('SELECT id FROM periods ORDER BY id DESC');
        if (!$pid) return ['period' => null];

        $period = Db::one('SELECT * FROM periods WHERE id=?', [$pid]);
        $uf = $q->user['role'] === 'auditee' ? (int)$q->user['unit_id'] : null;
        $px = $uf ? [$pid, $uf] : [$pid];

        $standar = Db::one("SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN status='ditetapkan' THEN 1 ELSE 0 END),0) ditetapkan
                              FROM standards WHERE period_id=?", [$pid]);
        $indikator = (int)Db::val("SELECT COUNT(*) FROM standard_indicators i JOIN standards s ON s.id=i.standard_id
                                    WHERE s.period_id=? AND s.status='ditetapkan'", [$pid]);
        $pelaksanaan = Db::one("SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN status IN ('diserahkan','dinilai') THEN 1 ELSE 0 END),0) diserahkan
                                  FROM implementations WHERE period_id=? " . ($uf ? 'AND unit_id=?' : ''), $px);
        $audit = Db::one("SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN status='ditandatangani' THEN 1 ELSE 0 END),0) selesai
                            FROM audits WHERE period_id=? " . ($uf ? 'AND unit_id=?' : ''), $px);
        $temuan = Db::all("SELECT f.kategori, COUNT(*) jumlah, SUM(CASE WHEN f.status='ditutup' THEN 1 ELSE 0 END) ditutup
                             FROM findings f JOIN audits a ON a.id=f.audit_id
                            WHERE a.period_id=? " . ($uf ? 'AND a.unit_id=?' : '') . ' GROUP BY f.kategori', $px);
        $rtm = Db::one("SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN status='ditandatangani' THEN 1 ELSE 0 END),0) selesai
                          FROM rtm_meetings WHERE period_id=?", [$pid]);
        $capKat = Db::all('SELECT s.category, ROUND(AVG(ass.capaian_persen),1) rata
                             FROM audit_assessments ass
                             JOIN audits a ON a.id=ass.audit_id AND a.period_id=?
                             JOIN standard_indicators i ON i.id=ass.indicator_id
                             JOIN standards s ON s.id=i.standard_id ' . ($uf ? 'WHERE a.unit_id=?' : '') . '
                            GROUP BY s.category ORDER BY s.category', $px);
        $tenggat = Db::all("SELECT f.code, f.kategori, un.name AS unit_name, ca.rencana_tanggal, ca.status
                              FROM corrective_actions ca JOIN findings f ON f.id=ca.finding_id
                              JOIN audits a ON a.id=f.audit_id JOIN units un ON un.id=a.unit_id
                             WHERE a.period_id=? AND f.status<>'ditutup' AND ca.rencana_tanggal IS NOT NULL "
                           . ($uf ? 'AND a.unit_id=?' : '') . ' ORDER BY ca.rencana_tanggal LIMIT 10', $px);

        return ['period' => $period, 'standar' => $standar, 'indikator' => $indikator, 'pelaksanaan' => $pelaksanaan,
                'audit' => $audit, 'temuan' => $temuan, 'rtm' => $rtm, 'capaian_kategori' => $capKat, 'tenggat' => $tenggat];
    }, $L);

    $r->get('/dashboard/capaian-unit', function (Request $q) {
        return Db::all("SELECT un.name AS unit_name, un.code, ROUND(AVG(ass.capaian_persen),1) rata_capaian,
                               COUNT(ass.id) jumlah_indikator,
                               COALESCE(SUM(CASE WHEN ass.verdict LIKE 'kts%' THEN 1 ELSE 0 END),0) kts
                          FROM audits a JOIN units un ON un.id=a.unit_id
                          LEFT JOIN audit_assessments ass ON ass.audit_id=a.id
                         WHERE a.period_id=? GROUP BY un.id, un.name, un.code ORDER BY rata_capaian DESC", [(int)$q->query('period_id', 0)]);
    }, $L);
}
