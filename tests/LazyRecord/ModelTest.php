<?php
use LazyRecord\Schema\SqlBuilder;

class ModelTest extends PHPUnit_Framework_ModelTestCase
{

    public function getModels()
    {
        return array( 
            '\tests\AuthorSchema', 
            '\tests\BookSchema',
            '\tests\AuthorBookSchema',
            '\tests\NameSchema',
            '\tests\AddressSchema',
            '\tests\UserSchema',
        );
    }


    /**
     * @dataProvider booleanFalseTestDataProvider
     */
    public function testBooleanFalse($args)
    {
        $n = new \tests\Name;
        $ret = $n->create($args);
        ok( $ret->success , $ret  . " SQL: " . $ret->sql . print_r($ret->vars,1) );
        ok( $n->id );
        is( false, $n->confirmed );

        // reload
        ok( $n->load( $n->id )->success );
        is( false, $n->confirmed );
        ok( $n->delete()->success );
    }

    public function testClone()
    {
        $test1 = new \tests\Name;
        $test2 = clone $test1;
        ok( $test1 !== $test2 );
    }


    public function testSchemaInterface()
    {
        $author = new \tests\Author;

        $names = array('updated_on','created_on','id','name','email','identity','confirmed');
        foreach( $author->getColumnNames() as $n ) {
            ok( in_array( $n , $names ));
            ok( $author->getColumn( $n ) );
        }

        $columns = $author->getColumns();
        count_ok( 7 , $columns );

        $columns = $author->getColumns(true); // with virtual column 'v'
        count_ok( 8 , $columns );

        ok( 'authors' , $author->getTable() );
        ok( 'Author' , $author->getLabel() );


        isa_ok(  '\tests\AuthorCollection' , $author->newCollection() );
    }

    public function testCollection()
    {
        $author = new \tests\Author;
        $collection = $author->asCollection();
        ok($collection);
        isa_ok('\tests\AuthorCollection',$collection);
    }

    public function testGeneralInterface() 
    {
        $a = new \tests\Address;
        ok($a);

        ok( $a->getQueryDriver('default') );
        ok( $a->getWriteQueryDriver() );
        ok( $a->getReadQueryDriver() );

        $query = $a->createQuery();
        ok($query);
        isa_ok('SQLBuilder\QueryBuilder', $query );
    }

    public function testVirtualColumn() 
    {
        $author = new \tests\Author;
        $ret = $author->create(array( 
            'name' => 'Pedro' , 
            'email' => 'pedro@gmail.com' , 
            'identity' => 'id',
        ));
        ok($ret->success);

        ok( $v = $author->getColumn('v') ); // virtual colun
        ok( $v->virtual );

        $columns = $author->schema->getColumns();

        ok( ! isset($columns['v']) );

        is('pedro@gmail.compedro@gmail.com',$author->get('v'));

        ok( $display = $author->display( 'v' ) );

        $authors = new tests\AuthorCollection;
        ok( $authors );
    }

    public function testSchema()
    {
        $author = new \tests\Author;
        ok( $author->schema );

        $columnMap = $author->schema->getColumns();

        ok( isset($columnMap['confirmed']) );
        ok( isset($columnMap['identity']) );
        ok( isset($columnMap['name']) );

        ok( $author::schema_proxy_class );

        $columnMap = $author->getColumns();

        ok( isset($columnMap['identity']) );
        ok( isset($columnMap['name']) );
    }

    public function testLoadOrCreate() 
    {
        $b = new \tests\Book;
        $ret = $b->find( array( 'name' => 'LoadOrCreateTest' ) );
        result_fail( $ret );
        ok( ! $b->id );

        $ret = $b->create(array( 'title' => 'Should Not Load This' ));
        result_ok( $ret );

        $ret = $b->create(array( 'title' => 'LoadOrCreateTest' ));
        result_ok( $ret );

        $id = $b->id;
        ok($id);

        $ret = $b->loadOrCreate( array( 'title' => 'LoadOrCreateTest'  ) , 'title' );
        result_ok($ret);
        is($id, $b->id, 'is the same ID');


        $b2 = new \tests\Book;
        $ret = $b2->loadOrCreate( array( 'title' => 'LoadOrCreateTest'  ) , 'title' );
        result_ok($ret);
        is($id,$b2->id);

        $ret = $b2->loadOrCreate( array( 'title' => 'LoadOrCreateTest2'  ) , 'title' );
        result_ok($ret);
        ok($b2);
        ok($id != $b2->id , 'we should create anther one'); 

        $b3 = new \tests\Book;
        $ret = $b3->loadOrCreate( array( 'title' => 'LoadOrCreateTest3'  ) , 'title' );
        result_ok($ret);
        ok($b3);
        ok($id != $b3->id , 'we should create anther one'); 

        $b3->delete();

        foreach( $b2->flushResults() as $r ) {
            result_ok( \tests\Book::delete($r->id)->execute() );
        }
        foreach( $b->flushResults() as $r ) {
            result_ok( \tests\Book::delete($r->id)->execute() );
        }
    }

