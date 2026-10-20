<?php
declare(strict_types=1);

function rute_master(Router $r): void
{
    $L   = [Auth::class, 'wajibLogin'];
    $spm = [$L, Auth::peran('spm')];
    $adm = [$L, Auth::peran('admin')];

    /* ------------------------------- UNIT ------------------------------ */
    $r->get('/master/units', fn() => Db::all(
        'SELECT u.*, p.name AS parent_name FROM units u LEFT JOIN units p ON p.id = u.parent_id
          WHERE u.is_active = 1 ORDER BY u.type, u.name'), [$L]);

    $r->post('/master/units', function (Request $q) {
        $code = $q->in('code'); $name = $q->in('name'); $type = $q->in('type');
        if (kosong($code) || kosong($name) || kosong($type)) throw new HttpError('code, name, dan type wajib diisi', 400);
        $parent = $q->in('parent_id');
        if ($type === 'sub_unit' && kosong($parent)) throw new HttpError('Sub-unit kerja wajib memilih unit induk', 400);
        $id = Db::insert('INSERT INTO units (code, name, type, parent_id, jenjang) VALUES (?,?,?,?,?)',
            [$code, $name, $type, $parent ?: null, $type === 'prodi' ? ($q->in('jenjang') ?: null) : null]);
        return new Respons(['id' => $id], 201);
    }, $spm);

    $r->put('/master/units/:id', function (Request $q) {
        $type = $q->in('type'); $parent = $q->in('parent_id');
        if ($type === 'sub_unit' && kosong($parent)) throw new HttpError('Sub-unit kerja wajib memilih unit induk', 400);
        Db::run('UPDATE units SET code=?, name=?, type=?, parent_id=?, is_active=?, jenjang=? WHERE id=?', [
            $q->in('code'), $q->in('name'), $type, $parent ?: null, $q->in('is_active', 1) ?? 1,
            $type === 'prodi' ? ($q->in('jenjang') ?: null) : null, (int)$q->params['id'],
        ]);
        return ['ok' => true];
    }, $spm);

    $r->get('/master/jenjang', function () {
        $a = array_column(Db::all("SELECT DISTINCT jenjang FROM units WHERE jenjang IS NOT NULL AND jenjang<>''"), 'jenjang');
        $b = array_column(Db::all("SELECT DISTINCT jenjang FROM refs WHERE jenjang IS NOT NULL AND jenjang<>''"), 'jenjang');
        $s = array_values(array_unique(array_merge(['Diploma III', 'Sarjana Terapan'], $a, $b)));
        sort($s, SORT_STRING);
        return $s;
    }, [$L]);

    /* ------------------------------- RANAH ----------------------------- */
    $r->get('/master/ranah', fn() => Db::all('SELECT id, code, label, pendek FROM ranah_custom ORDER BY code'), [$L]);

    $r->post('/master/ranah', function (Request $q) {
        $kode = strtoupper(teks($q->in('code')));
        $label = teks($q->in('label'));
        $pendek = teks($q->in('pendek')) ?: $label;
        if ($kode === '' || $label === '') throw new HttpError('Kode dan label ranah wajib diisi', 400);
        if (in_array($kode, ['A', 'B', 'C', 'D', 'E'], true)) throw new HttpError("Kode \"$kode\" sudah dipakai ranah bawaan", 400);
        if (Db::one('SELECT id FROM ranah_custom WHERE code=?', [$kode])) throw new HttpError("Kode ranah \"$kode\" sudah pernah dibuat", 400);
        $id = Db::insert('INSERT INTO ranah_custom (code, label, pendek, created_by) VALUES (?,?,?,?)', [$kode, $label, $pendek, $q->user['id']]);
        Db::log((int)$q->user['id'], 'tambah', 'ranah_custom', $id, "Ranah baru: $kode — $label");
        return new Respons(['id' => $id, 'code' => $kode, 'label' => $label, 'pendek' => $pendek], 201);
    }, $spm);

    /* ------------------------------ PENGGUNA --------------------------- */
    $r->get('/master/users', fn() => Db::all(
        'SELECT u.id, u.nip, u.name, u.email, u.role, u.jabatan, u.unit_id, u.is_active, un.name AS unit_name
           FROM users u LEFT JOIN units un ON un.id = u.unit_id ORDER BY u.role, u.name'),
        [$L, Auth::peran('spm', 'auditor', 'pimpinan')]);

    $r->post('/master/users', function (Request $q) {
        foreach (['name', 'email', 'password', 'role'] as $f)
            if (kosong($q->in($f))) throw new HttpError('name, email, password, dan role wajib diisi', 400);
        try {
            $id = Db::insert('INSERT INTO users (nip, name, email, password_hash, role, jabatan, unit_id) VALUES (?,?,?,?,?,?,?)', [
                $q->in('nip'), $q->in('name'), $q->in('email'),
                password_hash((string)$q->in('password'), PASSWORD_BCRYPT, ['cost' => 10]),
                $q->in('role'), $q->in('jabatan'), $q->in('unit_id'),
            ]);
        } catch (PDOException $e) {
            throw new HttpError('Email atau NIP sudah digunakan', 400);
        }
        Db::log((int)$q->user['id'], 'tambah_pengguna', 'users', $id, (string)$q->in('name'));
        return new Respons(['id' => $id], 201);
    }, $adm);

    $r->put('/master/users/:id', function (Request $q) {
        $id = (int)$q->params['id'];
        try {
            Db::run('UPDATE users SET nip=?, name=?, email=?, role=?, jabatan=?, unit_id=?, is_active=? WHERE id=?', [
                $q->in('nip'), $q->in('name'), $q->in('email'), $q->in('role'), $q->in('jabatan'),
                $q->in('unit_id'), $q->in('is_active', 1) ?? 1, $id,
            ]);
        } catch (PDOException $e) {
            throw new HttpError('Email atau NIP sudah digunakan', 400);
        }
        $pw = $q->in('password');
        if (!kosong($pw)) Db::run('UPDATE users SET password_hash=? WHERE id=?', [password_hash((string)$pw, PASSWORD_BCRYPT, ['cost' => 10]), $id]);
        return ['ok' => true];
    }, $adm);

    /* ------------------------------ PERIODE ---------------------------- */
    $r->get('/master/periods', fn() => Db::all('SELECT * FROM periods ORDER BY year DESC, id DESC'), [$L]);

    $r->get('/master/periods/aktif', fn() =>
        Db::one("SELECT * FROM periods WHERE status='aktif' ORDER BY id DESC") ?? Db::one('SELECT * FROM periods ORDER BY id DESC'), [$L]);

    $r->post('/master/periods', function (Request $q) {
        if (kosong($q->in('name')) || kosong($q->in('year'))) throw new HttpError('Nama dan tahun periode wajib diisi', 400);
        $id = Db::insert('INSERT INTO periods (name, year, start_date, end_date, status) VALUES (?,?,?,?,?)',
            [$q->in('name'), (int)$q->in('year'), $q->in('start_date'), $q->in('end_date'), $q->in('status') ?? 'draft']);
        return new Respons(['id' => $id], 201);
    }, $spm);

    $r->put('/master/periods/:id', function (Request $q) {
        $st = $q->in('status');
        if ($st === 'aktif') Db::run("UPDATE periods SET status='selesai' WHERE status='aktif'");
        Db::run('UPDATE periods SET name=?, year=?, start_date=?, end_date=?, status=? WHERE id=?',
            [$q->in('name'), (int)$q->in('year'), $q->in('start_date'), $q->in('end_date'), $st, (int)$q->params['id']]);
        return ['ok' => true];
    }, $spm);

    /* --------------------------- LOG AKTIVITAS ------------------------- */
    $r->get('/master/log', fn() => Db::all(
        'SELECT l.*, u.name AS user_name FROM activity_log l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 200'), $spm);
}
