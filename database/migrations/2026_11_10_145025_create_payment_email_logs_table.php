<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('aa', 64)->index();
            $table->unsignedBigInteger('billpaid_snapshot');
            $table->unsignedBigInteger('payment_amount')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->string('broadcaster_id', 64)->nullable();
            $table->timestamps();

            $table->unique(['aa', 'billpaid_snapshot'], 'payment_email_logs_aa_billpaid_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_email_logs');
    }
};