    /**
     * Basic CRUD Test 
     */
    public function testModel()
    {
        $author = new \tests\Author;
        ok($author);

        $a2 = new \tests\Author;
        $ret = $a2->find( array( 'name' => 'A record does not exist.' ) );
        ok( ! $ret->success );
        ok( ! $a2->id );

        $ret = $a2->create(array( 'name' => 'long string \'` long string' , 'email' => 'email' , 'identity' => 'id' ));
        ok( $ret->success );
        ok( $a2->id );

        $ret = $a2->create(array( 'xxx' => true, 'name' => 'long string \'` long string' , 'email' => 'email2' , 'identity' => 'id2' ));
        ok( $ret->success );
        ok( $a2->id );

        $ret = $author->create(array());
        ok( $ret );
        ok( ! $ret->success );
        ok( $ret->message );
        is( 'Empty arguments' , $ret->message );

        $ret = $author->create(array( 'name' => 'Foo' , 'email' => 'foo@google.com' , 'identity' => 'foo' ));
        ok( $ret );
        ok( $id = $ret->id );
        ok( $ret->success );
        is( 'Foo', $author->name );
        is( 'foo@google.com', $author->email );

        $ret = $author->load( $id );
        ok( $ret->success );
        is( $id , $author->id );
        is( 'Foo', $author->name );
        is( 'foo@google.com', $author->email );
        is( false , $author->confirmed );

        $ret = $author->find(array( 'name' => 'Foo' ));
        ok( $ret->success );
        is( $id , $author->id );
        is( 'Foo', $author->name );
        is( 'foo@google.com', $author->email );
        is( false , $author->confirmed );

        $ret = $author->update(array( 'name' => 'Bar' ));
        ok( $ret->success );

        is( 'Bar', $author->name );

        $ret = $author->delete();
        ok( $ret->success );

        $data = $author->toArray();
        ok( empty($data), 'should be empty');
    }


    public function testFilter()
    {
        $name = new \tests\Name;
        $ret = $name->create(array(  'name' => 'Foo' , 'country' => 'Taiwan' , 'address' => 'John' ));
        result_ok($ret);
        is( 'XXXX' , $name->address , 'Be canonicalized' );
    }

    public function testBooleanFromStringZero()
    {
        $n = new \tests\Name;

        /** confirmed will be cast to true **/
        $ret = $n->create(array( 'name' => 'Foo' , 'country' => 'Tokyo', 'confirmed' => '0' ));
        result_ok( $ret );
        ok( $n->id );
        is( false, $n->confirmed );
        ok( $n->delete()->success );
    }




    public function booleanTrueTestDataProvider()
    {
        return array(
            array( array( 'name' => 'Foo' , 'country' => 'Tokyo', 'confirmed' => 1 ) ),
            array( array( 'name' => 'Foo' , 'country' => 'Tokyo', 'confirmed' => '1' ) ),
            array( array( 'name' => 'Foo' , 'country' => 'Tokyo', 'confirmed' => true ) ),
            array( array( 'name' => 'Foo' , 'country' => 'Tokyo', 'confirmed' => 'true' ) ),
            array( array( 'name' => 'Foo' , 'country' => 'Tokyo', 'confirmed' => ' ' ) ),  // space string (true)
        );
    }

