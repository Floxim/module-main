<div fx:template="breadcrumbs" fx:b="breadcrumbs" fx:of="floxim.main.page:breadcrumbs">
    <span fx:each="$items.slice(0,-1)" fx:e="item">
        <a href="{$url}" fx:e="link">{$name}</a>
        <span fx:e="separator" fx:separator>{%separator} / {/%}</span>
    </span>
    <h1 fx:e="header">
        <span fx:e="header-text" fx:with="$items.last()">{$h1}{$name /}{/$}</span>
    </h1>
</div>