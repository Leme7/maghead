<?php
use LazyRecord\Testing\ModelTestCase;
use AuthorBooks\Model\Address;
use AuthorBooks\Model\Author;

class AuthorAddressModelTest extends ModelTestCase
{
    public function getModels()
    {
        return array(
            new \AuthorBooks\Model\AuthorSchema,
            new \AuthorBooks\Model\AddressSchema,
        );
    }

    public function testHasManyRelationFetch()
    {
        $author = new \AuthorBooks\Model\Author;

        $ret = $author->create(array( 'name' => 'Z' , 'email' => 'z@z' , 'identity' => 'z' ));
        $this->assertResultSuccess($ret);
        $address = new Address;
        $ret = $address->create(array(
            'author_id' => $author->id,
            'address' => 'Taiwan Taipei',
        ));
        $this->assertResultSuccess($ret);

        $this->assertNotNull($address->author, 'has many relation fetch');
        $this->assertNotNull($address->author->id);
        $this->assertEquals($author->id, $address->author->id);

        $ret = $address->create(array( 
            'author_id' => $author->id,
            'address' => 'Taiwan Taipei II',
        ));
        $this->assertResultSuccess($ret);

        // xxx: provide getAddresses() method generator
        $addresses = $author->addresses;
        $this->assertCollectionSize(2, $addresses);

        $items = $addresses->items();
        $this->assertNotEmpty($items);

        ok($addresses[0]);
        ok($addresses[1]);
        ok(! isset($addresses[2]));
        ok(! @$addresses[2]);

        ok($addresses[0]->id);
        ok($addresses[1]->id);
        $this->assertCount(2 , $addresses);

        /*
        foreach($author->addresses as $ad) {
            $this->assertResultSuccess($ad->delete());
        }
        $this->assertResultSuccess($author->delete());
        */
    }


    /**
     * @basedata false
     */
    public function testHasManyRelationCreate()
    {
        $author = new Author;
        $ret = $author->create(array( 'name' => 'Z' , 'email' => 'z@z' , 'identity' => 'z' ));
        $this->assertResultSuccess($ret);
        ok( $author->id );

        $address = $author->addresses->create(array(
            'address' => 'farfaraway'
        ));

        ok($address->id);
        ok($address->author_id);
        $this->assertEquals( $author->id, $address->author_id );

        $this->assertEquals('farfaraway' , $address->address);
        $this->assertResultSuccess($address->delete());
        $this->assertResultSuccess($author->delete());
    }

    /**
     * @rebuild false
     * @basedata false
     */
    public function testHasManyRelationCreate2()
    {
        $author = new \AuthorBooks\Model\Author;
        $ret = $author->create(array( 'name' => 'Z' , 'email' => 'z@z' , 'identity' => 'z' ));
        $this->assertResultSuccess($ret);
        $this->assertResultSuccess($ret);

        // append items
        $author->addresses[] = array( 'address' => 'Harvard' );
        $author->addresses[] = array( 'address' => 'Harvard II' );

        is(2, $author->addresses->size() , 'just two item' );

        $addresses = $author->addresses->items();
        ok( $addresses );
        is( 'Harvard' , $addresses[0]->address );

        $a = $addresses[0];
        ok($retAuthor = $a->author );
        ok($retAuthor->id );
        ok($retAuthor->name );
        is('Z', $retAuthor->name);
        $this->assertResultSuccess($author->delete());
    }

    /**
     * @rebuild false
     */
    public function testGeneralInterface()
    {
        $a = new Address;
        ok($a->getQueryDriver('default'));
        ok($a->getWriteQueryDriver());
        ok($a->getReadQueryDriver());
    }
}
