<?php

namespace App\Support;

use App\Services\AmountParser;

/**
 * The classification cache's lookup key, and every normalization rule behind it (the explain API reads them here).
 * Key: code + name + budget item; a row with neither code nor budget item gets its parent's name instead, so generic
 * names ("أخرى", "متنوعة") under unrelated sections never share one answer.
 */
final class ClassificationKey
{
    /** Applied to names (account, budget item, parent) in this order: rule => [Arabic description, example]. */
    public const RULES = [
        'tashkeel'     => ['description' => 'حذف التشكيل (الفتحة، الضمة، الكسرة، السكون، الشدة، التنوين).', 'example' => 'مَصْرُوفَات'],
        'tatweel'      => ['description' => 'حذف التطويل (ـ).', 'example' => 'مصـــروفات'],
        'alef'         => ['description' => 'توحيد الألف: أ وإ وآ تصبح ا.', 'example' => 'أصول إدارية آجلة'],
        'ta_marbuta'   => ['description' => 'التاء المربوطة تصبح هاءً: ة تصبح ه.', 'example' => 'مصروفات إدارية'],
        'alef_maqsura' => ['description' => 'الألف المقصورة تصبح ياءً: ى تصبح ي.', 'example' => 'مستشفى'],
        'spaces'       => ['description' => 'حذف المسافات من البداية والنهاية، وتحويل أي مسافات متكررة إلى مسافة واحدة.', 'example' => '  مصروفات   إدارية '],
    ];

    /** The account code: digits made ASCII (١٢٣ → 123), spaces removed. */
    public const CODE_RULE = ['description' => 'كود الحساب: تُحوَّل الأرقام العربية إلى أرقام إنجليزية (١٢٣ تصبح 123) وتُحذف المسافات.', 'example' => '١٢٠ ١٠١'];

    /** The fields of the key, for the explain API. */
    public const KEY_FIELDS = [
        ['field' => 'account_code', 'description' => 'كود الحساب من الملف، إن وُجد.'],
        ['field' => 'account_name', 'description' => 'اسم الحساب بعد التوحيد.'],
        ['field' => 'budget_item', 'description' => 'بند الميزانية من عمود تصنيف الملف (مثل «بنود الميزانية»)، إن وُجد.'],
        ['field' => 'parent_name', 'description' => 'يُضاف فقط إذا لم يكن للصف كود ولا بند ميزانية: اسم أقرب حساب رئيسي أو عنوان قسم فوقه في الملف. '
            .'إذا كان فوقه صف جديد غير موجود في الذاكرة (قد يكون حسابًا رئيسيًا جديدًا)، تُرسل الصفوف العامة التي بعده إلى الذكاء الاصطناعي، حتى لا يُؤخذ تصنيف من قسم آخر.'],
    ];

    public const KEY_WHY = 'يحدد المفتاح متى نعتبر صفين هما الحساب نفسه. ويمنع الكود والاسم معًا أن يأخذ حسابان مختلفان بنفس الاسم التصنيف نفسه، '
        .'ويضمن بند الميزانية أن يُصنَّف الاسم نفسه من جديد إذا ورد تحت بند مختلف. وتتكرر الأسماء العامة مثل «أخرى» أو «متنوعة» تحت أقسام لا علاقة بينها، '
        .'فإذا لم يكن للصف كود ولا بند ميزانية أضفنا اسم الحساب الرئيسي الذي يعلوه، حتى لا يأخذ «أخرى» تحت المصروفات تصنيف «أخرى» تحت الإيرادات.';

    public static function apply(string $rule, string $text): string
    {
        return match ($rule) {
            'tashkeel'     => preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text),
            'tatweel'      => str_replace('ـ', '', $text),
            'alef'         => str_replace(['أ', 'إ', 'آ'], 'ا', $text),
            'ta_marbuta'   => str_replace('ة', 'ه', $text),
            'alef_maqsura' => str_replace('ى', 'ي', $text),
            'spaces'       => trim(preg_replace('/\s+/u', ' ', $text)),
        };
    }

    public static function name(?string $text): string
    {
        $text = (string) $text;
        foreach (array_keys(self::RULES) as $rule) {
            $text = self::apply($rule, $text);
        }
        return $text;
    }

    public static function code(?string $code): string
    {
        return preg_replace('/\s+/u', '', AmountParser::asciiDigits((string) $code));
    }

    /** A row needs its parent's name in the key when it has neither code nor budget item. */
    public static function isGeneric(?string $code, ?string $budgetItem): bool
    {
        return self::code($code) === '' && self::name($budgetItem) === '';
    }

    /**
     * The readable key, or null when the row can't be cached: no name, or a generic row whose parent is unknown.
     * $parent: the parent's name ('' = no parent above the row); only used for generic rows.
     */
    public static function make(?string $code, ?string $name, ?string $budgetItem, ?string $parent): ?string
    {
        $name = self::name($name);
        if ($name === '') {
            return null;
        }
        if (self::isGeneric($code, $budgetItem)) {
            return $parent === null ? null : 'name:'.$name.'|parent:'.self::name($parent);
        }
        return 'code:'.self::code($code).'|name:'.$name.'|budget:'.self::name($budgetItem);
    }

    public static function hash(string $key): string
    {
        return sha1($key);
    }

    /**
     * The key without its budget item («code:…|name:…»): the fallback lookup of imported entries, whose key always has the
     * accountant's budget item while a client's trial balance usually has none. Null without a code (a name alone is the
     * name layer's job) and for generic keys.
     */
    public static function base(?string $key): ?string
    {
        if ($key === null || ! preg_match('/^code:([^|]+)\|name:([^|]*)\|budget:/u', $key, $m)) {
            return null;
        }

        return 'code:'.$m[1].'|name:'.$m[2];
    }

    public static function baseHash(?string $key): ?string
    {
        $base = self::base($key);

        return $base === null ? null : sha1($base);
    }

    /** sha1 of the normalized name: finds the generic rows' entries before their parent is known. */
    public static function nameHash(?string $name): string
    {
        return sha1(self::name($name));
    }
}
