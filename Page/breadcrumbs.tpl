<div fx:template="breadcrumbs" fx:b="breadcrumbs" fx:of="floxim.main.page:breadcrumbs">
    <span fx:each="$items.slice(0,-1)" fx:e="item">
        <a href="{$url}" fx:e="link">{$name}</a>
        {if $position != $total}<span>{%separator} / {/%}</span>{/if}
    </span>
    
    {set $header}
        <span fx:e="header-text" fx:with="$items.last()">{$h1}{$name /}{/$}</span>
    {/set}
    {apply floxim.ui.header:header /}
</div>