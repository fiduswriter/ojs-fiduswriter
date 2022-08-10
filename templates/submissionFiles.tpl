<div class="pkp_controllers_grid">
	<div class="header">
		<h4>{$grid->getTitle()|translate}</h4>
	</div>
	<div style="text-align: center;">
        {if $revisionUrl}
			<a href="{$openInFidusUrl}" target="FidusWriter">
				{'plugins.generic.fidusWriter.linkText'|translate}
			</a>
        {else}
            {'grid.noItems'|translate}
        {/if}
	</div>
</div>
