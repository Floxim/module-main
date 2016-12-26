<div 
    fx:b="text-list .fx_no_add" 
    fx:template="list" 
    fx:name="Текст"
    fx:of="list">
    {$items || :text with $el_name = 'item'/}
</div>

<div fx:template="text" fx:e="{$el_name /}" fx:b="text" fx:styled="Стиль текста">
    {css}text.less{/css}
    {$text}
</div>