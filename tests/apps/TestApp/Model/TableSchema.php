<?php
namespace TestApp\Model;

use Maghead\Schema\DeclareSchema;

class TableSchema extends DeclareSchema
{
    public function schema()
    {
        $this->column('id')
            ->integer()
            ->primary()
            ->autoIncrement();

        $this->column('title')
            ->varchar(512)
            ;

        $this->column('columns')
            ->json();
        ;

        $this->column('rows')
            ->text()
            ->inflator(function ($value) {
                return json_decode($value);
            })
            ->deflator(function ($value) {
                return json_encode($value);
            })
            ;
    }
}
