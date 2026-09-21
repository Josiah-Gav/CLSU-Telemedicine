<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Sanctum token principal for an external system integration —
     * currently only the simulated CHIS demo client. Deliberately not a
     * `users` row: CHIS is a system, not a CLSU account holder, so this
     * keeps machine-to-machine credentials off the users table's
     * role/account_status/login surface entirely. See App\Models\ChisIntegrationClient.
     */
    public function up(): void
    {
        Schema::create('chis_integration_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chis_integration_clients');
    }
};
