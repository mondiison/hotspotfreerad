<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pppoe_subscribers', function (Blueprint $table) {
            // Backs the "pay to renew" email link (PppoeRenewalController) --
            // a short, random, unguessable token rather than exposing the
            // username or numeric id directly, the same precedent
            // routers.public_token already set for the self-service connect
            // link.
            $table->string('public_token', 32)->nullable()->unique()->after('id');

            // Dedup flags for the 3-day/1-day/expired reminder emails --
            // each is set once that email actually sends and reset back to
            // null the next time this subscriber renews (see
            // PppoeSubscriberManagementService::extendExpiry()), so a given
            // billing cycle's reminders never fire twice but the next
            // cycle's can fire again.
            $table->timestamp('reminder_3day_sent_at')->nullable()->after('last_provisioned_at');
            $table->timestamp('reminder_1day_sent_at')->nullable()->after('reminder_3day_sent_at');
            $table->timestamp('reminder_expired_sent_at')->nullable()->after('reminder_1day_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('pppoe_subscribers', function (Blueprint $table) {
            $table->dropColumn(['public_token', 'reminder_3day_sent_at', 'reminder_1day_sent_at', 'reminder_expired_sent_at']);
        });
    }
};
