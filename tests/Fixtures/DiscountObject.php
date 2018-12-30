<?php

namespace Booni3\Tests\ShoppingCart\Fixtures;

use Booni3\ShoppingCart\Contracts\Discountable;

class DiscountObject implements Discountable
{
    /**
     * @var int|string
     */
    private $id;

    /**
     * @var string
     */
    private $name;

    /**
     * @var float
     */
    private $value;

    /**
     * BuyableProduct constructor.
     *
     * @param int|string $id
     * @param string     $name
     * @param float      $price
     */
    public function __construct($id = 1, $name = 'Discount item name', $value = 10.00)
    {
        $this->id = $id;
        $this->name = $name;
        $this->value = $value;
    }

    /**
     * Get the identifier of the Buyable item.
     *
     * @return int|string
     */
    public function getDiscountableIdentifier($options = null)
    {
        return $this->id;
    }

    /**
     * Get the description or title of the Buyable item.
     *
     * @return string
     */
    public function getDiscountableDescription($options = null)
    {
        return $this->name;
    }

    /**
     * Get the price of the Buyable item.
     *
     * @return float
     */
    public function getDiscountableValue($options = null)
    {
        return $this->value;
    }
}
