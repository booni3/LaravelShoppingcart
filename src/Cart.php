<?php

namespace Ollywarren\ShoppingCart;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Session\SessionManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Contracts\Events\Dispatcher;
use PhpUnitsOfMeasure\PhysicalQuantity\Mass;
use Ollywarren\ShoppingCart\Contracts\Buyable;
use Ollywarren\ShoppingCart\Contracts\Shippable;
use Ollywarren\ShoppingCart\Exceptions\UnknownModelException;
use Ollywarren\ShoppingCart\Exceptions\InvalidRowIDException;
use Ollywarren\ShoppingCart\Exceptions\CartAlreadyStoredException;
use Ollywarren\ShoppingCart\Contracts\Discountable;
use Ollywarren\ShoppingCart\CartItem;

class Cart
{
    const DEFAULT_INSTANCE = 'default';

    /**
     * Instance of the session manager.
     *
     * @var \Illuminate\Session\SessionManager
     */
    private $session;

    /**
     * Instance of the event dispatcher.
     *
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    private $events;

    /**
     * Holds the current cart instance.
     *
     * @var string
     */
    private $instance;

    /**
     * Discount Monetary Values
     *
     * @var int
     */
    private $discountMonetaryValue;

    /**
     * Disocunt Percentage Value
     *
     * @var int
     */
    private $discountPercentageValue;

    /**
     * Does the Cart have Free Shipping Applied
     *
     * @var boolean
     */
    private $freeShipping = false;

    /**
     * Value of Any Shipping Discount Applied.
     *
     * @var int
     */
    private $shippingDiscount;

    /**
     * Cart constructor.
     *
     * @param \Illuminate\Session\SessionManager      $session
     * @param \Illuminate\Contracts\Events\Dispatcher $events
     */
    public function __construct(SessionManager $session, Dispatcher $events)
    {
        $this->session = $session;
        $this->events = $events;

        // Set Inital Discount States
        $this->discountMonetaryValue    = 0;
        $this->discountPercentageValue  = 0;
        $this->shippingDiscount         = 0;
        $this->freeShipping             = false;

        $this->instance(self::DEFAULT_INSTANCE);
    }

    /**
     * Set the current cart instance.
     *
     * @param string|null $instance
     * @return \Gloudemans\Shoppingcart\Cart
     */
    public function instance($instance = null)
    {
        $instance = $instance ?: self::DEFAULT_INSTANCE;

        $this->instance = sprintf('%s.%s', 'cart', $instance);

        return $this;
    }

    /**
     * Get the current cart instance.
     *
     * @return string
     */
    public function currentInstance()
    {
        return str_replace('cart.', '', $this->instance);
    }

    /**
     * Add an item to the cart.
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param int|float $qty
     * @param float     $price
     * @param array     $options
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public function add($id, $name = null, $qty = null, $price = null, array $options = [])
    {
        if ($this->isMulti($id)) {
            return array_map(function ($item) {
                return $this->add($item);
            }, $id);
        }

        $cartItem = $this->createCartItem($id, $name, $qty, $price, $options);

        $content = $this->getContent();

        if ($content->has($cartItem->rowId)) {
            $cartItem->qty += $content->get($cartItem->rowId)->qty;
        }

        $content->put($cartItem->rowId, $cartItem);
        
        $this->events->fire('cart.added', $cartItem);

        $this->session->put($this->instance, $content);

        return $cartItem;
    }

    /**
     * Add a Shipping Item to the Cart
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param float     $price
     * @return  \Ollywarren\ShoppingCart\ShippingItem
     */
    public function shipping($id, $name = null, $price = null)
    {
        $shippingItem = $this->createShippingItem($id, $name, $price);

        $content = $this->getContent();

        $check = collect($content->filter(function ($value, $key) {
            return $value instanceof ShippingItem;
        })->all());

        if ($check->count() == 1) {
            $content->forget($check->first());
        }

        $content->put($shippingItem->rowId, $shippingItem);
        
        $this->events->fire('shipping.added', $shippingItem);

        $this->session->put($this->instance, $content);

        return $shippingItem;
    }

    /**
     * Add a Discount Item to the Cart
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param float     $value
     * @return  \Ollywarren\ShoppingCart\ShippingItem
     */
    public function discount($id, $name = null, $qty = null, $value = null, $type = null)
    {
        $discountItem = $this->createDiscountItem($id, $name, $qty, $value, $type);

        $content = $this->getContent();

        if ($content->has($discountItem->rowId)) {
            $discountItem->qty += $content->get($discountItem->rowId)->qty;
        }

        $content->put($discountItem->rowId, $discountItem);

        $this->events->fire('discount.added', $discountItem);

        $this->session->put($this->instance, $content);

        if ($type == 'monetary') {
            $this->discountMonetaryValue = $this->discountMonetaryValue + $value;
        } elseif ($type == 'percent') {
            $this->discountPercentValue = $this->discountPercentValue + $value;
        }

        return $discountItem;
    }

