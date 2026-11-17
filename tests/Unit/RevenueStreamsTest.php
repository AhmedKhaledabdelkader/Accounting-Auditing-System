<?php

namespace Tests\Unit;

use App\Services\Statements\RevenueStreams;
use PHPUnit\Framework\TestCase;

class RevenueStreamsTest extends TestCase
{
    public function test_activity_and_account_names_give_the_streams(): void
    {
        $this->assertSame(['goods'], RevenueStreams::infer('تصنيع وبيع مواد العزل', ['مبيعات محلية']));
        $this->assertSame(['services', 'contracting'], RevenueStreams::infer('مقاولات عامة وخدمات صيانة', []));
        $this->assertSame(['leasing'], RevenueStreams::infer(null, ['إيرادات إيجارات']));
    }

    public function test_property_sales_are_not_goods(): void
    {
        $this->assertSame(['property_sales'], RevenueStreams::infer('تطوير عقاري وبيع وحدات سكنية', []));
    }

    public function test_nothing_said_gives_nothing(): void
    {
        $this->assertSame([], RevenueStreams::infer(null, []));
        $this->assertSame([], RevenueStreams::infer('شركة', ['حساب 1']));
    }
}
