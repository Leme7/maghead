<?php
namespace tests;



class NameCollectionBase 
extends \Lazy\BaseCollection
{

            const schema_proxy_class = '\\tests\\NameSchemaProxy';
        const model_class = '\\tests\\Name';
        const table = 'names';
        
}
