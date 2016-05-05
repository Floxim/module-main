<div 
    fx:template="pagination" 
    fx:b="pagination" 
    fx:styled
    fx:if="count($links) > 0">
    <fx:a href="$prev" fx:e="item type_prev {if !$prev}disabled{/if}">
        <span fx:e="wrap">&laquo;</span>
    </fx:a>
    <a fx:each="$links" href="{$url}" fx:e="item type_number {if $active}active{/if}">
        <span fx:e="wrap">{$page}</span>
    </a>
    <fx:a href="$next" fx:e="item type_next {if !$next}disabled{/if}">
        <span fx:e="wrap">&raquo;</span>
    </fx:a>
</div>