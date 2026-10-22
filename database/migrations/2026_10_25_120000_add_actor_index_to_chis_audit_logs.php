<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // "What did physician X view" is a real query once physicians look up profiles.
    public function up(): void
    {
        Schema::table('chis_audit_logs', function (Blueprint $table) {
            $table->index('actor_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('chis_audit_logs', function (Blueprint $table) {
            $table->dropIndex(['actor_user_id']);
        });
    }
};
