<?php

namespace Fmipa\EipKlien;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http as HttpFacade;

/**
 * Permintaan HTTP ke EIP. Bila aplikasi satu server dengan EIP, domain EIP
 * diarahkan ke `resolve_ip` (hairpin NAT); SNI/Host tetap benar.
 */
final class Http
{
    public static function ke(string $baseUrl): PendingRequest
    {
        $req = HttpFacade::acceptJson()->timeout((int) config('eip-klien.timeout', 15));

        $ip = (string) config('eip-klien.resolve_ip');
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if ($ip !== '' && $host && defined('CURLOPT_RESOLVE')) {
            $port = parse_url($baseUrl, PHP_URL_PORT) ?? (parse_url($baseUrl, PHP_URL_SCHEME) === 'http' ? 80 : 443);
            $req = $req->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]]]);
        }

        return $req;
    }
}
