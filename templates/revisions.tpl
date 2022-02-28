{assign var=staticId value="component-"|concat:$grid->getId()}
{assign var=gridId value=$staticId|concat:'-'|uniqid}
{assign var=gridTableId value=$gridId|concat:"-table"}
{assign var=gridActOnId value=$gridTableId}

<script>
	$(function() {ldelim}
		$('#{$gridId|escape:javascript}').pkpHandler(
				'{$grid->getJSHandler()|escape:javascript}',
				{ldelim}
					gridId: {$grid->getId()|json_encode},
		{foreach from=$grid->getUrls() key=key item=itemUrl name=gridUrls}
		{$key|json_encode}: {$itemUrl|json_encode},
		{/foreach}
		bodySelector: '#{$gridActOnId|escape:javascript}',
		{if $grid->getPublishChangeEvents()}
				publishChangeEvents: {$grid->getPublishChangeEvents()|@json_encode},
		{/if}
		features: {include file='controllers/grid/feature/featuresOptions.tpl' features=$features}
		{rdelim}
	);
		{rdelim});
</script>

<div id="{$gridId|escape}" class="pkp_controllers_grid">
	<div class="header">
		<h4>{$grid->getTitle()|translate}</h4>

		{if $createRevisionAction}
			<ul class="actions">
				<li>
					{include file="linkAction/linkAction.tpl" action=$createRevisionAction contextId=$staticId}
				</li>
			</ul>
		{/if}
	</div>

	<table id="{$gridTableId|escape}">
		<tbody>
		<tr>
			<td style="text-align: center">
				{if $revisionUrl}
					<a href="{$revisionUrl}" target="FidusWriter" rel="noopener noreferrer">
						{'plugins.generic.fidusWriter.linkText'|translate}
					</a>
				{else}
					{'grid.noItems'|translate}
				{/if}
			</td>
		</tr>
		</tbody>
	</table>
</div>
