<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Repositories\Eloquents\TaxonomyRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The income statement's classes that are their sub class's own name show a group instead (display only). */
class DisplayGroupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_income_main_class_that_equals_its_sub_class_has_a_group_and_every_group_names_a_real_main_class(): void
    {
        $taxonomy = app(TaxonomyRepository::class);
        $groups = config('classification.display_groups');
        $mains = $taxonomy->mainClasses();
        foreach (array_keys($groups) as $main) {
            $this->assertContains($main, $mains, "«{$main}» is not a main class");
        }
        foreach ($taxonomy->map() as $sub => [$statement, $main]) {
            if ($statement === 'قائمة الدخل' && $main === $sub) {
                $this->assertArrayHasKey($main, $groups, "«{$main}» is its sub class's own name and has no group");
                $this->assertNotSame($main, $groups[$main]);   // the group is not the same word again
            }
        }
    }

    public function test_the_classifier_page_carries_the_groups_and_shows_them_in_place_of_the_repeated_main_class(): void
    {
        $role = Role::create(['name_ar' => 'م', 'is_admin' => true]);
        $this->actingAs(User::forceCreate(['name' => 'س', 'username' => 'sara', 'password' => 'secret123', 'role_id' => $role->id, 'status' => User::ACTIVE, 'office_id' => \App\Models\Office::create(['name_ar' => 'م', 'code' => 'a'])->id]));

        $html = $this->get('/classifier')->assertOk()->getContent();

        $this->assertStringContainsString('data-groups=', $html);
        $this->assertStringContainsString('الزكاة وضريبة الدخل', $html);
        $this->assertStringContainsString('const shownMain = r =>', $html);
        $this->assertStringContainsString('${esc(shownMain(r) ?? \'—\')}', $html);
    }
}