    public function booleanFalseTestDataProvider()
    {
        return array(
#              array( array( 'name' => 'Foo' , 'country' => 'Tokyo', 'confirmed' => 0 ) ),
#              array( array( 'name' => 'Foo' , 'country' => 'Tokyo', 'confirmed' => '0' ) ),
#              array( array( 'name' => 'Foo' , 'country' => 'Tokyo', 'confirmed' => false ) ),
#              array( array( 'name' => 'Foo' , 'country' => 'Tokyo', 'confirmed' => 'false' ) ),
            array( array( 'name' => 'Foo' , 'country' => 'Tokyo', 'confirmed' => '' ) ),  // empty string should be (false)
            // array( array( 'name' => 'Foo' , 'country' => 'Tokyo', 'confirmed' => 'aa' ) ),
            // array( array( 'name' => 'Foo' , 'country' => 'Tokyo', 'confirmed' => 'bb' ) ),
        );
    }


    /**
     * @dataProvider booleanTrueTestDataProvider
     */
    public function testBooleanTrue($args)
    {
        $n = new \tests\Name;
        $ret = $n->create($args);
        result_ok($ret);
        ok( $n->id );
        is( true, $n->confirmed, 'Confirmed value should be TRUE.' );
        // reload
        ok( $n->load( $n->id )->success );
        is( true, $n->confirmed , 'Confirmed value should be TRUE.' );
        ok( $n->delete()->success );
    }



    public function testValueTypeConstraint()
    {
        // if it's a str type , we should not accept types not str.
        $n = new \tests\Name;
        /**
         * name column is required, after type casting, it's NULL, so
         * create should fail.
         */
        $ret = $n->create(array( 'name' => false , 'country' => 'Tokyo' ));
        ok( ! $ret->success );
        ok( ! $n->id );
    }

    public function testDefaultBuilder()
    {
        $name = new \tests\Name;
        $ret = $name->create(array(  'name' => 'Foo' , 'country' => 'Taiwan' ));

        result_ok( $ret );
        ok( $ret->validations );

        ok( $ret->validations['address'] );
        ok( $ret->validations['address']->success );

        ok( $vlds = $ret->getSuccessValidations() );
        count_ok( 1, $vlds );

        ok( $name->id );
        ok( $name->address );

        $ret = $name->create(array(  'name' => 'Foo', 'address' => 'fuck' , 'country' => 'Tokyo' ));
        ok( $ret->validations );

        foreach( $ret->getErrorValidations() as $vld ) {
            is( false , $vld->success );
            is( 'Please don\'t',  $vld->message );
        }
    }


    public function testRefer()
    {
        $user = new \tests\User;
        ok( $user );
        $ret = $user->create(array( 'account' => 'c9s' ));
        result_ok($ret);
        ok( $user->id );

        $book = new \tests\Book;
        $ret = $book->create(array( 
            'title' => 'Programming Perl',
            'subtitle' => 'Way Way to Roman',
            'publisher_id' => '""',  /* cast this to null or empty */
            'created_by' => $user->id,
        ));
        ok( $ret );

        // XXX: broken
#          ok( $book->created_by );
#          is( $user->id, $book->created_by->id );
#          ok( $user->id , $book->getValue('created_by') );
    }

    public function testTypeConstraint()
    {
        $book = new \tests\Book;
        $ret = $book->create(array( 
            'title' => 'Programming Perl',
            'subtitle' => 'Way Way to Roman',
            'publisher_id' => '""',  /* cast this to null or empty */
            // 'publisher_id' => NULL,  /* cast this to null or empty */
        ));


        // FIXME: in sqlite, it works, in pgsql, can not be cast to null
        // ok( $ret->success );
#          print_r($ret->sql);
#          print_r($ret->vars);
#          echo $ret->exception;
    }

    public function testUpdateRaw() 
    {
        $author = new \tests\Author;
        $ret = $author->create(array( 
            'name' => 'Mary III',
            'email' => 'zz3@zz3',
            'identity' => 'zz3',
        ));
        result_ok($ret);
        $ret = $author->update(array( 'id' => array('id + 3') ));
        result_ok($ret);
    }

    public function testUpdateNull()
    {
        $author = new \tests\Author;
        $ret = $author->create(array( 
            'name' => 'Mary III',
            'email' => 'zz3@zz3',
            'identity' => 'zz3',
        ));
        result_ok($ret);

        $id = $author->id;

        ok( $author->update(array( 'name' => 'I' ))->success );
        is( $id , $author->id );
        is( 'I', $author->name );

        ok( $author->update(array( 'name' => null ))->success );
        is( $id , $author->id );
        is( null, $author->name );

        ok( $author->load( $author->id )->success );
        is( $id , $author->id );
        is( null, $author->name );
    }

