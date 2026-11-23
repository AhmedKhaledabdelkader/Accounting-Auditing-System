<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The engagement file (ملف الارتباط, config/engagement.php): a client's year as six phases of steps, each with its sign-offs,
 * and the registers the steps keep — the risks (ISA 315), the materiality's approved versions (ISA 320), the adjusting entries
 * (the adjusted trial balance), the misstatements (ISA 450), the reviewer's notes — and one log of every change.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A step's sign-offs: prepared, reviewed, approved (the partner), eqr. The hash is the step's content when signed: a
        // content that changed since drops the sign-off (shown, not deleted: the record stays).
        Schema::create('engagement_signoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('step_key', 40);
            $table->string('role', 16);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'year', 'step_key', 'role']);
        });

        // The reviewer's notes on a step: raised, answered by the preparer, closed by the one who raised it.
        Schema::create('review_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('step_key', 40);
            $table->text('text');
            $table->string('status', 16)->default('open');   // open, answered, closed
            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('response')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'year', 'status']);
        });

        // The materiality as approved: never changed nor deleted; a change after it is a new version, with its reason.
        Schema::create('materiality_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('version');
            $table->string('stage', 16)->default('planning');   // planning, final
            $table->string('benchmark', 40);
            $table->decimal('benchmark_amount', 20, 2);
            $table->decimal('pct', 8, 4);
            $table->decimal('overall', 20, 2);
            $table->decimal('performance_pct', 8, 4);
            $table->decimal('performance', 20, 2);
            $table->decimal('trivial_pct', 8, 4);
            $table->decimal('trivial', 20, 2);
            $table->text('rationale');
            $table->text('change_reason')->nullable();
            $table->json('specific')->nullable();   // [{area, amount, reason}]: the specific materialities
            $table->json('choice')->nullable();     // the working paper's choices it was approved on
            $table->string('status', 16)->default('approved');   // approved, superseded
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approved_name')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'year', 'version']);
        });

        // The risks of material misstatement (ISA 315), with the procedures that answer each (ISA 330).
        Schema::create('audit_risks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('area', 40);                    // an area of config/audit_procedures.php, or «fs» (the statements as a whole)
            $table->string('level', 16)->default('assertion');   // fs, assertion
            $table->text('description');
            $table->json('assertions')->nullable();
            $table->unsignedTinyInteger('likelihood')->default(2);   // 1 low, 2 medium, 3 high
            $table->unsignedTinyInteger('magnitude')->default(2);
            $table->string('inherent_rating', 8)->default('medium');   // low, medium, high
            $table->boolean('is_significant')->default(false);
            $table->boolean('is_fraud')->default(false);
            $table->boolean('control_reliance')->default(false);
            $table->string('status', 16)->default('accepted');   // suggested, accepted, dismissed
            $table->string('source', 40)->nullable();             // the suggestion's key (one per year), null when typed
            $table->json('procedures')->nullable();              // the area's procedure codes answering it
            $table->text('response')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'year']);
        });

        // The adjusting entries on the year's approved trial balance (a run): the adjusted trial balance = the run + the posted ones.
        Schema::create('adjusting_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->uuid('classification_run_id')->nullable()->index();
            $table->unsignedInteger('number');
            $table->string('type', 20)->default('adjusting');   // adjusting, reclassification
            $table->string('source_type', 20)->default('manual');   // manual, import, finding, sample
            $table->string('source_ref')->nullable();
            $table->string('source_file')->nullable();
            $table->unsignedInteger('source_page')->nullable();
            $table->text('description');
            $table->string('status', 16)->default('proposed');   // proposed, accepted, rejected, posted
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'year', 'status']);
        });
        Schema::create('adjusting_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjusting_entry_id')->constrained('adjusting_entries')->cascadeOnDelete();
            $table->unsignedInteger('row')->nullable();   // the trial balance's row of the account
            $table->string('account_code')->nullable();
            $table->string('account_name')->nullable();
            $table->decimal('debit', 20, 2)->default(0);
            $table->decimal('credit', 20, 2)->default(0);
        });

        // The misstatements (ISA 450): the ones typed and the entries the client refused; the samples' projections are added on reading.
        Schema::create('misstatements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('source', 20)->default('manual');   // manual, entry
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('kind', 16)->default('factual');   // factual, judgmental, projected
            $table->text('description');
            $table->decimal('amount', 20, 2);
            $table->decimal('effect_assets', 20, 2)->default(0);
            $table->decimal('effect_liabilities', 20, 2)->default(0);
            $table->decimal('effect_equity', 20, 2)->default(0);
            $table->decimal('effect_pl', 20, 2)->default(0);
            $table->boolean('corrected')->default(false);
            $table->boolean('prior_period')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'year']);
        });

        // One log of every change on the engagement: who, when, the value before and after.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->nullable()->constrained('offices')->cascadeOnDelete();
            $table->uuid('company_id')->nullable()->index();
            $table->unsignedSmallInteger('year')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('action', 60);
            $table->string('target_type', 40);
            $table->string('target_id', 80)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // The completion memo (subsequent events, going concern, representations) and the release (the opinion, the issue).
        Schema::table('audit_plans', function (Blueprint $table) {
            $table->json('completion')->nullable()->after('procedures');
            $table->json('release')->nullable()->after('completion');
        });
    }

    public function down(): void
    {
        Schema::table('audit_plans', fn (Blueprint $table) => $table->dropColumn(['completion', 'release']));
        foreach (['audit_logs', 'misstatements', 'adjusting_entry_lines', 'adjusting_entries', 'audit_risks', 'materiality_versions', 'review_notes', 'engagement_signoffs'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
