<?php
/* User Level: 2
 * Group Name: Продажи
 * Plugin Name: CampaignManager
 * Version: 2019-01-05
 * Description: Расчет планов и бонусов менеджеров
 * Author: baycik 2019
 * Author URI: 
 * Trigger After: CampaignManager
 */
class CampaignManager extends Catalog{
    public $min_level=3;
    
    public function index(){
        $this->load->view('campaign_manager.html');
    }
    
    public function campaignGet( int $campaign_id ){
        $settings=$this->get_row("SELECT * FROM plugin_campaign_list WHERE campaign_id='$campaign_id'");
        return [
            'settings'=>$settings,
            'staff_list'=>$this->Hub->load_model("Pref")->getStaffList(),
            'bonuses'=>$this->bonusesGet( $campaign_id ),
            'stock_category_list'=>$this->treeFetch('stock_tree',0,'top')
        ];
    }
    
    public function campaignAdd(){
        return $this->create('plugin_campaign_list');
    }
    
    public function campaignRemove(int $campaign_id){
        return $this->delete('plugin_campaign_list',['campaign_id'=>$campaign_id]);
    }
    
    public function campaignUpdate(int $campaign_id,string $field,string $value){
        return $this->update('plugin_campaign_list',[$field=>$value],['campaign_id'=>$campaign_id]);
    }
    
    private function clientListFilterGet($campaign_id){
        $settings=$this->get_row("SELECT * FROM plugin_campaign_list WHERE campaign_id='$campaign_id'");
        $assigned_path=  $this->Hub->svar('user_assigned_path');
        $or_case=[];
        $and_case=[];
        if( $assigned_path ){
            $and_case[]=" path LIKE '%".str_replace(",", "%' OR path LIKE '%", $assigned_path)."%'";
        }
        if( $settings->subject_path_include ){
            $or_case[]=" path LIKE '%".str_replace(",", "%' OR path LIKE '%", $settings->subject_path_include)."%'";
        }
        if( $settings->subject_path_exclude ){
            $and_case[]=" path NOT LIKE '%".str_replace(",", "%' AND path NOT LIKE '%", $settings->subject_path_exclude)."%'";
        }
        if( $settings->subject_manager_include ){
            $or_case[]=" manager_id = '".str_replace(",", "' OR manager_id = '", $settings->subject_manager_include)."'";
        }
        if( $settings->subject_manager_exclude ){
            $and_case[]=" manager_id <> '".str_replace(",", "' OR manager_id <> '", $settings->subject_manager_exclude)."'";
        }
        if( count($or_case) ){
            $where="(".implode(' OR ',$or_case).")";
        }
        if( count($and_case) ){
            if( count($or_case) ){
                $where.=" AND ";
            }
            $where.=implode(' AND ', $and_case);
        }
        return $where?$where:1;
    }
    
    public function clientListFetch(int $campaign_id, int $offset,int $limit,string $sortby='label',string $sortdir='ASC',array $filter){
        $having=$this->makeFilter($filter);
        $where=$this->clientListFilterGet($campaign_id);
        $sql="
            SELECT 
                label,
                path
            FROM
                companies_list
                    JOIN
                companies_tree USING(branch_id)
            WHERE $where
            HAVING $having
            ORDER BY $sortby $sortdir
	    LIMIT $limit OFFSET $offset";
        return $this->get_list($sql);
    }
    
    public function bonusAdd( int $campaign_id ){
        $sql="
            INSERT INTO 
                plugin_campaign_bonus
            SET
                campaign_id='$campaign_id',
                bonus_type='volume',
                campaign_start_at=NOW(),
                campaign_finish_at=DATE_ADD(NOW(), INTERVAL 1 YEAR)";
        return $this->query($sql);
    }
    
    public function bonusUpdate( int $campaign_bonus_id, string $field, string $value){
        $ok=$this->update('plugin_campaign_bonus',[$field=>$value],['campaign_bonus_id'=>$campaign_bonus_id]);
        if( $field === 'campaign_grouping_interval' ){
            $this->bonusPeriodsClear( $campaign_bonus_id );
            $this->bonusPeriodsFill( $campaign_bonus_id );
        } else if( $field === 'campaign_start_at' || $field === 'campaign_finish_at'  ){
            $this->bonusPeriodsFill( $campaign_bonus_id );
        }
        return $ok;
    }
    
    public function bonusRemove( int $campaign_bonus_id ){
        $this->bonusPeriodsClear( $campaign_bonus_id );
        return $this->delete('plugin_campaign_bonus',['campaign_bonus_id'=>$campaign_bonus_id]);
    }
    
    private function bonusGet($campaign_bonus_id){
        return $this->get('plugin_campaign_bonus',['campaign_bonus_id'=>$campaign_bonus_id]);
    }
    private function bonusesGet( int $campaign_id ){
        $bonuses=$this->get_list("SELECT * FROM plugin_campaign_bonus WHERE campaign_id='$campaign_id'");
//        foreach($bonuses as $bonus){
//            $bonus->periods=$this->bonusCalculate($bonus->campaign_bonus_id);
//        }
        return $bonuses;
    }    
    
