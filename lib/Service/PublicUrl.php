<?php

declare(strict_types=1);

namespace Aclips\Fakesign\Service;

/**
 * Вспомогательные функции публичного адреса (детект схемы при установке).
 */
final class PublicUrl
{
    /**
     * Определяет secure-схему запроса из серверных переменных.
     * Важно: nginx отдаёт HTTPS как 'Off' (с большой О) - сравнение регистронезависимое.
     */
    public static function isSecureRequest(array $server): bool
    {
        $https = strtolower((string)($server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off' && $https !== '0')
        {
            return true;
        }

        return strtolower((string)($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
}
