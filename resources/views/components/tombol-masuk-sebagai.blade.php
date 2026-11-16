{{-- Tombol "Masuk sebagai" per baris daftar pengguna (ADR 0019 B).
     <x-eip-klien::tombol-masuk-sebagai :pengguna="$user" class="btn btn-sm" />
     Tidak tampil bila aturan menolak (tanpa memanggil EIP). --}}
@props(['pengguna', 'label' => 'Masuk sebagai'])
@php
    $eipImp = app(\Fmipa\EipKlien\Impersonasi::class);
    $eipBoleh = $eipImp->bolehTampil(auth()->user(), $pengguna);
@endphp
@if ($eipBoleh)
    <details data-eip-masuk-sebagai style="display:inline-block;position:relative">
        <summary {{ $attributes->merge(['style' => 'cursor:pointer;list-style:none']) }}>{{ $label }}</summary>
        <form method="POST" action="{{ route('eip.impersonasi.mulai', ['pengguna' => $pengguna->getAuthIdentifier()]) }}"
              style="position:absolute;right:0;z-index:50;margin-top:6px;width:260px;padding:12px;border-radius:12px;background:#fff;color:#1f3b4d;box-shadow:0 10px 30px rgba(31,59,77,.22);text-align:left">
            @csrf
            <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px">Alasan</label>
            <input name="alasan" value="{{ config('eip-klien.impersonasi.alasan_bawaan', 'Testing') }}" required maxlength="250"
                   style="width:100%;box-sizing:border-box;padding:6px 8px;border:1px solid #c9d6d4;border-radius:8px;font:inherit">
            <p style="font-size:11px;opacity:.75;margin:6px 0 8px">Paling lama {{ config('eip-klien.impersonasi.menit', 25) }} menit; tercatat di riwayat.</p>
            <button type="submit" style="cursor:pointer;width:100%;border:0;border-radius:8px;padding:7px;background:#d9773a;color:#fff;font-weight:700">Mulai</button>
        </form>
    </details>
@endif
