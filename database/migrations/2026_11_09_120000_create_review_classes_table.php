<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Classes that were still on the schedule on the day they ran (App\Support\ReviewRequester).
 *
 * content.json is not a record of what happened: a class taken off the site
 * before its date was cancelled, and one taken off afterwards is gone from
 * the file too. So on the class day the hourly tick notes the class here,
 * and the day after its people are asked for a review.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('review_classes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->string('event_name');
            $table->string('event_date', 20);   // the class's own date field, e.g. 2026-11-11
            $table->string('day', 40)->nullable();
            $table->date('class_date');
            $table->date('last_day');           // multi-week courses end weeks later
            $table->text('offline')->nullable(); // [{name, email}] booked offline, as of the class day
            $table->timestamps();
            $table->unique(['event_id', 'class_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_classes');
    }
};