    public function testJoin()
    {
        $author = new \tests\Author;
        $author->create(array( 
            'name' => 'Mary III',
            'email' => 'zz3@zz3',
            'identity' => 'zz3',
        ));

        $ab = new \tests\AuthorBook;
        $book = new \tests\Book;

        ok( $book->create(array( 'title' => 'Book I' ))->success );
        ok( $ab->create(array( 
            'author_id' => $author->id,
            'book_id' => $book->id,
        ))->success );

        ok( $book->create(array( 'title' => 'Book II' ))->success );
        $ab->create(array( 
            'author_id' => $author->id,
            'book_id' => $book->id,
        ));

        ok( $book->create(array( 'title' => 'Book III' ))->success );
        $ab->create(array( 
            'author_id' => $author->id,
            'book_id' => $book->id,
        ));

        $books = new \tests\BookCollection;
        $books->join('author_books')
            ->alias('ab')
            ->on()
            ->equal( 'ab.book_id' , array('m.id') );
        $books->where()
            ->equal( 'ab.author_id' , $author->id );
        $items = $books->items();

        $bookTitles = array();
        foreach( $items as $item ) {
            $bookTitles[ $item->title ] = true;
            $item->delete();
        }

        count_ok( 3, array_keys($bookTitles) );
        ok( $bookTitles[ 'Book I' ] );
        ok( $bookTitles[ 'Book II' ] );
        ok( $bookTitles[ 'Book III' ] );
    }


    public function testManyToManyRelationCreate()
    {
        $author = new \tests\Author;
        $author->create(array( 'name' => 'Z' , 'email' => 'z@z' , 'identity' => 'z' ));
        ok( 
            $book = $author->books->create( array( 
                'title' => 'Programming Perl I',
                ':author_books' => array( 'created_on' => '2010-01-01' ),
            ))
        );
        ok( $book->id );
        is( 'Programming Perl I' , $book->title );

        is( 1, $author->books->size() );
        is( 1, $author->author_books->size() );
        ok( $author->author_books[0] );
        ok( $author->author_books[0]->created_on );
        is( '2010-01-01', $author->author_books[0]->created_on->format('Y-m-d') );

        $author->books[] = array( 
            'title' => 'Programming Perl II',
        );
        is( 2, $author->books->size() , '2 books' );

        $books = $author->books;
        is( 2, $books->size() , '2 books' );

        foreach( $books as $book ) {
            ok( $book->id );
            ok( $book->title );
        }

        foreach( $author->books as $book ) {
            ok( $book->id );
            ok( $book->title );
        }

        $books = $author->books;
        is( 2, $books->size() , '2 books' );
        $author->delete();
    }

    public function testLoadFromContstructor()
    {
        $name = new \tests\Name;
        $name->create(array( 
            'name' => 'John',
            'country' => 'Taiwan',
            'type' => 'type-a',
        ));
        ok( $name->id );
        $name2 = new \tests\Name( $name->id );
        is( $name2->id , $name->id );
    }


    public function testValidValueBuilder()
    {
        $name = new \tests\Name;
        $ret = $name->create(array( 
            'name' => 'John',
            'country' => 'Taiwan',
            'type' => 'type-a',
        ));
        ok( $ret->success );
        is( 'Type Name A', $name->display( 'type' ) );

        $xml = $name->toXml();
        ok( $xml );

        $dom = new DOMDocument;
        $dom->loadXml( $xml );

        $yaml = $name->toYaml();
        ok( $yaml );

        yaml_parse($yaml);

        $json = $name->toJson();
        ok( $json );

        json_decode( $json );

        ok( $name->delete()->success );
    }


