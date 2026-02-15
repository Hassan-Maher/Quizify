<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('instructor_id')->constrained('users')->onDelete('cascade');
            $table->string('name');
            $table->string('subject');
            $table->string('grade');
            $table->enum('visibility', ['Public', 'Restricted'])->default('Public');
            $table->enum('status', ['Draft', 'Archived', 'Running', 'Completed'])->default('Draft');
            $table->integer('time_limit'); // Minutes
            $table->dateTime('start_date');
            $table->dateTime('end_date');
            $table->integer('max_attempts')->default(1);
            $table->boolean('show_answer_after_question')->default(false);
            $table->string('cover_image')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quizzes');
    }
};
