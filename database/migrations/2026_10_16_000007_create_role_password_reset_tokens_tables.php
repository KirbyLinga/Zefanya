<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One password-reset token table PER role broker.
     *
     * Previously every broker (buyers, sellers, logistics, admins) shared the
     * email-keyed `password_reset_tokens` table, so the same e-mail in two role
     * tables overwrote the other role's token. Separate tables make the tokens
     * impossible to collide. config/auth.php points each broker at its own
     * table.
     */
    private const TABLES = [
        'password_reset_tokens_buyers',
        'password_reset_tokens_sellers',
        'password_reset_tokens_logistics',
        'password_reset_tokens_admins',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                continue;
            }

            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->string('email')->primary();
                $blueprint->string('token');
                $blueprint->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
