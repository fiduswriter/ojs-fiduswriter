<!-- Display link to Fiduswriter Revision -->
<div id="editorialDraftGrid" class="pkp_controllers_grid">
	<div class="header">
		<h4>{$title|translate}</h4>
	</div>
	<table id="authorEditorialDraftTable">
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
