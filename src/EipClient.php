<?php

namespace Fmipa\EipKlien;

use Illuminate\Http\Client\PendingRequest;

/**
 * Klien baca-saja ke EIP Core `/api/v1` (kontrak: eip-contracts openapi).
 * Aplikasi TIDAK PERNAH menulis ke EIP Core.
 */
class EipClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $token,
        private readonly ?string $kodeAplikasi = null,
    ) {}

    public static function dariConfig(): self
    {
        return new self(
            (string) config('eip-klien.base_url'),
            config('eip-klien.api_token'),
            config('eip-klien.kode_aplikasi'),
        );
    }

    public function baseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    public function punyaToken(): bool
    {
        return filled($this->token);
    }

    private function http(): PendingRequest
    {
        return Http::ke($this->baseUrl())->baseUrl($this->baseUrl().'/api/v1')->withToken((string) $this->token);
    }

    private function aplikasi(?string $aplikasi): string
    {
        return $aplikasi ?? (string) $this->kodeAplikasi;
    }

    /**
     * Ikuti paginasi (meta.last_page atau links.next).
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function semuaHalaman(string $path, array $query, int $batas = 50): array
    {
        $semua = [];
        $halaman = 1;

        do {
            $body = $this->http()->get($path, $query + ['per_page' => 200, 'page' => $halaman])->throw()->json();
            foreach ($body['data'] ?? [] as $baris) {
                $semua[] = $baris;
            }
            $lanjut = isset($body['meta']['last_page'])
                ? $halaman < (int) $body['meta']['last_page']
                : ! empty($body['links']['next']);
            $halaman++;
        } while ($lanjut && $halaman <= $batas);

        return $semua;
    }

    /**
     * Pegawai berdasarkan email persis. Null bila bukan pegawai.
     *
     * @return array<string, mixed>|null
     */
    public function pegawaiByEmail(string $email): ?array
    {
        return $this->http()->get('/pegawai', ['email' => $email])->throw()->json('data.0');
    }

    /**
     * Semua pegawai, mis. `['is_active' => 1]`.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function pegawaiList(array $query = []): array
    {
        return $this->semuaHalaman('/pegawai', $query);
    }

    /**
     * Unit kerja, mis. `['status' => 'all']` untuk termasuk yang nonaktif.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function unitKerjaList(array $query = []): array
    {
        return $this->semuaHalaman('/unit-kerja', $query, 20);
    }

    /** @return array<string, mixed>|null */
    public function unitKerjaByKode(string $kode, array $query = ['status' => 'all']): ?array
    {
        foreach ($this->unitKerjaList($query) as $unit) {
            if (($unit['kode'] ?? null) === $kode) {
                return $unit;
            }
        }

        return null;
    }

    /**
     * Aplikasi yang boleh dibuka pegawai (`listAplikasiPegawai`). Kosong bila
     * email bukan pegawai.
     *
     * @return list<array<string, mixed>>
     */
    public function aplikasiByEmail(string $email): array
    {
        $uid = $this->pegawaiByEmail($email)['uid'] ?? null;

        return $uid ? ($this->http()->get('/pegawai/'.rawurlencode((string) $uid).'/aplikasi')->throw()->json('data') ?? []) : [];
    }

    /**
     * Akses & peran pegawai di satu aplikasi (ADR 0017, `/akses`):
     * `['boleh_masuk' => bool, 'peran' => [['kode', 'unit_kerja' => [...]], ...]]`.
     * Null bila email bukan pegawai.
     *
     * @return array<string, mixed>|null
     */
    public function akses(string $email, ?string $aplikasi = null): ?array
    {
        $res = $this->http()->get('/akses', ['email' => $email, 'aplikasi' => $this->aplikasi($aplikasi)]);
        if ($res->status() === 404) {
            return null;
        }

        return $res->throw()->json('data');
    }

    /**
     * Pemegang satu peran (`/akses/pemegang`):
     * `[['uid', 'nama', 'email', 'unit_kerja' => [...]], ...]`.
     *
     * @return list<array<string, mixed>>
     */
    public function pemegangPeran(string $peran, ?string $aplikasi = null): array
    {
        return $this->http()->get('/akses/pemegang', ['aplikasi' => $this->aplikasi($aplikasi), 'peran' => $peran])
            ->throw()->json('data') ?? [];
    }

    /**
     * Pemegang beberapa peran, digabung per orang (kunci = uid):
     * `['uid', 'nama', 'email', 'peran' => [kode...], 'unit_kerja' => [kode => [unit...]]]`.
     *
     * @param  list<string>  $peran
     * @return array<string, array<string, mixed>>
     */
    public function pemegangPerOrang(array $peran, ?string $aplikasi = null): array
    {
        $orang = [];
        foreach ($peran as $kode) {
            foreach ($this->pemegangPeran($kode, $aplikasi) as $p) {
                $uid = (string) $p['uid'];
                $orang[$uid] ??= [
                    'uid' => $uid,
                    'nama' => (string) ($p['nama'] ?? ''),
                    'email' => strtolower((string) ($p['email'] ?? '')),
                    'peran' => [],
                    'unit_kerja' => [],
                ];
                $orang[$uid]['peran'][] = $kode;
                $orang[$uid]['unit_kerja'][$kode] = (array) ($p['unit_kerja'] ?? []);
            }
        }

        return $orang;
    }
}
