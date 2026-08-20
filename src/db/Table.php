<?php

namespace justinholtweb\vismaz\db;

/**
 * Vismaz's database tables.
 */
abstract class Table
{
    public const DOCUMENTS = '{{%vismaz_documents}}';
    public const DOCUMENTORDERS = '{{%vismaz_documentorders}}';
    public const ENTITIES = '{{%vismaz_entities}}';
    public const TOKENS = '{{%vismaz_tokens}}';
    public const LOG = '{{%vismaz_log}}';
}
