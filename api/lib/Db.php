<?php
declare(strict_types=1);

/** Pembungkus tipis PDO MySQL. Semua query memakai parameter terikat (tanda tanya). */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo) return self::$pdo;
        $port = (int)Config::get('db_port', 0);
        $dsn = sprintf('mysql:host=%s;%sdbname=%s;charset=utf8mb4', Config::get('db_host'), $port ? "port=$port;" : '', Config::get('db_name'));
        self::$pdo = new PDO($dsn, (string)Config::get('db_user'), (string)Config::get('db_pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        $tz = (string)Config::get('db_timezone', '+07:00');
        self::$pdo->exec("SET time_zone = '" . preg_replace('/[^0-9+\-:A-Za-z\/_]/', '', $tz) . "'");
        self::$pdo->exec('SET SESSION group_concat_max_len = 1000000');
        self::$pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        return self::$pdo;
    }

    private static function jalankan(string $sql, array $p): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $i = 1;
        foreach ($p as $v) {
            if ($v === null)            $st->bindValue($i, null, PDO::PARAM_NULL);
            elseif (is_bool($v))        $st->bindValue($i, $v ? 1 : 0, PDO::PARAM_INT);
            elseif (is_int($v))         $st->bindValue($i, $v, PDO::PARAM_INT);
            elseif (is_float($v))       $st->bindValue($i, (string)$v, PDO::PARAM_STR);
            else                        $st->bindValue($i, (string)$v, PDO::PARAM_STR);
            $i++;
        }
        $st->execute();
        return $st;
    }

    /** Semua baris. Kolom DECIMAL (hasil SUM/AVG/ROUND) diubah ke angka agar JSON-nya sama dengan versi Node. */
    public static function all(string $sql, array $p = []): array
    {
        $st = self::jalankan($sql, $p);
        $rows = $st->fetchAll();
        if (!$rows) return [];
        $desimal = [];
        for ($i = 0, $n = $st->columnCount(); $i < $n; $i++) {
            $m = $st->getColumnMeta($i);
            if ($m && in_array($m['native_type'] ?? '', ['NEWDECIMAL', 'DECIMAL'], true)) $desimal[] = $m['name'];
        }
        if ($desimal) {
            foreach ($rows as &$r) {
                foreach ($desimal as $k) if ($r[$k] !== null) $r[$k] = $r[$k] + 0;
            }
            unset($r);
        }
        return $rows;
    }

    public static function one(string $sql, array $p = []): ?array
    {
        $r = self::all($sql, $p);
        return $r[0] ?? null;
    }

    public static function val(string $sql, array $p = []): mixed
    {
        $r = self::one($sql, $p);
        return $r ? reset($r) : null;
    }

    /** INSERT/UPDATE/DELETE; mengembalikan jumlah baris terpengaruh. */
    public static function run(string $sql, array $p = []): int
    {
        return self::jalankan($sql, $p)->rowCount();
    }

    public static function insert(string $sql, array $p = []): int
    {
        self::jalankan($sql, $p);
        return (int)self::pdo()->lastInsertId();
    }

    public static function transaksi(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $hasil = $fn();
            $pdo->commit();
            return $hasil;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function log(?int $userId, string $aksi, ?string $entity = null, ?int $entityId = null, ?string $ket = null): void
    {
        try {
            self::run('INSERT INTO activity_log (user_id, aksi, entity, entity_id, keterangan) VALUES (?,?,?,?,?)',
                [$userId, $aksi, $entity, $entityId, $ket]);
        } catch (Throwable $e) { /* log tidak boleh menggagalkan permintaan */ }
    }
}
