<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_email_logs', function (Blueprint $table) {
            $table->unsignedInteger('installment_snapshot')->default(0)->after('billpaid_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('payment_email_logs', function (Blueprint $table) {
            $table->dropColumn('installment_snapshot');
        });
    }
};
