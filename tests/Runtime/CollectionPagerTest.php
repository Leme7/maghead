<?php
use Maghead\Runtime\CollectionPager;

/**
 * @group collection
 */
class CollectionPagerTest extends PHPUnit\Framework\TestCase
{
    public function testCollectionPager()
    {
        $pager = new CollectionPager(range(1, 200), 1, 10);
        $items = $pager->items();
        is(1, $items[0]);
        is(2, $items[1]);

        $pager->next();
        $items = $pager->items();
        is(11, $items[0]);
        is(12, $items[1]);

        $pager->next();
        $items = $pager->items();
        is(21, $items[0]);
        is(22, $items[1]);

        $pager->previous();
        $items = $pager->items();
        is(11, $items[0]);
        is(12, $items[1]);

        is(20, $pager->pages());
    }
}
