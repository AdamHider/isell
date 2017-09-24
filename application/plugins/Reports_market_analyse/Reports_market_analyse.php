<?php
class Reports_market_analyse extends Catalog{
    public function __construct() {
	$this->fdate=$this->dmy2iso( $this->request('fdate','\d\d.\d\d.\d\d\d\d') ).' 23:59:59';
        $this->group_by_filter=$this->request('group_by_filter');
	$this->group_by=$this->request('group_by','\w+');
	parent::__construct();
    }
    private function dmy2iso( $dmy ){
	$chunks=  explode('.', $dmy);
	return "$chunks[2]-$chunks[1]-$chunks[0]";
    }

    public function viewGet(){
        $having=$this->group_by_filter?"HAVING group_by LIKE '%$this->group_by_filter%'":"";
        
        $pcomp_id=$this->Hub->pcomp('company_id');
        $sql_clear="DROP TEMPORARY TABLE IF EXISTS tmp_market_report";#TEMPORARY
        $sql_prepare="CREATE TEMPORARY TABLE tmp_market_report ( INDEX(product_code) ) ENGINE=MyISAM AS (
            SELECT
                B article,
                product_code,
                ru product_name,
                analyse_type,
                analyse_group,
                C store_code,
                D sold,
                E leftover
            FROM
                imported_data
                    LEFT JOIN
                prod_list pl ON analyse_section=B
            WHERE
                B<>'' 
                AND label='маркет')";
        
        $sql_price_setup="SET @_product_code:='',@_acomp_id:=2,@_pcomp_id:=222,@_to_cstamp:='{$this->fdate}';";
        $sql_price_clear="DROP  TABLE IF EXISTS tmp_market_report_price";
        $sql_price_prepare="CREATE  TABLE tmp_market_report_price ( INDEX(product_code) ) ENGINE=MyISAM AS (
            SELECT 
		product_code,ROUND(SUM(qty*invoice_price)/SUM(qty),2) avg_price 
	    FROM
		(SELECT 
		    product_code,
		    invoice_price,
		    @_quantity:=IF(product_code<>@_product_code AND @_product_code:=product_code,total,@_quantity)-product_quantity q,
		    product_quantity+LEAST(0,@_quantity) qty
		FROM
		    (SELECT 
			product_code,
			product_quantity,
			invoice_price*(1+dl.vat_rate/100) invoice_price,
			total
		    FROM
			(SELECT product_code,SUM(sold)+SUM(leftover) total FROM tmp_market_report GROUP BY product_code) tmr
			    JOIN
			document_entries de USING(product_code)
			    JOIN
			document_list dl USING (doc_id)
		    WHERE
			cstamp < @_to_cstamp
			AND active_company_id=@_acomp_id
			AND passive_company_id=@_pcomp_id
		    ORDER BY product_code,cstamp DESC) sub
		) sub2
            WHERE qty>0
            GROUP BY product_code)";
        $this->query($sql_clear);
        $this->query($sql_prepare);
        $this->query($sql_price_setup);
        $this->query($sql_price_clear);
        $this->query($sql_price_prepare);
	
        $sql_clear="DROP TEMPORARY TABLE IF EXISTS rpt_market_result";#TEMPORARY
        $sql_prepare="CREATE TEMPORARY TABLE rpt_market_result ( INDEX(product_code) ) ENGINE=MyISAM AS (
	    SELECT
		*,
		{$this->group_by} group_by,
                avg_price*sold sold_sum,
                avg_price*leftover leftover_sum
            FROM
                tmp_market_report
                    LEFT JOIN
                tmp_market_report_price USING(product_code)
	    #GROUP BY product_code,$this->group_by
            #$having
	    )";
		
		
        $sql_fetch="
            SELECT
		*
            FROM
                rpt_market_result";
        $sql_summary_type_fetch="
            SELECT
		group_by,
                SUM(sold) sold,
                SUM(avg_price*sold) sold_sum,
                SUM(leftover) leftover,
                SUM(avg_price*leftover) leftover_sum
            FROM
                rpt_market_result
		GROUP BY $this->group_by";
        $this->query($sql_clear);
        $this->query($sql_prepare);
	$rows=$this->get_list($sql_fetch);
	$sum_rows=$this->get_list($sql_summary_type_fetch);
	return [
	    'rows'=>count($rows)?$rows:[[]],
	    'sum_rows'=>count($sum_rows)?$sum_rows:[[]],
	    'sum'=>$this->calc_sum($sum_rows)
	];
    }
    private function calc_sum($sum_rows){
	$sum=[
	    'sum_sold'=>0,
	    'sum_leftover'=>0,
	    'sum_sold_sum'=>0,
	    'sum_leftover_sum'=>0
	];
	foreach($sum_rows as $row){
	    $sum['sum_sold']+=$row->sold;
	    $sum['sum_leftover']+=$row->leftover;
	    $sum['sum_sold_sum']+=$row->sold_sum;
	    $sum['sum_leftover_sum']+=$row->leftover_sum;
	}
	return $sum;
    }
}