<?php
require_once 'AccountsData.php';
class AccountsBank extends AccountsData{
    public $min_level=3;
    
    public $clientBankGet=[ 'main_acc_code'=>['string',0], 'page'=>['int',1], 'rows'=>['int',30] ];
    public function clientBankGet( $main_acc_code=0, $page=1, $rows=30 ){
	$active_company_id=$this->Hub->acomp('company_id');
        
	$having=$this->decodeFilterRules();
	$offset=$page>0?($page-1)*$rows:0;
	$sql="SELECT *,
		    IF(trans_id,'ok Проведен','gray Непроведен') AS status,
		    IF(debit_amount,ROUND(debit_amount,2),'') AS debit,
		    IF(credit_amount,ROUND(credit_amount,2),'') AS credit,
		    DATE_FORMAT(transaction_date,'%d.%m.%Y') AS tdate,
		    DATE_FORMAT(date,'%d.%m.%Y') AS date
                FROM acc_check_list 
		WHERE main_acc_code='$main_acc_code' AND active_company_id='$active_company_id'
		HAVING $having
		ORDER BY trans_id IS NOT NULL,transaction_date DESC 
		LIMIT $rows OFFSET $offset";
	$result_rows=$this->get_list($sql);
	$total_estimate=$offset+(count($result_rows)==$rows?$rows+1:count($result_rows));
	return ['rows'=>$result_rows,'total'=>$total_estimate,'sub_totals'=>$this->clientBankGetTotals( $result_rows )];
    }
    private function clientBankGetTotals( $rows ){
	$totals=['tdebit'=>0,'tcredit'=>0];
        foreach ($rows as $row) {
	    $totals['tdebit']+=$row->debit;
	    $totals['tcredit']+=$row->credit;
        }
	$totals['tdebit']=$totals['tdebit']?round($totals['tdebit'],2):'';
	$totals['tcredit']=$totals['tcredit']?round($totals['tcredit'],2):'';
	return $totals;
    }
    
    public $getCorrespondentStats=['check_id'=>['int',0]];
    public function getCorrespondentStats($check_id){
	$check=$this->getCheck($check_id);
	
        $Company=$this->Hub->load_model("Company");
        $company_id=$Company->companyFindByCode( 
                isset($check->correspondent_code)?$check->correspondent_code:null, 
                isset($check->correspondent_code)?$check->correspondent_code:null, 
                isset($check->company_bank_account)?$check->company_bank_account:null
                );
	if( !$company_id ){
	    return null;
	}
	
	$pcomp=$Company->selectPassiveCompany($company_id);
	$favs=$this->accountFavoritesFetch(true);
	foreach($favs as $acc){
	    $this->appendSuggestions($acc,$check);
	}
	return [
	    'trans_id'=>$check->trans_id,
	    'pcomp'=>$pcomp,
	    'favs'=>$favs
	];
    }
    
    private function getCheck( $check_id ){
	return $this->get_row("SELECT * FROM acc_check_list WHERE check_id=$check_id");
    }
    
    private function appendSuggestions( &$acc, $check ){
	$active_company_id=$this->Hub->acomp('company_id');
	$passive_company_id=$this->Hub->pcomp('company_id');
	$sql="SELECT 
		    at.*,
                    ROUND(at.amount,2) amount, 
		    DATE_FORMAT(tstamp,'%d.%m.%Y') date,
		    code,
		    descr
		FROM 
		    acc_trans  at
		        JOIN
		    acc_trans_status USING (trans_status)
			JOIN
		    acc_check_list acl ON debit_amount=amount OR credit_amount=amount
		WHERE 
		    at.active_company_id=$active_company_id
		    AND at.passive_company_id=$passive_company_id
                    AND IF(debit_amount>0,acc_credit_code='{$acc->acc_code}',acc_debit_code='{$acc->acc_code}')
		    AND trans_status IN(0,1,2,3)
		    AND acl.check_id={$check->check_id}";
	$acc->suggs=$this->get_list($sql);
	return $acc;
    }
    