    /**
     * Update the cart item with the given rowId.
     *
     * @param string $rowId
     * @param mixed  $qty
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public function update($rowId, $qty)
    {
        $cartItem = $this->get($rowId);

        if ($qty instanceof Buyable) {
            $cartItem->updateFromBuyable($qty);
        } elseif ($qty instanceof Shippable) {
            $cartItem->updateFromShippable($qty);
        } elseif ($qty instanceof Discountable) {
            $cartItem->updateFromDiscountable($qty);
        } elseif (is_array($qty)) {
            $cartItem->updateFromArray($qty);
        } else {
            $cartItem->qty = $qty;
        }

        $content = $this->getContent();

        if ($rowId !== $cartItem->rowId) {
            $content->pull($rowId);

            if ($content->has($cartItem->rowId)) {
                $existingCartItem = $this->get($cartItem->rowId);
                $cartItem->setQuantity($existingCartItem->qty + $cartItem->qty);
            }
        }

        if ($cartItem->qty <= 0) {
            $this->remove($cartItem->rowId);
            return;
        } else {
            $content->put($cartItem->rowId, $cartItem);
        }

        $this->events->fire('cart.updated', $cartItem);

        $this->session->put($this->instance, $content);

        return $cartItem;
    }

    /**
     * Remove the cart item with the given rowId from the cart.
     *
     * @param string $rowId
     * @return void
     */
    public function remove($rowId)
    {
        $cartItem = $this->get($rowId);

        $content = $this->getContent();

        $content->pull($cartItem->rowId);

        $this->events->fire('cart.removed', $cartItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Get a cart item from the cart by its rowId.
     *
     * @param string $rowId
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public function get($rowId)
    {
        $content = $this->getContent();

        if (! $content->has($rowId)) {
            throw new InvalidRowIDException("The cart does not contain rowId {$rowId}.");
        }

        return $content->get($rowId);
    }

    /**
     * Destroy the current cart instance.
     *
     * @return void
     */
    public function destroy()
    {
        $this->session->remove($this->instance);
    }

    /**
     * Get the content of the cart.
     *
     * @return \Illuminate\Support\Collection
     */
    public function content()
    {
        if (is_null($this->session->get($this->instance))) {
            return new Collection([]);
        }

        return $this->session->get($this->instance);
    }

    /**
     * Get the number of items in the cart.
     *
     * @return int|float
     */
    public function count()
    {
        $content = $this->getContent();

        return $content->sum('qty');
    }

    /**
     * Looks up any weights in the product options
     *
     * @return void
     */
    public function weight()
    {
        $weightSplit = [
            'imperial'  => 0,
            'metric'    => 0
        ];

        $content = $this->getContent();

        foreach ($content as $row) {
            if ($row instanceof CartItem) {
                if ($row->options->weight) {
                    if ($row->options->unit == 'Metric') {
                        $weightSplit['metric']  = $weightSplit['metric'] + ($row->options->weight * $row->qty);
                    } else {
                        $weightSplit['imperial']  = $weightSplit['imperial'] + ($row->options->weight * $row->qty);
                    }
                }
            }
        }

        // Convert the two types inot a total of each Type
        $imperialTotal      = new Mass($weightSplit['imperial'], 'pounds');
        $metricTotal        = new Mass($weightSplit['metric'], 'kilograms');

        $imperialConversion = new Mass($imperialTotal->toUnit('kilograms'), 'kilograms');
        $metricConversion   = new Mass($metricTotal->toUnit('pounds'), 'pounds');

        $weight = [
            'lbs'       => $imperialTotal->add($metricConversion)->toUnit('pounds'),
            'ounces'    => $imperialTotal->add($metricConversion)->toUnit('ounces'),
            'kgs'       => $metricTotal->add($imperialConversion)->toUnit('kilograms'),
            'grams'     => $metricTotal->add($imperialConversion)->toUnit('grams')
        ];

        return collect($weight);
    }

    /**
     * Get the Number of Product Lines in the Cart
     * excluding shipping and Discounts
     *
     * @return int|float
     */
    public function lines()
    {
        $content = $this->getContent();

        $filter = $content->filter(function ($value, $key) {
            return $value instanceof CartItem;
        })->all();

        return $filter->count();
    }

    /**
     * Get the total price of the items in the cart.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function total($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        $content = $this->getContent();

        $total = $content->reduce(function ($total, $cartItem) {
            if (!$cartItem instanceof Discountable) {
                return $total + ($cartItem->qty * $cartItem->priceTax);
            }
        }, 0);

        return $this->numberFormat($total, $decimals, $decimalPoint, $thousandSeperator);
    }



    /**
     * Get the total tax of the items in the cart.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return float
     */
    public function tax($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        $content = $this->getContent();

        $tax = $content->reduce(function ($tax, $cartItem) {
            if (!$cartItem instanceof Discountable) {
                return $tax + ($cartItem->qty * $cartItem->tax);
            }
        }, 0);

        return $this->numberFormat($tax, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Get the subtotal (total - tax) of the items in the cart.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return float
     */
    public function subtotal($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        $content = $this->getContent();

        $subTotal = $content->reduce(function ($subTotal, $cartItem) {
            if (!$cartItem instanceof Discountable) {
                return $subTotal + ($cartItem->qty * $cartItem->price);
            }
        }, 0);

        return $this->numberFormat($subTotal, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Get the subtotal (total - tax) of the items in the cart, exlcuing any shipping Items
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return float
     */
    public function subtotalExcShipping($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        $content = $this->getContent();
        $filtered = $content->filter(function ($value, $key) {
            return $value instanceof CartItem;
        });

        $content = collect($filtered->all());

        $subTotal = $content->reduce(function ($subTotal, $cartItem) {
            return $subTotal + ($cartItem->qty * $cartItem->price);
        }, 0);

        return $this->numberFormat($subTotal, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Search the cart content for a cart item matching the given search closure.
     *
     * @param \Closure $search
     * @return \Illuminate\Support\Collection
     */
    public function search(Closure $search)
    {
        $content = $this->getContent();

        return $content->filter($search);
    }

    /**
     * Associate the cart item with the given rowId with the given model.
     *
     * @param string $rowId
     * @param mixed  $model
     * @return void
     */
    public function associate($rowId, $model)
    {
        if (is_string($model) && ! class_exists($model)) {
            throw new UnknownModelException("The supplied model {$model} does not exist.");
        }

        $cartItem = $this->get($rowId);

        $cartItem->associate($model);

        $content = $this->getContent();

        $content->put($cartItem->rowId, $cartItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Set the tax rate for the cart item with the given rowId.
     *
     * @param string    $rowId
     * @param int|float $taxRate
     * @return void
     */
    public function setTax($rowId, $taxRate)
    {
        $cartItem = $this->get($rowId);

        $cartItem->setTaxRate($taxRate);

        $content = $this->getContent();

        $content->put($cartItem->rowId, $cartItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Store an the current instance of the cart.
     *
     * @param mixed $identifier
     * @return void
     */
    public function store($identifier)
    {
        $content = $this->getContent();

        if ($this->storedCartWithIdentifierExists($identifier)) {
            throw new CartAlreadyStoredException("A cart with identifier {$identifier} was already stored.");
        }

        $this->getConnection()->table($this->getTableName())->insert([
            'identifier' => $identifier,
            'instance' => $this->currentInstance(),
            'content' => serialize($content)
        ]);

        $this->events->fire('cart.stored');
    }

    /**
     * Restore the cart with the given identifier.
     *
     * @param mixed $identifier
     * @return void
     */
    public function restore($identifier)
    {
        if (! $this->storedCartWithIdentifierExists($identifier)) {
            return;
        }

        $stored = $this->getConnection()->table($this->getTableName())
            ->where('identifier', $identifier)->first();

        $storedContent = unserialize($stored->content);

        $currentInstance = $this->currentInstance();

        $this->instance($stored->instance);

        $content = $this->getContent();

        foreach ($storedContent as $cartItem) {
            $content->put($cartItem->rowId, $cartItem);
        }

        $this->events->fire('cart.restored');

        $this->session->put($this->instance, $content);

        $this->instance($currentInstance);

        $this->getConnection()->table($this->getTableName())
            ->where('identifier', $identifier)->delete();
    }

    /**
     * Magic method to make accessing the total, tax and subtotal properties possible.
     *
     * @param string $attribute
     * @return float|null
     */
    public function __get($attribute)
    {
        if ($attribute === 'total') {
            return $this->total();
        }

        if ($attribute === 'tax') {
            return $this->tax();
        }

        if ($attribute === 'subtotal') {
            return $this->subtotal();
        }

        return null;
    }

    /**
     * Get the carts content, if there is no cart content set yet, return a new empty Collection
     *
     * @return \Illuminate\Support\Collection
     */
    protected function getContent()
    {
        $content = $this->session->has($this->instance)
            ? $this->session->get($this->instance)
            : new Collection;

        return $content;
    }

    /**
     * Create a new CartItem from the supplied attributes.
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param int|float $qty
     * @param float     $price
     * @param array     $options
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    private function createCartItem($id, $name, $qty, $price, array $options)
    {
        if ($id instanceof Buyable) {
            $cartItem = CartItem::fromBuyable($id, $qty ?: []);
            $cartItem->setQuantity($name ?: 1);
            $cartItem->associate($id);
        } elseif (is_array($id)) {
            $cartItem = CartItem::fromArray($id);
            $cartItem->setQuantity($id['qty']);
        } else {
            $cartItem = CartItem::fromAttributes($id, $name, $price, $options);
            $cartItem->setQuantity($qty);
        }

        $cartItem->setTaxRate(config('cart.tax'));

        return $cartItem;
    }

    /**
     * Create a new ShippingItem from the supplied attributes.
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param float     $price
     * @return \Ollywarren\ShoppingCart\ShippingItem
     */
    private function createShippingItem($id, $name, $price)
    {
        if ($id instanceof Shippable) {
            $shippingItem = ShippingItem::fromShippable($id);
            $shippingItem->setQuantity($name ?: 1);
            $shippingItem->associate($id);
        } elseif (is_array($id)) {
            $shippingItem = ShippingItem::fromArray($id);
        } else {
            $shippingItem = ShippingItem::fromAttributes($id, $name, $price);
        }

        $shippingItem->setTaxRate(config('cart.tax'));

        return $shippingItem;
    }

    /**
     * Create a new DiscountItem from the supplied attributes.
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param float     $value
     * @return \Ollywarren\ShoppingCart\DiscountItem
     */
    private function createDiscountItem($id, $name, $qty, $price, $type)
    {
        if ($id instanceof Discountable) {
            $discountItem = DiscountItem::fromDiscountable($id, $qty ?: []);
            $discountItem->setQuantity($name ?: 1);
            $discountItem->associate($id);
        } elseif (is_array($id)) {
            $discountItem = DiscountItem::fromArray($id);
            $discountItem->setQuantity($id['qty']);
        } else {
            $discountItem = DiscountItem::fromAttributes($id, $name, $value, $type);
            $discountItem->setQuantity($qty);
        }
        return $discountItem;
    }

    /**
     * Detects if a coupon is already applied to the cart and returns it,
     * otherwise it returns false;
     *
     * @return mixed
     */
    public function hasDiscountItem()
    {
        // Get the cart items
        $items = $this->getContent();

        // If theres nothing in the cart retrun false
        // Otherwise filter the items for only DiscountItems
        if ($items->count() > 0) {
            $filter = $items->filter(function ($value, $key) {
                return $value instanceof DiscountItem;
            })->all();
            
            // Re-collect the output array
            $filter = collect($filter);

            // If theres discount items return them
            if ($filter->count() > 0) {
                return $filter;
            }

            //otherwise return false;
            return false;
        }

        return false;
    }

    /**
     * Updates the Cart to Apply Free Shipping.
     *
     * @return void
     */
    public function applyFreeShipping()
    {
        $items = Cart::instance('basket')->content();

        $shipping = $items->filter(function ($value, $key) {
            return $value instanceof ShippingItem;
        });

        if ($shipping->first() !== null) {
            $this->update($shipping->first()->rowID, ['price' => 0.00]);
            return true;
        }
        
        return false;
    }

    /**
     * Check if the item is a multidimensional array or an array of Buyables.
     *
     * @param mixed $item
     * @return bool
     */
    private function isMulti($item)
    {
        if (! is_array($item)) {
            return false;
        }

        return is_array(head($item)) || head($item) instanceof Buyable;
    }

    /**
     * @param $identifier
     * @return bool
     */
    private function storedCartWithIdentifierExists($identifier)
    {
        return $this->getConnection()->table($this->getTableName())->where('identifier', $identifier)->exists();
    }

    /**
     * Get the database connection.
     *
     * @return \Illuminate\Database\Connection
     */
    private function getConnection()
    {
        $connectionName = $this->getConnectionName();

        return app(DatabaseManager::class)->connection($connectionName);
    }

    /**
     * Get the database table name.
     *
     * @return string
     */
    private function getTableName()
    {
        return config('cart.database.table', 'shoppingcart');
    }

    /**
     * Get the database connection name.
     *
     * @return string
     */
    private function getConnectionName()
    {
        $connection = config('cart.database.connection');

        return is_null($connection) ? config('database.default') : $connection;
    }

    /**
     * Get the Formated number
     *
     * @param $value
     * @param $decimals
     * @param $decimalPoint
     * @param $thousandSeperator
     * @return string
     */
    private function numberFormat($value, $decimals, $decimalPoint, $thousandSeperator, $currency = null)
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

        if (is_null($currency)) {
            $currency= is_null(config('cart.format.currency')) ? '' : config('cart.format.currency');
        }
        return $currency . number_format($value, $decimals, $decimalPoint, $thousandSeperator);
    }
}
