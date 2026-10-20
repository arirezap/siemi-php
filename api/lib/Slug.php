<?php
declare(strict_types=1);

final class Slug
{
    public static function buat(?string $teks): string
    {
        $t = (string)$teks;
        if (class_exists('Normalizer')) $t = Normalizer::normalize($t, Normalizer::FORM_KD) ?: $t;
        $t = preg_replace('/\p{Mn}+/u', '', $t);
        $t = strtolower($t);
        $t = trim(preg_replace('/[^a-z0-9]+/', '-', $t), '-');
        return substr($t, 0, 120);
    }

    public static function valid(mixed $s): bool
    {
        return is_string($s) && $s !== '' && strlen($s) <= 120 && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $s) === 1;
    }
}
