<?php

namespace Tests\Unit;

use App\Support\ClassificationKey;
use PHPUnit\Framework\TestCase;

/** Arabic spelling variants collapse to one key; the base key drops the budget item for the imported fallback. */
class ClassificationKeyTest extends TestCase
{
    public function test_arabic_spelling_variants_give_one_key(): void
    {
        $variants = ['مَصْرُوفَاتُ إِدَارِيَّةٌ أُخرى', 'مصـــروفات إدارية أخرى', 'مصروفات اداريه اخري', '  مصروفات   إدارية   آخرى '];
        $keys = array_unique(array_map(fn ($n) => ClassificationKey::make('١٢٠ ١', $n, 'بنود المصروفات', null), $variants));

        $this->assertSame(['code:1201|name:مصروفات اداريه اخري|budget:بنود المصروفات'], array_values($keys));
        $this->assertSame('ا ا ا', ClassificationKey::name('أ إ آ'));
        $this->assertSame('مكتبه مستشفي', ClassificationKey::name('مكتبة مستشفى'));
    }

    public function test_the_base_key_is_code_and_name_without_the_budget_item(): void
    {
        $withBudget = ClassificationKey::make('1101', 'الصَّندوق', 'النقد وما في حكمه', null);
        $without    = ClassificationKey::make('1101', 'الصندوق', null, '');

        $this->assertNotSame($withBudget, $without);
        $this->assertSame('code:1101|name:الصندوق', ClassificationKey::base($withBudget));
        $this->assertSame(ClassificationKey::baseHash($withBudget), ClassificationKey::baseHash($without));
        $this->assertSame(sha1('code:1101|name:الصندوق'), ClassificationKey::baseHash($without));

        // No code: no base (a name alone is the name layer's job); generic keys have none either.
        $this->assertNull(ClassificationKey::base(ClassificationKey::make('', 'الصندوق', 'النقد', null)));
        $this->assertNull(ClassificationKey::base(ClassificationKey::make('', 'أخرى', null, 'المصروفات')));
        $this->assertNull(ClassificationKey::base(null));
    }
}
