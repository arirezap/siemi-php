<?php
declare(strict_types=1);

final class Berkas
{
    public const EKSTENSI_AMAN = ['pdf','doc','docx','xls','xlsx','ppt','pptx','csv','txt','png','jpg','jpeg','gif','webp','zip','rar','odt','ods'];

    /** Simpan ke folder unggahan; kembalikan URL web-nya. */
    public static function simpan(string $nama, string $isi): string
    {
        $dir = (string)Config::get('upload_dir');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new HttpError('Folder unggahan tidak bisa dibuat', 500);
        $aman = preg_replace('/[^\w.\-]/', '_', $nama);
        $ext = strtolower(pathinfo($aman, PATHINFO_EXTENSION));
        if (!in_array($ext, self::EKSTENSI_AMAN, true)) throw new HttpError('Jenis berkas tidak diizinkan (.' . $ext . ')', 400);
        if (preg_match('/\.(php|phtml|phar|pl|cgi|sh|htaccess)\b/i', $aman)) throw new HttpError('Nama berkas tidak diizinkan', 400);
        if (file_put_contents($dir . '/' . $aman, $isi) === false) throw new HttpError('Gagal menyimpan berkas', 500);
        return rtrim((string)Config::get('upload_url', '/uploads'), '/') . '/' . $aman;
    }
}
