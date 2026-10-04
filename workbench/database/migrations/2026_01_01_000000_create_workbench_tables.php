<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->nullable()->constrained('rooms');
            $table->string('title');
            $table->string('location')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings');
            $table->string('name');
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->dateTime('starts_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('attendees');
        Schema::dropIfExists('meetings');
        Schema::dropIfExists('rooms');
    }
};
