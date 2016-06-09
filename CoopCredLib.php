<?php
/*******************************************************************************

    Copyright 2012 Whole Foods Co-op
    Copyright 2014 West End Food Co-op, Toronto

    This file is part of IT CORE.

    IT CORE is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    IT CORE is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    in the file license.txt along with IT CORE; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*********************************************************************************/

/**
  @class CoopCredLib
  Functions for the Coop Cred plugin.
*/
class CoopCredLib extends LibraryClass {

static private $SQL_CONNECTION = null;

    static public function cclibtest ()
    {
        return 'OK';
    }

    /**
      Whether OK to use Coop Cred. Assign balance and availBal.
      @return 1 if OK, 0 if not.
      Never used, may never be and is therefore deprecated.
      Originally intended for making usage more like other tenders.
       At this point is not program-specific so hard to see its use.
      The assignments are in getCCredSubtotals().
      $tender isn't needed.
     */
    static public function chargeOK($tender='') 
    {
        global $CORE_LOCAL;

        $availBal = $this->availCreditBalance + CoreLocal::get("memChargeTotal");

        CoreLocal::set("balance",$this->creditBalance);

        CoreLocal::set("availBal",number_format($availBal,2,'.',''));    

        $chargeOk = 1;
        /* This check has already been done.
        if ($num_rows == 0 || !$row["ChargeOk"]) {
            $chargeOk = 0;
        } elseif ( $row["ChargeOk"] == 0 ) {
            $chargeOk = 0;    
        }
         */

        return $chargeOk;

    // chargeOK()
    }

