<?php

use Maghead\Testing\ModelTestCase;
use AuthorBooks\Model\Author;
use AuthorBooks\Model\AuthorSchema;
use AuthorBooks\Model\Address;
use AuthorBooks\Model\AddressSchema;

/**
 * @group app
 */
class AuthorAddressTest extends ModelTestCase
{
    public function models()
    {
        return array(
            new AuthorSchema,
            new AddressSchema,
        );
    }


    public function testIdentityMinStringLength3()
    {
        $ret = Author::create(['name' => 'Yo-An Lin' , 'email' => 'c9s@gmail.com' , 'identity' => 'c' ]);
        $vld = $ret->getColumnValidation('identity');
        $this->assertFalse($vld['valid']);
        // FIXME check the message
        // $this->assertStringMatchesFormat('',$vld['message']);
    }


    /**
     * @basedata false
     */
    public function testHasManyFetch()
    {
        $author = Author::createAndLoad(['name' => 'Z' , 'email' => 'z@z' , 'identity' => 'zzz' ]);
        $this->assertNotFalse($author);
        for ($i = 0 ; $i < 10 ; $i++) {
            $address = Address::createAndLoad([
                'author_id' => $author->id,
                'address' => array_rand(['Taiwan', 'Taipei']),
            ]);
            $this->assertNotFalse($address);
        }
        $addresses = $author->fetchAddresses();
        $this->assertCount(10, $addresses);
    }

    /**
     * @basedata false
     */
    public function testHasManyCollectionAccessor()
    {
        $author = Author::createAndLoad(['name' => 'Z' , 'email' => 'z@z' , 'identity' => 'zae' ]);
        $this->assertNotFalse($author);
        for ($i = 0 ; $i < 10 ; $i++) {
            $address = Address::createAndLoad([
                'author_id' => $author->id,
                'address' => array_rand(['Taiwan', 'Taipei']),
            ]);
            $this->assertNotFalse($address);
        }
        $addresses = $author->getAddresses();
        $this->assertInstanceOf('Maghead\Runtime\BaseCollection', $addresses);
        $this->assertCount(10, $addresses);
    }

    /**
     * @basedata false
     */
    public function testBelongsToFetch()
    {
        $author = Author::createAndLoad(array( 'name' => 'Z' , 'email' => 'z@z' , 'identity' => 'fetch' ));
        $this->assertNotFalse($author);

        $address = Address::createAndLoad(array(
            'author_id' => $author->id,
            'address' => 'Taiwan Taipei',
        ));
        $this->assertNotFalse($address);

        $author = $address->fetchAuthor();
        $this->assertNotFalse($author);
    }


    /**
     * @basedata false
     */
    public function testHasManyRelationFetch()
    {
        $author = Author::createAndLoad(array( 'name' => 'Z' , 'email' => 'z@z' , 'identity' => 'zzz' ));
        $this->assertNotFalse($author);

        $address = Address::createAndLoad(array(
            'author_id' => $author->id,
            'address' => 'Taiwan Taipei',
        ));
        $this->assertNotFalse($address);

        $this->assertNotNull($address->author_id);
        $this->assertNotNull($address->author, 'has many relation fetch');
        $this->assertNotNull($address->author->getId());
        $this->assertEquals($author->id, $address->author->id);

        $address = Address::createAndLoad(array(
            'author_id' => $author->id,
            'address' => 'Taiwan Taipei II',
        ));
        $this->assertNotFalse($address);


        // xxx: provide getAddresses() method generator
        $addresses = $author->addresses;
        $this->assertCollectionSize(2, $addresses);

        $items = $addresses->items();
        $this->assertNotEmpty($items);

        $this->assertNotNull($addresses[0]);
        $this->assertNotNull($addresses[1]);
        $this->assertFalse(isset($addresses[2]));

        $this->assertNotNull($addresses[0]->id);
        $this->assertNotNull($addresses[1]->id);
        $this->assertCount(2, $addresses);
    }


    /**
     * @basedata false
     */
    public function testHasManyRelationCreate()
    {
        $author = Author::createAndLoad(array( 'name' => 'Z' , 'email' => 'z@z' , 'identity' => 'zzz' ));
        $this->assertNotFalse($author);
        $this->assertNotNull($author->id);

        $address = $author->addresses->create(array(
            'address' => 'farfaraway'
        ));

        $this->assertNotNull($address->id);
        $this->assertNotNull($address->author_id);
        $this->assertEquals($author->id, $address->author_id);

        $this->assertEquals('farfaraway', $address->address);
        $this->assertResultSuccess($address->delete());
        $this->assertResultSuccess($author->delete());
    }

    /**
     * @rebuild false
     * @basedata false
     */
    public function testHasManyDynamicAccessorWithCreate()
    {
        $author = Author::createAndLoad(array( 'name' => 'Z' , 'email' => 'z@z' , 'identity' => 'zaa' ));
        $this->assertNotFalse($author);

        // append items
        $author->addresses->createAndAppend(['address' => 'Harvard']);
        $author->addresses->createAndAppend(['address' => 'Harvard II']);

        $this->assertEquals(2, $author->addresses->size(), 'just two item');

        $addresses = $author->addresses->items();
        $this->assertCount(2, $addresses);
        $this->assertEquals('Harvard', $addresses[0]->address);

        $a = $addresses[0];
        $this->assertNotNull($retAuthor = $a->author); // dynamic model getter
        $this->assertNotNull($retAuthor->id);
        $this->assertNotNull($retAuthor->name);
        $this->assertEquals('Z', $retAuthor->name);
        $this->assertResultSuccess($author->delete());
    }
}
