{{-- Riwayat "Masuk sebagai" (ADR 0019 D). Hanya untuk admin; aplikasi yang
     menjaga akses halamannya. <x-eip-klien::riwayat-masuk-sebagai class="tabel" />
     Sel diberi data-label supaya cocok dengan tabel bertumpuk di layar kecil. --}}
@props(['batas' => 100])
@php
    $eipRiwayat = \Fmipa\EipKlien\ImpersonasiLog::query()->latest('mulai_at')->latest('id')->limit((int) $batas)->get();
    $eipMenit = (int) config('eip-klien.impersonasi.menit', 25);
    $eipCara = ['kembali' => 'Kembali', 'kedaluwarsa' => 'Waktu habis', 'keluar' => 'Keluar'];
@endphp
<table {{ $attributes->merge(['data-eip-riwayat-masuk-sebagai' => true]) }}>
    <thead>
        <tr>
            <th scope="col">Mulai</th>
            <th scope="col">Admin</th>
            <th scope="col">Masuk sebagai</th>
            <th scope="col">Alasan</th>
            <th scope="col">Selesai</th>
            <th scope="col">IP</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($eipRiwayat as $baris)
            @php
                $eipHabis = $baris->mulai_at->copy()->addMinutes($eipMenit);
            @endphp
            <tr>
                <td data-label="Mulai" style="white-space:nowrap">{{ $baris->mulai_at->format('d/m/Y H:i') }}</td>
                <td data-label="Admin">{{ $baris->pelaku_nama }}</td>
                <td data-label="Masuk sebagai">{{ $baris->target_nama }}</td>
                <td data-label="Alasan">{{ $baris->alasan }}</td>
                <td data-label="Selesai" style="white-space:nowrap">
                    @if ($baris->selesai())
                        {{ $baris->selesai_at->format('H:i') }} · {{ $eipCara[$baris->cara_selesai] ?? $baris->cara_selesai }}
                    @elseif ($eipHabis->isPast())
                        Ditinggal (habis {{ $eipHabis->format('H:i') }})
                    @else
                        Berlangsung
                    @endif
                </td>
                <td data-label="IP">{{ $baris->ip }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="6">Belum ada riwayat.</td>
            </tr>
        @endforelse
    </tbody>
</table>
