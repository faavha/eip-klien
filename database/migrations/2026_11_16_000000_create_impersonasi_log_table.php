<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('impersonasi_log')) {
            return;
        }

        Schema::create('impersonasi_log', function (Blueprint $table) {
            $table->id();
            // String: id akun bisa angka atau ULID, tergantung aplikasi.
            $table->string('pelaku_id', 64);
            $table->string('pelaku_nama');
            $table->string('target_id', 64);
            $table->string('target_nama');
            $table->string('alasan');
            $table->dateTime('mulai_at');
            $table->dateTime('selesai_at')->nullable();
            $table->string('cara_selesai', 20)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->index('mulai_at');
            $table->index('pelaku_id');
            $table->index('target_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonasi_log');
    }
};
