<div fx:template="pagination" class="pagination" fx:with-each="$links">
    <a fx:omit="!$prev" href="{$prev}">
		<div class="item prev {if !$prev}disabled{/if}">&nbsp;</div>
	</a>
    <a href="{$url}" fx:item>
		<div class="item">{$page}</div>
	</a>
	<div class="item active" fx:item="$active">{$page}</div>
    <a fx:omit="!$next" href="{$next}">
		<div class="item next {if !$next}disabled{/if}">&nbsp;</div>
	</a>
</div>