    public function testManyToManyRelationFetch()
    {
        $author = new \tests\Author;
        $author->create(array( 'name' => 'Z' , 'email' => 'z@z' , 'identity' => 'z' ));

        // XXX: in different database engine, it's different.
        // sometimes it's string, sometimes it's integer
        // ok( is_string( $author->getValue('id') ) );
        ok( is_integer( $author->get('id') ) );

        $book = $author->books->create(array( 'title' => 'Book Test' ));
        ok( $book );
        ok( $book->id , 'book is created' );

        $ret = $book->delete();
        ok( $ret->success );

        $ab = new \tests\AuthorBook;
        $book = new \tests\Book;

        // should not include this
        ok( $book->create(array( 'title' => 'Book I Ex' ))->success );

        ok( $book->create(array( 'title' => 'Book I' ))->success );
        ok( $ab->create(array( 
            'author_id' => $author->id,
            'book_id' => $book->id,
        ))->success );

        ok( $book->create(array( 'title' => 'Book II' ))->success );
        $ab->create(array( 
            'author_id' => $author->id,
            'book_id' => $book->id,
        ));

        ok( $book->create(array( 'title' => 'Book III' ))->success );
        $ab->create(array( 
            'author_id' => $author->id,
            'book_id' => $book->id,
        ));

        // retrieve books from relationshipt
        $author->flushCache();
        $books = $author->books;
        is( 3, $books->size() , 'We have 3 books' );


        $bookTitles = array();
        foreach( $books->items() as $item ) {
            $bookTitles[ $item->title ] = true;
            $item->delete();
        }

        count_ok( 3, array_keys($bookTitles) );
        ok( $bookTitles[ 'Book I' ] );
        ok( $bookTitles[ 'Book II' ] );
        ok( $bookTitles[ 'Book III' ] );
        ok( ! isset($bookTitles[ 'Book I Ex' ] ) );

        $author->delete();
    }

    public function testHasManyRelationCreate2()
    {
        $author = new \tests\Author;
        $author->create(array( 'name' => 'Z' , 'email' => 'z@z' , 'identity' => 'z' ));
        ok( $author->id );

        // append items
        $author->addresses[] = array( 'address' => 'Harvard' );
        $author->addresses[] = array( 'address' => 'Harvard II' );

        is(2, $author->addresses->size() , 'just two item' );

        $addresses = $author->addresses->items();
        ok( $addresses );
        is( 'Harvard' , $addresses[0]->address );

        $a = $addresses[0];
        ok( $retAuthor = $a->author );
        ok( $retAuthor->id );
        ok( $retAuthor->name );
        is( 'Z', $retAuthor->name );

        $author->delete();
    }

    public function testHasManyRelationCreate()
    {
        $author = new \tests\Author;
        $author->create(array( 'name' => 'Z' , 'email' => 'z@z' , 'identity' => 'z' ));
        ok( $author->id );

        $address = $author->addresses->create(array( 
            'address' => 'farfaraway'
        ));

        ok( $address->id );
        ok( $address->author_id );
        is( $author->id, $address->author_id );

        is( 'farfaraway' , $address->address );

        $address->delete();
        $author->delete();

    }

    public function testHasManyRelationFetch()
    {
        $author = new \tests\Author;
        ok( $author );

        $author->create(array( 'name' => 'Z' , 'email' => 'z@z' , 'identity' => 'z' ));
        ok( $author->id );

        $address = new \tests\Address;
        ok( $address );

        $address->create(array( 
            'author_id' => $author->id,
            'address' => 'Taiwan Taipei',
        ));
        ok( $address->author );
        ok( $address->author->id );
        is( $author->id, $address->author->id );

        $address->create(array( 
            'author_id' => $author->id,
            'address' => 'Taiwan Taipei II',
        ));

        // xxx: provide getAddresses() method generator
        $addresses = $author->addresses;
        ok( $addresses );

        $items = $addresses->items();
        ok( $items );

        ok( $addresses[0] );
        ok( $addresses[1] );
        ok( ! isset($addresses[2]) );
        ok( ! @$addresses[2] );

        ok( $addresses[0]->id );
        ok( $addresses[1]->id );

        ok( $size = $addresses->size() );
        is( 2 , $size );

        foreach( $author->addresses as $ad ) {
            ok( $ad->delete()->success );
        }

        $author->delete();
    }


    public function testDeflator()
    {
        $n = new \tests\Name;
        $ret = $n->create(array( 
            'name' => 'Deflator Test' , 
            'country' => 'Tokyo', 
            'confirmed' => '0',
            'date' => '2011-01-01'
        ));
        $d = $n->date;
        ok( $d );
        isa_ok( 'DateTime' , $d );
        is( '20110101' , $d->format( 'Ymd' ) );
        ok( $n->delete()->success );
    }


    public function nameDataProvider()
    {
        return array( 
            array(array(
                'name' => '中文',
                'country' => 'Tokyo',
                'confirmed' => true,
                'date' => new DateTime('2011-01-01 00:00:00'),
            )),
            array(array(
                'name' => 'Test2',
                'country' => 'Taipei',
                'confirmed' => false,
                'date' => '2011-01-01 00:00:00',
            )),
        );
    }