    ////////////////////////////////////////////////////
    //PERIODS HANDLING
    ////////////////////////////////////////////////////
    private function bonusPeriodsClear( $campaign_bonus_id ){
        $this->delete('plugin_campaign_bonus_periods',['campaign_bonus_id'=>$campaign_bonus_id]);
    }
    
    public function bonusPeriodUpdate( int $campaign_bonus_period_id, string $field, string $value){
        return $this->update('plugin_campaign_bonus_periods',[$field=>$value],['campaign_bonus_period_id'=>$campaign_bonus_period_id]);
    }
    
    private function bonusPeriodsFill( $campaign_bonus_id ){
        $bonus=$this->bonusGet($campaign_bonus_id);
        if( $bonus->campaign_grouping_interval && $bonus->campaign_start_at && $bonus->campaign_finish_at ){
            $start_year= substr($bonus->campaign_start_at, 0, 4);
            $start_month = substr($bonus->campaign_start_at, 5, 2)*1;
            $start_quarter=ceil($start_month/3);

            $finish_year= substr($bonus->campaign_finish_at, 0, 4);
            $finish_month = substr($bonus->campaign_finish_at, 5, 2);
            $finish_quarter=ceil($finish_month/3);

            $delta_year=$finish_year-$start_year;
            $delta_month=$finish_month + $delta_year*12 - $start_month;
            $delta_quarter=$finish_quarter - $start_quarter + $delta_year*4;
            
            $delete_sql="DELETE FROM plugin_campaign_bonus_periods WHERE ";
            if( $bonus->campaign_grouping_interval =='YEAR' ){
                for( $y=$start_year;$m<=$finish_year; $y++ ){
                    $data=[
                        'campaign_bonus_id'=>$campaign_bonus_id,
                        'period_year'=>$y,
                        'period_quarter'=>0,
                        'period_month'=>0,
                        'period_plan1'=>0,
                        'period_plan2'=>0,
                        'period_plan3'=>0
                    ];
                    $this->bonusPeriodCreate($data);
                }
                $delete_sql.="  period_year<$start_year 
                                OR period_year>$finish_year";
            }
            if( $bonus->campaign_grouping_interval =='QUARTER' ){
                for( $q=$start_quarter;$q<=$start_quarter+$delta_quarter; $q++ ){
                    $year=$start_year+ceil($q/4)-1;
                    $quarter=($q-1)%4+1;
                    $data=[
                        'campaign_bonus_id'=>$campaign_bonus_id,
                        'period_year'=>$year,
                        'period_quarter'=>$quarter,
                        'period_month'=>0,
                        'period_plan1'=>0,
                        'period_plan2'=>0,
                        'period_plan3'=>0
                    ];
                    $this->bonusPeriodCreate($data);
                }
                $delete_sql.="  period_year<$start_year 
                                OR period_year>$finish_year
                                OR period_year=$start_year AND period_quarter<$start_quarter
                                OR period_year=$finish_year AND period_quarter>$finish_quarter";
            }
            if( $bonus->campaign_grouping_interval =='MONTH' ){
                for( $m=$start_month;$m<=$start_month+$delta_month; $m++ ){
                    $year=$start_year+ceil($m/12)-1;
                    $month=($m-1)%12+1;
                    $quarter=ceil($month/3);
                    $data=[
                        'campaign_bonus_id'=>$campaign_bonus_id,
                        'period_year'=>$year,
                        'period_quarter'=>$quarter,
                        'period_month'=>$month,
                        'period_plan1'=>0,
                        'period_plan2'=>0,
                        'period_plan3'=>0
                    ];
                    $this->bonusPeriodCreate($data);
                }
                $delete_sql.="  period_year<$start_year 
                                OR period_year>$finish_year
                                OR period_year=$start_year AND period_month<$start_month
                                OR period_year=$finish_year AND period_month>$finish_month";
            }
            $this->query($delete_sql);
            return true;
        }
        return false;
    }
    
