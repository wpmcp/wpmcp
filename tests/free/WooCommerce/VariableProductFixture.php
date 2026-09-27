<?php

namespace WPMCP\Tests\Free\WooCommerce;

/**
 * Builds a variable product with two size variations through WooCommerce's
 * own CRUD, so the parent's synced price range, the children transient and
 * the meta lookup rows are all in the state a real store would have.
 */
trait VariableProductFixture
{
    /** @return array{parent:int, small:int, large:int} */
    private function variable_product(): array
    {
        $attribute = new \WC_Product_Attribute();
        $attribute->set_name('size');
        $attribute->set_options(['small', 'large']);
        $attribute->set_visible(true);
        $attribute->set_variation(true);

        $parent = new \WC_Product_Variable();
        $parent->set_name('Linen Shirt');
        $parent->set_attributes([$attribute]);
        $parent_id = $parent->save();

        $ids = ['parent' => $parent_id];
        foreach (['small' => ['10.00', 5], 'large' => ['20.00', 8]] as $size => [$price, $stock]) {
            $variation = new \WC_Product_Variation();
            $variation->set_parent_id($parent_id);
            $variation->set_attributes(['size' => $size]);
            $variation->set_regular_price($price);
            $variation->set_manage_stock(true);
            $variation->set_stock_quantity($stock);
            $ids[ $size ] = $variation->save();
        }

        \WC_Product_Variable::sync($parent_id);

        return $ids;
    }
}
