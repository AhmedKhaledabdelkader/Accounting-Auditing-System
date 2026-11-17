<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One remembered classification of a row (ClassificationCache). */
class ClassificationCacheEntry extends Model
{
    protected $table = 'classification_cache';

    protected $fillable = [
        'key_hash', 'base_hash', 'name_hash', 'normalized_key', 'original_name', 'account_code', 'budget_item', 'row_type',
        'main_class', 'sub_class', 'source', 'confidence', 'reason', 'taxonomy_version', 'hit_count', 'import_id', 'side', 'side_locked',
    ];

    protected function casts(): array
    {
        return ['confidence' => 'integer', 'hit_count' => 'integer', 'side_locked' => 'boolean'];
    }

    /**
     * What each stored column means, for the accountant (GET /api/v1/classification-cache/explain).
     * Every column of the table must be listed here (a test checks it).
     */
    public const FIELD_DESCRIPTIONS = [
        'id'               => 'رقم السجل، ويُستخدم لحذف سجل خاطئ.',
        'key_hash'         => 'بصمة (sha1) لمفتاح البحث، ليكون البحث سريعًا ولا يوجد سجلان لنفس المفتاح.',
        'base_hash'        => 'بصمة (sha1) لكود الحساب واسمه دون بند الميزانية: إذا لم يوجد سجل للمفتاح كاملًا، يُبحث بها عن التصنيفات المستوردة لنفس الكود والاسم.',
        'name_hash'        => 'بصمة (sha1) لاسم الحساب بعد التوحيد، لنجد سجلات الأسماء العامة في البحث نفسه قبل معرفة الحساب الرئيسي الذي يعلوها.',
        'normalized_key'   => 'مفتاح البحث مقروء: كود الحساب والاسم بعد التوحيد وبند الميزانية (أو اسم الحساب الأب للأسماء العامة).',
        'original_name'    => 'اسم الحساب كما كان مكتوبًا في الملف عند أول تخزين.',
        'account_code'     => 'كود الحساب من الملف (إن وُجد).',
        'budget_item'      => 'بند الميزانية من عمود تصنيف الملف (إن وُجد).',
        'row_type'         => 'نوع الصف: حساب، حساب رئيسي، إجمالي، عنوان قسم، أو غيره.',
        'main_class'       => 'التصنيف الرئيسي وقت التخزين (للعرض فقط؛ وقت الاستخدام يؤخذ من شجرة التصنيف الحالية بحسب التصنيف الفرعي).',
        'sub_class'        => 'التصنيف الفرعي المطبق على الصف. وبالنسبة للتصنيفات التي تعتمد على اتجاه الرصيد (العملاء، الموردين، العُهد، ضريبة القيمة المضافة) يُخزَّن نوع الحساب الأساسي، ويُحدَّد الجانب من رصيد كل صف وقت الاستخدام.',
        'source'           => 'مصدر التصنيف: ai (الذكاء الاصطناعي) أو manual_correction (تصحيح يدوي) أو imported (تصنيف محاسب من ملف مستورد).',
        'confidence'       => 'درجة ثقة الذكاء الاصطناعي (0–100). التصحيح اليدوي = 100. المستورد = ثقة ربط بند الميزانية والإيضاح بالشجرة.',
        'reason'           => 'سبب التصنيف كما كتبه الذكاء الاصطناعي وقت التخزين. ويظهر للصف القادم من الذاكرة مع ملاحظة أنه من الذاكرة. ويكون فارغًا للتصحيح اليدوي.',
        'taxonomy_version' => 'نسخة شجرة التصنيف والتعليمات وقت التخزين. لا تُستخدم إجابات الذكاء الاصطناعي من نسخة قديمة.',
        'import_id'        => 'للتصنيف المستورد: رقم الاستيراد الذي كتبه (وتحذف إعادة تطبيق الاستيراد سجلاته التي لم يعد لها صف).',
        'side'             => 'للتصحيح اليدوي: اتجاه رصيد الصف (مدين/دائن) وقت التصحيح.',
        'side_locked'      => 'اختار التصحيح اليدوي عكس اتجاه الرصيد عن قصد: لا يُعكس هذا التصنيف بحسب اتجاه الرصيد أبدًا.',
        'hit_count'        => 'عدد المرات التي استُخدم فيها هذا السجل بدل سؤال الذكاء الاصطناعي.',
        'created_at'       => 'وقت أول تخزين.',
        'updated_at'       => 'آخر وقت تم فيه تعديل السجل.',
    ];
}
