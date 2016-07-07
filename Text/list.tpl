<div 
    fx:b="text-list .fx_no_add" 
    fx:template="list" 
    fx:of="list">
    {$items || :text /}
</div>

<div fx:template="text" fx:e="item" fx:b="text" fx:styled="Стиль текста">
    {$text}
</div>