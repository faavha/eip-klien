{{-- Banner "Masuk sebagai" (ADR 0019 C3). Letakkan sekali di layout utama:
     <x-eip-klien::banner-menyamar />. Tidak bisa ditutup. --}}
@php
    $eipImp = app(\Fmipa\EipKlien\Impersonasi::class);
@endphp
@if ($eipImp->aktif())
    <div role="status" aria-live="polite" data-eip-banner-menyamar
         style="position:fixed;top:10px;left:50%;transform:translateX(-50%);z-index:2147483000;max-width:94vw;display:flex;align-items:center;gap:10px;padding:8px 10px 8px 14px;border-radius:14px;background:#d9773a;color:#fff;font:600 13px/1.35 Manrope,system-ui,sans-serif;box-shadow:0 8px 24px rgba(31,59,77,.28);border:1px solid rgba(255,255,255,.35)">
        <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            Masuk sebagai <strong style="font-weight:800">{{ $eipImp->targetNama() }}</strong>
            <span style="opacity:.85">· oleh {{ $eipImp->pelakuNama() }} · sisa <span data-eip-sisa="{{ $eipImp->sisaDetik() }}">{{ $eipImp->sisaMenit() }}</span> menit</span>
        </span>
        <form method="POST" action="{{ route('eip.impersonasi.berhenti') }}" style="margin:0;flex-shrink:0">
            @csrf
            <button type="submit" style="cursor:pointer;border:0;border-radius:10px;padding:6px 10px;background:rgba(255,255,255,.22);color:#fff;font:inherit;font-weight:700">Kembali ke akun saya</button>
        </form>
    </div>
    <script>
        (function () {
            var el = document.querySelector('[data-eip-sisa]');
            if (!el) return;
            var habis = Date.now() + Number(el.getAttribute('data-eip-sisa')) * 1000;
            setInterval(function () {
                var detik = Math.max(0, Math.round((habis - Date.now()) / 1000));
                el.textContent = Math.ceil(detik / 60);
                if (detik === 0) location.reload();
            }, 15000);
        })();
    </script>
@endif
