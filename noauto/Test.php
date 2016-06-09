<?php

use COREPOS\pos\lib\FormLib;

class Test extends PHPUnit_Framework_TestCase
{
    public function testPlugin()
    {
        $obj = new CoopCred();
        $obj->plugin_transaction_reset();
    }

    public function testSpecailDept()
    {
        $cpd = new CCredProgramDepartments();
    }

    public function testParsers()
    {
        $p = new CoopCredCheckQ();

        $p = new TenderKeyCoopCred();
    }

    public function testTender()
    {
        $t = new CoopCredTender();
    }

    public function testPages()
    {
        $p = new tenderlist_coopCred();
    }

    public function testModels()
    {
        $dbc = new SQLManager('host','dbms','db','user','pass');
        $m = new CCredMembershipsModel($dbc);
        $m = new CCredProgramsModel($dbc);
    }

    public function testReceiptMessages()
    {
        $m = new CCredBalanceMessage();
        $m = new CCredSigSlip();
        $m = new CCredUsedBalances();
    }
}