    /**
     * @dataProvider nameDataProvider
     */
    public function testCreateName($args)
    {
        $name = new \tests\Name;
        $ret = $name->create($args);
        ok( $ret->success );
        $ret = $name->delete();
        ok( $ret->success );
    }

    /**
     * @dataProvider nameDataProvider
     */
    public function testFromArray($args)
    {
        $instance = \tests\Name::fromArray(array( 
            $args
        ));
        ok( $instance );
        isa_ok( 'tests\Name' ,  $instance );

        $collection = \tests\NameCollection::fromArray(array( 
            $args,
            $args,
        ));
        isa_ok( 'tests\NameCollection' , $collection );
    }



    public function testRawSQL()
    {
        $n = new \tests\Book;
        $n->create(array(
            'title' => 'book title',
            'view' => 0,
        ));
        is( 0 , $n->view );

        $ret = $n->update(array( 
            'view' => array('view + 1')
        ));

        ok( $ret->success );
        is( 1 , $n->view );

        $n->update(array( 
            'view' => array('view + 3')
        ));
        $ret = $n->reload();
        ok( $ret->success );
        is( 4, $n->view );
    }



    public function testDateTimeInflator()
    {
        $n = new \tests\Name;
        $date = new DateTime('2011-01-01 00:00:00');
        $ret = $n->create(array( 
            'name' => 'Deflator Test' , 
            'country' => 'Tokyo', 
            'confirmed' => false,
            'date' => $date,
        ));
        ok( $ret->success , $ret );

        $array = $n->toArray();
        ok( is_string( $array['date'] ) );

        $d = $n->date; // inflated
        isa_ok( 'DateTime' , $d );
        is( '20110101' , $d->format( 'Ymd' ) );
        ok( $n->delete()->success );
    }

    public function testZeroInflator()
    {
        $b = new \tests\Book;
        $ret = $b->create(array( 'title' => 'Create X' , 'view' => 0 ));
        result_ok($ret);
        ok( $b->id );
        is( 0 , $b->view );

        $ret = $b->load($ret->id);
        result_ok($ret);
        ok( $b->id );
        is( 0 , $b->view );

        // test incremental
        $ret = $b->update(array( 'view'  => array('"view" + 1') ), array('reload' => true));
        result_ok($ret);
        is( 1,  $b->view );

        $ret = $b->update(array( 'view'  => array('"view" + 1') ), array('reload' => true));
        result_ok($ret);
        is( 2,  $b->view );

        $ret = $b->delete();
        result_ok($ret);
    }

    public function testStaticFunctions() 
    {
        $record = \tests\Author::create(array( 
            'name' => 'Mary',
            'email' => 'zz@zz',
            'identity' => 'zz',
        ));
        ok( $record->popResult()->success );

        $record = \tests\Author::load( (int) $record->popResult()->id );
        ok( $record );
        ok( $id = $record->id );

        $record = \tests\Author::load( array( 'id' => $id ));
        ok( $record );
        ok( $record->id );

        /**
         * Which runs:
         *    UPDATE authors SET name = 'Rename' WHERE name = 'Mary'
         */
        $ret = \tests\Author::update(array( 'name' => 'Rename' ))
            ->where()
            ->equal('name','Mary')
            ->execute();
        ok( $ret->success );


        $ret = \tests\Author::delete()
            ->where()
            ->equal('name','Rename')
            ->execute();
        ok( $ret->success );
    }



    public function testCreateSpeed()
    {
        // FIXME: On build machine,  we got 21185.088157654, that's really slow, fix later.
        return;

        $s = microtime(true);
        $n = new \tests\Name;
        $ids = array();
        $cnt = 10;
        foreach( range(1,$cnt) as $i ) {
            // you can use _create to gain 120ms faster
            $ret = $n->create(array(
                'name' => "Deflator Test $i", 
                'country' => 'Tokyo', 
                'confirmed' => true,
                'date' => new DateTime('2011-01-01 00:00:00'),
            ));
            $ids[] = $n->id;
        }

        $duration = (microtime(true) - $s) / $cnt * 1000000; // get average microtime.

        // $limit = 1400; before commit: e9c891ee3640f58871eb676df5f8f54756b14354
        $limit = 3500;
        if( $duration > $limit ) {
            ok( false , "performance test: should be less than $limit ms, got $duration ms." );
        }

        foreach( $ids as $id ) {
            \tests\Name::delete($id);
        }
    }
}

