<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('manifest_items', function (Blueprint $table) {
            $table->string('issue_type', 20)->nullable()->after('released_at');  // 'missing' or 'damaged'
            $table->text('issue_note')->nullable()->after('issue_type');
            $table->timestamp('issue_recorded_at')->nullable()->after('issue_note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('manifest_items', function (Blueprint $table) {
            $table->dropColumn(['issue_type', 'issue_note', 'issue_recorded_at']);
        });
    }
};
