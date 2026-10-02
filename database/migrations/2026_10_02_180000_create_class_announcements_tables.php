<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * State for the monthly class email (App\Support\ClassAnnouncer): one row per month,
 * plus opens/clicks of campaign emails (broadcast_events).
 *
 * broadcast_sent / broadcast_unsubscribes were created by hand in June for the
 * manual Broadcast tab and never had a migration. They are created here only if
 * missing, so a fresh database gets them and production keeps its rows.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('class_announcement_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('campaign', 64)->unique();
            $table->text('event_ids');
            $table->string('subject')->nullable();
            $table->integer('sent')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
        });

        // Opens (1×1 image) and clicks (redirect) from campaign emails.
        Schema::create('broadcast_events', function (Blueprint $table) {
            $table->id();
            $table->string('campaign', 64);
            $table->string('email');
            $table->string('type', 10);
            $table->string('url', 500)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['campaign', 'email']);
        });

        if (!Schema::hasTable('broadcast_sent')) {
            Schema::create('broadcast_sent', function (Blueprint $table) {
                $table->id();
                $table->string('campaign', 64);
                $table->string('email');
                $table->timestamp('sent_at')->nullable()->index();
                $table->unique(['campaign', 'email']);
            });
        }

        if (!Schema::hasTable('broadcast_unsubscribes')) {
            Schema::create('broadcast_unsubscribes', function (Blueprint $table) {
                $table->id();
                $table->string('email')->unique();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_events');
        Schema::dropIfExists('class_announcement_campaigns');
    }
};
