<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The follow-up by email: what the office sent a client about the missing documents, and what came back (FollowUp).
        Schema::create('client_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('direction', 3);                 // out | in
            $table->string('ref', 12)->index();             // the thread's token, in the subject: [ref]
            $table->foreignId('parent_id')->nullable()->constrained('client_emails')->nullOnDelete();
            $table->string('message_id')->nullable()->index();
            $table->string('address');                      // to (out) or from (in)
            $table->string('subject');
            $table->text('body');
            $table->string('status', 12);                   // out: sent | failed; in: received | unmatched | processed
            $table->json('request_ids')->nullable();        // the requests the email asked about
            $table->json('analysis')->nullable();           // in: what the AI understood from the reply
            $table->unsignedTinyInteger('reminders')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->string('error')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_emails');
    }
};
