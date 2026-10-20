<?php
declare(strict_types=1);

/** Setara "!nilai" di JavaScript: null, '', 0, '0'?? (hanya null/''/false/0/0.0 — '0' di JS truthy, di sini juga dianggap isi). */
function kosong(mixed $v): bool
{
    return $v === null || $v === '' || $v === false || $v === 0 || $v === 0.0;
}

/** Hapus spasi tepi bila string; selain itu '' */
function teks(mixed $v): string
{
    return is_string($v) ? trim($v) : (is_scalar($v) ? trim((string)$v) : '');
}

/** String tak-kosong atau null (setara `x?.trim() || null`) */
function teksAtauNull(mixed $v): ?string
{
    $t = teks($v);
    return $t === '' ? null : $t;
}

function waktuSekarang(): string
{
    return date('Y-m-d H:i:s');
}

/** "?, ?, ?" sebanyak n */
function tandaTanya(int $n): string
{
    return implode(',', array_fill(0, $n, '?'));
}

/** '' / null -> null, selain itu float (kolom DOUBLE tidak menerima string kosong di MySQL strict). */
function angkaNull(mixed $v): ?float
{
    return ($v === null || $v === '' || !is_numeric($v)) ? null : (float)$v;
}

/** '' / null / 0 -> null, selain itu int (kolom kunci asing). */
function idNull(mixed $v): ?int
{
    return kosong($v) || !is_numeric($v) ? null : (int)$v;
}
