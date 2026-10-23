<?php

namespace App\Domain\AiProviders\Services;

use Illuminate\Validation\ValidationException;

/**
 * Адрес внешнего провайдера: только https и не внутренние адреса — ключ не уходит в открытом виде,
 * а портал нельзя направить на внутренние сервисы сервера (SSRF).
 */
final class EndpointGuard
{
    public function assertSafe(?string $url): void
    {
        $parts = $url ? parse_url($url) : false;
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw ValidationException::withMessages(['endpoint' => 'Укажите адрес вида https://… (только HTTPS).']);
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withMessages(['endpoint' => 'Ключ передаётся отдельно — не включайте логин и пароль в адрес.']);
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal') || ! str_contains($host, '.')) {
            throw ValidationException::withMessages(['endpoint' => 'Внутренние адреса не допускаются.']);
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw ValidationException::withMessages(['endpoint' => 'Адрес указывает на внутреннюю сеть — не допускается.']);
            }
        }
    }
}
