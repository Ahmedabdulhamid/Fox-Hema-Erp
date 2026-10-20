<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->comment('Queued jobs waiting to be processed.');
            $table->id()->comment('Primary key for the queued job.');
            $table->string('queue')->index()->comment('Queue on which the job should be processed.');
            $table->longText('payload')->comment('Serialized job payload and execution metadata.');
            $table->unsignedSmallInteger('attempts')->comment('Number of times the job has been attempted.');
            $table->unsignedInteger('reserved_at')->nullable()->comment('Unix timestamp when a worker reserved the job.');
            $table->unsignedInteger('available_at')->comment('Unix timestamp from which the job may be processed.');
            $table->unsignedInteger('created_at')->comment('Unix timestamp when the job was queued.');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->comment('Metadata and progress counters for batches of queued jobs.');
            $table->string('id')->primary()->comment('Unique identifier for the job batch.');
            $table->string('name')->comment('Human-readable name of the job batch.');
            $table->integer('total_jobs')->comment('Total number of jobs added to the batch.');
            $table->integer('pending_jobs')->comment('Number of jobs still waiting to finish.');
            $table->integer('failed_jobs')->comment('Number of jobs that failed.');
            $table->longText('failed_job_ids')->comment('Serialized identifiers of failed jobs.');
            $table->mediumText('options')->nullable()->comment('Serialized batch configuration and callbacks.');
            $table->integer('cancelled_at')->nullable()->comment('Unix timestamp when the batch was cancelled.');
            $table->integer('created_at')->comment('Unix timestamp when the batch was created.');
            $table->integer('finished_at')->nullable()->comment('Unix timestamp when the batch finished.');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->comment('Queued jobs that exhausted their allowed attempts.');
            $table->id()->comment('Primary key for the failed job record.');
            $table->string('uuid')->unique()->comment('Globally unique identifier of the failed job.');
            $table->string('connection')->comment('Queue connection used by the failed job.');
            $table->string('queue')->comment('Queue from which the job was processed.');
            $table->longText('payload')->comment('Serialized payload of the failed job.');
            $table->longText('exception')->comment('Exception message and stack trace produced by the failure.');
            $table->timestamp('failed_at')->useCurrent()->comment('Date and time when the job failed.');

            $table->index(['connection', 'queue', 'failed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
    }
};
