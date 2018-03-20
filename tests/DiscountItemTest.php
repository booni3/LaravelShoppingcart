<?php

namespace Ollywarren\Tests\ShoppingCart;

use Orchestra\Testbench\TestCase;
use Ollywarren\ShoppingCart\DiscountItem;
use Ollywarren\ShoppingCart\ShoppingCartServiceProvider;

class DiscountItemTest extends TestCase
{
    /**
     * Set the package service provider.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [ShoppingcartServiceProvider::class];
    }

    /** @test */
    public function it_can_be_cast_to_an_array()
    {
        $discountItem = new DiscountItem(1, 'Some discount item', 10.00);

        $this->assertEquals([
            'rowId' => 'fb0c6e61b31a93045a6779dd59f31f98',
            'id' => 1,
            'name' => 'Some discount item',
            'value' => 10.00
        ], $discountItem->toArray());
    }

    /** @test */
    public function it_can_be_cast_to_json()
    {
        $discountItem = new DiscountItem(1, 'Some discount item', 10.00);

        $this->assertJson($discountItem->toJson());

        $json = '{"rowId":"fb0c6e61b31a93045a6779dd59f31f98","id":1,"name":"Some discount item","value":10}';

        $this->assertEquals($json, $discountItem->toJson());
    }
}
