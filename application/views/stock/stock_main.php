<script>
    /* global App */
    StockJs={
	init:function(){
	    App.initTabs('stock_main_tabs');
	}
    };
</script>

<div id="stock_main_tabs" class="slim-tab" style="min-width: 1000px">
    <div title="Остатки товара" href="page/stock/leftovers.html" style="min-height: 500px;"></div>
    <div title="Движения товара" href="page/stock/movements.html" style="min-height: 500px;"></div>
    <div title="Прайс лист" href="page/plugins/stock_price_list/stock_price_list.html" style="min-height: 500px;"></div>
</div>
