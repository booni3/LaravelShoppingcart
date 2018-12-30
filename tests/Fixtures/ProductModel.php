<?php

namespace Booni3\Tests\ShoppingCart\Fixtures;

class ProductModel
{
    public $someValue = 'Some value';

    public function find($id)
    {
        return $this;
    }
}
