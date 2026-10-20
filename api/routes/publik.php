<?php
declare(strict_types=1);

/* RUTE PUBLIK — tanpa login. Aturan keamanan (sama dengan versi Node):
   1. Berita/halaman hanya status='terbit', difilter di SETIAP query.
   2. Rekap mutu hanya angka agregat; JANGAN pernah men-SELECT kolom naratif temuan/tindakan koreksi. */
function rute_publik(Router $r): void
{
    $batas = function (Request $q): void { Http::batasiLaju($q->ip(), 120, 60); };

    $r->get('/publik/berita', function (Request $q) {
        $limit = min(max((int)$q->query('limit', 20) ?: 20, 1), 50);
        $offset = max((int)$q->query('offset', 0), 0);
        $items = Db::all("SELECT id, slug, title, excerpt, cover_url, published_at FROM posts
                           WHERE status='terbit' ORDER BY published_at DESC LIMIT ? OFFSET ?", [$limit, $offset]);
        $total = (int)Db::val("SELECT COUNT(*) FROM posts WHERE status='terbit'");
        return ['items' => $items, 'total' => $total];
    }, [$batas]);

    $r->get('/publik/berita/:slug', function (Request $q) {
        $s = $q->params['slug'];
        if (!Slug::valid($s)) throw new HttpError('Berita tidak ditemukan', 404);
        $row = Db::one("SELECT id, slug, title, excerpt, content, cover_url, published_at FROM posts WHERE slug=? AND status='terbit'", [$s]);
        if (!$row) throw new HttpError('Berita tidak ditemukan', 404);
        return $row;
    }, [$batas]);

    $r->get('/publik/halaman', function () {
        $induk = Db::all("SELECT id, slug, title, external_url FROM pages WHERE status='terbit' AND parent_id IS NULL ORDER BY sort_order, title");
        $out = [];
        foreach ($induk as $p) {
            $anak = Db::all("SELECT slug, title, external_url FROM pages WHERE status='terbit' AND parent_id=? ORDER BY sort_order, title", [$p['id']]);
            $out[] = ['slug' => $p['slug'], 'title' => $p['title'], 'external_url' => $p['external_url'], 'children' => $anak];
        }
        return $out;
    }, [$batas]);

    $r->get('/publik/halaman/:slug', function (Request $q) {
        $s = $q->params['slug'];
        if (!Slug::valid($s)) throw new HttpError('Halaman tidak ditemukan', 404);
        $row = Db::one("SELECT slug, title, content, external_url FROM pages WHERE slug=? AND status='terbit'", [$s]);
        if (!$row) throw new HttpError('Halaman tidak ditemukan', 404);
        return $row;
    }, [$batas]);

    $r->get('/publik/rekap-mutu', function (Request $q) {
        $pid = (int)$q->query('period_id', 0)
            ?: (int)Db::val("SELECT id FROM periods WHERE status='aktif' ORDER BY id DESC")
            ?: (int)Db::val('SELECT id FROM periods ORDER BY id DESC');

        $daftar = Db::all("SELECT DISTINCT p.id, p.name, p.year FROM periods p
             WHERE EXISTS (SELECT 1 FROM audits a JOIN findings f ON f.audit_id = a.id WHERE a.period_id = p.id)
                OR EXISTS (SELECT 1 FROM standards s WHERE s.period_id = p.id AND s.status='ditetapkan')
             ORDER BY p.year DESC, p.id DESC");

        if (!$pid) return ['period' => null, 'kategori' => [], 'total' => ['temuan' => 0, 'selesai' => 0], 'standar' => [], 'daftar_periode' => []];

        $period = Db::one('SELECT id, name, year FROM periods WHERE id=?', [$pid]);
        $kategori = Db::all("SELECT f.kategori, COUNT(*) AS jumlah, SUM(CASE WHEN f.status='ditutup' THEN 1 ELSE 0 END) AS selesai
                               FROM findings f JOIN audits a ON a.id = f.audit_id WHERE a.period_id = ?
                              GROUP BY f.kategori ORDER BY f.kategori", [$pid]);
        $total = ['temuan' => 0, 'selesai' => 0];
        foreach ($kategori as $k) { $total['temuan'] += (int)$k['jumlah']; $total['selesai'] += (int)$k['selesai']; }
        $standar = Db::all("SELECT sifat, COUNT(*) AS jumlah FROM standards WHERE period_id=? AND status='ditetapkan' GROUP BY sifat", [$pid]);
        return ['period' => $period, 'kategori' => $kategori, 'total' => $total, 'standar' => $standar, 'daftar_periode' => $daftar];
    }, [$batas]);
}
