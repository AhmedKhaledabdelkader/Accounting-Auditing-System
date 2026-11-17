<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting offices and their clients' portal.
 *
 * - An office (مكتب) has a code the staff and the clients type when they sign in; its users, companies, reports and
 *   classification runs belong to it and no other office sees them. Everything that exists now moves to the first office.
 * - A client is a company with a portal user (users.company_id) whose role is a «client» role: it holds only `portal.use`.
 * - The accountant asks a client for documents (document_requests), the client uploads files to each request
 *   (client_files) and they talk (client_messages), on one request or in general.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offices', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('code', 20)->unique();             // lowercase; typed on the sign-in page
            $table->boolean('is_platform')->default(false);   // the office that runs the site: only it manages roles and offices
            $table->string('status', 16)->default('active');  // active | disabled
            $table->timestamps();
        });

        $office = DB::table('offices')->insertGetId(['name_ar' => 'المكتب الرئيسي', 'name_en' => 'Main office', 'code' => 'main',
            'is_platform' => true, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_client')->default(false)->after('open_for_signup');   // the role of a client's portal user
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('office_id')->nullable()->after('id')->constrained('offices')->nullOnDelete();
            $table->foreignUuid('company_id')->nullable()->after('office_id')->constrained('companies')->nullOnDelete();
            // A username is unique in its office: two offices can both have «sara».
            $table->dropUnique(['username']);
            $table->unique(['office_id', 'username']);
        });
        DB::table('users')->update(['office_id' => $office]);

        foreach (['companies', 'financial_reports', 'classification_runs'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('office_id')->nullable()->constrained('offices')->nullOnDelete();
            });
            DB::table($name)->update(['office_id' => $office]);
        }

        $role = DB::table('roles')->insertGetId(['name_ar' => 'عميل', 'name_en' => 'Client', 'is_admin' => false,
            'open_for_signup' => false, 'is_client' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('role_permissions')->insert(['role_id' => $role, 'permission' => 'portal.use']);

        Schema::create('document_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');                   // the financial year asked about
            $table->string('section');                              // the group it sits in, e.g. «المخزون»
            $table->unsignedSmallInteger('number')->nullable();     // its number in the checklist
            $table->string('title', 500);
            $table->string('format', 16)->nullable();               // excel | pdf: what the accountant asked for
            $table->boolean('optional')->default(false);            // «إن وجد»
            $table->string('status', 16)->default('pending');       // pending | uploaded | returned | accepted | not_applicable
            $table->text('note')->nullable();                       // why it was returned
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'year']);
        });

        Schema::create('client_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('document_request_id')->constrained('document_requests')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('from_client')->default(true);
            $table->string('original_name');
            $table->string('path');                                 // on the private disk
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });

        Schema::create('client_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('document_request_id')->nullable()->constrained('document_requests')->cascadeOnDelete();   // null = the general chat
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('from_client')->default(false);
            $table->string('kind', 8)->default('text');             // text | event (an upload, a review: shown in the thread)
            $table->text('body');
            $table->timestamp('read_at')->nullable();               // read by the other side
            $table->timestamps();
            $table->index(['company_id', 'document_request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_messages');
        Schema::dropIfExists('client_files');
        Schema::dropIfExists('document_requests');
        DB::table('roles')->where('is_client', true)->delete();
        foreach (['classification_runs', 'financial_reports', 'companies'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('office_id'));
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['office_id', 'username']);
            $table->dropConstrainedForeignId('company_id');
            $table->dropConstrainedForeignId('office_id');
            $table->unique('username');
        });
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('is_client'));
        Schema::dropIfExists('offices');
    }
};
