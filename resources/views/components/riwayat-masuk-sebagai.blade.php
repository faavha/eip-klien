{{-- Riwayat "Masuk sebagai" (ADR 0019 D). Hanya untuk admin; aplikasi yang
     menjaga akses halamannya. <x-eip-klien::riwayat-masuk-sebagai class="tabel" /> --}}
@props(['batas' => 100])
@php
    $eipRiwayat = \Fmipa\EipKlien\ImpersonasiLog::query()->latest('mulai_at')->latest('id')->limit((int) $batas)->get();
    $eipMenit = (int) config('eip-klien.impersonasi.menit', 25);
    $eipCara = ['kembali' => 'Kembali', 'kedaluwarsa' => 'Waktu habis', 'keluar' => 'Keluar'];
@endphp
<table {{ $attributes->merge(['data-eip-riwayat-masuk-sebagai' => true]) }}>
    <thead>
        <tr>
            <th>Mulai</th>
            <th>Pelaku</th>
            <th>Masuk sebagai</th>
            <th>Alasan</th>
            <th>Selesai</th>
            <th>IP</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($eipRiwayat as $baris)
            <tr>
                <td>{{ $baris->mulai_at->format('d/m/Y H:i') }}</td>
                <td>{{ $baris->pelaku_nama }}</td>
                <td>{{ $baris->target_nama }}</td>
                <td>{{ $baris->alasan }}</td>
                <td>
                    @if ($baris->selesai())
                        {{ $baris->selesai_at->format('H:i') }} · {{ $eipCara[$baris->cara_selesai] ?? $baris->cara_selesai }}
                    @elseif ($baris->mulai_at->copy()->addMinutes($eipMenit)->isPast())
                        ditinggal (habis {{ $baris->mulai_at->copy()->addMinutes($eipMenit)->format('H:i') }})
                    @else
                        berlangsung
                    @endif
                </td>
                <td>{{ $baris->ip }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="6">Belum ada riwayat.</td>
            </tr>
        @endforelse
    </tbody>
</table>
