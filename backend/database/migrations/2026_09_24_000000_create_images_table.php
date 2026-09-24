<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('images', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('original_name');
            $table->string('extension', 10);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            // Not smallint: TIFF allows edges beyond 65535 px.
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('original_path');
            $table->string('thumbnail_path');
            $table->string('uploader_name', 100);
            $table->string('uploader_email');
            $table->json('metadata')->nullable();
            $table->decimal('temperature_c', 4, 1)->nullable();
            $table->timestamp('temperature_fetched_at')->nullable();
            $table->timestamps();

            // Matches the cursor pagination order (created_at desc, id desc as tie-breaker).
            $table->index(['created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};
