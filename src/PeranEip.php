<?php

namespace Fmipa\EipKlien;

/**
 * Peran seorang pegawai di satu aplikasi menurut EIP (ADR 0017), beserta
 * unit lingkup tiap peran. Aplikasi boleh menurunkan kelas ini untuk
 * menambah cara menerapkan peran ke akun lokal.
 */
class PeranEip
{
    /**
     * @param  array<string, list<int>>  $unit  kode peran => id unit kerja
     * @param  array<string, list<array<string, mixed>>>  $detailUnit  kode peran => unit lengkap (id, kode, nama)
     */
    final public function __construct(
        public readonly array $unit = [],
        public readonly array $detailUnit = [],
    ) {}

    /** Dari respons API `/akses`. */
    public static function dariAkses(?array $akses): static
    {
        $unit = $detail = [];
        foreach ($akses['peran'] ?? [] as $p) {
            $kode = (string) $p['kode'];
            $detail[$kode] = array_values((array) ($p['unit_kerja'] ?? []));
            $unit[$kode] = array_map('intval', array_column($detail[$kode], 'id'));
        }

        return new static($unit, $detail);
    }

    /** Dari klaim ID token SSO EIP (`peran` + `peran_unit`). */
    public static function dariKlaim(array $klaim): static
    {
        $unit = array_fill_keys(array_map('strval', (array) ($klaim['peran'] ?? [])), []);
        $detail = $unit;
        foreach ((array) ($klaim['peran_unit'] ?? []) as $u) {
            if (isset($u['peran'], $u['id'], $unit[$u['peran']])) {
                $unit[$u['peran']][] = (int) $u['id'];
                $detail[$u['peran']][] = $u;
            }
        }

        return new static($unit, $detail);
    }

    public function punya(string $kode): bool
    {
        return array_key_exists($kode, $this->unit);
    }

    /** @return list<string> */
    public function kode(): array
    {
        return array_keys($this->unit);
    }

    public function kosong(): bool
    {
        return $this->unit === [];
    }

    /**
     * Peran pertama yang dimiliki menurut urutan prioritas.
     *
     * @param  list<string>  $prioritas
     */
    public function utama(array $prioritas): ?string
    {
        foreach ($prioritas as $kode) {
            if ($this->punya($kode)) {
                return $kode;
            }
        }

        return null;
    }

    /** @return list<int> id unit kerja lingkup satu peran */
    public function unitUntuk(string $kode): array
    {
        return $this->unit[$kode] ?? [];
    }

    /** @return list<array<string, mixed>> unit lengkap (id, kode, nama) lingkup satu peran */
    public function detailUnitUntuk(string $kode): array
    {
        return $this->detailUnit[$kode] ?? [];
    }
}
