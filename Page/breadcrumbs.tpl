<div fx:template="breadcrumbs" fx:b="breadcrumbs" fx:of="floxim.main.page:breadcrumbs" fx:styled="label:Стиль крошек">
    <span fx:each="$items.slice(0,-1)" fx:e="item">
        <a href="{$url}" fx:e="link" fx:nows>
            <span fx:b="floxim.main.text:text" fx:styled="label:Стиль текста">{$name}</span>
        </a>
        {*
        {if $position != $total}<span>{%separator} / {/%}</span>{/if}
        *}
    </span>
    
    {set $header}
        <span fx:e="header-text" fx:with="$items.last()">{$h1}{$name /}{/$}</span>
    {/set}
    {apply floxim.ui.header:header el header /}
</div>