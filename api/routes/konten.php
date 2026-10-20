<?php
declare(strict_types=1);

function rute_konten(Router $r): void
{
    $mw = [[Auth::class, 'wajibLogin'], Auth::peran('spm')];

    $slugUnik = function (string $tabel, string $basis, int $idKecuali = 0): string {
        $dasar = Slug::buat($basis) ?: 'tanpa-judul';
        $slug = $dasar; $n = 1;
        while (Db::one("SELECT id FROM $tabel WHERE slug=? AND id<>?", [$slug, $idKecuali])) { $n++; $slug = "$dasar-$n"; }
        return $slug;
    };
    $pilihSlug = function (mixed $input, string $judul): string {
        $in = teks($input);
        return ($in !== '' && Slug::valid(Slug::buat($in))) ? $in : $judul;
    };

    /* ------------------------------ BERITA ----------------------------- */
    $r->get('/konten/posts', fn() => Db::all(
        'SELECT p.id, p.slug, p.title, p.excerpt, p.cover_url, p.status, p.published_at, p.created_at, p.updated_at,
                u.name AS created_by_name
           FROM posts p LEFT JOIN users u ON u.id = p.created_by ORDER BY p.created_at DESC'), $mw);

    $r->get('/konten/posts/:id', function (Request $q) {
        $row = Db::one('SELECT * FROM posts WHERE id=?', [(int)$q->params['id']]);
        if (!$row) throw new HttpError('Berita tidak ditemukan', 404);
        return $row;
    }, $mw);

    $r->post('/konten/posts', function (Request $q) use ($slugUnik, $pilihSlug) {
        $title = teks($q->in('title')); $content = teks($q->in('content'));
        if ($title === '' || $content === '') throw new HttpError('Judul dan isi wajib diisi', 400);
        $st = $q->in('status') === 'terbit' ? 'terbit' : 'draft';
        $slug = $slugUnik('posts', $pilihSlug($q->in('slug'), $title));
        $id = Db::insert('INSERT INTO posts (slug, title, excerpt, content, cover_url, status, published_at, created_by) VALUES (?,?,?,?,?,?,?,?)', [
            $slug, $title, teksAtauNull($q->in('excerpt')), (string)$q->in('content'), $q->in('cover_url') ?: null, $st,
            $st === 'terbit' ? waktuSekarang() : null, $q->user['id'],
        ]);
        Db::log((int)$q->user['id'], 'tambah', 'posts', $id, "Berita baru: $title");
        return new Respons(['id' => $id, 'slug' => $slug], 201);
    }, $mw);

    $r->put('/konten/posts/:id', function (Request $q) use ($slugUnik) {
        $ex = Db::one('SELECT * FROM posts WHERE id=?', [(int)$q->params['id']]);
        if (!$ex) throw new HttpError('Berita tidak ditemukan', 404);
        $title = teks($q->in('title')); $content = teks($q->in('content'));
        if ($title === '' || $content === '') throw new HttpError('Judul dan isi wajib diisi', 400);
        $st = $q->in('status') === 'terbit' ? 'terbit' : 'draft';
        $slug = $ex['slug']; $baru = teks($q->in('slug'));
        if ($baru !== '' && $baru !== $ex['slug'] && Slug::valid(Slug::buat($baru))) $slug = $slugUnik('posts', $baru, (int)$ex['id']);
        $pub = $st === 'terbit' ? ($ex['published_at'] ?: waktuSekarang()) : null;
        Db::run('UPDATE posts SET title=?, excerpt=?, content=?, cover_url=?, status=?, slug=?, published_at=?, updated_at=NOW() WHERE id=?', [
            $title, teksAtauNull($q->in('excerpt')), (string)$q->in('content'), $q->in('cover_url') ?: null, $st, $slug, $pub, $ex['id'],
        ]);
        Db::log((int)$q->user['id'], 'ubah', 'posts', (int)$ex['id'], "Berita diubah: $title");
        return ['ok' => true, 'slug' => $slug];
    }, $mw);

    $r->delete('/konten/posts/:id', function (Request $q) {
        $id = (int)$q->params['id'];
        $ex = Db::one('SELECT title FROM posts WHERE id=?', [$id]);
        if (!$ex) throw new HttpError('Berita tidak ditemukan', 404);
        Db::run('DELETE FROM posts WHERE id=?', [$id]);
        Db::log((int)$q->user['id'], 'hapus', 'posts', $id, 'Berita dihapus: ' . $ex['title']);
        return ['ok' => true];
    }, $mw);

    /* ------------------------------ HALAMAN ---------------------------- */
    // Tautan eksternal: hanya http/https; tanpa skema -> awalan https://
    $normTautan = function (mixed $in): array {
        $t = teks($in);
        if ($t === '') return [true, null];
        if (preg_match('#^https?://#i', $t)) return [true, $t];
        if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $t)) return [false, 'Tautan eksternal harus berupa alamat web (http/https)'];
        return [true, "https://$t"];
    };
    $cekParent = function (mixed $parentIn, ?int $existingId): array {
        if (kosong($parentIn)) return [true, null];
        $pid = (int)$parentIn;
        if ($existingId && $pid === $existingId) return [false, 'Halaman tidak boleh menjadi induk dirinya sendiri'];
        $induk = Db::one('SELECT id, parent_id FROM pages WHERE id=?', [$pid]);
        if (!$induk) return [false, 'Halaman induk tidak ditemukan'];
        if ($induk['parent_id']) return [false, 'Halaman induk tidak boleh berupa sub-halaman (maksimal 2 tingkat)'];
        if ($existingId && Db::one('SELECT 1 AS x FROM pages WHERE parent_id=?', [$existingId]))
            return [false, 'Halaman ini sudah punya sub-halaman sendiri, tidak bisa dijadikan sub-halaman dari halaman lain'];
        return [true, $pid];
    };

    $r->get('/konten/pages', fn() => Db::all(
        'SELECT p.id, p.slug, p.title, p.status, p.sort_order, p.parent_id, p.external_url, par.title AS parent_title,
                p.created_at, p.updated_at, u.name AS created_by_name
           FROM pages p LEFT JOIN users u ON u.id = p.created_by LEFT JOIN pages par ON par.id = p.parent_id
          ORDER BY COALESCE(par.sort_order, p.sort_order), (p.parent_id IS NOT NULL), p.sort_order, p.title'), $mw);

    $r->get('/konten/pages/:id', function (Request $q) {
        $row = Db::one('SELECT * FROM pages WHERE id=?', [(int)$q->params['id']]);
        if (!$row) throw new HttpError('Halaman tidak ditemukan', 404);
        return $row;
    }, $mw);

    $simpanHalaman = function (Request $q, ?array $ex) use ($normTautan, $cekParent, $slugUnik, $pilihSlug) {
        [$okT, $url] = $normTautan($q->in('external_url'));
        if (!$okT) throw new HttpError((string)$url, 400);
        $title = teks($q->in('title')); $content = teks($q->in('content'));
        if ($title === '' || (!$url && $content === ''))
            throw new HttpError('Judul wajib diisi, dan isi wajib diisi kecuali halaman ini memakai tautan eksternal', 400);
        [$okP, $parent] = $cekParent($q->in('parent_id'), $ex ? (int)$ex['id'] : null);
        if (!$okP) throw new HttpError((string)$parent, 400);
        $st = $q->in('status') === 'terbit' ? 'terbit' : 'draft';
        $urut = (int)$q->in('sort_order', 0);
        if (!$ex) {
            $slug = $slugUnik('pages', $pilihSlug($q->in('slug'), $title));
            $id = Db::insert('INSERT INTO pages (slug, title, content, status, sort_order, parent_id, external_url, created_by) VALUES (?,?,?,?,?,?,?,?)',
                [$slug, $title, (string)($q->in('content') ?? ''), $st, $urut, $parent, $url, $q->user['id']]);
            Db::log((int)$q->user['id'], 'tambah', 'pages', $id, "Halaman baru: $title");
            return new Respons(['id' => $id, 'slug' => $slug], 201);
        }
        $slug = $ex['slug']; $baru = teks($q->in('slug'));
        if ($baru !== '' && $baru !== $ex['slug'] && Slug::valid(Slug::buat($baru))) $slug = $slugUnik('pages', $baru, (int)$ex['id']);
        Db::run('UPDATE pages SET title=?, content=?, status=?, sort_order=?, slug=?, parent_id=?, external_url=?, updated_at=NOW() WHERE id=?',
            [$title, (string)($q->in('content') ?? ''), $st, $urut, $slug, $parent, $url, $ex['id']]);
        Db::log((int)$q->user['id'], 'ubah', 'pages', (int)$ex['id'], "Halaman diubah: $title");
        return ['ok' => true, 'slug' => $slug];
    };

    $r->post('/konten/pages', fn(Request $q) => $simpanHalaman($q, null), $mw);

    $r->put('/konten/pages/:id', function (Request $q) use ($simpanHalaman) {
        $ex = Db::one('SELECT * FROM pages WHERE id=?', [(int)$q->params['id']]);
        if (!$ex) throw new HttpError('Halaman tidak ditemukan', 404);
        return $simpanHalaman($q, $ex);
    }, $mw);

    $r->delete('/konten/pages/:id', function (Request $q) {
        $id = (int)$q->params['id'];
        $ex = Db::one('SELECT title FROM pages WHERE id=?', [$id]);
        if (!$ex) throw new HttpError('Halaman tidak ditemukan', 404);
        Db::run('DELETE FROM pages WHERE id=?', [$id]);
        Db::log((int)$q->user['id'], 'hapus', 'pages', $id, 'Halaman dihapus: ' . $ex['title']);
        return ['ok' => true];
    }, $mw);

    /* ---------------------------- UNGGAH SAMPUL ------------------------ */
    $r->post('/konten/unggah-sampul', function (Request $q) {
        $nama = $q->in('filename'); $data = $q->in('data');
        if (kosong($nama) || kosong($data)) throw new HttpError('filename dan data wajib diisi', 400);
        if (!preg_match('#^data:image/(png|jpe?g|webp|gif);base64,(.+)$#is', (string)$data, $m))
            throw new HttpError('Berkas harus berupa gambar (PNG/JPG/WEBP/GIF)', 400);
        $buf = base64_decode($m[2], true);
        if ($buf === false) throw new HttpError('Data gambar tidak valid', 400);
        if (strlen($buf) > 5 * 1024 * 1024) throw new HttpError('Ukuran gambar maksimal 5 MB', 400);
        // Pastikan isinya benar-benar gambar (bukan skrip yang menyamar)
        if (@getimagesizefromstring($buf) === false) throw new HttpError('Berkas bukan gambar yang valid', 400);
        $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
        $nm = sprintf('sampul-%d-%d-%s.%s', (int)(microtime(true) * 1000), $q->user['id'], bin2hex(random_bytes(3)), $ext);
        $url = Berkas::simpan($nm, $buf);
        return ['url' => $url];
    }, $mw);
}
