<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contact changes are two-step: the address/number is only written after the
     * code sent to it comes back. Storing the pending value separately means the
     * account keeps its current, working contact detail until verification
     * finishes, so an abandoned change cannot lock someone out of the address
     * their password resets go to.
     */
    public function up(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->string('pending_email')->nullable()->after('email');
            $table->string('pending_email_code')->nullable()->after('pending_email');
            $table->timestamp('pending_email_expires_at')->nullable()->after('pending_email_code');

            $table->string('pending_phone', 11)->nullable()->after('contact_no');
            $table->string('pending_phone_code')->nullable()->after('pending_phone');
            $table->timestamp('pending_phone_expires_at')->nullable()->after('pending_phone_code');
        });
    }

    public function down(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->dropColumn([
                'pending_email',
                'pending_email_code',
                'pending_email_expires_at',
                'pending_phone',
                'pending_phone_code',
                'pending_phone_expires_at',
            ]);
        });
    }
};
