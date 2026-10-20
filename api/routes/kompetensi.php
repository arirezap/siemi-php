<?php
declare(strict_types=1);

/* KOMPETENSI AUDITOR (padanan server/src/routes/kompetensi.js) */

const KOMP_JENIS_LABEL = [
    'pelatihan_spmi' => 'Sertifikat Pelatihan SPMI',
    'sertifikat_auditor' => 'Sertifikat Auditor',
    'lainnya' => 'Sertifikat / Pelatihan Lainnya',
];
const KOMP_JENIS_WAJIB = ['pelatihan_spmi', 'sertifikat_auditor'];

function rute_kompetensi(Router $r): void
{
    $L = [[Auth::class, 'wajibLogin']];
    $B = '/kompetensi';
    $auditor = [[Auth::class, 'wajibLogin'], Auth::peran('auditor')];
    $spm = [[Auth::class, 'wajibLogin'], Auth::peran('spm')];
    $keduanya = [[Auth::class, 'wajibLogin'], Auth::peran('auditor', 'spm')];

    $r->get("$B/meta/jenis", fn() => ['jenis' => KOMP_JENIS_LABEL, 'wajib' => KOMP_JENIS_WAJIB], $L);

    $r->get("$B/saya", fn(Request $q) => Db::all('SELECT * FROM auditor_certificates WHERE user_id=? ORDER BY jenis, tanggal_terbit DESC', [$q->user['id']]), $auditor);

    $r->get($B, function () {
        $auditors = Db::all("SELECT u.id, u.name, u.email, u.jabatan, u.unit_id, un.name AS unit_name FROM users u
                               LEFT JOIN units un ON un.id = u.unit_id WHERE u.role='auditor' AND u.is_active=1 ORDER BY u.name");
        $certs = Db::all('SELECT * FROM auditor_certificates ORDER BY jenis, tanggal_terbit DESC');
        $today = date('Y-m-d');
        $hasil = [];
        foreach ($auditors as $a) {
            $milik = array_values(array_filter($certs, fn($c) => (int)$c['user_id'] === (int)$a['id']));
            $pem = [];
            foreach (KOMP_JENIS_WAJIB as $j) {
                $punya = array_values(array_filter($milik, fn($c) => $c['jenis'] === $j));
                $berlaku = array_filter($punya, fn($c) => kosong($c['tanggal_kedaluwarsa']) || $c['tanggal_kedaluwarsa'] >= $today);
                $pem[$j] = !$punya ? 'belum' : ($berlaku ? 'berlaku' : 'kedaluwarsa');
            }
            $hasil[] = array_merge($a, ['sertifikat' => $milik, 'pemenuhan' => $pem]);
        }
        return $hasil;
    }, $spm);

    $r->get("$B/auditor/:userId", function (Request $q) {
        $t = Db::one('SELECT id, role FROM users WHERE id=?', [(int)$q->params['userId']]);
        if (!$t || $t['role'] !== 'auditor') throw new HttpError('Auditor tidak ditemukan', 404);
        return Db::all('SELECT * FROM auditor_certificates WHERE user_id=? ORDER BY jenis, tanggal_terbit DESC', [(int)$q->params['userId']]);
    }, $spm);

    $boleh = fn(Request $q, mixed $target): bool =>
        in_array($q->user['role'], ['spm', 'admin'], true) || ($q->user['role'] === 'auditor' && (int)$q->user['id'] === (int)$target);

    $r->post($B, function (Request $q) {
        $target = $q->user['role'] === 'auditor' ? (int)$q->user['id'] : (int)$q->in('user_id');
        if (!$target) throw new HttpError('user_id wajib diisi', 400);
        $jenis = $q->in('jenis');
        if (!is_string($jenis) || !isset(KOMP_JENIS_LABEL[$jenis])) throw new HttpError('Jenis sertifikat tidak dikenal', 400);
        $nama = teks($q->in('nama'));
        if ($nama === '') throw new HttpError('Nama sertifikat/pelatihan wajib diisi', 400);
        $t = Db::one('SELECT role FROM users WHERE id=?', [$target]);
        if (!$t || $t['role'] !== 'auditor') throw new HttpError('Pengguna target bukan auditor', 400);
        $id = Db::insert('INSERT INTO auditor_certificates (user_id, jenis, nama, penyelenggara, nomor_sertifikat, tanggal_terbit, tanggal_kedaluwarsa, berkas_url, keterangan, created_by)
                          VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$target, $jenis, $q->in('nama'), $q->in('penyelenggara'), $q->in('nomor_sertifikat'), $q->in('tanggal_terbit'), $q->in('tanggal_kedaluwarsa'),
             $q->in('berkas_url'), $q->in('keterangan'), $q->user['id']]);
        Db::log((int)$q->user['id'], 'tambah_sertifikat_auditor', 'auditor_certificates', $id, (string)$q->in('nama'));
        return new Respons(['id' => $id], 201);
    }, $keduanya);

    $r->put("$B/:id", function (Request $q) use ($boleh) {
        $c = Db::one('SELECT * FROM auditor_certificates WHERE id=?', [(int)$q->params['id']]);
        if (!$c) throw new HttpError('Sertifikat tidak ditemukan', 404);
        if (!$boleh($q, $c['user_id'])) throw new HttpError('Bukan sertifikat Anda', 403);
        $jenis = $q->in('jenis');
        if (!kosong($jenis) && !isset(KOMP_JENIS_LABEL[(string)$jenis])) throw new HttpError('Jenis sertifikat tidak dikenal', 400);
        Db::run("UPDATE auditor_certificates SET jenis=?, nama=?, penyelenggara=?, nomor_sertifikat=?, tanggal_terbit=?, tanggal_kedaluwarsa=?, berkas_url=?, keterangan=?, updated_at=NOW() WHERE id=?", [
            $jenis ?? $c['jenis'], $q->in('nama') ?? $c['nama'], $q->in('penyelenggara') ?? $c['penyelenggara'], $q->in('nomor_sertifikat') ?? $c['nomor_sertifikat'],
            $q->in('tanggal_terbit') ?? $c['tanggal_terbit'], $q->in('tanggal_kedaluwarsa') ?? $c['tanggal_kedaluwarsa'],
            $q->in('berkas_url') ?? $c['berkas_url'], $q->in('keterangan') ?? $c['keterangan'], $c['id'],
        ]);
        Db::log((int)$q->user['id'], 'ubah_sertifikat_auditor', 'auditor_certificates', (int)$c['id']);
        return ['ok' => true];
    }, $keduanya);

    $r->delete("$B/:id", function (Request $q) use ($boleh) {
        $c = Db::one('SELECT * FROM auditor_certificates WHERE id=?', [(int)$q->params['id']]);
        if (!$c) throw new HttpError('Sertifikat tidak ditemukan', 404);
        if (!$boleh($q, $c['user_id'])) throw new HttpError('Bukan sertifikat Anda', 403);
        Db::run('DELETE FROM auditor_certificates WHERE id=?', [$c['id']]);
        Db::log((int)$q->user['id'], 'hapus_sertifikat_auditor', 'auditor_certificates', (int)$c['id'], $c['nama']);
        return ['ok' => true];
    }, $keduanya);
}
