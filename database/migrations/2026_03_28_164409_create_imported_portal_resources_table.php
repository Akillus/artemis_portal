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
        Schema::create('imported_portal_resources', function (Blueprint $table) {
            $table->id();
            $table->text('source_reference');
            $table->string('record_id')->nullable()->index();
            $table->string('identifier')->nullable()->index();
            $table->string('status')->index();
            $table->string('resource_type')->nullable()->index();
            $table->text('title')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imported_portal_resources');
    }
};