    private function bonusPeriodCreate($data){
        $set=[];
        foreach($data as $field=>$value ){
            $set[]=" $field='$value'";
        }
        $sql="INSERT IGNORE INTO plugin_campaign_bonus_periods SET ". implode(',', $set);
        return $this->query($sql);
    }
    
    
    public function bonusCalculate( int $campaign_bonus_id ){
        $campaign_bonus=$this->bonusGet($campaign_bonus_id);
        $client_filter=$this->clientListFilterGet($campaign_bonus->campaign_id);
        switch( $campaign_bonus->campaign_grouping_interval ){
            case 'MONTH':
                //$select_interval=",DATE_FORMAT(cstamp,'%m.%Y') time_interval";
                //$period_filter='AND period_month<>0';
                $period_on=" YEAR(cstamp)=period_year AND MONTH(cstamp)=period_month ";
                break;
            case 'QUARTER':
                //$select_interval=",CONCAT(QUARTER(cstamp),' ',YEAR(cstamp)) time_interval";
                //$period_filter='AND period_quarter<>0 AND period_month=0';
                $period_on=" YEAR(cstamp)=period_year AND QUARTER(cstamp)=period_quarter";
                break;
            default :
                //$select_interval='';
                //$period_filter='AND period_year<>0 AND period_quarter=0';
                $period_on=" YEAR(cstamp)=period_year ";
        }
        switch( $campaign_bonus->bonus_type ){
            case 'VOLUME':
                $bonus_base= $this->bonusCalculateVolume($campaign_bonus,$period_on,$client_filter);
                break;
            case 'PROFIT':
                break;
            case 'PAYMENT':
                $bonus_base= $this->bonusCalculatePayment($campaign_bonus,$period_on,$client_filter);
                break;
        }
        $sql="SELECT
            *,
            ROUND(bonus_base*
                IF(bonus_base>period_plan3 AND period_plan3 AND campaign_bonus_ratio3,campaign_bonus_ratio3,
                IF(bonus_base>period_plan2 AND period_plan2 AND campaign_bonus_ratio2,campaign_bonus_ratio2,
                IF(bonus_base>period_plan1,campaign_bonus_ratio1,0
            )))/100) bonus_result,
            ROUND(bonus_base/period_plan1*100) period_percent1,
            ROUND(bonus_base/period_plan2*100) period_percent2,
            ROUND(bonus_base/period_plan3*100) period_percent3,
            CONCAT(period_month,'.',period_quarter,'.',period_year)=CONCAT(MONTH(NOW()),'.',QUARTER(NOW()),'.',YEAR(NOW())) is_current
        FROM (
            SELECT
                pcb.*,
                campaign_bonus_period_id,
                period_year,
                period_quarter,
                period_month,
                period_plan1,
                period_plan2,
                period_plan3,
                {$bonus_base['select']} bonus_base
            FROM
                plugin_campaign_bonus pcb 
                    JOIN
                plugin_campaign_bonus_periods pcbp USING (campaign_bonus_id)
                {$bonus_base['table']}
            WHERE
                campaign_bonus_id = $campaign_bonus_id
                {$bonus_base['where']}
            GROUP BY period_year,period_quarter,period_month
            ORDER BY period_year DESC,period_quarter DESC,period_month DESC) tt";
        return $this->get_list($sql);
    }
    
    private function bonusCalculateProductRange($campaign_bonus){
        $table="document_entries de";
        $where="";
        if( $campaign_bonus->product_category_id ){
            $stock_category_ids=$this->treeGetSub('stock_tree', $campaign_bonus->product_category_id);
            $table.=" JOIN stock_entries se ON de.product_code=se.product_code AND se.parent_id IN (". implode(',', $stock_category_ids).")";
        }
        if( $campaign_bonus->product_brand_filter || $campaign_bonus->product_type_filter ){
            $brand_filter =" analyse_brand LIKE '%". str_replace(',', "%' OR  analyse_brand LIKE '%", $campaign_bonus->product_brand_filter)."%'";
            $type_filter =" analyse_type  LIKE '%". str_replace(',', "%' OR  analyse_type  LIKE '%", $campaign_bonus->product_type_filter)."%'";
            $table.=" JOIN prod_list pl ON de.product_code=pl.product_code AND ($brand_filter) AND ($type_filter)";
        }
        return [
            'table'=>"(SELECT doc_id,invoice_price,de.product_quantity FROM $table)",
            'where'=>$where
        ];
    }
    
    private function bonusCalculateVolume($campaign_bonus,$period_on,$client_filter){
        $product_range= $this->bonusCalculateProductRange($campaign_bonus);
        $select="COALESCE(ROUND(SUM(invoice_price * product_quantity)),0)";
        $table="
                    LEFT JOIN
                document_list dl ON $period_on 
                                    AND doc_type = 1 
                                    AND is_commited 
                                    AND NOT notcount 
                                    AND passive_company_id IN (SELECT company_id FROM companies_list JOIN companies_tree USING(branch_id) WHERE $client_filter)
                    LEFT JOIN
                {$product_range['table']} product_range USING (doc_id)
                ";
        $where="";
        return [
            'select'=>$select,
            'table'=>$table,
            'where'=>$where
        ];
    }
    private function bonusCalculatePayment($campaign_bonus,$period_on,$client_filter){
        $payment_account="361";
        
        $select="COALESCE(ROUND(SUM(amount)),0)";
        $table="
                    LEFT JOIN
                        acc_trans at ON
                            passive_company_id IN (SELECT company_id FROM companies_list JOIN companies_tree USING(branch_id) WHERE $client_filter)
                    
                ";
        $where="";
        return [
            'select'=>$select,
            'table'=>$table,
            'where'=>$where
        ];
    }
}