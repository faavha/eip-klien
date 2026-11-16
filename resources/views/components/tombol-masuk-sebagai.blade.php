{{-- Tombol "Masuk sebagai" per baris daftar pengguna (ADR 0019 B).
     <x-eip-klien::tombol-masuk-sebagai :pengguna="$user" class="btn btn-sm" />
     Isi slot (mis. ikon + teks) menggantikan label. Form alasan tampil di
     <dialog> di tengah layar, jadi tidak terpotong tabel yang bisa digulir.
     Tidak tampil bila aturan menolak (tanpa memanggil EIP). --}}
@props(['pengguna', 'label' => 'Masuk sebagai'])
@php
    $eipImp = app(\Fmipa\EipKlien\Impersonasi::class);
    $eipBoleh = $eipImp->bolehTampil(auth()->user(), $pengguna);
    $eipNama = app(\Fmipa\EipKlien\Contracts\AturanImpersonasi::class)->nama($pengguna);
@endphp
@if ($eipBoleh)
    <button type="button" data-eip-masuk-sebagai onclick="this.nextElementSibling.showModal()" {{ $attributes }}>
        @if ($slot->isEmpty())
            {{ $label }}
        @else
            {{ $slot }}
        @endif
    </button>
    <dialog data-eip-dialog-masuk-sebagai style="width:min(380px,calc(100vw - 32px));padding:0;border:0;border-radius:16px;background:#fff;color:#1f3b4d;box-shadow:0 18px 50px rgba(31,59,77,.3);text-align:left;font:14px/1.45 Manrope,system-ui,sans-serif">
        <form method="POST" action="{{ route('eip.impersonasi.mulai', ['pengguna' => $pengguna->getAuthIdentifier()]) }}" style="margin:0;padding:20px">
            @csrf
            <p style="margin:0 0 4px;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#d9773a">Masuk sebagai</p>
            <p style="margin:0 0 14px;font-size:16px;font-weight:800;white-space:normal">{{ $eipNama }}</p>
            <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px">Alasan</label>
            <input name="alasan" value="{{ config('eip-klien.impersonasi.alasan_bawaan', 'Testing') }}" required maxlength="250"
                   style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #c9d6d4;border-radius:10px;font:inherit;color:inherit;background:#fff">
            <p style="font-size:12px;color:#5b7180;margin:8px 0 18px;white-space:normal">Paling lama {{ config('eip-klien.impersonasi.menit', 25) }} menit, lalu otomatis kembali ke akun Anda. Tercatat di riwayat.</p>
            <div style="display:flex;gap:8px;justify-content:flex-end">
                <button type="button" onclick="this.closest('dialog').close()" style="cursor:pointer;border:1px solid #c9d6d4;border-radius:10px;padding:8px 14px;background:#fff;color:#1f3b4d;font:inherit;font-weight:700">Batal</button>
                <button type="submit" style="cursor:pointer;border:0;border-radius:10px;padding:8px 16px;background:#d9773a;color:#fff;font:inherit;font-weight:700">Mulai</button>
            </div>
        </form>
    </dialog>
@endif
