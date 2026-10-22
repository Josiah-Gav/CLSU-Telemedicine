<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Checked on the normalized values and before anything is written:
        // MySQL DDL is not transactional, so a failure must leave the data
        // exactly as it was rather than half-normalized.
        $duplicates = DB::table('users')
            ->selectRaw('TRIM(clsu_id) as clsu_id')
            ->whereRaw("TRIM(clsu_id) <> ''")
            ->groupByRaw('TRIM(clsu_id)')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('clsu_id')
            ->all();

        if (! empty($duplicates)) {
            throw new RuntimeException(
                'Cannot add unique index on users.clsu_id. Duplicate clsu_id values found (after trimming): '
                .implode(', ', $duplicates)
                .'. Correct them through admin user management, then re-run the migration.'
            );
        }

        DB::table('users')->whereNotNull('clsu_id')->update(['clsu_id' => DB::raw('TRIM(clsu_id)')]);

        // Several NULLs are allowed under a unique index; several '' are not.
        DB::table('users')->where('clsu_id', '')->update(['clsu_id' => null]);

        Schema::table('users', function (Blueprint $table) {
            $table->unique('clsu_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['clsu_id']);
        });
    }
};
