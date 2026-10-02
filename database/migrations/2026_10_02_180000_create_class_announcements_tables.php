<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * State for the automatic new-class emails (App\Support\ClassAnnouncer).
 *
 * broadcast_sent / broadcast_unsubscribes were created by hand in June for the
 * manual Broadcast tab and never had a migration. They are created here only if
 * missing, so a fresh database gets them and production keeps its rows.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('class_announcements', function (Blueprint $table) {
            $table->id();
            $table->integer('event_id')->unique();
            $table->string('event_name')->nullable();
            $table->string('event_date', 20)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->string('campaign', 64)->nullable()->index();
            $table->timestamp('announced_at')->nullable();
        });

        Schema::create('class_announcement_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('campaign', 64)->unique();
            $table->text('event_ids');
            $table->string('subject')->nullable();
            $table->integer('sent')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
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
        Schema::dropIfExists('class_announcement_campaigns');
        Schema::dropIfExists('class_announcements');
    }
};
