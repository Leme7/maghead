<?php
namespace AuthorBooks\Tests;

use SQLBuilder\Raw;
use Maghead\Testing\ModelProfileTestCase;
use AuthorBooks\Model\Book;
use AuthorBooks\Model\BookSchema;
use AuthorBooks\Model\Tag;
use AuthorBooks\Model\TagSchema;
use DateTime;
use XHProfRuns_Default;

/**
 * @group profile
 * @group app
 */
class TagModelProfileTest extends ModelProfileTestCase
{
    public function models()
    {
        return [new TagSchema];
    }

    /**
     * @group profile
     * @rebuild true
     */
    public function testProfileCodeGenOverrideCreate()
    {
        $repo = Tag::masterRepo();
        for ($i = 0 ; $i < $this->N; $i++) {
            $repo->create(array('title' => uniqid()));
        }
    }
}
