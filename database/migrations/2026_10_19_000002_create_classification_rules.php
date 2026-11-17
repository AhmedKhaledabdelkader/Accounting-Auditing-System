<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The accountant's classification rules (docs/17-classification-rules.md), edited on the rules page: a kind of
// balance-sheet account, how it is recognised (whole words in its name or its heading, or a company's code group), and its
// class by the side of its balance. Seeded with the accountant's decisions of 2026-10-19; a class not decided yet is empty.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classification_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->unsignedInteger('sort')->default(0);        // the first rule that matches wins
            $table->json('name_words');                         // any of them in the account's name
            $table->json('also_words');                         // and, when given, one of these too
            $table->json('exclude_words');                      // none of them
            $table->json('heading_words');                      // or any of them in the heading above the account
            $table->string('debit_class', 100)->nullable();     // the sub class for a debit balance; null: not decided yet
            $table->string('credit_class', 100)->nullable();
            $table->string('review_side', 6)->default('none');  // none | debit | credit | both: flagged for review on that side
            $table->json('ai_classes');                         // the AI's classes that suggest this kind (code-group proposals)
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
        // A company's code group (an account-number prefix) marked as one kind of account.
        Schema::create('company_code_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('prefix', 40);
            $table->foreignId('rule_id')->constrained('classification_rules')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'prefix']);
        });

        $now = now();
        foreach (self::RULES as $i => $r) {
            DB::table('classification_rules')->insert([
                'name' => $r[0], 'sort' => ($i + 1) * 10,
                'name_words' => json_encode($r[1], JSON_UNESCAPED_UNICODE), 'also_words' => json_encode($r[2], JSON_UNESCAPED_UNICODE),
                'exclude_words' => json_encode($r[3], JSON_UNESCAPED_UNICODE), 'heading_words' => json_encode($r[4], JSON_UNESCAPED_UNICODE),
                'debit_class' => $r[5], 'credit_class' => $r[6], 'review_side' => $r[7], 'ai_classes' => json_encode($r[8], JSON_UNESCAPED_UNICODE),
                'enabled' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_code_groups');
        Schema::dropIfExists('classification_rules');
    }

    /** [name, name words, also words, exclude words, heading words, debit class, credit class, review side, AI classes] */
    private const RULES = [
        // Before the suppliers: «تسهيلات موردين» has «موردين». Its classes wait for the accountant.
        ['تسهيلات موردين', ['تسهيلات موردين'], [], [], [], null, null, 'none', []],
        ['مدير / شريك', ['مدير', 'مديرين', 'شريك', 'شركاء', 'رئيس مجلس', 'عضو مجلس', 'مساهم', 'مساهمين'], [], [], [],
            'اطراف ذات علاقة مدينة', 'اطراف ذات علاقة دائنة', 'none', ['اطراف ذات علاقة مدينة', 'اطراف ذات علاقة دائنة']],
        // The general accrued-salaries account, before the employees' own accounts.
        ['رواتب مستحقة (عام)', ['رواتب', 'أجور', 'اجور', 'مرتبات'], ['مستحق', 'مستحقة'], [], [],
            'رواتب وأجور مستحقة', 'رواتب وأجور مستحقة', 'debit', []],
        ['عهدة', ['عهدة', 'عهد'], [], [], [], 'عُهد العاملين', 'عُهد وسُلف دائنه', 'none', ['عُهد العاملين']],
        // Personal accounts: recognised by their heading or a company's code group (their names are people's names).
        ['موظف (حساب شخصي)', [], [], ['رواتب', 'أجور', 'اجور', 'مرتبات', 'مستحق', 'مستحقة', 'تأمينات', 'مكافأة', 'مكافاة'],
            ['موظفين', 'موظفون', 'العاملين', 'عاملين', 'ذمم موظفين', 'سلف'],
            null, 'عُهد وسُلف دائنه', 'none', ['سلف العاملين', 'عُهد العاملين', 'أمانات العاملين', 'عُهد وسُلف دائنه', 'رواتب وأجور مستحقة']],
        ['مورد', ['مورد', 'موردين', 'موردون'], [], [], ['مورد', 'موردين', 'موردون'],
            'دفعات مقدمة للموردين', 'موردون', 'none', ['موردون', 'دفعات مقدمة للموردين', 'سلف الموردين']],
        ['عميل', ['عميل', 'عملاء'], [], [], ['عميل', 'عملاء'], 'عملاء', 'دفعات مقدمة من العملاء', 'none', ['عملاء', 'دفعات مقدمة من العملاء']],
        ['أرباح مرحلة', ['أرباح مرحلة', 'أرباح محتجزة', 'أرباح مبقاة', 'خسائر مرحلة', 'أرباح وخسائر مرحلة'], [], [], [],
            'أرباح أو خسائر سنوات سابقة', 'أرباح أو خسائر سنوات سابقة', 'none', []],
        // Before the pre-operating expenses themselves (their accumulated amortisation has the same words). Classes wait
        // for «أصول أخرى» (a new class and statement line).
        ['مجمع إطفاء ما قبل التشغيل', ['قبل التشغيل', 'تأسيس'], ['مجمع', 'إطفاء', 'اطفاء'], [], [], null, null, 'none', []],
        ['مصروفات ما قبل التشغيل', ['قبل التشغيل', 'تأسيس'], [], ['مجمع', 'إطفاء', 'اطفاء', 'إهلاك', 'اهلاك'], [], null, null, 'none', []],
        ['مخصص الزكاة', ['زكاة'], [], [], [], 'مخصص الزكاة الشرعية', 'مخصص الزكاة الشرعية', 'debit', ['مخصص الزكاة الشرعية']],
        ['جاري التأمينات الاجتماعية', ['تأمينات اجتماعية', 'تامينات اجتماعية'], [], [], [], null, 'تأمينات اجتماعية مستحقة', 'debit', []],
        ['مصروفات مقدمة / مستحقة', ['مصروفات', 'مصاريف', 'مصروف'], ['مقدما', 'مقدمة', 'مقدم', 'مستحقة', 'مستحق'], [], [],
            'مصروفات مدفوعة مقدماً', 'مصروفات مستحقة', 'none', ['مصروفات مدفوعة مقدماً', 'مصروفات مستحقة']],
    ];
};
