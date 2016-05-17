<div 
    fx:b="text-list .fx_no_add" 
    fx:styled
    fx:template="list" 
    fx:of="list">
    {css}text.less{/css}
    <div fx:e="item" fx:item>
        {$text}
    </div>
</div>