<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

/**
 * Expected results for tests/Fixtures/balance.xlsx (sheet "Report" + adjustments sheet "قيود التسويات").
 * Shared by the faked test (exact PHP behaviour) and the live test (real Claude).
 */
trait BalanceXlsxExpectations
{
    protected function balanceXlsx(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'bal').'.xlsx';
        copy(base_path('tests/Fixtures/balance.xlsx'), $path);

        return new UploadedFile($path, 'balance.xlsx', null, null, true);
    }

    /** @return array<string, array> account_number => row */
    protected function rowsByNumber(TestResponse $response): array
    {
        return collect($response->json('data'))->keyBy('account_number')->all();
    }

    protected function flagsOf(array $row, string $type): array
    {
        return array_values(array_filter($row['data_flags'], fn ($f) => $f['type'] === $type));
    }

    protected function assertBalanceXlsxResults(TestResponse $response): void
    {
        // 19 rows, 6 of them zero in every column: left out.
        $response->assertOk()->assertJsonPath('meta.total', 13);
        $rows = $this->rowsByNumber($response);

        // 1a — reconciliation: exactly three accounts, signed actual − expected.
        $reconciliation = [];
        foreach ($rows as $number => $row) {
            foreach ($this->flagsOf($row, 'reconciliation') as $flag) {
                $reconciliation[$number] = $flag;
            }
        }
        $this->assertSame(['110101010', '110101014', '110101510'], array_map('strval', array_keys($reconciliation)));
        $this->assertEquals(1174.0, $reconciliation['110101010']['difference']);
        $this->assertEquals(1300.0, $reconciliation['110101014']['difference']);
        $this->assertEquals(-1751.2, $reconciliation['110101510']['difference']);

        // 3 — all three matched to "قيود التسويات", flag kept, still needs review.
        $this->assertSame('هذا الفرق مطابق لقيد تسوية بمبلغ 1,174.00 (ورقة قيود التسويات)', $reconciliation['110101010']['message']);
        $this->assertSame('هذا الفرق مطابق لقيد تسوية بمبلغ 1,300.00 (ورقة قيود التسويات)', $reconciliation['110101014']['message']);
        $this->assertSame('هذا الفرق مطابق لقيد تسوية بمبلغ 1,751.20 (ورقة قيود التسويات)', $reconciliation['110101510']['message']);
        foreach (array_keys($reconciliation) as $number) {
            $this->assertTrue($rows[$number]['needs_review'], $number);
        }

        // 3 — the three "جاري الشريك" lines map to null and cause no error.
        $adjustments = $response->json('meta.adjustments');
        $this->assertCount(6, $adjustments);
        $partner = array_values(array_filter($adjustments, fn ($a) => $a['account_name'] === 'جاري الشريك'));
        $this->assertCount(3, $partner);
        foreach ($partner as $line) {
            $this->assertNull($line['account_row']);
        }

        // 1b — abnormal balances.
        $abnormal = [];
        foreach ($rows as $number => $row) {
            foreach (['abnormal_opening', 'abnormal_closing'] as $type) {
                foreach ($this->flagsOf($row, $type) as $flag) {
                    $abnormal["{$type}:{$number}"] = $flag['difference'];
                }
            }
        }
        // 110101014 (opening credit) and 110101023 (closing credit) flip to أمانات العاملين by their side: not abnormal.
        $this->assertEqualsCanonicalizing([
            'abnormal_opening:110101012' => -2876.9,
            'abnormal_opening:110101013' => -9.7,
        ], $abnormal);
        $this->assertSame('رصيد افتتاحي دائن 2,876.90 على حساب طبيعته مدينة', $this->flagsOf($rows['110101012'], 'abnormal_opening')[0]['message']);
        $this->assertSame([], $rows['110101529']['data_flags'], 'بنك الراجحي 0.002 must not be flagged');

        // 2a — the remark in column I.
        $this->assertSame('غير مطابق مع التحليلي', $rows['110151010']['source_note']);
        $this->assertTrue($rows['110151010']['needs_review']);

        // 1c — partial trial balance: info, not a debit ≠ credit error. The one warning: an account marked not to be used has movement.
        $this->assertSame(['1 حساب معلَّم «لا تستخدمه» وعليه حركة: «لا تستخدمه - بنك الرياض -الحساب الفرعى - لا تستخدمه» (الصف 15 في الملف)'], $response->json('meta.warnings'));
        $this->assertCount(4, $response->json('meta.info'));
        $this->assertStringStartsWith('هذا الملف ميزان جزئي (فيه أصول', $response->json('meta.info.0'));
        $this->assertStringEndsWith('فقط)، لذا فعدم التوازن متوقع', $response->json('meta.info.0'));
        $this->assertSame('6 صف رصيدها صفر في الافتتاحي والحركة والختامي (حسابات صفرية أو عناوين)، وقد أُزيلت من التبويب (الصفوف 8، 9، 10، 17، 18، 19 في الملف).',
            $response->json('meta.info.2'));
        $this->assertSame('انعكس تصنيف 2 صف حسب اتجاه الرصيد (عهد وسلف العاملين ↔ أمانات العاملين).', $response->json('meta.info.3'));
        // The total row, left out and matching the accounts, is said so.
        $this->assertSame('أُزيل صف الإجمالي (الصف 186 في الملف) من التبويب، ومجموعه مطابق لمجموع الحسابات.', $response->json('meta.info.1'));

        // 2b — adjustments sheet detected.
        $this->assertSame([['sheet' => 2, 'name' => 'قيود التسويات', 'type' => 'adjustments']],
            array_map(fn ($o) => array_intersect_key($o, array_flip(['sheet', 'name', 'type'])), $response->json('meta.other_sheets')));

        // 4 / 5b — classification of the ambiguous accounts.
        foreach ($rows as $number => $row) {
            if (str_starts_with($row['account_name'], 'عهدة')) {
                $this->assertSame(in_array($number, ['110101014', '110101023']) ? 'أمانات العاملين' : 'عُهد العاملين', $row['sub_class'], $number);
            }
        }

        // 1e — needs_review comes only from low confidence, data flags (not info ones) or a source note. A flip by the side
        // pair is not a numbering doubt: no prefix_mismatch on 110101014 / 110101023.
        foreach ($rows as $number => $row) {
            $expected = $row['classification_confidence'] < 70 || array_filter($row['data_flags'], fn ($f) => ($f['severity'] ?? null) !== 'info') !== []
                || $row['source_note'] !== null;
            $this->assertSame($expected, $row['needs_review'], $number);
        }
        foreach (['110101014', '110101023'] as $flipped) {
            $this->assertNotContains('prefix_mismatch', array_column($rows[$flipped]['data_flags'], 'type'), $flipped);
        }
        // Zero in every column: not classified.
        foreach (['110101017', '110101018', '110101020', '110101525', '110101526', '110101528'] as $zero) {
            $this->assertArrayNotHasKey($zero, $rows);
        }
    }
}
