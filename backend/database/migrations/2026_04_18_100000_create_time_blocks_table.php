<?php

use App\Enums\TimeBlockKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_blocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('kind')->default(TimeBlockKind::WORK->value);
            $table->time('start_time');
            $table->time('end_time');
            // Bitmask: Sunday=1, Monday=2, Tuesday=4, ... Saturday=64
            // 127 = all days; 62 = Mon-Fri
            $table->unsignedSmallInteger('weekday_mask')->default(127);
            $table->string('color_hint')->nullable();
            $table->unsignedTinyInteger('display_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_blocks');
    }
};