    /**
      OK to use the tender or make the input?
      @return True if all clear to charge or input to the program
                    message if not
      @param $pKey - a tenderType or paymentDepartment

      Note: Transfer-to-another member part is not done.
     *
     * Knowing tenderType or paymentDepartment and memberID
     * Step 1:
     * - Find the program
     * Step 2:
     * - Is the program active?
     * Step 3:
     * - Is the member in it? CCredMemberships record exists.
     * - Is the membership in the program active?
     * x Is there enough to cover the amount?

     */
    static public function programOK($pKey='', $conn='') 
    {
        global $CORE_LOCAL;

        if (!CoreLocal::get("memberID")) {
            return _("Please enter the Member ID");
        }

        if ($conn == '') {
            $conn = self::ccDataConnect();
            if (!is_object($conn)){
                return _("Coop Cred database connection failed:") . " {$conn}";
            }
        }
        $pKeyType = (preg_match('/^\d+$/',$pKey)) ? 'Department' : 'Tender';

        $ccpModel = new CCredProgramsModel($conn);
        if ($pKeyType == 'Department') {
            $ccpModel->paymentDepartment($pKey);
        } else {
            $ccpModel->tenderType($pKey);
        }
        $pCount = 0;
        foreach($ccpModel->find() as $pgm) {
            $pCount++;
            //$limit = $cdModel->ChargeLimit();
            //$prog['inputOK'] = $pgm->inputOK();
        }
        if ($pCount == 0) {
            return _("Error:") ." {$pKeyType}" . " '{$pKey}' " .
                _("is not used in any Coop Cred Program.");
        }
        if ($pCount > 1) {
            return _("Error:") ." {$pKeyType}" . " '{$pKey}' " .
                _("is used in more than one Coop Cred Program.");
        }


        $today = date('Y-m-d');

        if ($today < $pgm->startDate()) {
            return $pgm->programName() . _(" hasn't started yet.");
        }
        if ($today < $pgm->endDate() != '' && $today > $pgm->endDate()) {
            return $pgm->programName() . _(" is no longer operating.");
        }
        if (!$pgm->active()) {
            return $pgm->programName() . _(" is not active.");
        }
        if (!$pgm->creditOK()) {
            return $pgm->programName() . _(" is not accepting purchases at this time.");
        }

        /* These are only valid globally for calculations involving the
         * current tenderType or paymentDepartment.
         */
        CoreLocal::set("CCredProgramID",$pgm->programID());
        $programCode = "CCred{$pgm->programID()}";
        CoreLocal::set("CCredProgramCode",$programCode);
        /* Reset these in CoopCred::plugin_transaction_reset()
         */
        CoreLocal::set("{$programCode}programID",$pgm->programID());
        CoreLocal::set("{$programCode}programName",$pgm->programName());
        CoreLocal::set("{$programCode}paymentDepartment",$pgm->paymentDepartment());
        CoreLocal::set("{$programCode}paymentName",$pgm->paymentName());
        CoreLocal::set("{$programCode}paymentKeyCap",$pgm->paymentKeyCap());
        CoreLocal::set("{$programCode}tenderType",$pgm->tenderType());
        CoreLocal::set("{$programCode}tenderName",$pgm->tenderName());
        CoreLocal::set("{$programCode}tenderKeyCap",$pgm->tenderKeyCap());

        /* Membership info.
         * (At the moment members's name is not used, so a model query could be used.)
         */
        $query = "SELECT m.creditBalance, m.creditLimit, m.creditOK, m.inputOK,
                m.transferOK, m.isBank
                ,(m.creditLimit - m.creditBalance) as availCreditBalance
                ,c.FirstName, c.LastName
            FROM CCredMemberships m
            JOIN {CoreLocal::get('pDatabase')}.custdata c
                ON m.cardNo = c.CardNo
            WHERE m.cardNo = ? AND m.programID = ? AND c.personNum=1";
        $statement = $conn->prepare($query);
            if ($statement === False) {
                return "Error: prepare() failed for query: $query";
            }
        $args=array(
            (int)CoreLocal::get("memberID"),
            (int)$pgm->programID()
            );
        $result = $conn->execute($statement,$args);
            if ($result === False) {
                return "Error: execute() failed for query: $query args:" .
                    implode('|',$args);
            }

        $num_rows = $conn->num_rows($result);
        if ($num_rows == 0) {
            return _("Member ") .
                CoreLocal::get("memberID") .
                _(" is not registered for ") .
                CoreLocal::get("{$programCode}programName") .
                ".";
        }

        $mem = $conn->fetchRow($result);

        /* Suspended or not activated for either purchasing or input.
         */
        if (!$mem['creditOK']) {
            return _("Member #") .
                CoreLocal::get("memberID") .
                _(" is registered for ") .
                '<b>'.  CoreLocal::get("{$programCode}programName"). '</b>' .
                _(" but may not use it") .
                _(" at this time") . ".";
        }
        /* May not put money into the program.
         */
        if ($pKeyType == "Department" && !$mem['inputOK']) {
            return _("Member #") .
                CoreLocal::get("memberID") .
                _(" may not pay into ") .
                CoreLocal::get("{$programCode}programName") .
                ".";
        }

        /* May not transfer to another member.
         * I can't think how this could be done at cash without a special popup.
         * Transfer among one's own accounts: input to one, tender from another.
         *  Test would be at point of tender and need a scan of localtemptrans
         *  for CoopCredDepartments, $CoopCredDepartmentsUsed.
         *   "Payments" better, "Inputs" even better.
         *  AND trans_status in ('','0') - not cancel, void, refund - what would that be?
         */
        $isTransfer = 0;
        if (!$mem['transferOK'] && $isTransfer) {
            return _("Member #") .
                CoreLocal::get("memberID") .
                _(" may not transfer Coop Cred to another Member") .
                ".";
        }

        CoreLocal::set("{$programCode}availCreditBalance",$mem['availCreditBalance']);
        CoreLocal::set("{$programCode}creditBalance",$mem['creditBalance']);

        return True;

    // programOK()
    }