    public $checkDelete=['check_ids'=>'raw'];
    public function checkDelete( $check_ids ){
        $this->query("START TRANSACTION");
        foreach ($check_ids as $check_id){
            $check=$this->getCheck($check_id);
            $ok=true;
            if( $check->trans_id ){
                $ok=$this->transDelete($check->trans_id);
            }
            if( !$ok ){
                return false;
            }
        }
        $check_list=implode(',',$check_ids);
        $this->query("DELETE FROM acc_check_list WHERE check_id IN ($check_list)");
        if( $this->db->affected_rows()>0 ){
            $this->query("COMMIT");
            return true;
        }
        return false;
    }
    
    /*
     * IMPORT OF FILE .csv
     */
    
    public $Up=['string'];
    public function Up( $main_acc_code ){
	if( $_FILES['upload_file'] && !$_FILES['upload_file']['error'] ){
	    if ( strrpos($_FILES['upload_file']['name'], '.csv') ){
		return $this->parseCSV( $_FILES['upload_file']['tmp_name'], $main_acc_code );
	    }
	}
        return 'error'.$_FILES['upload_file']['error'];
    }
    
    private function parseCSV( $UPLOADED_FILE, $main_acc_code ){
	$csv_raw = file_get_contents($UPLOADED_FILE);
	$csv = iconv('Windows-1251', 'UTF-8', $csv_raw);
	$csv_lines = explode("\n", $csv);
	array_shift($csv_lines);
	$csv_sequence=explode(',',str_replace( '-', '_', $this->Hub->pref('clientbank_fields') ));
	foreach ($csv_lines as $line) {
	    if ( !$line ){
		continue;
	    }
	    $i=0;
	    $check = [];
	    $vals = str_getcsv($line, ';');
	    foreach($csv_sequence as $field){
		$check[trim($field)]=$vals[$i++];
	    }
	    $this->addCheckDocument($check, $main_acc_code);
	}
	return 'imported';
    }
    
    private function addCheckDocument($check, $main_acc_code) {
	error_reporting(E_ERROR | E_WARNING | E_PARSE);
	$active_company_id=$this->Hub->acomp('company_id');
        $fields = ['number','date','value_date','debit_amount','credit_amount','assumption_date','currency','transaction_date','client_name','client_code','client_account','client_bank_name','client_bank_code','correspondent_name','correspondent_code','correspondent_account','correspondent_bank_name','correspondent_bank_code','assignment'];
        $set = ["active_company_id='$active_company_id'","main_acc_code='$main_acc_code'"];
        foreach ($fields as $field) {
	    $value = isset($check[$field])?$check[$field]:'';
            if ($field == 'debit_amount' || $field == 'credit_amount') {
                $value = str_replace(',', '.', $value);
            }
            if (strpos($field, 'date') !== false) {
                preg_match_all('/(\d{2})[^\d](\d{2})[^\d](\d{4})( \d\d:\d\d(:\d\d)?)?/i', $value, $matches);
                $value = "{$matches[3][0]}-{$matches[2][0]}-{$matches[1][0]}{$matches[4][0]}";
            }
	    $set[] = "$field='" . addslashes($value) . "' ";
        }
        $this->query("INSERT INTO acc_check_list SET " . implode(',', $set), false);
        return true;
    }
    
    /*
     * VIEW OUT
     */

    public $cbankViewGet=['page'=>'int','rows'=>'int','main_acc_code'=>'string','out_type'=>'string'];
    public function cbankViewGet($page,$rows,$main_acc_code,$out_type){
	$dump=$this->fillDump($main_acc_code, $page, $rows);
	$ViewManager=$this->Hub->load_model('ViewManager');
	$ViewManager->store($dump);
	$ViewManager->outRedirect($out_type);	
    }
    private function fillDump($main_acc_code, $page, $rows){
	$table=$this->clientBankGet($main_acc_code, $page, $rows);
	$dump=[
	    'tpl_files'=>$this->Hub->acomp('language').'/CheckList.xlsx',
	    'title'=>"Платежные поручения",
	    'user_data'=>[
		'email'=>$this->Hub->svar('pcomp')?$this->Hub->svar('pcomp')->company_email:'',
		'text'=>'Доброго дня'
	    ],
	    'view'=>[
		'a'=>$this->Hub->svar('acomp'),
		'user_sign'=>$this->Hub->svar('user_sign'),
		'table'=>$table
	    ]
	];
	return $dump;	
    }
}