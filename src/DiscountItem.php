<?php

namespace Ollywarren\ShoppingCart;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Ollywarren\ShoppingCart\Contracts\Discountable;

class DiscountItem implements Arrayable, Jsonable
{
    /**
     * The rowID of the discount item.
     *
     * @var string
     */
    public $rowId;

    /**
     * The ID of the discount item.
     *
     * @var int|string
     */
    public $id;

    /**
     * The name of the discount item.
     *
     * @var string
     */
    public $name;

    /**
     * The Value of the discount item
     *
     * @var float
     */
    public $value;


    /**
     * DiscountItem constructor.
     *
     * @param int|string $id
     * @param string     $name
     * @param float      $value
     */
    public function __construct($id, $name, $value)
    {
        if (empty($id)) {
            throw new \InvalidArgumentException('Please supply a valid identifier.');
        }
        if (empty($name)) {
            throw new \InvalidArgumentException('Please supply a valid name.');
        }
        if (strlen($value) < 0 || ! is_numeric($value)) {
            throw new \InvalidArgumentException('Please supply a valid value.');
        }

        $this->id       = $id;
        $this->name     = $name;
        $this->value    = floatval($value);
        $this->rowId    = $this->generateRowId($id, $name, $value);
    }

    /**
     * Update the cart item from an array.
     *
     * @param array $attributes
     * @return void
     */
    public function updateFromArray(array $attributes)
    {
        $this->id       = array_get($attributes, 'id', $this->id);
        $this->name     = array_get($attributes, 'name', $this->name);
        $this->value    = array_get($attributes, 'value', $this->value);
        $this->rowId = $this->generateRowId($this->id, $this->options->all());
    }

    /**
     * Get an attribute from the discount item
     *
     * @param string $attribute
     * @return mixed
     */
    public function __get($attribute)
    {
        if (property_exists($this, $attribute)) {
            return $this->{$attribute};
        }

        return null;
    }

    /**
     * Create a new instance from a Discountable.
     *
     * @param \Ollywarren\ShoppingCart\Contracts\Discountable $item
     * @param array                                      $options
     * @return \Ollywarren\ShoppingCart\DiscountItem
     */
    public static function fromDiscountable(Discountable $item, array $options = [])
    {
        return new self($item->getDiscountableIdentifier($options), $item->getDiscountableDescription($options), $item->getDiscountableValue($options), $options);
    }

    /**
     * Create a new instance from the given array.
     *
     * @param array $attributes
     * @return \Ollywarren\ShoppingCart\DiscountItem
     */
    public static function fromArray(array $attributes)
    {
        return new self($attributes['id'], $attributes['name'], $attributes['value']);
    }

    /**
     * Create a new instance from the given attributes.
     *
     * @param int|string $id
     * @param string     $name
     * @param float      $price
     * @param array      $options
     * @return \Gloudemans\Shoppingcart\DiscountItem
     */
    public static function fromAttributes($id, $name, $value)
    {
        return new self($id, $name, $value);
    }

    /**
     * Generate a unique id for the cart item.
     *
     * @param string $id
     * @param array  $options
     * @return string
     */
    protected function generateRowId($id, $name, $value)
    {
        return md5($id . $name . $value);
    }

    /**
     * Get the instance as an array.
     *
     * @return array
     */
    public function toArray()
    {
        return [
            'rowId'    => $this->rowId,
            'id'       => $this->id,
            'name'     => $this->name,
            'value'    => $this->value,
        ];
    }

    /**
     * Convert the object to its JSON representation.
     *
     * @param int $options
     * @return string
     */
    public function toJson($options = 0)
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Get the formatted number.
     *
     * @param float  $value
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    private function numberFormat($value, $decimals, $decimalPoint, $thousandSeperator)
    {
        if (is_null($decimals)) {
            $decimals = is_null(config('cart.format.decimals')) ? 2 : config('cart.format.decimals');
        }

        if (is_null($decimalPoint)) {
            $decimalPoint = is_null(config('cart.format.decimal_point')) ? '.' : config('cart.format.decimal_point');
        }

        if (is_null($thousandSeperator)) {
            $thousandSeperator = is_null(config('cart.format.thousand_seperator')) ? ',' : config('cart.format.thousand_seperator');
        }

        return number_format($value, $decimals, $decimalPoint, $thousandSeperator);
    }
}