    /**
      Calculate Coop Cred-related subtotals for the named tender
        in the current transaction.
      @param $tender
      @param $programCode
      @param $table, default to localtemptrans for during-transaction,
             but localtranstoday for end of transaction.
      @param $ref emp-lane-trans, if needed for end of transaction
      @return True or error message string.
    */
    static public function getCCredSubtotals($tender='', $conn,
        $programCode='', $table='', $ref='') 
    {
        global $CORE_LOCAL;

        $pc = ($programCode != '') ? $programCode : CoreLocal::get("CCredProgramCode");
        //$pc = ($programCode != '') ? $programCode : CoreLocal::get("programCode");

        if ($table == '') {
            $table = 'localtemptrans';
            $refSQL = '';
        } else {
           if ($ref == '') {
               $ref = ReceiptLib::mostRecentReceipt();
               if ($ref === false) {
                   return "Cannot find most recent receipt";
               }
           }
           $refs = explode('-',$ref);
           $refSQL =
               ' AND emp_no =' .  $refs[0] .
               ' AND register_no =' .  $refs[1] .
               ' AND trans_no =' .  $refs[2];
        }

        $subsQ = "SELECT
            SUM(CASE
                WHEN trans_subtype = '{$tender}' 
                THEN total
                ELSE 0 END) AS chargeTotal,
            SUM(CASE
            WHEN department=" . CoreLocal::get("{$pc}paymentDepartment") .
            " THEN total
            ELSE 0 END) as paymentTotal
            FROM " . CoreLocal::get("tDatabase") . ".$table
            WHERE trans_type <> 'L'{$refSQL}";

        $subsR = $conn->query("$subsQ");
        if ($subsR === False) {
            return "Error: query() failed for query: $subsQ";
        } else {
            $row = $conn->fetchRow($subsR);
            CoreLocal::set("{$pc}chargeTotal",
                (!$row || !isset($row['chargeTotal']))
                    ? 0 : (double)$row["chargeTotal"] );
            CoreLocal::set("{$pc}paymentTotal",
                (!$row || !isset($row['paymentTotal']))
                    ? 0 : (double)$row["paymentTotal"] );
            CoreLocal::set("{$pc}memChargeTotal",
                CoreLocal::get("{$pc}chargeTotal") +
                CoreLocal::get("{$pc}paymentTotal") );
        }

        $availBal = ((CoreLocal::get("{$pc}availCreditBalance") == '')
                        ? 0 : CoreLocal::get("{$pc}availCreditBalance")) +
                    CoreLocal::get("{$pc}memChargeTotal");
        CoreLocal::set("{$pc}availBal",number_format($availBal,2,'.',''));    

        CoreLocal::set("{$pc}balance",
            ((CoreLocal::get("{$pc}creditBalance") == '')
                ? 0 : CoreLocal::get("{$pc}creditBalance"))
        );

        return True;

    // getCCredSubtotals()
    }



    /**
      Connect to the coop cred database (local)
      @return a SQLManager object
    */
    static public function ccDataConnect()
    {
        global $CORE_LOCAL;

        if (self::$SQL_CONNECTION === null){
            /**
              Create the connection object and add all local databases to it.
            */
            self::$SQL_CONNECTION = new \COREPOS\pos\lib\SQLManager(CoreLocal::get("localhost"),
                CoreLocal::get("DBMS"),
                CoreLocal::get("tDatabase"),
                CoreLocal::get("localUser"),CoreLocal::get("localPass"),
                False);
            self::$SQL_CONNECTION->db_types[CoreLocal::get('pDatabase')] =
                        strtoupper(CoreLocal::get('DBMS'));
            if (isset(self::$SQL_CONNECTION->connections[CoreLocal::get('tDatabase')])) {
                self::$SQL_CONNECTION->connections[CoreLocal::get('pDatabase')] =
                            self::$SQL_CONNECTION->connections[CoreLocal::get('tDatabase')];
            }
            self::$SQL_CONNECTION->db_types[CoreLocal::get('CoopCredLaneDatabase')] =
                        strtoupper(CoreLocal::get('DBMS'));
            if (isset(self::$SQL_CONNECTION->connections[CoreLocal::get('CoopCredLaneDatabase')])) {
                self::$SQL_CONNECTION->connections[CoreLocal::get('CoopCredLaneDatabase')] =
                            self::$SQL_CONNECTION->connections[CoreLocal::get('tDatabase')];
            }
            self::$SQL_CONNECTION->query('use '.CoreLocal::get('CoopCredLaneDatabase'));
            self::$SQL_CONNECTION->default_db = CoreLocal::get('CoopCredLaneDatabase');
        } else {
            self::$SQL_CONNECTION->query('use '.CoreLocal::get('CoopCredLaneDatabase'));
            self::$SQL_CONNECTION->default_db = CoreLocal::get('CoopCredLaneDatabase');
        }    

        return self::$SQL_CONNECTION;

    // ccDataConnect()
    }


    /**
      Add the department to the list of payment departments in the current transaction.
      @return True or False
    */
    static public function addDepartmentUsed($paymentDepartment=0,$programID=0)
    {
        global $CORE_LOCAL;

        /* I think it could default to using the current ones set by programOK()
         */
        if (!$paymentDepartment || !$programID) {
            return False;
        }

        /* Add the department to those input to in this transaction.
         */
        if (CoreLocal::get('CCredDepartmentsUsed') == '') {
            CoreLocal::set('CCredDepartmentsUsed', array(
                "$paymentDepartment" => $programID
            ));
        } else {
            // Is there a push() or append()?
            $du = CoreLocal::get('CCredDepartmentsUsed');
            if (!array_key_exists("$paymentDepartment", $du)) {
                $du["$paymentDepartment"] = $programID;
                CoreLocal::set('CCredDepartmentsUsed',$du);
            }
        }

        return True;

    // addDepartmentUsed()
    }


// CoopCredLib class
}

