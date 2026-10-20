<?php
declare(strict_types=1);

function rute_auth(Router $r): void
{
    $login = [Auth::class, 'wajibLogin'];

    $r->post('/auth/login', function (Request $q) {
        $email = $q->in('email');
        $pass  = $q->in('password');
        if (kosong($email) || kosong($pass)) throw new HttpError('Email dan kata sandi wajib diisi', 400);

        $u = Db::one('SELECT * FROM users WHERE LOWER(email) = LOWER(?)', [teks($email)]);
        if (!$u || !password_verify((string)$pass, $u['password_hash']))
            throw new HttpError('Email atau kata sandi salah', 401);
        if (!$u['is_active']) throw new HttpError('Akun dinonaktifkan', 403);

        $unit = $u['unit_id'] ? Db::one('SELECT name, code FROM units WHERE id = ?', [$u['unit_id']]) : null;
        Db::log((int)$u['id'], 'login', 'users', (int)$u['id']);

        return [
            'token' => Auth::buatToken($u),
            'user'  => [
                'id' => $u['id'], 'nip' => $u['nip'], 'name' => $u['name'], 'email' => $u['email'],
                'role' => $u['role'], 'jabatan' => $u['jabatan'], 'unit_id' => $u['unit_id'],
                'unit_name' => $unit['name'] ?? null, 'unit_code' => $unit['code'] ?? null,
            ],
        ];
    });

    $r->get('/auth/me', fn(Request $q) => ['user' => $q->user], [$login]);

    $r->post('/auth/ubah-sandi', function (Request $q) {
        $lama = (string)$q->in('sandi_lama', '');
        $baru = (string)$q->in('sandi_baru', '');
        if (strlen($baru) < 6) throw new HttpError('Kata sandi baru minimal 6 karakter', 400);
        $hash = Db::val('SELECT password_hash FROM users WHERE id = ?', [$q->user['id']]);
        if (!password_verify($lama, (string)$hash)) throw new HttpError('Kata sandi lama salah', 400);
        Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($baru, PASSWORD_BCRYPT, ['cost' => 10]), $q->user['id']]);
        Db::log((int)$q->user['id'], 'ubah_sandi', 'users', (int)$q->user['id']);
        return ['ok' => true];
    }, [$login]);

    $r->put('/auth/surel', function (Request $q) {
        $email = teks($q->in('email'));
        if ($email === '' || !preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email)) throw new HttpError('Surel tidak valid', 400);
        $hash = Db::val('SELECT password_hash FROM users WHERE id = ?', [$q->user['id']]);
        if (!password_verify((string)$q->in('sandi_saat_ini', ''), (string)$hash)) throw new HttpError('Kata sandi saat ini salah', 400);
        try {
            Db::run('UPDATE users SET email = ? WHERE id = ?', [$email, $q->user['id']]);
        } catch (PDOException $e) {
            throw new HttpError('Surel sudah digunakan akun lain', 400);
        }
        Db::log((int)$q->user['id'], 'ubah_surel', 'users', (int)$q->user['id'], $email);
        return ['ok' => true, 'email' => $email];
    }, [$login]);
}
