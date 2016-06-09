<?php

namespace COREPOS\pos\lib\models;

class BasicModel
{
    public function __construct($dbc)
    {
        $this->connection = $dbc;
    }
}

