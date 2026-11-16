<?php

namespace Fmipa\EipKlien\Http;

use Fmipa\EipKlien\Impersonasi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/** Route "Masuk sebagai" dan "Kembali ke akun saya" (ADR 0019). */
class ImpersonasiController extends Controller
{
    public function __construct(private readonly Impersonasi $impersonasi) {}

    public function mulai(Request $request, string $pengguna): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:250']]);

        $target = Auth::guard()->getProvider()->retrieveById($pengguna);
        abort_if(! $target, 404);

        $pesan = $this->impersonasi->periksa($request->user(), $target);
        if ($pesan !== null) {
            return back()->with($this->flash('gagal'), $pesan);
        }

        $log = $this->impersonasi->mulai($request->user(), $target, $data['alasan'], $request);
        $menit = (int) config('eip-klien.impersonasi.menit', 25);

        return redirect()->route($this->beranda())
            ->with($this->flash('sukses'), "Sekarang masuk sebagai {$log->target_nama} (paling lama {$menit} menit).");
    }

    public function berhenti(): RedirectResponse
    {
        if (! $this->impersonasi->aktif()) {
            return redirect()->route($this->beranda());
        }

        $asli = $this->impersonasi->hentikan('kembali');
        if (! $asli) {
            return redirect()->route('login')->with($this->flash('gagal'), 'Akun asli tidak ditemukan; silakan login ulang.');
        }

        return redirect()->route($this->beranda())->with($this->flash('sukses'), 'Kembali ke akun Anda.');
    }

    private function beranda(): string
    {
        return (string) config('eip-klien.impersonasi.beranda', 'dashboard');
    }

    private function flash(string $jenis): string
    {
        return (string) (config('eip-klien.impersonasi.flash.'.$jenis) ?? ($jenis === 'sukses' ? 'success' : 'error'));
    }
}
